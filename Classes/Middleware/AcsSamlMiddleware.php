<?php
declare(strict_types=1);

namespace Mediadreams\MdSaml\Middleware;

/**
 *
 * This file is part of the Extension "md_saml" for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * (c) 2022 Christoph Daecke <typo3@mediadreams.org>
 *
 */

use Mediadreams\MdSaml\Service\SettingsService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class SamlMiddleware
 * @package Mediadreams\MdSaml\Middleware
 */
class AcsSamlMiddleware implements MiddlewareInterface
{
    /** @var ResponseFactoryInterface */
    private $responseFactory;

    /** @var SettingsService */
    protected $settingsService;

    /**
     * SamlMiddleware constructor
     *
     * @param ResponseFactoryInterface $responseFactory
     * @param SettingsService $settingsService
     */
    public function __construct(ResponseFactoryInterface $responseFactory, SettingsService $settingsService)
    {
        $this->responseFactory = $responseFactory;
        $this->settingsService = $settingsService;
    }

    /**
     * Process request
     *
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     * @return ResponseInterface
     * @throws \OneLogin\Saml2\Error
     * @throws \OneLogin\Saml2\ValidationError
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
