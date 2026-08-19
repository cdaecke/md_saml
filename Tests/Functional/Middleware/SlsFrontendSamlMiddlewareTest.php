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
use Mediadreams\MdSaml\Tests\Fixtures\Saml\RedirectBindingSigner;
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
 * Regression coverage for 308c8c8: performNameIdFallbackLogoff() resolves the FE
 * session to terminate from the NameID in an IdP-initiated LogoutRequest when no
 * live session cookie is present (e.g. ADFS delivering SLO via a hidden iframe).
 * That fallback must only trust the NameID when the LogoutRequest carried a
 * Signature parameter that was actually verified — an unsigned request must not
 * let an unauthenticated caller terminate an arbitrary named user's session.
 */
#[CoversClass(SlsFrontendSamlMiddleware::class)]
final class SlsFrontendSamlMiddlewareTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['mediadreams/md_saml'];

    protected array $pathsToLinkInTestInstance = [
        // See Tests/Functional/Middleware/SamlMiddlewareTest.php for why the
        // whole Fixtures/SlsSites folder must be linked as one entry.
        'typo3conf/ext/md_saml/Tests/Functional/Middleware/Fixtures/SlsSites' => 'typo3conf/sites',
    ];

    private const HOST = 'typo3-slo-test.local';

    private const DESTINATION = 'https://typo3-slo-test.local/index.php';

    private const ISSUER = 'https://idp.example.com/entity';

    private const NAME_ID = 'test-nameid-value';

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/Fixtures/fe_users_saml_slo.csv');
    }

    /**
     * @param array<string, string> $samlQueryParams
     */
    private function buildRequest(array $samlQueryParams): ServerRequestInterface
    {
        $queryParams = array_merge(['sls' => '1'], $samlQueryParams);
        $queryString = http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);

        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = self::HOST;
        $_SERVER['SERVER_PORT'] = '443';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['REQUEST_URI'] = '/index.php?' . $queryString;
        $_SERVER['QUERY_STRING'] = $queryString;
        $_GET = $queryParams;
        GeneralUtility::flushInternalRuntimeCaches();

        $uri = 'https://' . self::HOST . '/index.php?' . $queryString;
        return (new ServerRequest($uri, 'GET'))->withQueryParams($queryParams);
    }

    private function nextHandler(): RequestHandlerInterface
    {
        return new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response();
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function getFeUser(): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('fe_users');
        $row = $queryBuilder->select('*')
            ->from('fe_users')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();
        self::assertIsArray($row);
        return $row;
    }

    private function feSessionExists(): bool
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('fe_sessions');
        $userId = $queryBuilder->createNamedParameter(1, Connection::PARAM_INT);
        $count = $queryBuilder->count('ses_id')
            ->from('fe_sessions')
            ->where($queryBuilder->expr()->eq('ses_userid', $userId))
            ->executeQuery()
            ->fetchOne();

        return (int)$count > 0;
    }

    /**
     * The test site's IdP has no singleLogoutService.responseUrl/url configured (see the
     * comment in Fixtures/SlsSites/md-saml-slo-test/config.yaml): once Auth::processSLO()
     * validates the incoming LogoutRequest and invokes cbDeleteSession (the code under
     * test), it tries to redirect the LogoutResponse back to the IdP via
     * Utils::redirect(), which asserts the target URL is a string. With no URL
     * configured that assertion fails cleanly instead of the alternative — a raw
     * header()+exit() that would kill the whole PHPUnit process, not just this test.
     */
    private function processAndExpectResponseRedirectToFailCleanly(
        SlsFrontendSamlMiddleware $subject,
        ServerRequestInterface $request
    ): void {
        try {
            $subject->process($request, $this->nextHandler());
            self::fail('Expected Utils::redirect() to fail on the unconfigured IdP SLO response URL.');
        } catch (\AssertionError $assertionError) {
            self::assertStringContainsString('is_string', $assertionError->getMessage());
        }
    }

    #[Test]
    public function resolvesSessionViaSignedLogoutRequestNameIdWhenNoLiveSessionIsPresent(): void
    {
        $signed = RedirectBindingSigner::signedLogoutRequest(self::DESTINATION, self::ISSUER, self::NAME_ID);
        $request = $this->buildRequest([
            'SAMLRequest' => $signed['SAMLRequest'],
            'SigAlg' => $signed['SigAlg'],
            'Signature' => $signed['Signature'],
        ]);

        $subject = GeneralUtility::makeInstance(SlsFrontendSamlMiddleware::class);
        $this->processAndExpectResponseRedirectToFailCleanly($subject, $request);

        self::assertFalse($this->feSessionExists());
        $user = $this->getFeUser();
        self::assertSame(0, (int)$user['md_saml_source']);
        self::assertSame('', $user['md_saml_nameid']);
    }

    #[Test]
    public function doesNotResolveSessionViaUnsignedLogoutRequestNameId(): void
    {
        $unsigned = RedirectBindingSigner::unsignedLogoutRequest(self::DESTINATION, self::ISSUER, self::NAME_ID);
        $request = $this->buildRequest([
            'SAMLRequest' => $unsigned['SAMLRequest'],
        ]);

        $subject = GeneralUtility::makeInstance(SlsFrontendSamlMiddleware::class);
        $this->processAndExpectResponseRedirectToFailCleanly($subject, $request);

        self::assertTrue($this->feSessionExists());
        $user = $this->getFeUser();
        self::assertSame(1, (int)$user['md_saml_source']);
        self::assertSame(self::NAME_ID, $user['md_saml_nameid']);
    }
}
