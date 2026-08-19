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

use Mediadreams\MdSaml\Authentication\SamlAuthService;
use Mediadreams\MdSaml\Middleware\SamlMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Covers the access control for the SP metadata endpoint: authenticated BE user
 * OR publicMetadata=true is required, everything else must pass through unchanged.
 */
#[CoversClass(SamlMiddleware::class)]
final class SamlMiddlewareTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['mediadreams/md_saml'];

    protected array $pathsToLinkInTestInstance = [
        // Linking the whole Fixtures/Sites folder as typo3conf/sites (rather than
        // one entry per site) matters: FunctionalTestCase::tearDown() deletes
        // typo3conf/sites after every test unless that exact path string appears
        // in pathsToLinkInTestInstance — per-site entries don't match that check.
        'typo3conf/ext/md_saml/Tests/Functional/Middleware/Fixtures/Sites' => 'typo3conf/sites',
    ];

    /**
     * @var array<string, mixed>
     */
    private array $serverBackup;

    protected function setUp(): void
    {
        parent::setUp();

        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;

        parent::tearDown();
    }

    /**
     * @param array<string, string> $queryParams
     */
    private function buildRequest(string $host, array $queryParams): ServerRequestInterface
    {
        $queryString = http_build_query($queryParams);

        // SettingsService resolves the current site via GeneralUtility::getIndpEnv('TYPO3_REQUEST_URL'),
        // which reads from $_SERVER, not from the PSR-7 request below — both must be kept in sync.
        // getIndpEnv() memoizes its result per key for the whole process, so it must be flushed
        // whenever $_SERVER changes between tests, or it keeps returning the first test's URL.
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = $host;
        $_SERVER['SERVER_PORT'] = '443';
        $_SERVER['REQUEST_URI'] = '/typo3/index.php?' . $queryString;
        GeneralUtility::flushInternalRuntimeCaches();

        $uri = 'https://' . $host . '/typo3/index.php?' . $queryString;
        return (new ServerRequest($uri, 'GET'))->withQueryParams($queryParams);
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

    /**
     * @return array<string, string>
     */
    private function metadataQueryParams(): array
    {
        return [
            'loginProvider' => (string)SamlAuthService::SAML_LOGIN_PROVIDER_ID,
            'mdsamlmetadata' => '1',
            'loginType' => 'backend',
        ];
    }

    #[Test]
    public function passesThroughWhenNotAMetadataRequest(): void
    {
        $request = $this->buildRequest('typo3-testing.local', ['foo' => 'bar']);

        $subject = GeneralUtility::makeInstance(SamlMiddleware::class);
        $response = $subject->process($request, $this->nextHandler());

        self::assertSame('NEXT-HANDLER-CALLED', (string)$response->getBody());
    }

    #[Test]
    public function passesThroughWhenLoginProviderDoesNotMatch(): void
    {
        $queryParams = $this->metadataQueryParams();
        $queryParams['loginProvider'] = '999999';
        $request = $this->buildRequest('typo3-testing.local', $queryParams);

        $subject = GeneralUtility::makeInstance(SamlMiddleware::class);
        $response = $subject->process($request, $this->nextHandler());

        self::assertSame('NEXT-HANDLER-CALLED', (string)$response->getBody());
    }

    #[Test]
    public function promptsForLoginWhenNoBackendUserIsAuthenticatedAndMetadataIsNotPublic(): void
    {
        $request = $this->buildRequest('typo3-testing.local', $this->metadataQueryParams());

        $subject = GeneralUtility::makeInstance(SamlMiddleware::class);
        $response = $subject->process($request, $this->nextHandler());

        self::assertSame('Please log into TYPO3!', (string)$response->getBody());
    }

    #[Test]
    public function returnsMetadataXmlWhenBackendUserIsAuthenticated(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/be_users.csv');
        $this->setUpBackendUser(1);

        $request = $this->buildRequest('typo3-testing.local', $this->metadataQueryParams());

        $subject = GeneralUtility::makeInstance(SamlMiddleware::class);
        $response = $subject->process($request, $this->nextHandler());

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('<?xml', (string)$response->getBody());
        self::assertStringContainsString('text/xml', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function returnsMetadataXmlWithoutBackendUserWhenPublicMetadataIsEnabled(): void
    {
        $request = $this->buildRequest('typo3-testing-public.local', $this->metadataQueryParams());

        $subject = GeneralUtility::makeInstance(SamlMiddleware::class);
        $response = $subject->process($request, $this->nextHandler());

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('<?xml', (string)$response->getBody());
    }
}
