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

namespace Mediadreams\MdSaml\Tests\Unit\Service;

use Mediadreams\MdSaml\Event\BeforeSettingsAreProcessedEvent;
use Mediadreams\MdSaml\Service\SettingsService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(SettingsService::class)]
final class SettingsServiceTest extends UnitTestCase
{
    /**
     * @var array<string, string>
     */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->tempFiles = [];

        parent::tearDown();
    }

    /**
     * Builds a SettingsService whose BeforeSettingsAreProcessedEvent listener
     * supplies $inputSettings directly, so getSettings() never has to fall back
     * to SiteFinder-based site resolution (getSamlConfig()) — that path belongs
     * to a functional test.
     *
     * @param array<string, mixed> $inputSettings
     * @return array<string, mixed>
     */
    private function getSettings(string $loginType, array $inputSettings): array
    {
        $eventDispatcher = self::createStub(EventDispatcherInterface::class);
        $eventDispatcher->method('dispatch')->willReturnCallback(
            static function (object $event) use ($inputSettings): object {
                if ($event instanceof BeforeSettingsAreProcessedEvent) {
                    $event->setSettings($inputSettings);
                }

                return $event;
            }
        );

        $subject = new SettingsService(new NullLogger(), $eventDispatcher);
        return $subject->getSettings($loginType);
    }

    /**
     * @return array<string, mixed>
     */
    private function baseSettings(): array
    {
        return [
            'mdsamlSpBaseUrl' => 'https://example.com',
            'saml' => [
                'sp' => [
                    'entityId' => '/base-entity',
                    'assertionConsumerService' => ['url' => '/base-acs'],
                    'singleLogoutService' => ['url' => '/base-sls'],
                ],
                'idp' => [
                    'singleLogoutService' => ['url' => 'https://idp.example.com/slo'],
                ],
            ],
            'fe_users' => [
                'saml' => [
                    'sp' => ['entityId' => '/fe-entity'],
                ],
            ],
            'be_users' => [
                'saml' => [
                    'sp' => ['entityId' => '/be-entity'],
                ],
            ],
        ];
    }

    #[Test]
    public function mergesLoginTypeSpecificSamlSettingsOverBaseSettings(): void
    {
        $feResult = $this->getSettings('FE', $this->baseSettings());
        $beResult = $this->getSettings('BE', $this->baseSettings());

        self::assertSame('https://example.com/fe-entity', $feResult['saml']['sp']['entityId']);
        self::assertSame('https://example.com/be-entity', $beResult['saml']['sp']['entityId']);
    }

    #[Test]
    public function keepsBaseSettingsUntouchedByLoginTypeOverrideMerge(): void
    {
        // fe_users/be_users only override sp.entityId in the fixture — the
        // recursive merge must not clobber sibling keys like assertionConsumerService.
        $result = $this->getSettings('FE', $this->baseSettings());

        self::assertSame('https://example.com/base-acs', $result['saml']['sp']['assertionConsumerService']['url']);
    }

    #[Test]
    public function prefixesSpUrlsWithConfiguredBaseUrl(): void
    {
        $result = $this->getSettings('FE', $this->baseSettings());

        self::assertSame('https://example.com', $result['saml']['baseurl']);
        self::assertSame('https://example.com/base-sls', $result['saml']['sp']['singleLogoutService']['url']);
    }

    #[Test]
    public function keepsSloEndpointsWhenIdpSupportsSlo(): void
    {
        $result = $this->getSettings('FE', $this->baseSettings());

        self::assertArrayHasKey('singleLogoutService', $result['saml']['idp']);
        self::assertArrayHasKey('singleLogoutService', $result['saml']['sp']);
    }

    #[Test]
    public function stripsSloEndpointsWhenIdpHasNoSloUrl(): void
    {
        $settings = $this->baseSettings();
        $settings['saml']['idp']['singleLogoutService']['url'] = '';

        $result = $this->getSettings('FE', $settings);

        self::assertArrayNotHasKey('singleLogoutService', $result['saml']['idp']);
        self::assertArrayNotHasKey('singleLogoutService', $result['saml']['sp']);
    }

    #[Test]
    public function leavesInlineCertContentUnchanged(): void
    {
        $settings = $this->baseSettings();
        $settings['saml']['sp']['x509cert'] = 'aW5saW5lLWJhc2U2NC1jb250ZW50';

        $result = $this->getSettings('FE', $settings);

        self::assertSame('aW5saW5lLWJhc2U2NC1jb250ZW50', $result['saml']['sp']['x509cert']);
    }

    #[Test]
    public function resolvesCertFilePathAndStripsPemHeadersAndWhitespace(): void
    {
        // GeneralUtility::getFileAbsFileName() only resolves absolute paths inside
        // Environment::getProjectPath() (or an explicitly allowed additional path),
        // so the fixture file must live under the project path, not sys_get_temp_dir().
        if (!is_dir(Environment::getVarPath())) {
            mkdir(Environment::getVarPath(), 0777, true);
        }

        $path = Environment::getVarPath() . '/md_saml_cert_test_' . bin2hex(random_bytes(8)) . '.crt';
        $this->tempFiles[] = $path;
        file_put_contents(
            $path,
            "-----BEGIN CERTIFICATE-----\nabc\ndef\n-----END CERTIFICATE-----\n"
        );

        $settings = $this->baseSettings();
        $settings['saml']['sp']['x509cert'] = $path;

        $result = $this->getSettings('FE', $settings);

        self::assertSame('abcdef', $result['saml']['sp']['x509cert']);
    }

    // getConfigurationWithBaseVariants() (site baseVariants / ExpressionLanguage
    // conditions) is intentionally not covered here: GeneralUtility::makeInstance()
    // cannot build the ExpressionLanguage Resolver's dependencies (ProviderConfigurationLoader)
    // without the full compiled DI container, which the bare unit test bootstrap does not
    // provide. That coverage belongs in a functional test instead.
}
