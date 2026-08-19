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
use Mediadreams\MdSaml\Service\SettingsService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Authentication\AbstractUserAuthentication;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * getUserArrayForDb() decides which SAML attributes end up in fe_users/be_users.
 * The protected-column stripping is the last line of defense against privilege
 * escalation via a misconfigured transformationArr/databaseDefaults or a
 * compromised IdP — these tests exist to catch accidental regressions there.
 *
 * authUser()/inCharge()/initAuth() decide whether this AuthService is in charge
 * of a given login attempt at all — getting this gating wrong would either let
 * md_saml swallow logins it should not touch, or (for the BE branch) let SAML
 * authenticate backend users when activateBackendLogin is disabled.
 */
#[CoversClass(SamlAuthService::class)]
final class SamlAuthServiceTest extends UnitTestCase
{
    private SamlAuthService $subject;

    /**
     * @var array<string, mixed>
     */
    private array $requestBackup;

    /**
     * @var array<string, mixed>
     */
    private array $extensionConfigurationBackup;

    protected function setUp(): void
    {
        parent::setUp();

        // The constructor pulls SettingsService/EventDispatcherInterface via
        // GeneralUtility::makeInstance(), neither of which getUserArrayForDb()
        // touches. Skipping it keeps this a fast, hermetic unit test.
        $this->subject = (new \ReflectionClass(SamlAuthService::class))->newInstanceWithoutConstructor();
        $this->subject->setLogger(new NullLogger());

        $this->requestBackup = $_REQUEST;
        $this->extensionConfigurationBackup = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['md_saml'] ?? [];
    }

    protected function tearDown(): void
    {
        $_REQUEST = $this->requestBackup;
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['md_saml'] = $this->extensionConfigurationBackup;

        parent::tearDown();
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

    /**
     * AbstractAuthenticationService::$writeAttemptLog defaults to false, so
     * pObj->writelog() is never actually invoked by the code under test here —
     * a bare, uninitialized FrontendUserAuthentication (concrete only because
     * AbstractUserAuthentication itself cannot be instantiated, even via
     * reflection) is enough to satisfy the (docblock-only, PHPStan-checked)
     * property type of $pObj. The concrete class is otherwise irrelevant here:
     * only the loginType string below is read by the code under test.
     */
    private function buildPObj(string $loginType): AbstractUserAuthentication
    {
        $pObj = (new \ReflectionClass(FrontendUserAuthentication::class))->newInstanceWithoutConstructor();
        $pObj->loginType = $loginType;

        return $pObj;
    }

    private function buildAuthUserSubject(bool $useAuthService, string $loginType): SamlAuthService
    {
        $service = (new \ReflectionClass(SamlAuthService::class))->newInstanceWithoutConstructor();
        $service->setLogger(new NullLogger());

        (new \ReflectionClass(SamlAuthService::class))
            ->getProperty('useAuthService')
            ->setValue($service, $useAuthService);

        $service->pObj = $this->buildPObj($loginType);
        $service->authInfo = ['REMOTE_ADDR' => '127.0.0.1', 'REMOTE_HOST' => ''];
        $service->login = ['uname' => 'someuser'];

        return $service;
    }

    #[Test]
    public function authUserFailsContinueWhenNotInCharge(): void
    {
        $service = $this->buildAuthUserSubject(false, 'FE');

        self::assertSame(SamlAuthService::FAIL_CONTINUE, $service->authUser(['username' => 'someuser']));
    }

    #[Test]
    public function authUserSucceedsBreakForNonEmptyUsername(): void
    {
        $service = $this->buildAuthUserSubject(true, 'FE');

        self::assertSame(SamlAuthService::SUCCESS_BREAK, $service->authUser(['username' => 'someuser']));
    }

    #[Test]
    public function authUserFailsBreakForEmptyUsername(): void
    {
        $service = $this->buildAuthUserSubject(true, 'FE');

        self::assertSame(SamlAuthService::FAIL_BREAK, $service->authUser(['username' => '']));
    }

    #[Test]
    public function authUserFailsBreakWhenUsernameKeyIsMissing(): void
    {
        $service = $this->buildAuthUserSubject(true, 'FE');

        self::assertSame(SamlAuthService::FAIL_BREAK, $service->authUser([]));
    }

    private function buildInChargeSubject(string $loginType, array $login): SamlAuthService
    {
        $service = (new \ReflectionClass(SamlAuthService::class))->newInstanceWithoutConstructor();
        $service->setLogger(new NullLogger());
        $service->pObj = $this->buildPObj($loginType);
        $service->login = $login;

        return $service;
    }

    private function invokeInCharge(SamlAuthService $service): bool
    {
        $method = (new \ReflectionClass(SamlAuthService::class))->getMethod('inCharge');

        return $method->invoke($service);
    }

    #[Test]
    public function inChargeIsTrueForMatchingLoginProviderTypeAndStatus(): void
    {
        $_REQUEST = ['login-provider' => 'md_saml'];
        $service = $this->buildInChargeSubject('FE', ['status' => 'login']);

        self::assertTrue($this->invokeInCharge($service));
    }

    #[Test]
    public function inChargeIsFalseWhenLoginProviderDoesNotMatch(): void
    {
        $_REQUEST = ['login-provider' => 'some-other-provider'];
        $service = $this->buildInChargeSubject('FE', ['status' => 'login']);

        self::assertFalse($this->invokeInCharge($service));
    }

    #[Test]
    public function inChargeIsFalseWhenLoginProviderIsMissing(): void
    {
        $_REQUEST = [];
        $service = $this->buildInChargeSubject('FE', ['status' => 'login']);

        self::assertFalse($this->invokeInCharge($service));
    }

    #[Test]
    public function inChargeIsFalseForAnUnsupportedLoginType(): void
    {
        $_REQUEST = ['login-provider' => 'md_saml'];
        $service = $this->buildInChargeSubject('UNKNOWN', ['status' => 'login']);

        self::assertFalse($this->invokeInCharge($service));
    }

    #[Test]
    public function inChargeIsFalseWhenLoginStatusIsNotLogin(): void
    {
        $_REQUEST = ['login-provider' => 'md_saml'];
        $service = $this->buildInChargeSubject('FE', ['status' => 'logout']);

        self::assertFalse($this->invokeInCharge($service));
    }

    /**
     * @param array<string, mixed> $extSettings
     */
    private function buildInitAuthSubject(array $extSettings): SamlAuthService
    {
        $service = (new \ReflectionClass(SamlAuthService::class))->newInstanceWithoutConstructor();
        $service->setLogger(new NullLogger());

        // initAuth() calls settingsService->getSettings($loginType) to populate
        // extSettings before evaluating the BE/FE gating below — stubbed here so
        // this stays a hermetic unit test independent of site resolution.
        $settingsServiceStub = new class ($extSettings) extends SettingsService {
            public function __construct(private readonly array $settings)
            {
                parent::__construct(new NullLogger(), new class () implements EventDispatcherInterface {
                    public function dispatch(object $event): object
                    {
                        return $event;
                    }
                });
            }

            public function getSettings(string $loginType): array
            {
                return $this->settings;
            }
        };
        (new \ReflectionClass(SamlAuthService::class))
            ->getProperty('settingsService')
            ->setValue($service, $settingsServiceStub);

        return $service;
    }

    private function getUseAuthService(SamlAuthService $service): bool
    {
        return (new \ReflectionClass(SamlAuthService::class))->getProperty('useAuthService')->getValue($service);
    }

    private function callInitAuth(SamlAuthService $service, string $mode, string $table, string $loginType): void
    {
        $service->initAuth(
            $mode,
            ['status' => 'login'],
            ['db_user' => ['table' => $table]],
            $this->buildPObj($loginType)
        );
    }

    #[Test]
    public function initAuthActivatesForBackendWhenConfigEnabledAndInCharge(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['md_saml'] = ['activateBackendLogin' => '1'];
        $_REQUEST = ['login-provider' => 'md_saml'];

        $service = $this->buildInitAuthSubject(['fe_users' => ['active' => false]]);
        $this->callInitAuth($service, 'getUserBE', 'be_users', 'BE');

        self::assertTrue($this->getUseAuthService($service));
    }

    #[Test]
    public function initAuthDoesNotActivateForBackendWhenConfigDisabled(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['md_saml'] = ['activateBackendLogin' => '0'];
        $_REQUEST = ['login-provider' => 'md_saml'];

        $service = $this->buildInitAuthSubject([]);
        $this->callInitAuth($service, 'getUserBE', 'be_users', 'BE');

        self::assertFalse($this->getUseAuthService($service));
    }

    #[Test]
    public function initAuthDoesNotActivateForBackendWhenNotInChargeEvenIfConfigEnabled(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['md_saml'] = ['activateBackendLogin' => '1'];
        $_REQUEST = [];

        $service = $this->buildInitAuthSubject([]);
        $this->callInitAuth($service, 'getUserBE', 'be_users', 'BE');

        self::assertFalse($this->getUseAuthService($service));
    }

    #[Test]
    public function initAuthActivatesForFrontendWhenFeUsersActiveAndInCharge(): void
    {
        $_REQUEST = ['login-provider' => 'md_saml'];

        $service = $this->buildInitAuthSubject(['fe_users' => ['active' => true]]);
        $this->callInitAuth($service, 'getUserFE', 'fe_users', 'FE');

        self::assertTrue($this->getUseAuthService($service));
    }

    #[Test]
    public function initAuthDoesNotActivateForFrontendWhenFeUsersNotActive(): void
    {
        $_REQUEST = ['login-provider' => 'md_saml'];

        $service = $this->buildInitAuthSubject(['fe_users' => ['active' => false]]);
        $this->callInitAuth($service, 'getUserFE', 'fe_users', 'FE');

        self::assertFalse($this->getUseAuthService($service));
    }
}
