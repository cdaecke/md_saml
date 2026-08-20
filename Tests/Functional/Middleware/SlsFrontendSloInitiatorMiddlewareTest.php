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

use Mediadreams\MdSaml\Middleware\SlsFrontendSloInitiatorMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Http\CookieScope;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Session\UserSessionManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * SP-initiated FE logout INITIATION: intercepts logintype=logout for a live,
 * SAML-authenticated FE session and redirects to the IdP's SLO endpoint — the
 * flow a user actually triggers by clicking "logout", as opposed to the
 * IdP-initiated NameID-fallback path covered in SlsFrontendSamlMiddlewareTest.
 */
#[CoversClass(SlsFrontendSloInitiatorMiddleware::class)]
final class SlsFrontendSloInitiatorMiddlewareTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['mediadreams/md_saml'];

    protected array $pathsToLinkInTestInstance = [
        // See Tests/Functional/Middleware/SamlMiddlewareTest.php for why the
        // whole Fixtures/SloInitiatorSites folder must be linked as one entry.
        'typo3conf/ext/md_saml/Tests/Functional/Middleware/Fixtures/SloInitiatorSites' => 'typo3conf/sites',
    ];

    private const HOST = 'typo3-slo-init-test.local';

    private const USER_ID = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/Fixtures/fe_users_slo_init.csv');
    }

    /**
     * Fixates a real, persisted FE session for the fixture user via the same
     * UserSessionManager API TYPO3 itself uses, and returns the cookie value
     * (JWT) that resolves back to it — hand-crafting the fe_sessions row
     * directly would require replicating internal ses_iplock/JWT-signing
     * details that are not part of any public contract.
     */
    private function fixateFeSession(string $host = self::HOST): string
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $sessionManager = UserSessionManager::create('FE');
        $anonymousSession = $sessionManager->createAnonymousSession();
        $session = $sessionManager->elevateToFixatedUserSession($anonymousSession, self::USER_ID);

        // Matches CookieScopeTrait::getCookieScope() with no cookieDomain configured
        // (the default): host-only domain, root site path.
        return $session->getJwt(new CookieScope($host, true, '/'));
    }

    /**
     * Mirrors typo3/testing-framework's private FunctionalTestCase::createServerRequest():
     * NormalizedParams::createFromRequest() (used internally by
     * UserSessionManager::createFromRequestOrAnonymous() to compute the cookie scope)
     * reads from the PSR-7 request's own server-params snapshot, not the global
     * $_SERVER — so the normalizedParams attribute must be attached explicitly here.
     *
     * SettingsService::getSamlConfig() separately resolves the current site via
     * GeneralUtility::getIndpEnv('TYPO3_REQUEST_URL'), which reads the global
     * $_SERVER — so that must be kept in sync too, with a cache flush since
     * getIndpEnv() memoizes per process.
     */
    private function createServerRequest(string $url): ServerRequestInterface
    {
        $urlParts = parse_url($url);
        $requestUri = ($urlParts['path'] ?? '/') . (isset($urlParts['query']) ? '?' . $urlParts['query'] : '');
        $serverParams = [
            'DOCUMENT_ROOT' => $this->instancePath,
            'HTTP_USER_AGENT' => 'TYPO3 Functional Test Request',
            'HTTP_HOST' => $urlParts['host'] ?? self::HOST,
            'SERVER_NAME' => $urlParts['host'] ?? self::HOST,
            'SERVER_ADDR' => '127.0.0.1',
            'REMOTE_ADDR' => '127.0.0.1',
            'SCRIPT_NAME' => '/index.php',
            'PHP_SELF' => '/index.php',
            'SCRIPT_FILENAME' => $this->instancePath . '/index.php',
            'PATH_TRANSLATED' => $this->instancePath . '/index.php',
            'QUERY_STRING' => $urlParts['query'] ?? '',
            'REQUEST_URI' => $requestUri,
            'REQUEST_METHOD' => 'GET',
            'HTTPS' => 'on',
            'SERVER_PORT' => '443',
        ];

        $_SERVER = array_merge($_SERVER, $serverParams);
        GeneralUtility::flushInternalRuntimeCaches();

        $request = new ServerRequest($url, 'GET', null, [], $serverParams);
        return $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
    }

    private function buildLogoutRequest(
        string $sessionCookie,
        string $referer = '',
        string $host = self::HOST
    ): ServerRequestInterface {
        $request = $this->createServerRequest('https://' . $host . '/index.php?logintype=logout')
            ->withQueryParams(['logintype' => 'logout'])
            ->withCookieParams(['fe_typo_user' => $sessionCookie]);

        return $referer !== '' ? $request->withHeader('Referer', $referer) : $request;
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

    private function feSessionExists(): bool
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('fe_sessions');
        $userId = $queryBuilder->createNamedParameter(self::USER_ID, Connection::PARAM_INT);
        $count = $queryBuilder->count('ses_id')
            ->from('fe_sessions')
            ->where($queryBuilder->expr()->eq('ses_userid', $userId))
            ->executeQuery()
            ->fetchOne();

        return (int)$count > 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function getFeUser(): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('fe_users');
        $userId = $queryBuilder->createNamedParameter(self::USER_ID, Connection::PARAM_INT);
        $row = $queryBuilder->select('*')
            ->from('fe_users')
            ->where($queryBuilder->expr()->eq('uid', $userId))
            ->executeQuery()
            ->fetchAssociative();
        self::assertIsArray($row);
        return $row;
    }

    #[Test]
    public function redirectsToIdpSloUrlAndTerminatesTheLocalSession(): void
    {
        $sessionCookie = $this->fixateFeSession();
        self::assertTrue($this->feSessionExists());

        $request = $this->buildLogoutRequest($sessionCookie, 'https://' . self::HOST . '/some/page');

        $subject = GeneralUtility::makeInstance(SlsFrontendSloInitiatorMiddleware::class);
        $response = $subject->process($request, $this->nextHandler());

        self::assertSame(303, $response->getStatusCode());
        self::assertStringStartsWith('https://idp.example.com/slo', $response->getHeaderLine('Location'));

        $setCookies = $response->getHeader('Set-Cookie');
        self::assertNotEmpty(
            array_filter($setCookies, static fn(string $c): bool => str_starts_with($c, 'md_saml_slo_context=FE'))
        );
        $expectedRedirectCookie = 'md_saml_slo_redirect=' . urlencode('https://' . self::HOST . '/some/page');
        self::assertNotEmpty(array_filter(
            $setCookies,
            static fn(string $c): bool => str_starts_with($c, $expectedRedirectCookie)
        ));

        self::assertFalse($this->feSessionExists());
        $user = $this->getFeUser();
        self::assertSame(0, (int)$user['md_saml_source']);
        self::assertSame('', $user['md_saml_nameid']);
    }

    #[Test]
    public function fallsBackToRootPathWhenRefererIsNotSameOrigin(): void
    {
        $sessionCookie = $this->fixateFeSession();
        $request = $this->buildLogoutRequest($sessionCookie, 'https://evil.example.com/phish');

        $subject = GeneralUtility::makeInstance(SlsFrontendSloInitiatorMiddleware::class);
        $response = $subject->process($request, $this->nextHandler());

        self::assertSame(303, $response->getStatusCode());
        $setCookies = $response->getHeader('Set-Cookie');
        self::assertNotEmpty(array_filter(
            $setCookies,
            static fn(string $c): bool => str_starts_with($c, 'md_saml_slo_redirect=' . urlencode('/'))
        ));
    }

    #[Test]
    public function passesThroughWhenLogoutIsNotTriggered(): void
    {
        $request = $this->createServerRequest('https://' . self::HOST . '/index.php')->withQueryParams([]);

        $subject = GeneralUtility::makeInstance(SlsFrontendSloInitiatorMiddleware::class);
        $response = $subject->process($request, $this->nextHandler());

        self::assertSame('NEXT-HANDLER-CALLED', (string)$response->getBody());
    }

    #[Test]
    public function passesThroughWhenNoLiveSamlSessionExists(): void
    {
        // logintype=logout but no session cookie at all: resolveSamlFrontendSession()
        // resolves to an anonymous session, so there is nothing to log out via SAML.
        $request = $this->buildLogoutRequest('');

        $subject = GeneralUtility::makeInstance(SlsFrontendSloInitiatorMiddleware::class);
        $response = $subject->process($request, $this->nextHandler());

        self::assertSame('NEXT-HANDLER-CALLED', (string)$response->getBody());
    }

    /**
     * IdPs without an SLO endpoint (e.g. Google Workspace): SettingsService strips
     * idp/sp singleLogoutService from the resolved settings, and this middleware
     * must pass through so felogin performs a normal local logout, instead of
     * attempting a SAML round-trip against a URL that does not exist.
     */
    #[Test]
    public function passesThroughWhenIdpHasNoSingleLogoutServiceConfigured(): void
    {
        $host = 'typo3-slo-init-no-slo-test.local';
        $sessionCookie = $this->fixateFeSession($host);
        self::assertTrue($this->feSessionExists());

        $request = $this->buildLogoutRequest($sessionCookie, host: $host);

        $subject = GeneralUtility::makeInstance(SlsFrontendSloInitiatorMiddleware::class);
        $response = $subject->process($request, $this->nextHandler());

        self::assertSame('NEXT-HANDLER-CALLED', (string)$response->getBody());
        self::assertTrue($this->feSessionExists());
    }
}
