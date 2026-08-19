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
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Full ACS roundtrip: a signed SAMLResponse (HTTP-POST binding) is validated by
 * onelogin/php-saml and turned into a fe_users record by
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

    private const DESTINATION = 'https://typo3-acs-test.local/index.php?loginProvider=1648123062&acs';

    private const ISSUER = 'https://idp.example.com/entity';

    private const AUDIENCE = 'https://typo3-acs-test.local/base-entity';

    /**
     * @var array<string, mixed>
     */
    private array $serverBackup;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/Fixtures/fe_users_saml_acs.csv');
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
    private function callGetUser(string $nameId, array $attributes): false|array
    {
        $responseB64 = PostBindingResponseSigner::signedResponse(
            self::DESTINATION,
            self::ISSUER,
            self::AUDIENCE,
            $nameId,
            'session-index-acs',
            $attributes
        );

        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = self::HOST;
        $_SERVER['SERVER_PORT'] = '443';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['REQUEST_URI'] = '/index.php?loginProvider=1648123062&acs';
        $_SERVER['QUERY_STRING'] = 'loginProvider=1648123062&acs';
        $_GET = ['loginProvider' => '1648123062', 'acs' => ''];
        $_REQUEST = $_GET;
        $_POST = ['SAMLResponse' => $responseB64];
        GeneralUtility::flushInternalRuntimeCaches();

        $subject = GeneralUtility::makeInstance(SamlAuthService::class);
        $subject->db_user = [
            'table' => 'fe_users',
            'username_column' => 'username',
            'enable_clause' => '',
        ];
        $subject->authInfo = [
            'loginType' => 'FE',
            'db_user' => ['table' => 'fe_users'],
            'REMOTE_ADDR' => '127.0.0.1',
            'REMOTE_HOST' => '',
        ];

        $user = (new \ReflectionClass(FrontendUserAuthentication::class))->newInstanceWithoutConstructor();
        $user->loginType = 'FE';

        $subject->pObj = $user;

        $subjectReflection = new \ReflectionClass(SamlAuthService::class);

        $useAuthServiceProperty = $subjectReflection->getProperty('useAuthService');
        $useAuthServiceProperty->setValue($subject, true);

        $settings = GeneralUtility::makeInstance(SettingsService::class)->getSettings('FE');
        $extSettingsProperty = $subjectReflection->getProperty('extSettings');
        $extSettingsProperty->setValue($subject, $settings);

        return $subject->getUser();
    }

    #[Test]
    public function createsNewFeUserFromSignedAcsResponse(): void
    {
        $result = $this->callGetUser('new-acs-nameid', ['mail' => 'new-acs-user']);

        self::assertIsArray($result);
        self::assertSame('new-acs-user', $result['username']);
        self::assertSame(1, (int)$result['md_saml_source']);
        self::assertSame('new-acs-nameid', $result['md_saml_nameid']);
        self::assertSame('session-index-acs', $result['md_saml_session_index']);
    }

    #[Test]
    public function updatesExistingFeUserFromSignedAcsResponse(): void
    {
        $result = $this->callGetUser('existing-acs-nameid', ['mail' => 'existing-acs-user']);

        self::assertIsArray($result);
        self::assertSame(1, (int)$result['uid']);
        self::assertSame('existing-acs-user', $result['username']);
        self::assertSame('existing-acs-nameid', $result['md_saml_nameid']);
    }

    #[Test]
    public function rejectsResponseWithTamperedSignature(): void
    {
        $responseB64 = PostBindingResponseSigner::signedResponse(
            self::DESTINATION,
            self::ISSUER,
            self::AUDIENCE,
            'tampered-nameid',
            'session-index-acs',
            ['mail' => 'tampered-user']
        );
        // Flip the attribute value after signing, invalidating the assertion's digest.
        $decoded = base64_decode($responseB64, true);
        self::assertIsString($decoded);
        $tampered = str_replace('tampered-user', 'attacker-user', $decoded);

        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = self::HOST;
        $_SERVER['SERVER_PORT'] = '443';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['REQUEST_URI'] = '/index.php?loginProvider=1648123062&acs';
        $_SERVER['QUERY_STRING'] = 'loginProvider=1648123062&acs';
        $_GET = ['loginProvider' => '1648123062', 'acs' => ''];
        $_REQUEST = $_GET;
        $_POST = ['SAMLResponse' => base64_encode($tampered)];
        GeneralUtility::flushInternalRuntimeCaches();

        $subject = GeneralUtility::makeInstance(SamlAuthService::class);
        $subject->db_user = [
            'table' => 'fe_users',
            'username_column' => 'username',
            'enable_clause' => '',
        ];
        $subject->authInfo = [
            'loginType' => 'FE',
            'db_user' => ['table' => 'fe_users'],
            'REMOTE_ADDR' => '127.0.0.1',
            'REMOTE_HOST' => '',
        ];

        $user = (new \ReflectionClass(FrontendUserAuthentication::class))->newInstanceWithoutConstructor();
        $user->loginType = 'FE';

        $subject->pObj = $user;

        $subjectReflection = new \ReflectionClass(SamlAuthService::class);
        $subjectReflection->getProperty('useAuthService')->setValue($subject, true);
        $settings = GeneralUtility::makeInstance(SettingsService::class)->getSettings('FE');
        $subjectReflection->getProperty('extSettings')->setValue($subject, $settings);

        self::assertFalse($subject->getUser());

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
