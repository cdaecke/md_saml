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
        $frontendUserAuthentication = $event->getUser();
        if ($frontendUserAuthentication->userSession->getUserId() > 0) {
            if ($frontendUserAuthentication->userSession->isAnonymous()) {
                return;
            }
            // Fetch the user from the DB
            $userRecord = $frontendUserAuthentication->getRawUserByUid(
                $frontendUserAuthentication->userSession->getUserId() ?? 0
            );
            if ($userRecord['md_saml_source'] ?? false) {
                // we are responsible
                $settingsService = GeneralUtility::makeInstance(SettingsService::class);
                $settingsService->setInCharge(true);
            }
        }
    }
}
