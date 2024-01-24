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

use TYPO3\CMS\Backend\Controller\LoginController;
use TYPO3\CMS\Backend\LoginProvider\LoginProviderInterface;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Fluid\View\StandaloneView;

class SamlLoginProvider implements LoginProviderInterface
{
    /**
     * Renders the login fields
     *
     * @param StandaloneView $view
     * @param PageRenderer $pageRenderer
     * @param LoginController $loginController
     */
    public function render(StandaloneView $view, PageRenderer $pageRenderer, LoginController $loginController)
    {
        $view->setTemplatePathAndFilename(
            GeneralUtility::getFileAbsFileName('EXT:md_saml/Resources/Private/Templates/Backend/LoginSaml.html')
        );

        if (!empty(GeneralUtility::_GP('error'))) {
            $view->assign('loginError', true);
        }

        $view->assign('enablePasswordReset', false);
    }
}
