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

namespace Mediadreams\MdSaml\Tests\Functional\Authentication\EventListener;

use Mediadreams\MdSaml\Authentication\EventListener\ClearSamlFieldsOnNormalLoginListener;
use Mediadreams\MdSaml\Authentication\SamlAuthService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\AbstractUserAuthentication;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Authentication\Event\AfterUserLoggedInEvent;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * A stale md_saml_source=1 from a prior SAML login must not survive a
 * subsequent normal TYPO3 login — otherwise the next logout would incorrectly
 * try to initiate a SAML SLO for a session the IdP never issued (see the
 * class docblock on ClearSamlFieldsOnNormalLoginListener).
 */
#[CoversClass(ClearSamlFieldsOnNormalLoginListener::class)]
final class ClearSamlFieldsOnNormalLoginListenerTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['mediadreams/md_saml'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/Fixtures/fe_users_clear_saml_fields.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/be_users_clear_saml_fields.csv');
    }

    /**
     * @param class-string<AbstractUserAuthentication> $class
     */
    private function buildUser(string $class, string $loginType, int $uid): AbstractUserAuthentication
    {
        $user = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        $user->loginType = $loginType;
        $user->user = $uid !== 0 ? ['uid' => $uid] : null;

        return $user;
    }

    /**
     * @param array<string, string> $queryParams
     */
    private function buildRequest(array $queryParams = []): ServerRequest
    {
        return (new ServerRequest('https://typo3-testing.local/index.php'))->withQueryParams($queryParams);
    }

    private function invokeListener(AfterUserLoggedInEvent $event): void
    {
        $listener = GeneralUtility::makeInstance(ClearSamlFieldsOnNormalLoginListener::class);
        $listener($event);
    }

    /**
     * @return array<string, mixed>
     */
    private function getFeUser(): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('fe_users');
        $row = $queryBuilder->select('*')
            ->from('fe_users')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter(1)))
            ->executeQuery()
            ->fetchAssociative();
        self::assertIsArray($row);
        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function getBeUser(): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('be_users');
        $row = $queryBuilder->select('*')
            ->from('be_users')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter(1)))
            ->executeQuery()
            ->fetchAssociative();
        self::assertIsArray($row);
        return $row;
    }

    #[Test]
    public function clearsSamlFieldsOnNormalFrontendLogin(): void
    {
        $user = $this->buildUser(FrontendUserAuthentication::class, 'FE', 1);
        $this->invokeListener(new AfterUserLoggedInEvent($user, $this->buildRequest()));

        $row = $this->getFeUser();
        self::assertSame(0, (int)$row['md_saml_source']);
        self::assertSame('', $row['md_saml_nameid']);
        self::assertSame('', $row['md_saml_nameid_format']);
        self::assertSame('', $row['md_saml_session_index']);
    }

    #[Test]
    public function clearsSamlFieldsOnNormalBackendLogin(): void
    {
        $user = $this->buildUser(BackendUserAuthentication::class, 'BE', 1);
        $this->invokeListener(new AfterUserLoggedInEvent($user, $this->buildRequest()));

        $row = $this->getBeUser();
        self::assertSame(0, (int)$row['md_saml_source']);
        self::assertSame('', $row['md_saml_nameid']);
    }

    #[Test]
    public function doesNotClearSamlFieldsWhenLoginWasViaSaml(): void
    {
        $user = $this->buildUser(FrontendUserAuthentication::class, 'FE', 1);
        $request = $this->buildRequest(['loginProvider' => (string)SamlAuthService::SAML_LOGIN_PROVIDER_ID]);
        $this->invokeListener(new AfterUserLoggedInEvent($user, $request));

        $row = $this->getFeUser();
        self::assertSame(1, (int)$row['md_saml_source']);
        self::assertSame('stale-fe-nameid', $row['md_saml_nameid']);
    }

    #[Test]
    public function clearsSamlFieldsWhenNoRequestIsAvailable(): void
    {
        // getRequest() can return null; loginProvider is then treated as absent,
        // i.e. as a non-SAML login, same as any other missing/mismatched value.
        $user = $this->buildUser(FrontendUserAuthentication::class, 'FE', 1);
        $this->invokeListener(new AfterUserLoggedInEvent($user, null));

        $row = $this->getFeUser();
        self::assertSame(0, (int)$row['md_saml_source']);
    }

    #[Test]
    public function doesNothingWhenUserIdIsZero(): void
    {
        $user = $this->buildUser(FrontendUserAuthentication::class, 'FE', 0);
        $this->invokeListener(new AfterUserLoggedInEvent($user, $this->buildRequest()));

        $row = $this->getFeUser();
        self::assertSame(1, (int)$row['md_saml_source']);
    }
}
