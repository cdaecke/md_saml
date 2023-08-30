<?php

return [
    'frontend' => [
        'mdsaml/saml-data' => [
            'target' => \Mediadreams\MdSaml\Middleware\AcsSamlMiddleware::class,
            'before' => [
                'typo3/cms-frontend/authentication',
            ],
        ],
    ],
    'backend' => [
        'mdsaml/saml-data' => [
            'target' => \Mediadreams\MdSaml\Middleware\SamlMiddleware::class,
            'after' => [
                'typo3/cms-backend/authentication',
            ],
        ],
    ],
];
