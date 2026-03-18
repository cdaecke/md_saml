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

use Mediadreams\MdSaml\Authentication\SamlAuthService;
use Mediadreams\MdSaml\Service\SettingsService;
use OneLogin\Saml2\Error;
use OneLogin\Saml2\Settings;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Serves the SAML SP metadata XML for backend and frontend configurations.
 *
 * Triggered by requests that carry both `?loginProvider=<SAML_PROVIDER_ID>` and
 * `?mdsamlmetadata`. The optional `?loginType=frontend|backend` parameter selects
 * which site-set configuration is used to generate the metadata document.
 *
 * Access is restricted to authenticated backend users unless the `publicMetadata`
 * option is enabled in the site-set configuration, which allows unauthenticated
 * access (useful during initial IdP setup when no BE user can log in yet).
 *
 * Registered in the backend middleware stack only.
 */
class SamlMiddleware implements MiddlewareInterface
{
    /**
     * SamlMiddleware constructor
     *
     * @param ResponseFactoryInterface $responseFactory
     * @param SettingsService $settingsService
     */
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        protected SettingsService $settingsService,
        private readonly LoggerInterface $logger
    ){}

    /**
     * Process request
     *
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     * @return ResponseInterface
     * @throws \Error
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $queryParams = $request->getQueryParams();
        if (
            !isset($queryParams['loginProvider'])
            || (int)$queryParams['loginProvider'] !== SamlAuthService::SAML_LOGIN_PROVIDER_ID
            || !isset($queryParams['mdsamlmetadata'])
        ) {
            // not our business, do nothing
            return $handler->handle($request);
        }
        // Normalise the URL-friendly loginType value to the internal context
        // identifier used by SettingsService ('FE' / 'BE'). An empty string
        // is passed through as-is, which causes getSettings() to return [].
        $loginType = $queryParams['loginType'] ?? '';
        if ($loginType === 'frontend') {
            $loginType = 'FE';
        } elseif ($loginType === 'backend') {
            $loginType = 'BE';
        }

        $extSettings = $this->settingsService->getSettings($loginType);
        if ($extSettings === []) {
            $this->logger->error('No md_saml config found. Perhaps you did not include the site set `MdSaml base configuration (ext:md_saml)`.');
            return $handler->handle($request);
        }

        // Restrict metadata output to authenticated BE users, unless publicMetadata
        // is explicitly enabled (e.g. during initial IdP setup).
        if (isset($GLOBALS['BE_USER']->user['uid']) || (bool)($extSettings['publicMetadata'] ?? false)) {
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
                $this->logger->error(
                    'SAML metadata error: {message}',
                    ['message' => $e->getMessage(), 'exception' => $e]
                );
                $response = $this->responseFactory
                    ->createResponse(500)
                    ->withHeader('Content-Type', 'text/plain; charset=utf-8');
                $response->getBody()->write('An error occurred while processing SAML metadata.');
                return $response;
            }
        } else {
            $response = $this->responseFactory->createResponse();

            $response->getBody()->write('Please log into TYPO3!');
            return $response;
        }
    }
}
