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

namespace Mediadreams\MdSaml\Tests\Unit\LoginProvider;

use Mediadreams\MdSaml\LoginProvider\SamlLoginProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Covers modifyView() — the v14 backend-login-provider hook. render() (the v13
 * StandaloneView compatibility path) is not covered here: StandaloneView does
 * not exist at all in this v14-only test environment, and the production code
 * relies on exactly that fact (lazy type resolution) to never invoke it there.
 */
#[CoversClass(SamlLoginProvider::class)]
final class SamlLoginProviderTest extends UnitTestCase
{
    private SamlLoginProvider $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subject = new SamlLoginProvider();
    }

    private function buildRequest(string $query = ''): ServerRequestInterface
    {
        $uri = 'https://typo3-testing.local/typo3/login' . ($query !== '' ? '?' . $query : '');
        $request = new ServerRequest($uri);
        parse_str($query, $queryParams);

        return $request->withQueryParams($queryParams);
    }

    #[Test]
    public function setsTemplatePathWhenViewIsTemplateAware(): void
    {
        // GeneralUtility::getFileAbsFileName('EXT:...') does not reliably resolve
        // in this unit-test bootstrap (no instance-folder extension symlink, unlike
        // FunctionalTestCase), so this only asserts the chained call happened at
        // all — i.e. that the TemplateAwareViewInterface branch actually fires —
        // not the exact resolved path.
        $view = new RecordingTemplateAwareView();

        $this->subject->modifyView($this->buildRequest(), $view);

        self::assertNotNull($view->templatePathAndFilename);
    }

    #[Test]
    public function doesNotFailWhenViewIsNotTemplateAware(): void
    {
        $view = new RecordingView();

        $result = $this->subject->modifyView($this->buildRequest(), $view);

        self::assertSame('', $result);
    }

    #[Test]
    public function assignsLoginErrorTrueWhenErrorQueryParamIsPresent(): void
    {
        $view = new RecordingView();

        $this->subject->modifyView($this->buildRequest('error=1'), $view);

        self::assertTrue($view->assigned['loginError']);
    }

    #[Test]
    public function doesNotAssignLoginErrorWhenQueryParamIsAbsent(): void
    {
        $view = new RecordingView();

        $this->subject->modifyView($this->buildRequest(), $view);

        self::assertArrayNotHasKey('loginError', $view->assigned);
    }

    #[Test]
    public function doesNotAssignLoginErrorWhenQueryParamIsAnEmptyString(): void
    {
        $view = new RecordingView();

        $this->subject->modifyView($this->buildRequest('error='), $view);

        self::assertArrayNotHasKey('loginError', $view->assigned);
    }

    #[Test]
    public function alwaysDisablesPasswordReset(): void
    {
        $view = new RecordingView();

        $this->subject->modifyView($this->buildRequest(), $view);

        self::assertFalse($view->assigned['enablePasswordReset']);
    }

    #[Test]
    public function returnsAnEmptyString(): void
    {
        $view = new RecordingView();

        self::assertSame('', $this->subject->modifyView($this->buildRequest(), $view));
    }
}
