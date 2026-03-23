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
 * If no SLO endpoint is configured, the user is not a SAML user, or any error
 * occurs, the request is passed on unchanged and felogin performs a normal local
 * logout without notifying the IdP.
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

        // Read the current FE session directly, before the authentication middleware
        // processes it. FrontendUserAuthenticator calls FrontendUserAuthentication::start()
        // which handles logintype=logout (logoff) — so we must act before that runs.
        $cookieName = trim((string)($GLOBALS['TYPO3_CONF_VARS']['FE']['cookieName'] ?? ''));
        if ($cookieName === '') {
            $cookieName = 'fe_typo_user';
        }

        $userSessionManager = UserSessionManager::create('FE');
        $session = $userSessionManager->createFromRequestOrAnonymous($request, $cookieName);

        if ($session->isAnonymous()) {
            return $handler->handle($request);
        }

        $userId = $session->getUserId();
        if ($userId === null) {
            return $handler->handle($request);
        }

        // Look up the fe_users record to check whether this is a SAML-authenticated user
        // and to retrieve the NameID / SessionIndex needed for the LogoutRequest.
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
            return $handler->handle($request);
        }

        $extSettings = $this->settingsService->getSettings('FE');
        if ($extSettings === []) {
            return $handler->handle($request);
        }

        try {
            $auth = new Auth($extSettings['saml']);
            // Pass NameID and session index so the IdP can identify the session
            // to terminate. Stored in fe_users at login because TYPO3 does not
            // use PHP sessions (where the library would normally keep this data).
            $sloUrl = $auth->logout(
                nameId: ($user['md_saml_nameid'] ?? '') ?: null,
                sessionIndex: ($user['md_saml_session_index'] ?? '') ?: null,
                nameIdFormat: $user['md_saml_nameid_format'] ?? '',
                stay: true,
            );

            if (is_string($sloUrl) && $sloUrl !== '') {
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
                return $response->withAddedHeader(
                    'Set-Cookie',
                    'md_saml_slo_redirect=' . urlencode($redirectAfter) . '; Path=/; Max-Age=300; HttpOnly; SameSite=Lax; Secure'
                );
            }
        } catch (Error $e) {
            $this->logger->error(
                'md_saml: Could not build SAML SLO redirect URL during FE logout. '
                . 'Is idp.singleLogoutService configured?',
                ['exception' => $e->getMessage()]
            );
        }

        return $handler->handle($request);
    }
}
