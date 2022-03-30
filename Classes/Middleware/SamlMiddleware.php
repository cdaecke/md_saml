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
class SamlMiddleware implements MiddlewareInterface
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
        if (
            1648123062 == GeneralUtility::_GP('loginProvider')
            && is_a($GLOBALS['BE_USER'], '\TYPO3\CMS\Core\Authentication\BackendUserAuthentication')
        ) {
            if (null !== GeneralUtility::_GP('mdsamlmetadata')) {
                try {
                    $extSettings = $this->settingsService->getSettings();
                    // Now we only validate SP settings
                    $settings = new \OneLogin\Saml2\Settings($extSettings['saml'], true);
                    $metadata = $settings->getSPMetadata();
                    $errors = $settings->validateMetadata($metadata);
                    if (empty($errors)) {
                        $response = $this->responseFactory
                            ->createResponse()
                            ->withHeader('Content-Type', 'text/xml; charset=utf-8');

                        $response->getBody()->write($metadata);
                        return $response;
                    } else {
                        throw new \OneLogin\Saml2\Error(
                            'Invalid SP metadata: ' . implode(', ', $errors),
                            \OneLogin\Saml2\Error::METADATA_SP_INVALID
                        );
                    }
                } catch (Exception $e) {
                    echo $e->getMessage();
                }
            }
        }

        return $handler->handle($request);
    }
}
