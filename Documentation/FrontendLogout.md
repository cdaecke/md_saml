# Frontend Single Logout (SP-initiated) — Flow

The diagram shows the SP-initiated SLO flow for a frontend user who logged in via SAML.

## IdPs without SLO support

Some IdPs (e.g. Google Workspace) do not provide a Single Logout Service endpoint. When `saml.idp.singleLogoutService.url` is empty in the site configuration, `SettingsService` strips the SLO endpoint keys from the settings before passing them to the SAML library. `SlsFrontendSloInitiatorMiddleware` detects the missing key and passes the request on unchanged — felogin then performs a normal local logout as it did before v5.0.0, without notifying the IdP.

## Step 1 — Intercepting the logout

When the user clicks logout in a felogin form, the browser sends a request with `logintype=logout` (either as a POST body parameter or a GET query parameter). `SlsFrontendSloInitiatorMiddleware` intercepts this request **before** `FrontendUserAuthenticator` runs. This ordering is critical: `FrontendUserAuthenticator` calls `FrontendUserAuthentication::start()`, which processes `logintype=logout` and calls `logoff()` — so the user would already be gone by the time a post-authentication middleware could act.

## Step 2 — Reading the session

Because the request attribute `frontend.user` is not yet populated at this stage, the middleware reads the session directly via `UserSessionManager::create('FE')`. It resolves the session from the FE session cookie, retrieves the user ID, and queries `fe_users` for `md_saml_source`, `md_saml_nameid`, `md_saml_nameid_format`, and `md_saml_session_index`. If the user is not a SAML user (`md_saml_source ≠ 1`) or has no active session, the request is passed on and felogin handles the logout normally.

## Step 3 — Terminating the local session and marking the context

Before redirecting to the IdP, the middleware:

1. **Terminates the local TYPO3 frontend session immediately** via `UserSessionManager::removeSession()`. This ensures the user is always logged out of TYPO3 even if the IdP SLO callback never arrives — for example due to a network failure, IdP timeout, or an IdP that nominally supports SLO but does not reliably send the callback.
2. Sets two short-lived `HttpOnly` cookies:
   - `md_saml_slo_context=FE` — identifies the returning IdP callback as a frontend SLO
   - `md_saml_slo_redirect=<url>` — stores the `Referer` URL so the user can be redirected back to the felogin page after logout

A cookie is used instead of `RelayState` because ADFS does not preserve a custom `RelayState` from the `LogoutRequest`.

## Step 4 — IdP processes the logout

The browser follows the redirect to the IdP, which processes the `LogoutRequest` and sends a `LogoutResponse` back to the configured `sp.singleLogoutService.url`. Because the session was terminated in Step 3, the user is already effectively logged out of TYPO3 at this point.

## Step 5 — Processing the callback

The IdP callback arrives at the frontend stack. `SlsFrontendSloInitiatorMiddleware` sees no `logintype=logout` and passes through. `SlsBackendSamlMiddleware` sees no `md_saml_slo_context=BE` cookie and passes through. `SlsFrontendSamlMiddleware` detects `?sls` combined with the `md_saml_slo_context=FE` cookie and takes over. It calls `processSLO()` with `stay: true` to prevent the library from issuing an `exit()`. Since the session was already terminated in Step 3, `performLogoff()` is a safe no-op. If the IdP returns a non-success status (e.g. ADFS with Windows Integrated Authentication cannot terminate WIA sessions via SAML), the behaviour is unchanged — the user is already logged out of TYPO3.

## Step 6 — Redirect to the felogin page

After the callback is processed, both cookies are cleared and the user is redirected to the URL stored in `md_saml_slo_redirect` — typically the page that contained the felogin form, which now shows the login form again. If no valid redirect URL was stored, the user is sent to `/`.

## Sequence diagram

```mermaid
sequenceDiagram
    actor User
    participant FE as TYPO3 Frontend<br/>(FE Middleware Stack)
    participant ADFS as IdP (ADFS)

    User->>FE: POST/GET logintype=logout<br/>Cookie: fe_typo_user=...

    note over FE: SlsFrontendSloInitiatorMiddleware<br/>(before FrontendUserAuthenticator)<br/>reads session via UserSessionManager<br/>queries fe_users → md_saml_source = 1 ✓

    alt IdP has no SLO endpoint
        note over FE: idp.singleLogoutService absent<br/>→ pass through, felogin handles logout normally
        FE-->>User: Standard felogin logout response
    else IdP supports SLO
        FE->>FE: Auth::logout(nameId, sessionIndex, stay:true)
        FE->>FE: UserSessionManager::removeSession() (session terminated NOW)
        FE-->>User: 303 + Set-Cookie: md_saml_slo_context=FE<br/>Set-Cookie: md_saml_slo_redirect=[referer]<br/>Location: https://adfs/ls/?SAMLRequest=...

        User->>ADFS: GET /adfs/ls/?SAMLRequest=...
        note over ADFS: Processes LogoutRequest<br/>(may return Requester for WIA sessions)
        ADFS-->>User: 302 Location: /index.php?loginProvider=...&sls&SAMLResponse=...

        User->>FE: GET /index.php?...&sls&SAMLResponse=...<br/>Cookie: md_saml_slo_context=FE

        note over FE: SlsFrontendSloInitiatorMiddleware<br/>no logintype=logout → passes through

        note over FE: SlsBackendSamlMiddleware<br/>no md_saml_slo_context=BE → passes through

        note over FE: SlsFrontendSamlMiddleware<br/>?sls + Cookie=FE detected

        FE->>FE: Auth::processSLO(stay:true)
        note over FE: performLogoff() → no-op, session already gone
        FE-->>User: 303 + Set-Cookie: md_saml_slo_context= (cleared)<br/>Set-Cookie: md_saml_slo_redirect= (cleared)<br/>Location: [stored referer URL]

        User->>FE: GET [felogin page]
        FE-->>User: Page with felogin form (logged out)
    end
```
