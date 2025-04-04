<?php

use Mediadreams\MdSaml\Middleware\AcsSamlMiddleware;
use Mediadreams\MdSaml\Middleware\SamlMiddleware;
use Mediadreams\MdSaml\Middleware\SlsBackendSamlMiddleware;
use Mediadreams\MdSaml\Middleware\SlsFrontendSamlMiddleware;

return [
    'frontend' => [
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
