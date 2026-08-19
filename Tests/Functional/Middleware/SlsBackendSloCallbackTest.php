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
use Mediadreams\MdSaml\Middleware\SlsBackendSamlMiddleware;
use Mediadreams\MdSaml\Tests\Fixtures\Saml\RedirectBindingLogoutResponseSigner;
use OneLogin\Saml2\Constants;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * SP-initiated BE logout CALLBACK: the IdP's reply to the LogoutRequest built by
 * SlsBackendSamlMiddleware::initiateBackendSlo(), identified by the
 * md_saml_slo_context=BE cookie. Covers handleSloCallback(), as opposed to the
 * SP-initiation half already covered in SlsBackendSloInitiatorMiddlewareTest and
 * the IdP-initiated / ApplicationType-guard paths covered in
 * SlsBackendSamlMiddlewareTest.
 */
#[CoversClass(SlsBackendSamlMiddleware::class)]
final class SlsBackendSloCallbackTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['mediadreams/md_saml'];

    protected array $pathsToLinkInTestInstance = [
        // See Tests/Functional/Middleware/SamlMiddlewareTest.php for why the
        // whole Fixtures/BeSloCallbackSites folder must be linked as one entry.
        'typo3conf/ext/md_saml/Tests/Functional/Middleware/Fixtures/BeSloCallbackSites' => 'typo3conf/sites',
    ];

    private const HOST = 'typo3-be-slo-callback-test.local';

    private const DESTINATION = 'https://typo3-be-slo-callback-test.local/typo3/index.php';

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
        $this->importCSVDataSet(__DIR__ . '/Fixtures/be_users_slo_callback.csv');
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_GET = [];

        parent::tearDown();
    }

    /**
     * @param array{SAMLResponse: string, SigAlg: string, Signature: string, queryString: string} $signed
     */
    private function buildSloCallbackRequest(array $signed): ServerRequestInterface
    {
        $queryParams = [
            'sls' => '1',
            'SAMLResponse' => $signed['SAMLResponse'],
            'SigAlg' => $signed['SigAlg'],
            'Signature' => $signed['Signature'],
        ];
        $queryString = http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);

        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = self::HOST;
        $_SERVER['SERVER_PORT'] = '443';
        $_SERVER['SCRIPT_NAME'] = '/typo3/index.php';
        $_SERVER['REQUEST_URI'] = '/typo3/index.php?' . $queryString;
        $_SERVER['QUERY_STRING'] = $queryString;
        $_GET = $queryParams;
        GeneralUtility::flushInternalRuntimeCaches();

        $uri = self::DESTINATION . '?' . $queryString;
        return (new ServerRequest($uri, 'GET'))
            ->withQueryParams($queryParams)
            ->withCookieParams(['md_saml_slo_context' => 'BE']);
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
    public function terminatesLocalSessionAndRedirectsToBackendLoginOnSuccessfulCallback(): void
    {
        $this->setUpBackendUser(self::USER_ID);
        self::assertTrue($this->beSessionExists());

        $signed = RedirectBindingLogoutResponseSigner::signedLogoutResponse(self::DESTINATION, self::ISSUER);
        $request = $this->buildSloCallbackRequest($signed);

        $subject = GeneralUtility::makeInstance(SlsBackendSamlMiddleware::class);
        $response = $subject->process($request, $this->nextHandler());

        self::assertSame(303, $response->getStatusCode());
        self::assertSame(
            '/typo3/?loginProvider=' . SamlAuthService::SAML_LOGIN_PROVIDER_ID,
            $response->getHeaderLine('Location')
        );

        $setCookies = $response->getHeader('Set-Cookie');
        self::assertNotEmpty(
            array_filter($setCookies, static fn(string $c): bool => str_starts_with($c, 'md_saml_slo_context=;'))
        );

        self::assertFalse($this->beSessionExists());
        $user = $this->getBeUser();
        self::assertSame(0, (int)$user['md_saml_source']);
        self::assertSame('', $user['md_saml_nameid']);
    }

    #[Test]
    public function terminatesLocalSessionEvenWhenIdpReturnsNonSuccessStatus(): void
    {
        $this->setUpBackendUser(self::USER_ID);
        self::assertTrue($this->beSessionExists());

        $signed = RedirectBindingLogoutResponseSigner::signedLogoutResponse(
            self::DESTINATION,
            self::ISSUER,
            Constants::STATUS_RESPONDER
        );
        $request = $this->buildSloCallbackRequest($signed);

        $subject = GeneralUtility::makeInstance(SlsBackendSamlMiddleware::class);
        $response = $subject->process($request, $this->nextHandler());

        self::assertSame(303, $response->getStatusCode());
        self::assertFalse($this->beSessionExists());
    }
}
