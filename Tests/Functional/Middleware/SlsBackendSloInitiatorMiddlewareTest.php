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

namespace Mediadreams\MdSaml\Tests\Functional\Middleware;

use Mediadreams\MdSaml\Middleware\SlsBackendSamlMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * SP-initiated BE logout INITIATION: intercepts the /typo3/logout route for a
 * SAML-authenticated backend user and redirects to the IdP's SLO endpoint — the
 * flow a user actually triggers by clicking "logout" in the backend, as opposed
 * to the IdP-initiated / callback paths already covered in SlsBackendSamlMiddlewareTest.
 */
#[CoversClass(SlsBackendSamlMiddleware::class)]
final class SlsBackendSloInitiatorMiddlewareTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['mediadreams/md_saml'];

    protected array $pathsToLinkInTestInstance = [
        // Reuses the fixture site from SlsFrontendSloInitiatorMiddlewareTest: it
        // already configures be_users SAML settings and idp.singleLogoutService.
        // See Tests/Functional/Middleware/SamlMiddlewareTest.php for why the whole
        // Fixtures/SloInitiatorSites folder must be linked as one entry.
        'typo3conf/ext/md_saml/Tests/Functional/Middleware/Fixtures/SloInitiatorSites' => 'typo3conf/sites',
    ];

    private const HOST = 'typo3-slo-init-test.local';

    private const USER_ID = 1;

    /**
     * @var array<string, mixed>
     */
    private array $serverBackup;

    protected function setUp(): void
    {
        parent::setUp();

        $this->serverBackup = $_SERVER;
        $this->importCSVDataSet(__DIR__ . '/Fixtures/be_users_slo_init.csv');
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;

        parent::tearDown();
    }

    /**
     * SettingsService resolves the current site via GeneralUtility::getIndpEnv('TYPO3_REQUEST_URL'),
     * which reads from $_SERVER and memoizes per process — flushed here whenever $_SERVER changes.
     */
    private function buildLogoutRequest(): ServerRequestInterface
    {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = self::HOST;
        $_SERVER['SERVER_PORT'] = '443';
        $_SERVER['SCRIPT_NAME'] = '/typo3/index.php';
        $_SERVER['REQUEST_URI'] = '/typo3/logout';
        $_SERVER['QUERY_STRING'] = '';
        GeneralUtility::flushInternalRuntimeCaches();

        return (new ServerRequest('https://' . self::HOST . '/typo3/logout', 'GET'))
            ->withAttribute('route', new Route('/logout', []));
    }

    private function nextHandler(): RequestHandlerInterface
    {
        return new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $response = new Response();
                $response->getBody()->write('NEXT-HANDLER-CALLED');
                return $response;
            }
        };
    }

    private function beSessionExists(): bool
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('be_sessions');
        $userId = $queryBuilder->createNamedParameter(self::USER_ID, Connection::PARAM_INT);
        $count = $queryBuilder->count('ses_id')
            ->from('be_sessions')
            ->where($queryBuilder->expr()->eq('ses_userid', $userId))
            ->executeQuery()
            ->fetchOne();

        return (int)$count > 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function getBeUser(): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('be_users');
        $userId = $queryBuilder->createNamedParameter(self::USER_ID, Connection::PARAM_INT);
        $row = $queryBuilder->select('*')
            ->from('be_users')
            ->where($queryBuilder->expr()->eq('uid', $userId))
            ->executeQuery()
            ->fetchAssociative();
        self::assertIsArray($row);
        return $row;
    }

    #[Test]
    public function redirectsToIdpSloUrlAndTerminatesTheLocalBackendSession(): void
    {
        $this->setUpBackendUser(self::USER_ID);
        self::assertTrue($this->beSessionExists());

        $request = $this->buildLogoutRequest();

        $subject = GeneralUtility::makeInstance(SlsBackendSamlMiddleware::class);
        $response = $subject->process($request, $this->nextHandler());

        self::assertSame(303, $response->getStatusCode());
        self::assertStringStartsWith('https://idp.example.com/slo', $response->getHeaderLine('Location'));

        $setCookies = $response->getHeader('Set-Cookie');
        self::assertNotEmpty(
            array_filter($setCookies, static fn(string $c): bool => str_starts_with($c, 'md_saml_slo_context=BE'))
        );

        self::assertFalse($this->beSessionExists());
        $user = $this->getBeUser();
        self::assertSame(0, (int)$user['md_saml_source']);
        self::assertSame('', $user['md_saml_nameid']);
    }

    #[Test]
    public function passesThroughWhenRouteIsNotLogout(): void
    {
        $this->setUpBackendUser(self::USER_ID);

        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = self::HOST;
        $_SERVER['SERVER_PORT'] = '443';
        $_SERVER['SCRIPT_NAME'] = '/typo3/index.php';
        $_SERVER['REQUEST_URI'] = '/typo3/index.php';
        $_SERVER['QUERY_STRING'] = '';
        GeneralUtility::flushInternalRuntimeCaches();

        $request = (new ServerRequest('https://' . self::HOST . '/typo3/index.php', 'GET'))
            ->withAttribute('route', new Route('/module/some-module', []));

        $subject = GeneralUtility::makeInstance(SlsBackendSamlMiddleware::class);
        $response = $subject->process($request, $this->nextHandler());

        self::assertSame('NEXT-HANDLER-CALLED', (string)$response->getBody());
        self::assertTrue($this->beSessionExists());
    }

    #[Test]
    public function passesThroughWhenBackendUserIsNotSamlAuthenticated(): void
    {
        $this->getConnectionPool()->getConnectionForTable('be_users')->update(
            'be_users',
            ['md_saml_source' => 0],
            ['uid' => self::USER_ID]
        );
        $this->setUpBackendUser(self::USER_ID);

        $request = $this->buildLogoutRequest();

        $subject = GeneralUtility::makeInstance(SlsBackendSamlMiddleware::class);
        $response = $subject->process($request, $this->nextHandler());

        self::assertSame('NEXT-HANDLER-CALLED', (string)$response->getBody());
        self::assertTrue($this->beSessionExists());
    }

    #[Test]
    public function passesThroughWhenBackendSamlLoginIsNotActivated(): void
    {
        $originalConfig = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['md_saml'] ?? [];
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['md_saml']['activateBackendLogin'] = '0';

        try {
            $this->setUpBackendUser(self::USER_ID);

            $request = $this->buildLogoutRequest();

            $subject = GeneralUtility::makeInstance(SlsBackendSamlMiddleware::class);
            $response = $subject->process($request, $this->nextHandler());

            self::assertSame('NEXT-HANDLER-CALLED', (string)$response->getBody());
            self::assertTrue($this->beSessionExists());
        } finally {
            $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['md_saml'] = $originalConfig;
        }
    }
}
