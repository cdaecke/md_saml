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

namespace Mediadreams\MdSaml\Tests\Functional\Service;

use Mediadreams\MdSaml\Service\SettingsService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Covers getConfigurationWithBaseVariants() (site baseVariants / ExpressionLanguage
 * conditions). This needs a functional test rather than a unit test: building the
 * ExpressionLanguage Resolver's dependencies (ProviderConfigurationLoader) requires
 * the full compiled DI container, which a bare unit test bootstrap does not provide
 * (see Tests/Unit/Service/SettingsServiceTest.php for the rest of the coverage).
 */
#[CoversClass(SettingsService::class)]
final class SettingsServiceTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['mediadreams/md_saml'];

    /**
     * @param array<string, mixed> $mdSamlSettings
     * @param array<int, array<string, mixed>>|null $baseVariants
     * @return array<string, mixed>
     */
    private function invokeGetConfigurationWithBaseVariants(array $mdSamlSettings, ?array $baseVariants): array
    {
        $subject = GeneralUtility::makeInstance(SettingsService::class);

        $method = (new \ReflectionClass(SettingsService::class))->getMethod('getConfigurationWithBaseVariants');

        /** @var array<string, mixed> $result */
        $result = $method->invoke($subject, $mdSamlSettings, $baseVariants);
        return $result;
    }

    #[Test]
    public function returnsUnchangedSettingsWhenNoBaseVariantsGiven(): void
    {
        $mdSamlSettings = ['mdsamlSpBaseUrl' => 'https://example.com'];

        self::assertSame($mdSamlSettings, $this->invokeGetConfigurationWithBaseVariants($mdSamlSettings, null));
        self::assertSame($mdSamlSettings, $this->invokeGetConfigurationWithBaseVariants($mdSamlSettings, []));
    }

    #[Test]
    public function appliesOverrideOfFirstMatchingCondition(): void
    {
        $mdSamlSettings = ['mdsamlSpBaseUrl' => 'https://example.com'];
        $baseVariants = [
            ['condition' => '1 == 2', 'md_saml' => ['mdsamlSpBaseUrl' => 'https://not-applied.example.com']],
            ['condition' => '1 == 1', 'md_saml' => ['mdsamlSpBaseUrl' => 'https://staging.example.com']],
            ['condition' => '1 == 1', 'md_saml' => ['mdsamlSpBaseUrl' => 'https://also-not-applied.example.com']],
        ];

        $result = $this->invokeGetConfigurationWithBaseVariants($mdSamlSettings, $baseVariants);

        self::assertSame('https://staging.example.com', $result['mdsamlSpBaseUrl']);
    }

    #[Test]
    public function mergesOverrideRecursivelyOntoBaseSettings(): void
    {
        $mdSamlSettings = [
            'mdsamlSpBaseUrl' => 'https://example.com',
            'saml' => ['sp' => ['entityId' => '/base-entity']],
        ];
        $baseVariants = [
            ['condition' => '1 == 1', 'md_saml' => ['saml' => ['idp' => ['entityId' => 'https://idp.example.com']]]],
        ];

        $result = $this->invokeGetConfigurationWithBaseVariants($mdSamlSettings, $baseVariants);

        self::assertSame('/base-entity', $result['saml']['sp']['entityId']);
        self::assertSame('https://idp.example.com', $result['saml']['idp']['entityId']);
    }

    #[Test]
    public function skipsVariantsWithInvalidConditionSyntax(): void
    {
        $mdSamlSettings = ['mdsamlSpBaseUrl' => 'https://example.com'];
        $baseVariants = [
            [
                'condition' => 'this is not valid expression syntax {{{',
                'md_saml' => ['mdsamlSpBaseUrl' => 'https://broken.example.com'],
            ],
            ['condition' => '1 == 1', 'md_saml' => ['mdsamlSpBaseUrl' => 'https://staging.example.com']],
        ];

        $result = $this->invokeGetConfigurationWithBaseVariants($mdSamlSettings, $baseVariants);

        self::assertSame('https://staging.example.com', $result['mdsamlSpBaseUrl']);
    }
}
