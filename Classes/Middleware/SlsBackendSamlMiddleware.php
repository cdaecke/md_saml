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
     * Dispatch the request to the appropriate handler.
     *
     * Two distinct flows are handled:
     *   1. SP-initiated SLO — intercept /typo3/logout for SAML BE users and
     *      redirect to the IdP's SLO endpoint (see initiateBackendSlo()).
     *   2. IdP SLO callback — process the SAMLResponse returned by the IdP,
     *      terminate the local session, and redirect to the BE login page
     *      (see handleSloCallback()).
     *
     * All other requests are passed through unchanged.
     *
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     * @return ResponseInterface
     * @throws Error
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->context = 'BE';

        $initiatedResponse = $this->initiateBackendSlo($request);
        if ($initiatedResponse instanceof ResponseInterface) {
            return $initiatedResponse;
        }

        $queryParams = $request->getQueryParams();
        if (isset($queryParams['sls']) && ($request->getCookieParams()['md_saml_slo_context'] ?? '') === 'BE') {
            return $this->handleSloCallback($request);
        }

        return $handler->handle($request);
    }

    /**
     * Intercept the TYPO3 backend logout route for SAML-authenticated users and
     * redirect to the IdP's SLO endpoint.
     *
     * Checks preconditions before acting:
     *   - The current route is /typo3/logout (not an SLO callback)
     *   - A BE user is logged in with md_saml_source=1
     *   - The backend SAML login is enabled in the extension configuration
     *   - The SAML settings are available and valid
     *   - The IdP has an SLO endpoint configured
     *
     * If the IdP has no SLO endpoint (idp.singleLogoutService.url is empty),
     * null is returned immediately so that process() falls through to the
     * standard TYPO3 session termination — preserving pre-v5 behaviour for
     * IdPs that do not support SLO (e.g. Google Workspace).
     *
     * When SAML SLO is initiated, the local TYPO3 session is terminated
     * immediately before the IdP redirect. This ensures the user is always
     * logged out of TYPO3 even if the IdP SLO callback never arrives.
     *
     * On success, sets the md_saml_slo_context=BE cookie (used to identify the
     * IdP callback later) and returns a 303 redirect to the IdP SLO URL.
     * Returns null when none of the preconditions are met so that process()
     * can continue to the next middleware.
     *
     * @throws Error
     */
    private function initiateBackendSlo(ServerRequestInterface $request): ?ResponseInterface
    {
        $routePath = (string)($request->getAttribute('route')?->getPath() ?? '');
        if (
            $routePath !== '/logout'
            || isset($request->getQueryParams()['sls'])
            || !isset($GLOBALS['BE_USER']->user)
            || ($GLOBALS['BE_USER']->user['md_saml_source'] ?? 0) !== 1
        ) {
            return null;
        }

        $backendConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('md_saml');
        if (($backendConfiguration['activateBackendLogin'] ?? '0') !== '1') {
            return null;
        }

        $extSettings = $this->settingsService->getSettings('BE');
        if ($extSettings === []) {
            return null;
        }

        // If the IdP has no SLO endpoint (key stripped by SettingsService when
        // idp.singleLogoutService.url is empty), skip SAML logout entirely and
        // fall through to the standard TYPO3 session termination.
        if (!isset($extSettings['saml']['idp']['singleLogoutService'])) {
            return null;
        }

        try {
            $auth = new Auth($extSettings['saml']);
            // Pass NameID and SessionIndex so the IdP (e.g. ADFS) can identify the
            // exact session to terminate. These are persisted in be_users at login time
            // because TYPO3 does not use PHP sessions between requests.
            $nameId = $GLOBALS['BE_USER']->user['md_saml_nameid'] ?? '';
            $sessionIndex = $GLOBALS['BE_USER']->user['md_saml_session_index'] ?? '';
            $sloUrl = $auth->logout(
                nameId: $nameId !== '' ? $nameId : null,
                sessionIndex: $sessionIndex !== '' ? $sessionIndex : null,
                stay: true,
                nameIdFormat: $GLOBALS['BE_USER']->user['md_saml_nameid_format'] ?? '',
            );

            if (is_string($sloUrl) && $sloUrl !== '') {
                // Terminate the local TYPO3 session immediately — this ensures
                // the user is logged out of TYPO3 even if the IdP SLO callback
                // never arrives (e.g. network failure or IdP timeout).
                // handleSloCallback() will call performLogoff() again, but that
                // is a no-op once the session is already gone.
                $this->performLogoff($request);

                // Set a short-lived cookie to mark this flow as a BE SLO.
                // IdPs (e.g. ADFS) do not reliably preserve RelayState, so the
                // cookie is used to identify the callback in handleSloCallback().
                $response = new RedirectResponse($sloUrl, 303);
                return $response->withAddedHeader(
                    'Set-Cookie',
                    'md_saml_slo_context=BE; Path=/; Max-Age=300; HttpOnly; SameSite=Lax; Secure'
                );
            }
        } catch (Error $error) {
            $this->logger->error(
                'md_saml: Could not build SAML SLO redirect URL during BE logout. '
                . 'Is idp.singleLogoutService configured?',
                ['exception' => $error->getMessage()]
            );
        }

        return null;
    }

    /**
     * Process the SLO callback from the IdP, terminate the local BE session,
     * and redirect to the backend login page.
     *
     * Called when the request carries ?sls and the md_saml_slo_context=BE cookie,
     * which was set by initiateBackendSlo(). This middleware is registered in both
     * the frontend and backend stacks so that it can handle the callback even when
     * the IdP redirects to a frontend URL (a common ADFS setup).
     *
     * If the IdP returns a non-success status (e.g. ADFS with Windows Integrated
     * Authentication), the local TYPO3 session is terminated anyway to ensure the
     * user is logged out on the TYPO3 side.
     */
    private function handleSloCallback(ServerRequestInterface $request): ResponseInterface
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
                        // IdP returned non-success (e.g. ADFS with WIA cannot terminate
                        // the WIA session via SAML). Still terminate the local session.
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
        $response = new RedirectResponse('/typo3/?loginProvider=' . SamlAuthService::SAML_LOGIN_PROVIDER_ID, 303);
        return $response->withAddedHeader(
            'Set-Cookie',
            'md_saml_slo_context=; Path=/; Max-Age=0; HttpOnly; SameSite=Lax; Secure'
        );
    }

    protected function performLogoff(ServerRequestInterface $request): void
    {
        if (isset($GLOBALS['BE_USER']->user)) {
            $GLOBALS['BE_USER']->logoff();
        }
    }
}
