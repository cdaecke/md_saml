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

namespace Mediadreams\MdSaml\Middleware;

use OneLogin\Saml2\Error;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Context\Context;

/**
 * Class SlsFrontendSamlMiddleware
 */
class SlsFrontendSamlMiddleware extends SlsSamlMiddleware
{
    /**
     * Process request
     *
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     * @return ResponseInterface
     * @throws Error
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->context = 'FE';
        return parent::process($request, $handler);
    }
    
    protected function performLogoff(ServerRequestInterface $request) {

        $context = GeneralUtility::makeInstance(Context::class);

        if ($context->getPropertyFromAspect('frontend.user', 'isLoggedIn')) {
           $feUser = $request->getAttribute('frontend.user');
           $feUser->logoff();
        }
    }
}
