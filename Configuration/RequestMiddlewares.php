<?php

return [
    /*
    'frontend' => [
        'mdsaml/saml-data' => [
            'target' => \Mediadreams\MdSaml\Middleware\SamlMiddleware::class,
            'after' => [
                'typo3/cms-frontend/tsfe',
            ],
        ],
    ],
    */
    'backend' => [
        'mdsaml/saml-data' => [
            'target' => \Mediadreams\MdSaml\Middleware\SamlMiddleware::class,
            'after' => [
                'typo3/cms-backend/authentication',
            ],
        ],
    ],
];
