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

namespace Mediadreams\MdSaml\Tests\Unit\Security;

use Mediadreams\MdSaml\Security\SameOriginUrlGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(SameOriginUrlGuard::class)]
final class SameOriginUrlGuardTest extends UnitTestCase
{
    /**
     * @var array<string, mixed>
     */
    private array $serverBackup;

    protected function setUp(): void
    {
        parent::setUp();

        $this->serverBackup = $_SERVER;
        $_SERVER['HTTP_HOST'] = 'typo3.example.com';
        unset(
            $_SERVER['HTTPS'],
            $_SERVER['HTTP_X_FORWARDED_PROTO'],
            $_SERVER['HTTP_X_FORWARDED_HOST'],
            $_SERVER['HTTP_X_FORWARDED_PORT'],
            $_SERVER['SERVER_PORT'],
        );
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function isSameOriginProvider(): array
    {
        return [
            'exact host, no path' => ['http://typo3.example.com', true],
            'same-origin path' => ['http://typo3.example.com/some/page', true],
            'same-origin with query' => ['http://typo3.example.com?foo=bar', true],
            'same-origin with fragment' => ['http://typo3.example.com#section', true],
            'different scheme is still same host' => ['https://typo3.example.com/page', false],
            'completely different host' => ['https://evil.com/phish', false],
            'suffix-matching subdomain-style attacker host' => ['http://typo3.example.com.evil.com/phish', false],
            'attacker host directly appended, no separator' => ['http://typo3.example.comevil.com/', false],
            'protocol-relative attacker host' => ['//evil.com', false],
            'empty string' => ['', false],
        ];
    }

    #[Test]
    #[DataProvider('isSameOriginProvider')]
    public function isSameOriginDetectsRealSameOriginUrlsAndRejectsBoundaryBypasses(string $url, bool $expected): void
    {
        self::assertSame($expected, SameOriginUrlGuard::isSameOrigin($url));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function isSameOriginOrRootRelativeProvider(): array
    {
        return [
            'root-relative path' => ['/fileadmin/some/page', true],
            'same-origin absolute url' => ['http://typo3.example.com/page', true],
            'suffix-matching attacker host' => ['http://typo3.example.com.evil.com/phish', false],
            'protocol-relative attacker host' => ['//evil.com', false],
            'external absolute url' => ['https://evil.com', false],
            'empty string' => ['', false],
        ];
    }

    #[Test]
    #[DataProvider('isSameOriginOrRootRelativeProvider')]
    public function isSameOriginOrRootRelativeAcceptsRootRelativePathsAndRejectsProtocolRelativeUrls(
        string $url,
        bool $expected
    ): void {
        self::assertSame($expected, SameOriginUrlGuard::isSameOriginOrRootRelative($url));
    }
}
