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

namespace Mediadreams\MdSaml\Tests\Unit\Middleware;

use Mediadreams\MdSaml\Middleware\SlsFrontendSamlMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Covers the open-redirect guards and the ACS-callback RelayState redirect they
 * gate. The boundary logic itself (what counts as "same origin") is exhaustively
 * tested in SameOriginUrlGuardTest — these tests confirm the middleware wires
 * that guard in correctly, applies its own extra rule (ACS RelayState must not
 * loop back to the ACS route itself), and only redirects when a frontend user
 * actually ended up logged in.
 */
#[CoversClass(SlsFrontendSamlMiddleware::class)]
final class SlsFrontendSamlMiddlewareTest extends UnitTestCase
{
    /**
     * @var array<string, mixed>
     */
    private array $serverBackup;

    private SlsFrontendSamlMiddleware $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->serverBackup = $_SERVER;
        $_SERVER['HTTP_HOST'] = 'typo3.example.com';
        $_SERVER['REQUEST_URI'] = '/index.php?loginProvider=1648123062&acs=1';
        $_SERVER['QUERY_STRING'] = 'loginProvider=1648123062&acs=1';
        unset(
            $_SERVER['HTTPS'],
            $_SERVER['HTTP_X_FORWARDED_PROTO'],
            $_SERVER['HTTP_X_FORWARDED_HOST'],
            $_SERVER['HTTP_X_FORWARDED_PORT'],
            $_SERVER['SERVER_PORT'],
        );

        $this->subject = (new \ReflectionClass(SlsFrontendSamlMiddleware::class))->newInstanceWithoutConstructor();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;

        parent::tearDown();
    }

    private function isSafeAcsRelayState(string $relayState): bool
    {
        $method = (new \ReflectionClass(SlsFrontendSamlMiddleware::class))->getMethod('isSafeAcsRelayState');
        return $method->invoke($this->subject, $relayState);
    }

    private function isSafeSloRedirectTarget(string $redirectTo): bool
    {
        $method = (new \ReflectionClass(SlsFrontendSamlMiddleware::class))->getMethod('isSafeSloRedirectTarget');
        return $method->invoke($this->subject, $redirectTo);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function acsRelayStateProvider(): array
    {
        return [
            'same-origin, different page' => ['http://typo3.example.com/some/other/page', true],
            'loops back to the ACS route itself (exact)' => ['http://typo3.example.com/index.php', false],
            'loops back to the ACS route itself (with query)' => ['http://typo3.example.com/index.php?foo=bar', false],
            'suffix-matching attacker host' => ['http://typo3.example.com.evil.com/phish', false],
            'external host' => ['https://evil.com', false],
        ];
    }

    #[Test]
    #[DataProvider('acsRelayStateProvider')]
    public function isSafeAcsRelayStateFollowsSameOriginTargetsButNotTheAcsRouteItself(
        string $relayState,
        bool $expected
    ): void {
        self::assertSame($expected, $this->isSafeAcsRelayState($relayState));
    }

    /**
     * Regression test for https://github.com/cdaecke/md_saml/issues/73#issuecomment-5343597499:
     * when SAML login/ACS is configured on the site root, the ACS route ("/") is a literal
     * string prefix of every other page on the site. A str_starts_with() based loop-guard
     * would therefore block the redirect to every target page, not just the ACS route itself.
     */
    #[Test]
    public function isSafeAcsRelayStateAllowsSubpagesWhenAcsRouteIsTheSiteRoot(): void
    {
        $_SERVER['REQUEST_URI'] = '/?loginProvider=1648123062&acs=1';
        $_SERVER['QUERY_STRING'] = 'loginProvider=1648123062&acs=1';

        self::assertTrue($this->isSafeAcsRelayState('http://typo3.example.com/example-page'));
        self::assertFalse($this->isSafeAcsRelayState('http://typo3.example.com/'));
        self::assertFalse($this->isSafeAcsRelayState('http://typo3.example.com/?foo=bar'));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function sloRedirectTargetProvider(): array
    {
        return [
            'root-relative path' => ['/fileadmin/some/page', true],
            'same-origin absolute url' => ['http://typo3.example.com/page', true],
            'protocol-relative attacker host' => ['//evil.com', false],
            'suffix-matching attacker host' => ['http://typo3.example.com.evil.com/phish', false],
            'empty string' => ['', false],
        ];
    }

    #[Test]
    #[DataProvider('sloRedirectTargetProvider')]
    public function isSafeSloRedirectTargetDelegatesToTheSameOriginGuard(string $redirectTo, bool $expected): void
    {
        self::assertSame($expected, $this->isSafeSloRedirectTarget($redirectTo));
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
     * @param array<string, string> $queryParams
     * @param array<string, string> $parsedBody
     */
    private function buildAcsRequest(
        array $queryParams,
        array $parsedBody,
        ?FrontendUserAuthentication $feUser
    ): ServerRequestInterface {
        $request = (new ServerRequest('http://typo3.example.com/index.php?loginProvider=1648123062&acs=1', 'POST'))
            ->withQueryParams($queryParams)
            ->withParsedBody($parsedBody);

        return $feUser instanceof FrontendUserAuthentication
            ? $request->withAttribute('frontend.user', $feUser)
            : $request;
    }

    /**
     * @param array<string, mixed>|null $user
     */
    private function feUser(?array $user): FrontendUserAuthentication
    {
        $feUser = (new \ReflectionClass(FrontendUserAuthentication::class))->newInstanceWithoutConstructor();
        $feUser->user = $user;

        return $feUser;
    }

    #[Test]
    public function redirectsToRelayStateWhenSafeAndAFrontendUserIsLoggedIn(): void
    {
        $request = $this->buildAcsRequest(
            ['loginProvider' => '1648123062', 'acs' => '1'],
            ['RelayState' => 'http://typo3.example.com/some/other/page'],
            $this->feUser(['uid' => 1])
        );

        $response = $this->subject->process($request, $this->nextHandler());

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('http://typo3.example.com/some/other/page', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function passesThroughWhenRelayStateIsMissing(): void
    {
        $request = $this->buildAcsRequest(
            ['loginProvider' => '1648123062', 'acs' => '1'],
            [],
            $this->feUser(['uid' => 1])
        );

        $response = $this->subject->process($request, $this->nextHandler());

        self::assertSame('NEXT-HANDLER-CALLED', (string)$response->getBody());
    }

    #[Test]
    public function passesThroughWhenNoFrontendUserAttributeIsPresent(): void
    {
        $request = $this->buildAcsRequest(
            ['loginProvider' => '1648123062', 'acs' => '1'],
            ['RelayState' => 'http://typo3.example.com/some/other/page'],
            null
        );

        $response = $this->subject->process($request, $this->nextHandler());

        self::assertSame('NEXT-HANDLER-CALLED', (string)$response->getBody());
    }

    #[Test]
    public function passesThroughWhenFrontendUserIsNotLoggedIn(): void
    {
        $request = $this->buildAcsRequest(
            ['loginProvider' => '1648123062', 'acs' => '1'],
            ['RelayState' => 'http://typo3.example.com/some/other/page'],
            $this->feUser(null)
        );

        $response = $this->subject->process($request, $this->nextHandler());

        self::assertSame('NEXT-HANDLER-CALLED', (string)$response->getBody());
    }

    #[Test]
    public function passesThroughWhenRelayStateIsAnExternalHost(): void
    {
        $request = $this->buildAcsRequest(
            ['loginProvider' => '1648123062', 'acs' => '1'],
            ['RelayState' => 'https://evil.com'],
            $this->feUser(['uid' => 1])
        );

        $response = $this->subject->process($request, $this->nextHandler());

        self::assertSame('NEXT-HANDLER-CALLED', (string)$response->getBody());
    }
}
