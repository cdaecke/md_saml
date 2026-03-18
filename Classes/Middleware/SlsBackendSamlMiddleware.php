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

use Mediadreams\MdSaml\Authentication\SamlAuthService;
use OneLogin\Saml2\Auth;
use OneLogin\Saml2\Error;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Handles SAML Single Logout for backend users in both directions.
 *
 * SP-initiated SLO (TYPO3 → IdP):
 *   Intercepts the standard TYPO3 v13 backend logout route (/typo3/logout) for users
 *   authenticated via SAML (md_saml_source=1). Builds a signed LogoutRequest using the
 *   NameID and SessionIndex stored in be_users at login time, sets the short-lived
 *   md_saml_slo_context=BE cookie to mark the flow, and redirects to the IdP's SLO
 *   endpoint. The TYPO3 session is terminated only after the IdP callback arrives.
 *
 * IdP callback (IdP → TYPO3):
 *   Processes the SAMLResponse identified by the md_saml_slo_context=BE cookie.
 *   Validates the response, terminates the local backend session, clears the cookie,
 *   and redirects to the backend login page.
 *
 * Registered in both the backend and frontend middleware stacks. The dual-stack
 * registration is necessary because ADFS (and other IdPs) redirect the browser to
 * the URL in sp.singleLogoutService, which in many setups points to a frontend URL
 * even though the SLO was initiated from the backend.
 */
class SlsBackendSamlMiddleware extends SlsSamlMiddleware
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
        $this->context = 'BE';

        // SP-initiated SLO: intercept the standard TYPO3 v13 BE logout URL
        // (/typo3/logout?token=...) which no longer includes loginProvider/sls
        // parameters. We redirect to the IdP's SLO endpoint here, before the
        // route dispatcher runs, so we can return a clean RedirectResponse
        // without calling exit(). The actual TYPO3 session termination happens
        // when the IdP redirects back to the configured sp.singleLogoutService.url
        // and SlsSamlMiddleware::process() handles the SAMLResponse via processSLO().
        $routePath = (string)($request->getAttribute('route')?->getPath() ?? '');
        if (
            $routePath === '/logout'
            && !isset($request->getQueryParams()['sls'])
            && isset($GLOBALS['BE_USER']->user)
            && ($GLOBALS['BE_USER']->user['md_saml_source'] ?? 0) === 1
        ) {
            $backendConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class)
                ->get('md_saml');

            if (($backendConfiguration['activateBackendLogin'] ?? '0') === '1') {
                $extSettings = $this->settingsService->getSettings('BE');

                if ($extSettings !== []) {
                    try {
                        $auth = new Auth($extSettings['saml']);
                        // Pass NameID and session index so ADFS can identify the session
                        // to terminate. These are stored in the user record at login time
                        // because TYPO3 does not use PHP sessions (where the library would
                        // normally keep this data between the login and logout requests).
                        $sloUrl = $auth->logout(
                            nameId: ($GLOBALS['BE_USER']->user['md_saml_nameid'] ?? '') ?: null,
                            sessionIndex: ($GLOBALS['BE_USER']->user['md_saml_session_index'] ?? '') ?: null,
                            nameIdFormat: $GLOBALS['BE_USER']->user['md_saml_nameid_format'] ?? '',
                            stay: true,
                        );

                        if (is_string($sloUrl) && $sloUrl !== '') {
                            // Set a short-lived HttpOnly cookie to mark this as a BE SLO.
                            // IdPs (e.g. ADFS) do not preserve a custom RelayState, so we
                            // use a cookie instead. The cookie is sent back when the IdP
                            // redirects the browser to the sp.singleLogoutService.url,
                            // allowing SlsBackendSamlMiddleware (registered in both stacks)
                            // to identify and handle the callback with BE settings.
                            $response = new RedirectResponse($sloUrl, 303);
                            return $response->withAddedHeader(
                                'Set-Cookie',
                                'md_saml_slo_context=BE; Path=/; Max-Age=300; HttpOnly; SameSite=Lax'
                            );
                        }
                    } catch (Error $e) {
                        $this->logger->error(
                            'md_saml: Could not build SAML SLO redirect URL during BE logout. '
                            . 'Is idp.singleLogoutService configured?',
                            ['exception' => $e->getMessage()]
                        );
                    }
                }
            }
        }

        // SLO callback from IdP: only handle if the BE SLO cookie is present.
        // This middleware is registered in both stacks so it can handle BE callbacks
        // even when the IdP redirects to a frontend URL (e.g. ADFS with a frontend
        // URL in its SP metadata). The cookie was set when the SLO was initiated above.
        $queryParams = $request->getQueryParams();
        if (isset($queryParams['sls']) && ($request->getCookieParams()['md_saml_slo_context'] ?? '') === 'BE') {
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
                                'md_saml: IdP returned non-success status for BE SLO. '
                                . 'Local TYPO3 session terminated anyway.',
                                ['errors' => $errors, 'lastErrorReason' => $auth->getLastErrorReason()]
                            );
                        } else {
                            $this->logger->error(
                                'SAML logout error in SlsBackendSamlMiddleware',
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
                        'md_saml: Error processing BE SLO callback.',
                        ['exception' => $e->getMessage()]
                    );
                }
            }

            // Clear the marker cookie and redirect to the backend login.
            // We do not call $handler->handle() here to avoid triggering unnecessary
            // frontend page rendering — the user belongs back at the backend login.
            $response = new RedirectResponse('/typo3/?loginProvider=' . SamlAuthService::SAML_LOGIN_PROVIDER_ID, 303);
            return $response->withAddedHeader(
                'Set-Cookie',
                'md_saml_slo_context=; Path=/; Max-Age=0; HttpOnly; SameSite=Lax'
            );
        }

        return $handler->handle($request);
    }

    protected function performLogoff(ServerRequestInterface $request): void
    {
        if (isset($GLOBALS['BE_USER']->user)) {
            $GLOBALS['BE_USER']->logoff();
        }
    }
}
