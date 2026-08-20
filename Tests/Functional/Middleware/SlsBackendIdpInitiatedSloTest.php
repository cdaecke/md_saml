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
use Mediadreams\MdSaml\Tests\Fixtures\Saml\RedirectBindingSigner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * IdP-initiated BE logout: the IdP sends a LogoutRequest directly to the BE SLO
 * endpoint without a prior SP LogoutRequest, identified by the absence of the
 * md_saml_slo_context=BE cookie (that cookie is only set during SP-initiated SLO,
 * see SlsBackendSloInitiatorMiddlewareTest). Covers the base SlsSamlMiddleware
 * validation + performLogoff() round-trip with a real, signed LogoutRequest and
 * site fixture — as opposed to SlsBackendSamlMiddlewareTest, which only proves
 * the ApplicationType guard using a RuntimeException from a missing site.
 */
#[CoversClass(SlsBackendSamlMiddleware::class)]
final class SlsBackendIdpInitiatedSloTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['mediadreams/md_saml'];

    protected array $pathsToLinkInTestInstance = [
        // See Tests/Functional/Middleware/SamlMiddlewareTest.php for why the
        // whole Fixtures/BeIdpInitiatedSloSites folder must be linked as one entry.
        'typo3conf/ext/md_saml/Tests/Functional/Middleware/Fixtures/BeIdpInitiatedSloSites' => 'typo3conf/sites',
    ];

    private const HOST = 'typo3-be-idp-slo-test.local';

    private const DESTINATION = 'https://typo3-be-idp-slo-test.local/typo3/index.php';

    private const ISSUER = 'https://idp.example.com/entity';

    private const NAME_ID = 'idp-slo-be-nameid';

    private const USER_ID = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/Fixtures/be_users_idp_slo.csv');
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
        $_SERVER['SCRIPT_NAME'] = '/typo3/index.php';
        $_SERVER['REQUEST_URI'] = '/typo3/index.php?' . $queryString;
        $_SERVER['QUERY_STRING'] = $queryString;
        $_GET = $queryParams;
        GeneralUtility::flushInternalRuntimeCaches();

        $uri = self::DESTINATION . '?' . $queryString;
        return (new ServerRequest($uri, 'GET'))
            ->withQueryParams($queryParams)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
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

    /**
     * The test site's IdP has no singleLogoutService.responseUrl/url configured (see
     * the comment in Fixtures/BeIdpInitiatedSloSites/.../config.yaml): once
     * Auth::processSLO() validates the incoming LogoutRequest and invokes
     * cbDeleteSession (the code under test), it tries to redirect the LogoutResponse
     * back to the IdP via Utils::redirect(), which asserts the target URL is a
     * string. With no URL configured that assertion fails cleanly instead of the
     * alternative — a raw header()+exit() that would kill the whole PHPUnit
     * process, not just this test.
     */
    private function processAndExpectResponseRedirectToFailCleanly(
        SlsBackendSamlMiddleware $subject,
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
    public function terminatesLocalBackendSessionAndClearsSamlFieldsOnValidLogoutRequest(): void
    {
        $this->setUpBackendUser(self::USER_ID);
        self::assertTrue($this->beSessionExists());

        $signed = RedirectBindingSigner::signedLogoutRequest(self::DESTINATION, self::ISSUER, self::NAME_ID);
        $request = $this->buildRequest([
            'SAMLRequest' => $signed['SAMLRequest'],
            'SigAlg' => $signed['SigAlg'],
            'Signature' => $signed['Signature'],
        ]);

        $subject = GeneralUtility::makeInstance(SlsBackendSamlMiddleware::class);
        $this->processAndExpectResponseRedirectToFailCleanly($subject, $request);

        self::assertFalse($this->beSessionExists());
        $user = $this->getBeUser();
        self::assertSame(0, (int)$user['md_saml_source']);
        self::assertSame('', $user['md_saml_nameid']);
    }

    #[Test]
    public function doesNotTerminateBackendSessionWhenLogoutRequestDestinationDoesNotMatch(): void
    {
        // A wrong Destination fails onelogin's own LogoutRequest::isValid() check
        // (structural, independent of the signature) before cbDeleteSession is
        // ever invoked — unlike the unsigned-but-well-formed case, this never
        // reaches redirectTo() at all, so no AssertionError is expected either.
        $this->setUpBackendUser(self::USER_ID);
        self::assertTrue($this->beSessionExists());

        $signed = RedirectBindingSigner::signedLogoutRequest(
            'https://typo3-be-idp-slo-test.local/wrong-destination',
            self::ISSUER,
            self::NAME_ID
        );
        $request = $this->buildRequest([
            'SAMLRequest' => $signed['SAMLRequest'],
            'SigAlg' => $signed['SigAlg'],
            'Signature' => $signed['Signature'],
        ]);

        $subject = GeneralUtility::makeInstance(SlsBackendSamlMiddleware::class);
        $response = $subject->process($request, $this->nextHandler());

        self::assertSame('NEXT-HANDLER-CALLED', (string)$response->getBody());
        self::assertTrue($this->beSessionExists());
        $user = $this->getBeUser();
        self::assertSame(1, (int)$user['md_saml_source']);
        self::assertSame(self::NAME_ID, $user['md_saml_nameid']);
    }
}
