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
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Covers only the open-redirect guards. The boundary logic itself (what counts
 * as "same origin") is exhaustively tested in SameOriginUrlGuardTest — these
 * tests just confirm the middleware wires that guard in correctly and applies
 * its own extra rule (ACS RelayState must not loop back to the ACS route itself).
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
}
