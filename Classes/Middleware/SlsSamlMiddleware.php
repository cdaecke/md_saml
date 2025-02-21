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
use OneLogin\Saml2\Auth;
use OneLogin\Saml2\Error;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Log\LogLevel;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class SlsSamlMiddleware
 * Do not call directly, but extend this class and set the `$context`!
 */
class SlsSamlMiddleware implements MiddlewareInterface
{

    protected string $context = '';

    /**
     * SlsSamlMiddleware constructor
     *
     * @param SettingsService $settingsService
     */
    public function __construct(protected SettingsService $settingsService)
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
            && isset($_REQUEST['sls'])
        ) {
            $extSettings = $this->settingsService->getSettings($this->context);
            $auth = new Auth($extSettings['saml'], true);
            $auth->processSLO();
            $errors = $auth->getErrors();

            if (!empty($errors)) {
                $logger = GeneralUtility::makeInstance(LogManager::class)
                    ->getLogger(self::class);

                $logger->log(
                    LogLevel::ERROR,
                    'SAML logout error in SlsSamlMiddleware',
                    [
                        'context' => $this->context,
                        'errors' => $errors,
                        'exception' => $auth->getLastErrorException(),
                    ]
                );
            }
        }

        return $handler->handle($request);
    }
}
