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

namespace Mediadreams\MdSaml\LoginProvider;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Controller\LoginController;
use TYPO3\CMS\Backend\LoginProvider\LoginProviderInterface;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewInterface;
use TYPO3\CMS\Fluid\View\StandaloneView;
use TYPO3Fluid\Fluid\View\TemplateAwareViewInterface;

class SamlLoginProvider implements LoginProviderInterface
{
    public function modifyView(ServerRequestInterface $request, ViewInterface $view): string
    {
        // getRenderingContext() is declared on TemplateAwareViewInterface in Fluid 5.x (v14),
        // but not in Fluid 4.x (v13). The concrete class has the method in both versions.
        if ($view instanceof TemplateAwareViewInterface) {
            // @phpstan-ignore-next-line
            $view->getRenderingContext()->getTemplatePaths()->setTemplatePathAndFilename(
                GeneralUtility::getFileAbsFileName('EXT:md_saml/Resources/Private/Templates/Backend/LoginSaml.html')
            );
        }

        $queryParams = $request->getQueryParams();
        if (isset($queryParams['error']) && $queryParams['error'] !== '') {
            $view->assign('loginError', true);
        }

        $view->assign('enablePasswordReset', false);
        return '';
    }

    // v13 compatibility: StandaloneView does not exist in v14, but this method is never
    // called there (lazy type resolution). StandaloneView does not implement
    // TYPO3\CMS\Core\View\ViewInterface, so this must remain self-contained.
    public function render(StandaloneView $view, PageRenderer $pageRenderer, LoginController $loginController): void
    {
        $view->getRenderingContext()->getTemplatePaths()->setTemplatePathAndFilename(
            GeneralUtility::getFileAbsFileName('EXT:md_saml/Resources/Private/Templates/Backend/LoginSaml.html')
        );

        $queryParams = ($GLOBALS['TYPO3_REQUEST'] ?? null)?->getQueryParams() ?? [];
        if (isset($queryParams['error']) && $queryParams['error'] !== '') {
            $view->assign('loginError', true);
        }

        $view->assign('enablePasswordReset', false);
    }
}
