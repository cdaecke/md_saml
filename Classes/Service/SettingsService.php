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
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class SettingsService
 */
class SettingsService implements SingletonInterface
{
    protected bool $inCharge = false;

    protected array $extSettings = [];

    protected EventDispatcherInterface $eventDispatcher;

    public function __construct(private readonly LoggerInterface $logger)
    {
        $this->eventDispatcher = GeneralUtility::makeInstance(EventDispatcherInterface::class);
    }

    public function getInCharge(): bool
    {
        return $this->inCharge;
    }

    public function setInCharge(bool $inCharge): void
    {
        $this->inCharge = $inCharge;
    }

    public function useFrontendAssertionConsumerServiceAuto(string $path): bool
    {
        $extSettings = $this->getSettings('fe');

        if (
            $extSettings['fe_users']['saml']['sp']['assertionConsumerService']['auto']
            && $extSettings['fe_users']['active']
        ) {
            $assertionConsumerServiceUrl = $extSettings['fe_users']['saml']['sp']['assertionConsumerService']['url'] ?? '/';
            return $path === $assertionConsumerServiceUrl && $_POST['SAMLResponse'];
        }

        return false;
    }

    /**
     * Return settings
     *
     * @param string $loginType Can be 'FE' or 'BE'
     * @return array
     * @throws \RuntimeException
     */
    public function getSettings(string $loginType): array
    {
        $this->extSettings = $this->eventDispatcher->dispatch(
            new BeforeSettingsAreProcessedEvent($loginType, $this->extSettings)
        )->getSettings();

        $this->extSettings = $this->getSamlConfig($this->getRootPageId());

        if (!$this->extSettings) {
            $this->logger->error('No md_saml config found. Perhaps you did not include the site set `MdSaml base configuration (ext:md_saml)`.');
            return [];
        }

        // Merge settings according to given context (frontend or backend)
        $this->extSettings['saml'] = array_replace_recursive($this->extSettings['saml'], $this->extSettings[mb_strtolower($loginType) . '_users']['saml']);

        // Add base url
        $this->extSettings['saml']['baseurl'] = $this->extSettings['mdsamlSpBaseUrl'];
        $this->extSettings['saml']['sp']['entityId'] = $this->extSettings['saml']['baseurl'] . $this->extSettings['saml']['sp']['entityId'];
        $this->extSettings['saml']['sp']['assertionConsumerService']['url'] = $this->extSettings['saml']['baseurl'] . $this->extSettings['saml']['sp']['assertionConsumerService']['url'];
        $this->extSettings['saml']['sp']['singleLogoutService']['url'] = $this->extSettings['saml']['baseurl'] . $this->extSettings['saml']['sp']['singleLogoutService']['url'];

        return $this->eventDispatcher->dispatch(
            new AfterSettingsAreProcessedEvent($loginType, $this->extSettings)
        )->getSettings();
    }

    /**
     * Get SAML configuration
     *
     * @param int $pageId
     * @return array
     * @throws SiteNotFoundException
     */
    private function getSamlConfig(int $pageId): array
    {
        $siteFinder = GeneralUtility::makeInstance(SiteFinder:: class);
        $site = $siteFinder->getSiteByPageId($pageId);

        return $site->getConfiguration()['settings']['md_saml']?? [];
    }

    /**
     * Get root page ID according to calling url
     *
     * @return int|null
     * @throws \RuntimeException
     */
    private function getRootPageId(): ?int
    {
        $siteUrl = GeneralUtility::getIndpEnv('TYPO3_HOST_ONLY');

        /** @var Site $site */
        foreach (GeneralUtility::makeInstance(SiteFinder::class)->getAllSites() as $site) {
            if ($site->getBase()->getHost() === $siteUrl) {
                return $site->getRootPageId();
            }

            /** @var SiteLanguage $language */
            foreach ($site->getLanguages() as $language) {
                if ($language->getBase()->getHost() == $siteUrl) {
                    return $site->getRootPageId();
                }
            }
        }

        throw new \RuntimeException('The site configuration could not be resolved.', 1648646492);
    }
}
