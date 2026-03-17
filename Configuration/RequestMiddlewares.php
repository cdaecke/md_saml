<?php

use Mediadreams\MdSaml\Middleware\SamlMiddleware;
use Mediadreams\MdSaml\Middleware\SlsBackendSamlMiddleware;
use Mediadreams\MdSaml\Middleware\SlsFrontendSamlMiddleware;

return [
    'frontend' => [
        // Also registered in the frontend stack to handle BE SLO callbacks when
        // the IdP redirects to a frontend URL (e.g. ADFS with frontend URL in SP metadata).
        // Only processes requests tagged with RelayState 'md_saml_be'.
        'mdsaml/saml-slo-be' => [
            'target' => SlsBackendSamlMiddleware::class,
            'after' => [
                'typo3/cms-frontend/authentication',
            ],
            'before' => [
                'mdsaml/saml-slo',
            ],
        ],
        'mdsaml/saml-slo' => [
            'target' => SlsFrontendSamlMiddleware::class,
            'after' => [
                'typo3/cms-frontend/authentication',
            ],
        ],
    ],

    'backend' => [
        'mdsaml/saml-data' => [
            'target' => SamlMiddleware::class,
            'after' => [
                'typo3/cms-backend/authentication',
            ],
        ],

        'mdsaml/saml-slo' => [
            'target' => SlsBackendSamlMiddleware::class,
            'after' => [
                'typo3/cms-backend/authentication',
            ],
        ],
    ],
];
