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
use Mediadreams\MdSaml\Service\SettingsService;
use Mediadreams\MdSaml\Tests\Fixtures\Saml\PostBindingResponseSigner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\AbstractUserAuthentication;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Full ACS roundtrip: a signed SAMLResponse (HTTP-POST binding) is validated by
 * onelogin/php-saml and turned into a fe_users/be_users record by
 * SamlAuthService::getUser() -> processAcsResponse().
 */
#[CoversClass(SamlAuthService::class)]
final class AcsResponseTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['mediadreams/md_saml'];

    protected array $pathsToLinkInTestInstance = [
        // See Tests/Functional/Middleware/SamlMiddlewareTest.php for why the
        // whole Fixtures/AcsSites folder must be linked as one entry.
        'typo3conf/ext/md_saml/Tests/Functional/Authentication/Fixtures/AcsSites' => 'typo3conf/sites',
    ];

    private const HOST = 'typo3-acs-test.local';

    private const ISSUER = 'https://idp.example.com/entity';

    private const FE_PATH = '/index.php?loginProvider=1648123062&acs';

    private const FE_DESTINATION = 'https://typo3-acs-test.local/index.php?loginProvider=1648123062&acs';

    private const FE_AUDIENCE = 'https://typo3-acs-test.local/base-entity';

    private const BE_PATH = '/typo3/index.php?loginProvider=1648123062&acs';

    private const BE_DESTINATION = 'https://typo3-acs-test.local/typo3/index.php?loginProvider=1648123062&acs';

    private const BE_AUDIENCE = 'https://typo3-acs-test.local/typo3/index.php?loginProvider=1648123062&mdsamlmetadata';

    /**
     * @var array<string, mixed>
     */
    private array $serverBackup;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/Fixtures/fe_users_saml_acs.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/be_users_saml_acs.csv');
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;

        parent::tearDown();
    }

    /**
     * @param array<string, string> $attributes
     */
    private function buildSignedResponse(
        string $destination,
        string $audience,
        string $nameId,
        array $attributes
    ): string {
        return PostBindingResponseSigner::signedResponse(
            $destination,
            self::ISSUER,
            $audience,
            $nameId,
            'session-index-acs',
            $attributes
        );
    }

    private function setAcsRequestGlobals(string $path, string $responseB64): void
    {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = self::HOST;
        $_SERVER['SERVER_PORT'] = '443';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['REQUEST_URI'] = $path;
        $_SERVER['QUERY_STRING'] = ltrim((string)strstr($path, '?'), '?');
        $_GET = ['loginProvider' => '1648123062', 'acs' => ''];
        $_REQUEST = $_GET;
        $_POST = ['SAMLResponse' => $responseB64];
        GeneralUtility::flushInternalRuntimeCaches();
    }

    private function buildPObj(string $loginType): AbstractUserAuthentication
    {
        $class = $loginType === 'BE' ? BackendUserAuthentication::class : FrontendUserAuthentication::class;
        $pObj = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        $pObj->loginType = $loginType;
        return $pObj;
    }

    private function callGetUser(string $loginType, string $table, string $path, string $responseB64): false|array
    {
        $this->setAcsRequestGlobals($path, $responseB64);

        $subject = GeneralUtility::makeInstance(SamlAuthService::class);
        $subject->db_user = [
            'table' => $table,
            'username_column' => 'username',
            'enable_clause' => '',
        ];
        $subject->authInfo = [
            'loginType' => $loginType,
            'db_user' => ['table' => $table],
            'REMOTE_ADDR' => '127.0.0.1',
            'REMOTE_HOST' => '',
        ];
        $subject->pObj = $this->buildPObj($loginType);

        $subjectReflection = new \ReflectionClass(SamlAuthService::class);
        $subjectReflection->getProperty('useAuthService')->setValue($subject, true);

        $settings = GeneralUtility::makeInstance(SettingsService::class)->getSettings($loginType);
        $subjectReflection->getProperty('extSettings')->setValue($subject, $settings);

        return $subject->getUser();
    }

    #[Test]
    public function createsNewFeUserFromSignedAcsResponse(): void
    {
        $responseB64 = $this->buildSignedResponse(
            self::FE_DESTINATION,
            self::FE_AUDIENCE,
            'new-acs-nameid',
            ['mail' => 'new-acs-user']
        );
        $result = $this->callGetUser('FE', 'fe_users', self::FE_PATH, $responseB64);

        self::assertIsArray($result);
        self::assertSame('new-acs-user', $result['username']);
        self::assertSame(1, (int)$result['md_saml_source']);
        self::assertSame('new-acs-nameid', $result['md_saml_nameid']);
        self::assertSame('session-index-acs', $result['md_saml_session_index']);
    }

    #[Test]
    public function updatesExistingFeUserFromSignedAcsResponse(): void
    {
        $responseB64 = $this->buildSignedResponse(
            self::FE_DESTINATION,
            self::FE_AUDIENCE,
            'existing-acs-nameid',
            ['mail' => 'existing-acs-user']
        );
        $result = $this->callGetUser('FE', 'fe_users', self::FE_PATH, $responseB64);

        self::assertIsArray($result);
        self::assertSame(1, (int)$result['uid']);
        self::assertSame('existing-acs-user', $result['username']);
        self::assertSame('existing-acs-nameid', $result['md_saml_nameid']);
    }

    #[Test]
    public function createsNewBeUserFromSignedAcsResponse(): void
    {
        $responseB64 = $this->buildSignedResponse(
            self::BE_DESTINATION,
            self::BE_AUDIENCE,
            'new-be-acs-nameid',
            ['mail' => 'new-be-acs-user']
        );
        $result = $this->callGetUser('BE', 'be_users', self::BE_PATH, $responseB64);

        self::assertIsArray($result);
        self::assertSame('new-be-acs-user', $result['username']);
        self::assertSame(1, (int)$result['md_saml_source']);
        self::assertSame('new-be-acs-nameid', $result['md_saml_nameid']);
        // The 'admin' attribute is never in the response here, but this locks in that
        // a BE user created via SAML is never implicitly made an admin.
        self::assertSame(0, (int)$result['admin']);
    }

    #[Test]
    public function updatesExistingBeUserFromSignedAcsResponse(): void
    {
        $responseB64 = $this->buildSignedResponse(
            self::BE_DESTINATION,
            self::BE_AUDIENCE,
            'existing-be-acs-nameid',
            ['mail' => 'existing-be-acs-user']
        );
        $result = $this->callGetUser('BE', 'be_users', self::BE_PATH, $responseB64);

        self::assertIsArray($result);
        self::assertSame(1, (int)$result['uid']);
        self::assertSame('existing-be-acs-user', $result['username']);
        self::assertSame('existing-be-acs-nameid', $result['md_saml_nameid']);
    }

    #[Test]
    public function rejectsResponseWithTamperedSignature(): void
    {
        $responseB64 = $this->buildSignedResponse(
            self::FE_DESTINATION,
            self::FE_AUDIENCE,
            'tampered-nameid',
            ['mail' => 'tampered-user']
        );
        // Flip the attribute value after signing, invalidating the assertion's digest.
        $decoded = base64_decode($responseB64, true);
        self::assertIsString($decoded);
        $tampered = str_replace('tampered-user', 'attacker-user', $decoded);

        $result = $this->callGetUser('FE', 'fe_users', self::FE_PATH, base64_encode($tampered));

        self::assertFalse($result);

        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('fe_users');
        $count = $queryBuilder->count('uid')
            ->from('fe_users')
            ->where($queryBuilder->expr()->eq(
                'username',
                $queryBuilder->createNamedParameter('tampered-user')
            ))
            ->executeQuery()
            ->fetchOne();
        self::assertSame(0, (int)$count);
    }
}
