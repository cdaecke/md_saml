<?php

declare(strict_types=1);

/*
 * This file is part of the Extension "md_saml" for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * (c) 2022 Christoph Daecke <typo3@mediadreams.org>
 */

namespace Mediadreams\MdSaml\Middleware;

use OneLogin\Saml2\Auth;
use OneLogin\Saml2\Error;
use OneLogin\Saml2\Utils;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;

/**
 * Class SlsFrontendSamlMiddleware
 */
class SlsFrontendSamlMiddleware extends SlsSamlMiddleware
{
    /**
     * Process request
     *
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     * @return ResponseInterface
     * @throws Error
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->context = 'FE';

        // After a successful IdP callback (ACS), redirect to the original URL that was
        // passed as RelayState. felogin cannot do this itself because redirect_url is
        // not part of the IdP POST-back body — only SAMLResponse and RelayState are.
        // This middleware runs after FrontendUserAuthenticator, so the user is already
        // authenticated when we reach this point.
        if (isset($request->getQueryParams()['acs'])) {
            $response = $handler->handle($request);

            $parsedBody = $request->getParsedBody();
            $relayState = is_array($parsedBody) ? (string)($parsedBody['RelayState'] ?? '') : '';
            $feUser = $request->getAttribute('frontend.user');

            if (
                $relayState !== ''
                && $feUser instanceof FrontendUserAuthentication
                && $feUser->user !== null
                && str_starts_with($relayState, Utils::getSelfURLhost())
                && !str_starts_with($relayState, Utils::getSelfRoutedURLNoQuery())
            ) {
                return new RedirectResponse($relayState, 303);
            }

            return $response;
        }

        $queryParams = $request->getQueryParams();

        // SLO callback from IdP for a FE-initiated SLO (context cookie = FE).
        // Processes the SAMLResponse, terminates the local FE session, clears both
        // cookies, and redirects to the page that originally triggered the logout.
        if (isset($queryParams['sls']) && ($request->getCookieParams()['md_saml_slo_context'] ?? '') === 'FE') {
            $extSettings = $this->settingsService->getSettings($this->context);
            if ($extSettings !== []) {
                try {
                    $auth = new Auth($extSettings['saml'], true);
                    // stay=true prevents the library from calling exit() internally.
                    // retrieveParametersFromServer=true preserves the exact URL encoding
                    // used by the IdP when computing the redirect-binding signature.
                    $auth->processSLO(
                        retrieveParametersFromServer: true,
                        stay: true,
                        cbDeleteSession: fn() => $this->performLogoff($request)
                    );
                    $errors = $auth->getErrors();

                    if ($errors !== []) {
                        if (in_array('logout_not_success', $errors, true)) {
                            // IdP returned non-success (e.g. ADFS with Windows Integrated
                            // Authentication cannot terminate the WIA session via SAML).
                            // Still terminate the local TYPO3 session so the user is logged out.
                            $this->performLogoff($request);
                            $this->logger->warning(
                                'md_saml: IdP returned non-success status for FE SLO. '
                                . 'Local TYPO3 session terminated anyway.',
                                ['errors' => $errors, 'lastErrorReason' => $auth->getLastErrorReason()]
                            );
                        } else {
                            $this->logger->error(
                                'SAML logout error in SlsFrontendSamlMiddleware',
                                [
                                    'context' => $this->context,
                                    'errors' => $errors,
                                    'lastErrorReason' => $auth->getLastErrorReason(),
                                    'exception' => $auth->getLastErrorException(),
                                ]
                            );
                        }
                    }
                } catch (Error $e) {
                    $this->logger->error(
                        'md_saml: Error processing FE SLO callback.',
                        ['exception' => $e->getMessage()]
                    );
                }
            }

            // Determine redirect target from the stored cookie (set during initiation).
            $redirectTo = urldecode($request->getCookieParams()['md_saml_slo_redirect'] ?? '');
            if (
                $redirectTo === ''
                || (!str_starts_with($redirectTo, '/') && !str_starts_with($redirectTo, Utils::getSelfURLhost()))
            ) {
                $redirectTo = '/';
            }

            // Clear both context and redirect cookies, then redirect to the post-logout page.
            $response = new RedirectResponse($redirectTo, 303);
            $response = $response->withAddedHeader(
                'Set-Cookie',
                'md_saml_slo_context=; Path=/; Max-Age=0; HttpOnly; SameSite=Lax'
            );
            return $response->withAddedHeader(
                'Set-Cookie',
                'md_saml_slo_redirect=; Path=/; Max-Age=0; HttpOnly; SameSite=Lax'
            );
        }

        // Skip SLO processing if this is a BE-initiated SLO callback (identified by cookie).
        // SlsBackendSamlMiddleware (registered before this in the frontend stack) handles it.
        if (isset($queryParams['sls']) && ($request->getCookieParams()['md_saml_slo_context'] ?? '') === 'BE') {
            return $handler->handle($request);
        }

        return parent::process($request, $handler);
    }

    protected function performLogoff(ServerRequestInterface $request): void
    {
        $context = GeneralUtility::makeInstance(Context::class);

        if ((bool)$context->getPropertyFromAspect('frontend.user', 'isLoggedIn')) {
            $feUser = $request->getAttribute('frontend.user');
            if ($feUser instanceof FrontendUserAuthentication) {
                $feUser->logoff();
            }
        }
    }
}
