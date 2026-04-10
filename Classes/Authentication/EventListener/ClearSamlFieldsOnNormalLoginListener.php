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

namespace Mediadreams\MdSaml\Authentication\EventListener;

use Mediadreams\MdSaml\Authentication\SamlAuthService;
use TYPO3\CMS\Core\Authentication\Event\AfterUserLoggedInEvent;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Clears stale SAML session fields when a user logs in via the standard TYPO3
 * login (not via SAML).
 *
 * The md_saml_* fields (md_saml_source, md_saml_nameid, md_saml_nameid_format,
 * md_saml_session_index) persist in the user record across sessions. Without
 * this listener, a user who previously logged in via SAML and later switches to
 * the normal TYPO3 login would still have md_saml_source=1 in the database.
 * SlsBackendSamlMiddleware would then attempt to initiate a SAML SLO on the
 * next logout — redirecting the browser to the IdP, which has no active SAML
 * session and returns an error.
 *
 * Fires for both BE and FE logins (AfterUserLoggedInEvent covers both).
 */
final class ClearSamlFieldsOnNormalLoginListener
{
    public function __construct(
        private readonly ConnectionPool $connectionPool
    ) {
    }

    public function __invoke(AfterUserLoggedInEvent $event): void
    {
        // If this was a SAML login, SamlAuthService has already written the
        // correct md_saml_* values — nothing to clear.
        $request = $event->getRequest();
        $loginProvider = (string)($request?->getQueryParams()['loginProvider'] ?? '');
        if ($loginProvider === (string)SamlAuthService::SAML_LOGIN_PROVIDER_ID) {
            return;
        }

        $user = $event->getUser();
        $userId = (int)($user->user['uid'] ?? 0);
        if ($userId === 0) {
            return;
        }

        $table = $user->loginType === 'BE' ? 'be_users' : 'fe_users';
        $this->connectionPool->getConnectionForTable($table)->update(
            $table,
            [
                'md_saml_source' => 0,
                'md_saml_nameid' => '',
                'md_saml_nameid_format' => '',
                'md_saml_session_index' => '',
            ],
            ['uid' => $userId]
        );
    }
}
