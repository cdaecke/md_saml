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

use Mediadreams\MdSaml\Service\SettingsService;
use OneLogin\Saml2\Auth;
use OneLogin\Saml2\Error;
use OneLogin\Saml2\Utils;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Session\UserSessionManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Initiates SP-initiated SAML Single Logout for SAML-authenticated frontend users.
 *
 * Intercepts requests containing logintype=logout (as a GET parameter or in the POST
 * body) for users whose fe_users record carries md_saml_source=1. Builds a signed
 * LogoutRequest using the NameID and SessionIndex stored in fe_users at login time,
 * and redirects the browser to the IdP's SLO endpoint.
 *
 * Must run before typo3/cms-frontend/authentication: FrontendUserAuthenticator calls
 * FrontendUserAuthentication::start(), which processes logintype=logout and calls
 * logoff() — making the user record unavailable to any later middleware. To work
 * around this, the FE session is read directly via UserSessionManager and fe_users
 * is queried for the SAML session data.
 *
 * Sets two short-lived HttpOnly cookies before redirecting to the IdP:
 *   - md_saml_slo_context=FE  identifies the returning callback as a frontend SLO
 *   - md_saml_slo_redirect=<url>  stores the Referer so SlsFrontendSamlMiddleware
 *     can redirect the user back to the felogin page after the callback.
 *
 * If the IdP has no SLO endpoint (idp.singleLogoutService.url is empty), the user
 * is not a SAML user, or any error occurs, the request is passed on unchanged and
 * felogin performs a normal local logout without notifying the IdP — preserving
 * pre-v5 behaviour for IdPs that do not support SLO (e.g. Google Workspace).
 *
 * When SAML SLO is initiated, the local FE session is terminated immediately
 * before the IdP redirect via UserSessionManager::removeSession(). This ensures
 * the user is always logged out of TYPO3 even if the IdP SLO callback never
 * arrives (e.g. network failure or IdP timeout).
 *
 * Registered in the frontend middleware stack only, before typo3/cms-frontend/authentication.
 */
class SlsFrontendSloInitiatorMiddleware implements MiddlewareInterface
{
    public function __construct(
        protected SettingsService $settingsService,
        protected readonly LoggerInterface $logger
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $queryParams = $request->getQueryParams();
        $parsedBody = $request->getParsedBody();

        // Only intercept logout requests; ignore SLO callbacks.
        // logintype=logout can arrive as a GET parameter or in the POST body.
        $logoutTriggered = ($queryParams['logintype'] ?? '') === 'logout'
            || (is_array($parsedBody) && ($parsedBody['logintype'] ?? '') === 'logout');

        if (!$logoutTriggered || isset($queryParams['sls'])) {
            return $handler->handle($request);
        }

        $samlSession = $this->resolveSamlFrontendSession($request);
        if ($samlSession === null) {
            return $handler->handle($request);
        }

        [
            'userId' => $userId,
            'user' => $user,
            'session' => $session,
            'sessionManager' => $userSessionManager
        ] = $samlSession;

        $extSettings = $this->settingsService->getSettings('FE');
        if ($extSettings === [] || !isset($extSettings['saml']['idp']['singleLogoutService'])) {
            return $handler->handle($request);
        }

        try {
            $auth = new Auth($extSettings['saml']);
            // Pass NameID and session index so the IdP can identify the session
            // to terminate. Stored in fe_users at login because TYPO3 does not
            // use PHP sessions (where the library would normally keep this data).
            $nameId = $user['md_saml_nameid'] ?? '';
            $sessionIndex = $user['md_saml_session_index'] ?? '';
            $sloUrl = $auth->logout(
                nameId: $nameId !== '' ? $nameId : null,
                sessionIndex: $sessionIndex !== '' ? $sessionIndex : null,
                stay: true,
                nameIdFormat: $user['md_saml_nameid_format'] ?? '',
            );

            if (is_string($sloUrl) && $sloUrl !== '') {
                // Terminate the local FE session immediately — this ensures the
                // user is logged out of TYPO3 even if the IdP SLO callback never
                // arrives (e.g. network failure or IdP timeout).
                // SlsFrontendSamlMiddleware will call performLogoff() again on the
                // callback, but that is a no-op once the session is already gone.
                $userSessionManager->removeSession($session);

                // Clear the SAML session fields so that if the user later logs in
                // via the standard TYPO3 login, a stale md_saml_source=1 does not
                // cause SlsFrontendSloInitiatorMiddleware to redirect to the IdP on logout.
                GeneralUtility::makeInstance(ConnectionPool::class)
                    ->getConnectionForTable('fe_users')
                    ->update(
                        'fe_users',
                        [
                            'md_saml_source' => 0,
                            'md_saml_nameid' => '',
                            'md_saml_nameid_format' => '',
                            'md_saml_session_index' => '',
                        ],
                        ['uid' => $userId]
                    );

                // Store the referer as the post-logout redirect target so the user
                // lands back on the felogin page (now showing the login form).
                // Use a cookie because ADFS does not preserve custom RelayState.
                $referer = $request->getHeaderLine('Referer');
                $redirectAfter = ($referer !== '' && str_starts_with($referer, Utils::getSelfURLhost()))
                    ? $referer
                    : '/';

                $response = new RedirectResponse($sloUrl, 303);
                $response = $response->withAddedHeader(
                    'Set-Cookie',
                    'md_saml_slo_context=FE; Path=/; Max-Age=300; HttpOnly; SameSite=Lax; Secure'
                );
                // @phpcs:ignore Generic.Files.LineLength
                $cookie = 'md_saml_slo_redirect=' . urlencode($redirectAfter) . '; Path=/; Max-Age=300; HttpOnly; SameSite=Lax; Secure';
                return $response->withAddedHeader('Set-Cookie', $cookie);
            }
        } catch (Error $error) {
            $this->logger->error(
                'md_saml: Could not build SAML SLO redirect URL during FE logout. '
                . 'Is idp.singleLogoutService configured?',
                ['exception' => $error->getMessage()]
            );
        }

        return $handler->handle($request);
    }

    /**
     * Reads the current FE session from the request and looks up the fe_users record.
     *
     * Returns an array with keys userId, user, session, and sessionManager when the
     * current visitor is a SAML-authenticated frontend user (md_saml_source=1).
     * Returns null for anonymous visitors, non-SAML users, or missing user records.
     *
     * @return array<string, mixed>|null
     */
    private function resolveSamlFrontendSession(ServerRequestInterface $request): ?array
    {
        $cookieName = trim((string)($GLOBALS['TYPO3_CONF_VARS']['FE']['cookieName'] ?? ''));
        if ($cookieName === '') {
            $cookieName = 'fe_typo_user';
        }

        $sessionManager = UserSessionManager::create('FE');
        $session = $sessionManager->createFromRequestOrAnonymous($request, $cookieName);

        if ($session->isAnonymous()) {
            return null;
        }

        $userId = $session->getUserId();
        if ($userId === null) {
            return null;
        }

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('fe_users');
        $user = $queryBuilder
            ->select('md_saml_source', 'md_saml_nameid', 'md_saml_session_index', 'md_saml_nameid_format')
            ->from('fe_users')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($userId, Connection::PARAM_INT))
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($user === false || (int)$user['md_saml_source'] !== 1) {
            return null;
        }

        return [
            'userId' => $userId,
            'user' => $user,
            'session' => $session,
            'sessionManager' => $sessionManager,
        ];
    }
}
