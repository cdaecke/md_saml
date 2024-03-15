<?php

declare(strict_types=1);

namespace Mediadreams\MdSaml\EventListener;

use Mediadreams\MdSaml\Service\SettingsService;
use TYPO3\CMS\Core\Authentication\Event\BeforeUserLogoutEvent;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class SamlBeforeUserLogoutEventListener
{
    public function __invoke(BeforeUserLogoutEvent $event): void
    {
        $userAuthentication = $event->getUser();

        if ($userAuthentication->userSession->getUserId() > 0) {
            if ($userAuthentication->userSession->isAnonymous()) {
                return;
            }

            // Fetch the user from the DB
            $userRecord = $userAuthentication->getRawUserByUid(
                $userAuthentication->userSession->getUserId() ?? 0
            );

            if ($userRecord['md_saml_source'] ?? false) {
                // we are responsible
                $settingsService = GeneralUtility::makeInstance(SettingsService::class);
                $settingsService->setInCharge(true);
            }
        }
    }
}
