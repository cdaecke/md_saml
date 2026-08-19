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

use Mediadreams\MdSaml\Middleware\SlsFrontendSamlMiddleware;
use Mediadreams\MdSaml\Tests\Fixtures\Saml\RedirectBindingLogoutResponseSigner;
use OneLogin\Saml2\Constants;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Http\CookieScope;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Session\UserSessionManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * SP-initiated FE logout CALLBACK: the IdP's reply to the LogoutRequest built by
 * SlsFrontendSloInitiatorMiddleware, identified by the md_saml_slo_context=FE
 * cookie. Covers SlsFrontendSamlMiddleware::handleFeSloCallback() and the "live
 * session" branch of performLogoff() (as opposed to the NameID-fallback branch
 * already covered in SlsFrontendSamlMiddlewareTest, which applies when no live
 * session cookie is present at all).
 */
#[CoversClass(SlsFrontendSamlMiddleware::class)]
final class SlsFrontendSloCallbackTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['mediadreams/md_saml'];

    protected array $pathsToLinkInTestInstance = [
        // See Tests/Functional/Middleware/SamlMiddlewareTest.php for why the
        // whole Fixtures/FeSloCallbackSites folder must be linked as one entry.
        'typo3conf/ext/md_saml/Tests/Functional/Middleware/Fixtures/FeSloCallbackSites' => 'typo3conf/sites',
    ];

    private const HOST = 'typo3-fe-slo-callback-test.local';

    private const DESTINATION = 'https://typo3-fe-slo-callback-test.local/index.php';

    private const ISSUER = 'https://idp.example.com/entity';

    private const USER_ID = 1;

    /**
     * @var array<string, mixed>
     */
    private array $serverBackup;

    protected function setUp(): void
    {
        parent::setUp();

        $this->serverBackup = $_SERVER;
        $this->importCSVDataSet(__DIR__ . '/Fixtures/fe_users_slo_callback.csv');
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_GET = [];

        parent::tearDown();
    }

    /**
     * Mirrors typo3/testing-framework's private FunctionalTestCase::createServerRequest().
     * SettingsService::getSamlConfig() and onelogin's Utils::getSelf*() helpers read the
     * global $_SERVER, while NormalizedParams::createFromRequest() reads the PSR-7
     * request's own server-params snapshot — both are kept in sync here.
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

    /**
     * Fixates a real, persisted FE session for the fixture user and boots a
     * FrontendUserAuthentication from a request carrying that session's cookie —
     * the same mechanism FrontendUserAuthenticator uses to resolve the "live"
     * frontend.user that performLogoff()'s live-session branch depends on.
     */
    private function fixateLiveFeUser(): FrontendUserAuthentication
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $sessionManager = UserSessionManager::create('FE');
        $anonymousSession = $sessionManager->createAnonymousSession();
        $session = $sessionManager->elevateToFixatedUserSession($anonymousSession, self::USER_ID);
        $sessionCookie = $session->getJwt(new CookieScope(self::HOST, true, '/'));

        $bootstrapRequest = $this->createServerRequest('https://' . self::HOST . '/index.php')
            ->withCookieParams(['fe_typo_user' => $sessionCookie]);

        $feUser = GeneralUtility::makeInstance(FrontendUserAuthentication::class);
        $feUser->start($bootstrapRequest);

        return $feUser;
    }

    /**
     * @param array{SAMLResponse: string, SigAlg: string, Signature: string, queryString: string} $signed
     * @return array<string, string>
     */
    private function samlQueryParams(array $signed): array
    {
        return [
            'SAMLResponse' => $signed['SAMLResponse'],
            'SigAlg' => $signed['SigAlg'],
            'Signature' => $signed['Signature'],
        ];
    }

    /**
     * @param array<string, string> $samlQueryParams
     */
    private function buildSloCallbackRequest(
        FrontendUserAuthentication $feUser,
        array $samlQueryParams,
        string $redirectCookieValue
    ): ServerRequestInterface {
        $queryParams = array_merge(['sls' => '1'], $samlQueryParams);
        $queryString = http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);

        $request = $this->createServerRequest(self::DESTINATION . '?' . $queryString)
            ->withQueryParams($queryParams)
            ->withCookieParams([
                'md_saml_slo_context' => 'FE',
                'md_saml_slo_redirect' => $redirectCookieValue,
            ])
            ->withAttribute('frontend.user', $feUser);

        // Auth::processSLO() reads $_GET directly rather than the PSR-7 request.
        $_GET = $queryParams;

        // performLogoff()'s live-session branch checks the frontend.user Context aspect,
        // which is normally populated by TYPO3's own FrontendUserAuthenticator middleware.
        GeneralUtility::makeInstance(Context::class)
            ->setAspect('frontend.user', GeneralUtility::makeInstance(UserAspect::class, $feUser));

        return $request;
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
    public function terminatesLiveSessionAndRedirectsToStoredTargetOnSuccessfulCallback(): void
    {
        $feUser = $this->fixateLiveFeUser();
        self::assertTrue($this->feSessionExists());

        $signed = RedirectBindingLogoutResponseSigner::signedLogoutResponse(self::DESTINATION, self::ISSUER);
        $request = $this->buildSloCallbackRequest(
            $feUser,
            $this->samlQueryParams($signed),
            urlencode('https://' . self::HOST . '/some/page')
        );

        $subject = GeneralUtility::makeInstance(SlsFrontendSamlMiddleware::class);
        $response = $subject->process($request, $this->nextHandler());

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('https://' . self::HOST . '/some/page', $response->getHeaderLine('Location'));

        $setCookies = $response->getHeader('Set-Cookie');
        self::assertNotEmpty(
            array_filter($setCookies, static fn(string $c): bool => str_starts_with($c, 'md_saml_slo_context=;'))
        );
        self::assertNotEmpty(
            array_filter($setCookies, static fn(string $c): bool => str_starts_with($c, 'md_saml_slo_redirect=;'))
        );

        self::assertFalse($this->feSessionExists());
        $user = $this->getFeUser();
        self::assertSame(0, (int)$user['md_saml_source']);
        self::assertSame('', $user['md_saml_nameid']);
    }

    #[Test]
    public function terminatesLocalSessionEvenWhenIdpReturnsNonSuccessStatus(): void
    {
        $feUser = $this->fixateLiveFeUser();
        self::assertTrue($this->feSessionExists());

        $signed = RedirectBindingLogoutResponseSigner::signedLogoutResponse(
            self::DESTINATION,
            self::ISSUER,
            Constants::STATUS_RESPONDER
        );
        $request = $this->buildSloCallbackRequest(
            $feUser,
            $this->samlQueryParams($signed),
            urlencode('/')
        );

        $subject = GeneralUtility::makeInstance(SlsFrontendSamlMiddleware::class);
        $response = $subject->process($request, $this->nextHandler());

        self::assertSame(303, $response->getStatusCode());
        self::assertFalse($this->feSessionExists());
    }

    #[Test]
    public function fallsBackToRootPathWhenNoRedirectCookieIsStored(): void
    {
        $feUser = $this->fixateLiveFeUser();

        $signed = RedirectBindingLogoutResponseSigner::signedLogoutResponse(self::DESTINATION, self::ISSUER);
        $request = $this->buildSloCallbackRequest(
            $feUser,
            $this->samlQueryParams($signed),
            ''
        );

        $subject = GeneralUtility::makeInstance(SlsFrontendSamlMiddleware::class);
        $response = $subject->process($request, $this->nextHandler());

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/', $response->getHeaderLine('Location'));
    }
}
