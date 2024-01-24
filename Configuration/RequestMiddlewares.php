<?php

use Mediadreams\MdSaml\Middleware\AcsSamlMiddleware;
use Mediadreams\MdSaml\Middleware\SamlMiddleware;

return [
    'frontend' => [
        'mdsaml/saml-data' => [
            'target' => AcsSamlMiddleware::class,
            'before' => [
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
    ],
];
