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
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Regression coverage for d837b9c: SlsBackendSamlMiddleware is registered in
 * BOTH the backend and frontend middleware stacks (ADFS et al. may redirect an
 * SLO callback to a frontend URL). Without the ApplicationType guard, an
 * IdP-initiated SLO (?sls, no context cookie) arriving on a genuine FRONTEND
 * request would incorrectly be processed here with BE settings.
 *
 * No site is configured in the test instance, so whenever the middleware
 * actually attempts to process the SLO it will hit
 * SettingsService::getSamlConfig()'s "site configuration could not be
 * resolved" RuntimeException — that exception is used here as the signal
 * that the code reached past the guard, without needing real SAML fixtures.
 */
#[CoversClass(SlsBackendSamlMiddleware::class)]
final class SlsBackendSamlMiddlewareTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['mediadreams/md_saml'];

    private function buildRequest(int $applicationType, array $cookieParams = []): ServerRequestInterface
    {
        $request = (new ServerRequest('https://typo3-testing.local/index.php?sls=1', 'GET'))
            ->withQueryParams(['sls' => '1'])
            ->withCookieParams($cookieParams)
            ->withAttribute('applicationType', $applicationType);

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

    #[Test]
    public function passesThroughIdpInitiatedSloOnAFrontendRequest(): void
    {
        $request = $this->buildRequest(SystemEnvironmentBuilder::REQUESTTYPE_FE);

        $subject = GeneralUtility::makeInstance(SlsBackendSamlMiddleware::class);
        $response = $subject->process($request, $this->nextHandler());

        self::assertSame('NEXT-HANDLER-CALLED', (string)$response->getBody());
    }

    #[Test]
    public function attemptsToProcessIdpInitiatedSloOnABackendRequest(): void
    {
        $request = $this->buildRequest(SystemEnvironmentBuilder::REQUESTTYPE_BE);

        $subject = GeneralUtility::makeInstance(SlsBackendSamlMiddleware::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The site configuration could not be resolved.');
        $subject->process($request, $this->nextHandler());
    }

    #[Test]
    public function handlesSloCallbackViaContextCookieRegardlessOfApplicationType(): void
    {
        // ADFS et al. may redirect the SLO callback to a frontend URL even for a
        // BE-initiated logout; the context cookie (not ApplicationType) is what
        // routes it to handleSloCallback() in that case.
        $request = $this->buildRequest(SystemEnvironmentBuilder::REQUESTTYPE_FE, ['md_saml_slo_context' => 'BE']);

        $subject = GeneralUtility::makeInstance(SlsBackendSamlMiddleware::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The site configuration could not be resolved.');
        $subject->process($request, $this->nextHandler());
    }
}
