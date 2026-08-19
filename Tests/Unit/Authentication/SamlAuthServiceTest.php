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

namespace Mediadreams\MdSaml\Tests\Unit\Authentication;

use Mediadreams\MdSaml\Authentication\SamlAuthService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * getUserArrayForDb() decides which SAML attributes end up in fe_users/be_users.
 * The protected-column stripping is the last line of defense against privilege
 * escalation via a misconfigured transformationArr/databaseDefaults or a
 * compromised IdP — these tests exist to catch accidental regressions there.
 */
#[CoversClass(SamlAuthService::class)]
final class SamlAuthServiceTest extends UnitTestCase
{
    private SamlAuthService $subject;

    protected function setUp(): void
    {
        parent::setUp();

        // The constructor pulls SettingsService/EventDispatcherInterface via
        // GeneralUtility::makeInstance(), neither of which getUserArrayForDb()
        // touches. Skipping it keeps this a fast, hermetic unit test.
        $this->subject = (new \ReflectionClass(SamlAuthService::class))->newInstanceWithoutConstructor();
        $this->subject->setLogger(new NullLogger());
    }

    /**
     * @param array<string, mixed> $extSettingsForTable
     * @param array<string, array<int, string>> $samlAttributes
     * @return array<string, mixed>
     */
    private function getUserArrayForDb(
        string $loginType,
        string $table,
        array $extSettingsForTable,
        array $samlAttributes
    ): array {
        $user = (new \ReflectionClass(FrontendUserAuthentication::class))->newInstanceWithoutConstructor();
        $user->loginType = $loginType;

        $this->subject->pObj = $user;
        $this->subject->authInfo = ['db_user' => ['table' => $table]];

        $extSettingsProperty = (new \ReflectionClass(SamlAuthService::class))->getProperty('extSettings');
        $extSettingsProperty->setValue($this->subject, [$table => $extSettingsForTable]);

        $method = (new \ReflectionClass(SamlAuthService::class))->getMethod('getUserArrayForDb');

        /** @var array<string, mixed> $result */
        $result = $method->invoke($this->subject, $samlAttributes);
        return $result;
    }

    #[Test]
    public function mapsSingleValueSamlAttributeToScalarUsingTransformationArr(): void
    {
        $result = $this->getUserArrayForDb(
            'FE',
            'fe_users',
            ['transformationArr' => ['username' => 'mail']],
            ['mail' => ['jane@example.com']],
        );

        self::assertSame('jane@example.com', $result['username']);
    }

    #[Test]
    public function mapsMultiValueSamlAttributeToArray(): void
    {
        $result = $this->getUserArrayForDb(
            'FE',
            'fe_users',
            ['transformationArr' => ['usergroup' => 'groups']],
            ['groups' => ['editor', 'author']],
        );

        self::assertSame(['editor', 'author'], $result['usergroup']);
    }

    #[Test]
    public function ignoresSamlAttributesThatAreNotInTransformationArr(): void
    {
        $result = $this->getUserArrayForDb(
            'FE',
            'fe_users',
            ['transformationArr' => ['username' => 'mail']],
            ['mail' => ['jane@example.com'], 'unmapped' => ['whatever']],
        );

        self::assertArrayNotHasKey('unmapped', $result);
    }

    #[Test]
    public function addsNonEmptyDatabaseDefaultsAfterTrimming(): void
    {
        $result = $this->getUserArrayForDb(
            'FE',
            'fe_users',
            [
                'transformationArr' => [],
                'databaseDefaults' => [' pid ' => ' 42 '],
            ],
            [],
        );

        self::assertSame('42', $result['pid']);
    }

    #[Test]
    public function skipsDatabaseDefaultsThatAreEmptyAfterTrimming(): void
    {
        $result = $this->getUserArrayForDb(
            'FE',
            'fe_users',
            [
                'transformationArr' => [],
                'databaseDefaults' => ['crdate' => '   '],
            ],
            [],
        );

        self::assertArrayNotHasKey('crdate', $result);
    }

    #[Test]
    public function alwaysMarksTheRecordAsSamlSourced(): void
    {
        $result = $this->getUserArrayForDb('FE', 'fe_users', ['transformationArr' => []], []);

        self::assertSame(1, $result['md_saml_source']);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function protectedColumnProvider(): array
    {
        return [
            'FE password via transformationArr' => ['FE', 'fe_users', 'password'],
            'FE uid via transformationArr' => ['FE', 'fe_users', 'uid'],
            'FE deleted via transformationArr' => ['FE', 'fe_users', 'deleted'],
            'BE password via transformationArr' => ['BE', 'be_users', 'password'],
            'BE uid via transformationArr' => ['BE', 'be_users', 'uid'],
            'BE deleted via transformationArr' => ['BE', 'be_users', 'deleted'],
            'BE admin via transformationArr' => ['BE', 'be_users', 'admin'],
        ];
    }

    #[Test]
    #[DataProvider('protectedColumnProvider')]
    public function stripsProtectedColumnSetViaMisconfiguredTransformationArr(
        string $loginType,
        string $table,
        string $column
    ): void {
        $result = $this->getUserArrayForDb(
            $loginType,
            $table,
            ['transformationArr' => [$column => 'attackerControlledAttribute']],
            ['attackerControlledAttribute' => ['1']],
        );

        self::assertArrayNotHasKey($column, $result);
    }

    #[Test]
    #[DataProvider('protectedColumnProvider')]
    public function stripsProtectedColumnSetViaDatabaseDefaults(string $loginType, string $table, string $column): void
    {
        $result = $this->getUserArrayForDb(
            $loginType,
            $table,
            [
                'transformationArr' => [],
                'databaseDefaults' => [$column => '1'],
            ],
            [],
        );

        self::assertArrayNotHasKey($column, $result);
    }

    #[Test]
    public function doesNotStripAdminColumnForFrontendUsers(): void
    {
        // fe_users has no 'admin' column, so this is a don't-care in practice —
        // this test only locks in the current, intentional BE-only scoping of
        // the 'admin' protection so it does not silently change.
        $result = $this->getUserArrayForDb(
            'FE',
            'fe_users',
            ['transformationArr' => ['admin' => 'attackerControlledAttribute']],
            ['attackerControlledAttribute' => ['1']],
        );

        self::assertArrayHasKey('admin', $result);
    }
}
