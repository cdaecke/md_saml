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
use OneLogin\Saml2\Auth;
use OneLogin\Saml2\Error;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Class SlsSamlMiddleware
 * Do not call directly, but extend this class and set the `$context`!
 */
abstract class SlsSamlMiddleware implements MiddlewareInterface
{

    protected string $context = '';

    /**
     * SlsSamlMiddleware constructor
     *
     * @param SettingsService $settingsService
     */
    public function __construct(
        protected SettingsService $settingsService,
        protected readonly LoggerInterface $logger
    ) {}

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
        $queryParams = $request->getQueryParams();
        // loginProvider is optional: IdP-initiated SLO callbacks may not include it,
        // and SP-initiated SLO via BeforeUserLogoutListener redirects back without it.
        // If loginProvider is present it must match our provider ID.
        if (
            isset($queryParams['sls'])
            && (
                !isset($queryParams['loginProvider'])
                || (int)$queryParams['loginProvider'] === SamlAuthService::SAML_LOGIN_PROVIDER_ID
            )
        ) {
            $extSettings = $this->settingsService->getSettings($this->context);
            $auth = new Auth($extSettings['saml'], true);
            // retrieveParametersFromServer=true uses $_SERVER['QUERY_STRING'] directly
            // instead of reconstructing the query string from $_GET. This preserves the
            // exact URL encoding that the IdP (e.g. ADFS) used when computing the signature,
            // preventing "Signature validation failed" errors caused by encoding differences.
            $auth->processSLO(retrieveParametersFromServer: true, cbDeleteSession: fn() => $this->performLogoff($request));
            $errors = $auth->getErrors();

            if ($errors !== []) {
                $this->logger->error(
                    'SAML logout error in SlsSamlMiddleware',
                    [
                        'context' => $this->context,
                        'errors' => $errors,
                        'lastErrorReason' => $auth->getLastErrorReason(),
                        'exception' => $auth->getLastErrorException(),
                    ]
                );
            }
        }

        return $handler->handle($request);
    }

    abstract protected function performLogoff(ServerRequestInterface $request): void;
}
