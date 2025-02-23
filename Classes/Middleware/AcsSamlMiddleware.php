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

use Mediadreams\MdSaml\Service\SettingsService;
use OneLogin\Saml2\Error;
use OneLogin\Saml2\ValidationError;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Class SamlMiddleware
 */
class AcsSamlMiddleware implements MiddlewareInterface
{
    /**
     * SamlMiddleware constructor
     *
     * @param ResponseFactoryInterface $responseFactory
     * @param SettingsService $settingsService
     */
    public function __construct(private readonly ResponseFactoryInterface $responseFactory, protected SettingsService $settingsService)
    {
    }

    /**
     * Process request
     *
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     * @return ResponseInterface
     * @throws Error
     * @throws ValidationError
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->settingsService->useFrontendAssertionConsumerServiceAuto($request->getUri()->getPath())) {
            $loginParams = [
                'logintype' => 'login',
                'login_status' => 'login',
                'loginProvider' => 1648123062,
                'login-provider' => 'md_saml',
            ];
            if (isset($_POST['RelayState'])) {
                $loginParams['redirect_url'] = $_POST['RelayState'];
            }

            $queryParams = array_replace_recursive($loginParams, $request->getQueryParams());
            $request = $request->withQueryParams($queryParams);
        }

        return $handler->handle($request);
    }
}
