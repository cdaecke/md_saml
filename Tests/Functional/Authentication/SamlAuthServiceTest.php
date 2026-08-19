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

namespace Mediadreams\MdSaml\Tests\Functional\Authentication;

use Mediadreams\MdSaml\Authentication\SamlAuthService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Covers the fe_users DB roundtrip: record creation, record update (incl. the
 * no-op-when-unchanged short circuit), and the FE pid restriction in
 * fetchUserRecord(). ChangeUserEvent itself is a plain pass-through when no
 * listener is registered, so it is not separately covered here.
 */
#[CoversClass(SamlAuthService::class)]
final class SamlAuthServiceTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['mediadreams/md_saml'];

    private SamlAuthService $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/Fixtures/fe_users.csv');

        $this->subject = GeneralUtility::makeInstance(SamlAuthService::class);
        $this->subject->setLogger(new NullLogger());
        $this->subject->db_user = [
            'table' => 'fe_users',
            'username_column' => 'username',
            'enable_clause' => '',
        ];
        $this->subject->authInfo = [
            'loginType' => 'FE',
            'db_user' => ['table' => 'fe_users'],
        ];

        $user = (new \ReflectionClass(FrontendUserAuthentication::class))->newInstanceWithoutConstructor();
        $user->loginType = 'FE';

        $this->subject->pObj = $user;
    }

    /**
     * @param array<string, mixed> $extSettings
     */
    private function setExtSettings(array $extSettings): void
    {
        $property = (new \ReflectionClass(SamlAuthService::class))->getProperty('extSettings');
        $property->setValue($this->subject, $extSettings);
    }

    /**
     * @param array<string, mixed> $userData
     * @return array<string, mixed>|false
     */
    private function createUser(array $userData): array|false
    {
        $method = (new \ReflectionClass(SamlAuthService::class))->getMethod('createUser');
        return $method->invoke($this->subject, $userData);
    }

    /**
     * @param array<string, mixed> $localUser
     * @param array<string, mixed> $userData
     * @return array<string, mixed>|false
     */
    private function updateUser(array $localUser, array $userData): array|false
    {
        $method = (new \ReflectionClass(SamlAuthService::class))->getMethod('updateUser');
        return $method->invoke($this->subject, $localUser, $userData);
    }

    #[Test]
    public function createUserInsertsANewRecordWithHashedPasswordAndTimestamps(): void
    {
        $this->setExtSettings([]);

        $result = $this->createUser([
            'username' => 'new-user',
            'email' => 'new-user@example.com',
        ]);

        self::assertIsArray($result);
        self::assertSame('new-user', $result['username']);
        self::assertSame('new-user@example.com', $result['email']);
        self::assertNotSame('', $result['password']);
        self::assertGreaterThan(20, strlen((string)$result['password']));
        self::assertGreaterThan(0, (int)$result['crdate']);
        self::assertGreaterThan(0, (int)$result['tstamp']);
    }

    #[Test]
    public function createUserReturnsFalseWhenUsernameIsMissing(): void
    {
        $this->setExtSettings([]);

        self::assertFalse($this->createUser(['email' => 'no-username@example.com']));
    }

    #[Test]
    public function updateUserWritesChangedFieldsAndBumpsTstamp(): void
    {
        $this->setExtSettings([]);

        $localUser = $this->getFeUser('existing-user');
        $result = $this->updateUser($localUser, [
            'username' => 'existing-user',
            'email' => 'changed@example.com',
        ]);

        self::assertIsArray($result);
        self::assertSame('changed@example.com', $result['email']);
        self::assertGreaterThan(1000, (int)$result['tstamp']);
    }

    #[Test]
    public function updateUserDoesNotWriteWhenDataIsUnchanged(): void
    {
        $this->setExtSettings([]);

        $localUser = $this->getFeUser('existing-user');
        $this->updateUser($localUser, [
            'username' => 'existing-user',
            'email' => 'old@example.com',
        ]);

        $unchanged = $this->getFeUser('existing-user');
        self::assertSame(1000, (int)$unchanged['tstamp']);
    }

    #[Test]
    public function fetchUserRecordFindsUserWithinConfiguredPid(): void
    {
        $this->setExtSettings([
            'fe_users' => ['databaseDefaults' => ['pid' => 10]],
        ]);

        $result = $this->subject->fetchUserRecord('existing-user');

        self::assertIsArray($result);
        self::assertSame('existing-user', $result['username']);
    }

    #[Test]
    public function fetchUserRecordIgnoresUserOutsideConfiguredPid(): void
    {
        $this->setExtSettings([
            'fe_users' => ['databaseDefaults' => ['pid' => 10]],
        ]);

        // "other-pid-user" exists but lives on pid 20, not the configured pid 10.
        self::assertFalse($this->subject->fetchUserRecord('other-pid-user'));
    }

    /**
     * @return array<string, mixed>
     */
    private function getFeUser(string $username): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('fe_users');
        $row = $queryBuilder->select('*')
            ->from('fe_users')
            ->where($queryBuilder->expr()->eq('username', $queryBuilder->createNamedParameter($username)))
            ->executeQuery()
            ->fetchAssociative();
        self::assertIsArray($row);
        return $row;
    }
}
