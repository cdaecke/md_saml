<?php

use Mediadreams\MdSaml\Middleware\SamlMiddleware;
use Mediadreams\MdSaml\Middleware\SlsBackendSamlMiddleware;
use Mediadreams\MdSaml\Middleware\SlsFrontendSamlMiddleware;
use Mediadreams\MdSaml\Middleware\SlsFrontendSloInitiatorMiddleware;

return [
    'frontend' => [
        // SP-initiated FE SLO — initiator.
        // Intercepts logintype=logout (GET or POST) for SAML-authenticated FE users
        // and redirects to the IdP before FrontendUserAuthenticator can process the
        // logout. Running before typo3/cms-frontend/authentication is critical:
        // FrontendUserAuthentication::start() calls logoff() when it sees
        // logintype=logout, destroying user data before any later middleware can act.
        // The session is therefore read directly via UserSessionManager and fe_users
        // is queried for md_saml_source, NameID, and SessionIndex.
        // Sets md_saml_slo_context=FE and md_saml_slo_redirect cookies.
        'mdsaml/saml-slo-fe-init' => [
            'target' => SlsFrontendSloInitiatorMiddleware::class,
            'before' => [
                'typo3/cms-frontend/authentication',
            ],
        ],

        // BE SLO callback handler — registered in the frontend stack.
        // ADFS (and other IdPs) redirect the SLO callback to the URL configured in
        // sp.singleLogoutService, which in many setups points to a frontend URL
        // (/index.php?loginProvider=...&sls) even for backend SLO flows.
        // This entry ensures SlsBackendSamlMiddleware can intercept those callbacks
        // (identified by the md_saml_slo_context=BE cookie) and process them with
        // BE settings. Must run before mdsaml/saml-slo so SlsFrontendSamlMiddleware
        // does not attempt to handle a BE SAMLResponse with FE settings.
        'mdsaml/saml-slo-be' => [
            'target' => SlsBackendSamlMiddleware::class,
            'after' => [
                'typo3/cms-frontend/authentication',
            ],
            'before' => [
                'mdsaml/saml-slo',
            ],
        ],

        // FE SLO callback handler + ACS redirect.
        // Handles three cases after typo3/cms-frontend/authentication has run:
        // 1. ACS callback (?acs): redirects to the RelayState URL after a successful
        //    FE SAML login (felogin cannot do this itself).
        // 2. FE SLO callback (?sls + md_saml_slo_context=FE): validates the
        //    SAMLResponse, terminates the local FE session, clears both SLO cookies,
        //    and redirects to the stored md_saml_slo_redirect URL.
        // 3. IdP-initiated SLO (?sls, no context cookie): delegates to the
        //    SlsSamlMiddleware base class which calls processSLO() and passes through.
        // Skips entirely when md_saml_slo_context=BE is present (handled above).
        'mdsaml/saml-slo' => [
            'target' => SlsFrontendSamlMiddleware::class,
            'after' => [
                'typo3/cms-frontend/authentication',
            ],
        ],
    ],

    'backend' => [
        // SP metadata endpoint.
        // Serves the SP metadata XML for ?loginProvider=...&mdsamlmetadata requests.
        // Access is restricted to authenticated BE users unless publicMetadata is
        // enabled in the site-set configuration.
        'mdsaml/saml-data' => [
            'target' => SamlMiddleware::class,
            'after' => [
                'typo3/cms-backend/authentication',
            ],
        ],

        // SP-initiated BE SLO — initiator and callback handler.
        // Intercepts /typo3/logout for SAML BE users (md_saml_source=1), builds a
        // signed LogoutRequest with NameID and SessionIndex from be_users, sets the
        // md_saml_slo_context=BE cookie, and redirects to the IdP. The returning
        // SAMLResponse callback is handled here if the IdP redirects to a backend
        // URL, or by mdsaml/saml-slo-be in the frontend stack if the IdP redirects
        // to a frontend URL (as is common with ADFS).
        'mdsaml/saml-slo' => [
            'target' => SlsBackendSamlMiddleware::class,
            'after' => [
                'typo3/cms-backend/authentication',
            ],
        ],
    ],
];
