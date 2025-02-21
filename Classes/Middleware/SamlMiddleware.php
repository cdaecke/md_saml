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
use OneLogin\Saml2\Settings;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Class SamlMiddleware
 */
class SamlMiddleware implements MiddlewareInterface
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
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (
            isset($_REQUEST['loginProvider'])
            && (int)$_REQUEST['loginProvider'] === 1648123062
            && isset($_REQUEST['mdsamlmetadata'])
        ) {
            $loginType = $_REQUEST['loginType'];
            if ($loginType === 'frontend') {
                $loginType = 'FE';
            } elseif ($loginType === 'backend') {
                $loginType = 'BE';
            }

            $extSettings = $this->settingsService->getSettings($loginType);

            if (isset($GLOBALS['BE_USER']->user) || (bool)($extSettings['publicMetadata'] ?? false)) {
                try {
                    // Now we only validate SP settings
                    $settings = new Settings($extSettings['saml'], true);
                    $metadata = $settings->getSPMetadata();
                    $errors = $settings->validateMetadata($metadata);
                    if ($errors === []) {
                        $response = $this->responseFactory
                            ->createResponse()
                            ->withHeader('Content-Type', 'text/xml; charset=utf-8');

                        $response->getBody()->write($metadata);
                        return $response;
                    }

                    throw new Error(
                        'Invalid SP metadata: ' . implode(', ', $errors),
                        Error::METADATA_SP_INVALID
                    );
                } catch (\Exception $e) {
                    echo $e->getMessage();
                }
            } else {
                $response = $this->responseFactory->createResponse();

                $response->getBody()->write('Please log into TYPO3!');
                return $response;
            }
        }

        return $handler->handle($request);
    }
}
