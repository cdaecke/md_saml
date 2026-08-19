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

namespace Mediadreams\MdSaml\Tests\Functional\Error;

use Mediadreams\MdSaml\Error\ForbiddenHandling;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * A 403 on the frontend must send SAML users straight to the IdP instead of
 * TYPO3's generic error page, since felogin's normal login form is not how
 * they authenticate. Falls back to a plain redirect to the site root when SAML
 * is not configured for the current site, rather than erroring out.
 */
#[CoversClass(ForbiddenHandling::class)]
final class ForbiddenHandlingTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['mediadreams/md_saml'];

    protected array $pathsToLinkInTestInstance = [
        // See Tests/Functional/Middleware/SamlMiddlewareTest.php for why the whole
        // Fixtures/ForbiddenHandlingSites folder must be linked as one entry.
        'typo3conf/ext/md_saml/Tests/Functional/Error/Fixtures/ForbiddenHandlingSites' => 'typo3conf/sites',
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
     * SettingsService resolves the current site via GeneralUtility::getIndpEnv('TYPO3_REQUEST_URL'),
     * which reads from $_SERVER and memoizes per process — flushed here whenever $_SERVER changes.
     * The request object handed to handlePageError() is otherwise unused by the production code.
     */
    private function requestOnHost(string $host): ServerRequest
    {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = $host;
        $_SERVER['SERVER_PORT'] = '443';
        $_SERVER['REQUEST_URI'] = '/some/forbidden/page';
        GeneralUtility::flushInternalRuntimeCaches();

        return new ServerRequest('https://' . $host . '/some/forbidden/page');
    }

    #[Test]
    public function redirectsToTheIdpWhenSamlIsConfiguredForTheSite(): void
    {
        $subject = new ForbiddenHandling(403, []);
        $response = $subject->handlePageError($this->requestOnHost('typo3-forbidden-test.local'), 'Forbidden');

        self::assertSame(302, $response->getStatusCode());
        self::assertStringStartsWith('https://idp.example.com/sso', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function redirectsToTheSiteRootWhenSamlIsNotConfiguredForTheSite(): void
    {
        $subject = new ForbiddenHandling(403, []);
        $response = $subject->handlePageError(
            $this->requestOnHost('typo3-forbidden-fallback-test.local'),
            'Forbidden'
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/', $response->getHeaderLine('Location'));
    }
}
