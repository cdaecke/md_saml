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

use TYPO3\CMS\Backend\Security\SudoMode\Event\SudoModeRequiredEvent;

/**
 * Bypasses TYPO3's sudo-mode password verification for SAML-authenticated
 * backend users.
 *
 * TYPO3 v13.4.12+ (security fix TYPO3-CORE-SA-2025-013) requires backend
 * users to re-enter their password before elevated actions (e.g. editing
 * their own profile). SAML users have no TYPO3 password, making this check
 * impossible. Since they have already authenticated via the IdP — which may
 * enforce its own MFA policies — the additional TYPO3 password prompt is
 * redundant and would permanently lock them out of those actions.
 *
 * Registers as a listener for SudoModeRequiredEvent (Feature #106743).
 */
final class SudoModeVerifyEventListener
{
    public function __invoke(SudoModeRequiredEvent $event): void
    {
        if (($GLOBALS['BE_USER']->user['md_saml_source'] ?? 0) !== 1) {
            return;
        }

        $event->setVerificationRequired(false);
    }
}
