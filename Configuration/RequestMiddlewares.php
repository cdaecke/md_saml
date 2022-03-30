<?php

return [
    /*
    'frontend' => [
        'mdsaml/saml-authentication' => [
            'target' => \Mediadreams\MdSaml\Middleware\SamlMiddleware::class,
            'after' => [
                'typo3/cms-frontend/tsfe',
            ],
        ],
    ],
    */
    'backend' => [
        'mdsaml/saml-authentication' => [
            'target' => \Mediadreams\MdSaml\Middleware\SamlMiddleware::class,
            'before' => [
                'typo3/cms-backend/authentication',
            ],
        ],
    ],
];
