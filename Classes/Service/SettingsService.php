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

namespace Mediadreams\MdSaml\Service;

use Mediadreams\MdSaml\Event\AfterSettingsAreProcessedEvent;
use Mediadreams\MdSaml\Event\BeforeSettingsAreProcessedEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\ExpressionLanguage\Resolver;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class SettingsService
 */
class SettingsService implements SingletonInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        protected EventDispatcherInterface $eventDispatcher
    ) {}

    /**
     * Return settings
     *
     * @param string $loginType Can be 'FE' or 'BE'
     * @return array
     * @throws \RuntimeException
     */
    public function getSettings(string $loginType): array
    {
        $extSettings = [];

        $extSettings = $this->eventDispatcher->dispatch(
            new BeforeSettingsAreProcessedEvent($loginType, $extSettings)
        )->getSettings();

        if ($extSettings === []) {
            $extSettings = $this->getSamlConfig();
        }

        if (!$extSettings) {
            $this->logger->error('No md_saml config found. Perhaps you did not include the site set `MdSaml base configuration (ext:md_saml)`.');
            return [];
        }

        // Merge settings according to given context (frontend or backend)
        $extSettings['saml'] = array_replace_recursive($extSettings['saml'], $extSettings[mb_strtolower($loginType) . '_users']['saml']);

        // Add base url
        $extSettings['saml']['baseurl'] = $extSettings['mdsamlSpBaseUrl'];
        $extSettings['saml']['sp']['entityId'] = $extSettings['saml']['baseurl'] . $extSettings['saml']['sp']['entityId'];
        $extSettings['saml']['sp']['assertionConsumerService']['url'] = $extSettings['saml']['baseurl'] . $extSettings['saml']['sp']['assertionConsumerService']['url'];
        $extSettings['saml']['sp']['singleLogoutService']['url'] = $extSettings['saml']['baseurl'] . $extSettings['saml']['sp']['singleLogoutService']['url'];

        return $this->eventDispatcher->dispatch(
            new AfterSettingsAreProcessedEvent($loginType, $extSettings)
        )->getSettings();
    }

    /**
     * Get SAML configuration
     *
     * @return array
     */
    private function getSamlConfig(): array
    {
        $siteUrl = GeneralUtility::getIndpEnv('TYPO3_HOST_ONLY');

        /** @var Site $site */
        foreach (GeneralUtility::makeInstance(SiteFinder::class)->getAllSites() as $site) {
            if ($site->getBase()->getHost() === $siteUrl) {
                $settings = $site->getConfiguration()['settings']['md_saml']?? [];
                return $this->getConfigurationWithBaseVariants(
                    $settings,
                    $site->getConfiguration()['settings']['baseVariants']?? []
                );
            }

            foreach ($site->getLanguages() as $language) {
                if ($language->getBase()->getHost() == $siteUrl) {
                    $settings = $site->getConfiguration()['settings']['md_saml']?? [];
                    return $this->getConfigurationWithBaseVariants(
                        $settings,
                        $site->getConfiguration()['settings']['baseVariants']?? []
                    );
                }
            }
        }

        throw new \RuntimeException('The site configuration could not be resolved.', 1648646492);
    }

    /**
     * Get SAML configuration with baseVariants
     *
     * @param array $mdSamlSettings
     * @param array|null $baseVariants
     * @return array
     */
    private function getConfigurationWithBaseVariants(array $mdSamlSettings, ?array $baseVariants): array
    {
        $overrideSettings = [];
        if (!empty($baseVariants)) {
            $expressionLanguageResolver = GeneralUtility::makeInstance(
                Resolver::class,
                'site',
                []
            );
            foreach ($baseVariants as $baseVariant) {
                try {
                    if ((bool)$expressionLanguageResolver->evaluate($baseVariant['condition'])) {
                        $overrideSettings = $baseVariant['md_saml'];
                        break;
                    }
                } catch (SyntaxError $e) {
                    // silently fail and do not evaluate
                    // no logger here, as Site is currently cached and serialized
                }
            }
        }

        return array_replace_recursive($mdSamlSettings, $overrideSettings);
    }
}
