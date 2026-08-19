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

use Mediadreams\MdSaml\Security\SameOriginUrlGuard;
use OneLogin\Saml2\Auth;
use OneLogin\Saml2\Error;
use OneLogin\Saml2\LogoutRequest;
use OneLogin\Saml2\Utils;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;

/**
 * Handles post-login ACS redirects and SAML Single Logout callbacks for frontend users.
 *
 * ACS redirect (after FE SAML login):
 *   After the IdP posts the SAMLResponse to the ACS endpoint (?acs), TYPO3 authenticates
 *   the user. This middleware then redirects to the RelayState URL — the original page the
 *   user intended to visit — which felogin cannot handle itself because RelayState is not
 *   part of its standard redirect mechanism.
 *
 * SP-initiated SLO callback (IdP → TYPO3, FE context):
 *   Handles the SAMLResponse returned by the IdP after SlsFrontendSloInitiatorMiddleware
 *   initiated a frontend user SLO (identified by the md_saml_slo_context=FE cookie).
 *   Validates the response, terminates the local frontend session, clears both SLO
 *   cookies, and redirects back to the URL stored in md_saml_slo_redirect.
 *
 * IdP-initiated SLO:
 *   Delegates to the parent SlsSamlMiddleware::process() for ?sls requests that carry
 *   no context cookie (pure IdP-initiated logout without a prior SP LogoutRequest).
 *
 * BE SLO passthrough:
 *   Skips all processing when md_saml_slo_context=BE is present — that callback is
 *   handled by SlsBackendSamlMiddleware, which runs earlier in the frontend stack.
 *
 * Registered in the frontend middleware stack only, after typo3/cms-frontend/authentication.
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

        if (isset($request->getQueryParams()['acs'])) {
            return $this->handleAcsCallback($request, $handler);
        }

        $queryParams = $request->getQueryParams();
        $sloContext = $request->getCookieParams()['md_saml_slo_context'] ?? '';

        if (isset($queryParams['sls']) && $sloContext === 'FE') {
            return $this->handleFeSloCallback($request);
        }

        // Skip SLO processing if this is a BE-initiated SLO callback (identified by cookie).
        // SlsBackendSamlMiddleware (registered before this in the frontend stack) handles it.
        if (isset($queryParams['sls']) && $sloContext === 'BE') {
            return $handler->handle($request);
        }

        return parent::process($request, $handler);
    }

    /**
     * After a successful ACS callback, redirect to the RelayState URL if it is a
     * same-origin URL different from the current ACS endpoint. felogin cannot do
     * this itself because RelayState is not part of its redirect mechanism.
     */
    private function handleAcsCallback(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        $response = $handler->handle($request);

        $parsedBody = $request->getParsedBody();
        $relayState = is_array($parsedBody) ? (string)($parsedBody['RelayState'] ?? '') : '';
        $feUser = $request->getAttribute('frontend.user');

        if (
            $relayState !== ''
            && $feUser instanceof FrontendUserAuthentication
            && $feUser->user !== null
            && $this->isSafeAcsRelayState($relayState)
        ) {
            return new RedirectResponse($relayState, 303);
        }

        return $response;
    }

    /**
     * A RelayState is only followed when it is same-origin (not an attacker-supplied
     * external URL) and does not point back at the ACS endpoint itself (which would
     * redirect-loop back into this same handler).
     */
    private function isSafeAcsRelayState(string $relayState): bool
    {
        return SameOriginUrlGuard::isSameOrigin($relayState)
            && !str_starts_with($relayState, Utils::getSelfRoutedURLNoQuery());
    }

    /**
     * Accepts only same-origin redirect targets: a relative path starting with a
     * single '/' or an absolute same-origin URL. Protocol-relative URLs (//evil.com)
     * are rejected — they start with '/' but browsers resolve them to an external host.
     */
    private function isSafeSloRedirectTarget(string $redirectTo): bool
    {
        return SameOriginUrlGuard::isSameOriginOrRootRelative($redirectTo);
    }

    /**
     * Process the SLO callback from the IdP for a FE-initiated logout.
     *
     * Validates the SAMLResponse, terminates the local FE session, clears both
     * SLO cookies, and redirects to the URL stored in md_saml_slo_redirect.
     */
    private function handleFeSloCallback(ServerRequestInterface $request): ResponseInterface
    {
        $extSettings = $this->settingsService->getSettings($this->context);
        if ($extSettings !== []) {
            try {
                $auth = new Auth($extSettings['saml'], true);
                // stay=true prevents the library from calling exit() internally.
                // retrieveParametersFromServer=true preserves the exact URL encoding
                // used by the IdP when computing the redirect-binding signature.
                $auth->processSLO(
                    retrieveParametersFromServer: true,
                    cbDeleteSession: fn() => $this->performLogoff($request),
                    stay: true
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
        if (!$this->isSafeSloRedirectTarget($redirectTo)) {
            $redirectTo = '/';
        }

        // Clear both context and redirect cookies, then redirect to the post-logout page.
        $response = new RedirectResponse($redirectTo, 303);
        $response = $response->withAddedHeader(
            'Set-Cookie',
            'md_saml_slo_context=; Path=/; Max-Age=0; HttpOnly; SameSite=Lax; Secure'
        );
        return $response->withAddedHeader(
            'Set-Cookie',
            'md_saml_slo_redirect=; Path=/; Max-Age=0; HttpOnly; SameSite=Lax; Secure'
        );
    }

    protected function performLogoff(ServerRequestInterface $request, ?Auth $auth = null): void
    {
        $context = GeneralUtility::makeInstance(Context::class);

        if ((bool)$context->getPropertyFromAspect('frontend.user', 'isLoggedIn')) {
            $feUser = $request->getAttribute('frontend.user');
            if ($feUser instanceof FrontendUserAuthentication) {
                // Capture the user ID before logoff() clears the user record.
                $userId = (int)($feUser->user['uid'] ?? 0);
                $feUser->logoff();

                // Clear the SAML session fields so that if the user later logs in
                // via the standard TYPO3 login, a stale md_saml_source=1 does not
                // cause SlsFrontendSloInitiatorMiddleware to redirect to the IdP on logout.
                // This is relevant for IdP-initiated SLO where Part A (SLO initiator) does
                // not run and the fields would otherwise remain set.
                $this->clearSamlFields('fe_users', $userId);
            }

            return;
        }

        $this->performNameIdFallbackLogoff($request, $auth);
    }

    /**
     * Fallback for IdP-initiated SLO delivered without the fe_typo_user cookie.
     * Some IdPs (e.g. ADFS) propagate a global logout to each SP via a hidden
     * iframe instead of a top-level browser redirect; an iframe navigation is
     * cross-site for SameSite=Lax cookie purposes, so the cookie is never sent
     * and no live FrontendUserAuthentication is available. Resolves the session
     * to terminate from the NameID in the already-validated LogoutRequest instead.
     *
     * Security: the NameID is only trusted when the LogoutRequest carried a
     * Signature parameter that LogoutRequest::isValid() actually verified. With
     * this project's default `wantMessagesSigned: false`, isValid() returns true
     * for a completely unsigned request as long as no Signature parameter is
     * present at all — without this check, an unsigned forged request would let
     * an unauthenticated caller terminate an arbitrary named user's sessions.
     */
    private function performNameIdFallbackLogoff(ServerRequestInterface $request, ?Auth $auth): void
    {
        if (!$auth instanceof Auth || !isset($request->getQueryParams()['Signature'])) {
            return;
        }

        $xml = $auth->getLastRequestXML();
        if ($xml === null) {
            return;
        }

        try {
            $nameId = LogoutRequest::getNameId($xml, $auth->getSettings()->getSPkey());
        } catch (\Exception $exception) {
            $this->logger->error(
                'md_saml: Could not extract NameID from IdP-initiated LogoutRequest.',
                ['exception' => $exception->getMessage()]
            );
            return;
        }

        if ($nameId === '') {
            return;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('fe_users');
        $uid = $queryBuilder
            ->select('uid')
            ->from('fe_users')
            ->where($queryBuilder->expr()->eq('md_saml_nameid', $queryBuilder->createNamedParameter($nameId)))
            ->executeQuery()
            ->fetchOne();
        $userId = $uid !== false ? (int)$uid : 0;

        if ($userId > 0) {
            $this->connectionPool->getConnectionForTable('fe_sessions')->delete('fe_sessions', ['ses_userid' => $userId]);
            $this->clearSamlFields('fe_users', $userId);
        }
    }
}
