# Backend Single Logout — Flow

This document covers two logout flows for backend users who logged in via SAML:

- **SP-initiated SLO** — the user clicks logout in the TYPO3 backend, TYPO3 sends the first `LogoutRequest` to the IdP.
- **IdP-initiated SLO** — the IdP sends a `LogoutRequest` directly to the configured `be_users.saml.sp.singleLogoutService.url` without a prior request from TYPO3.

## IdPs without SLO support

Some IdPs (e.g. Google Workspace) do not provide a Single Logout Service endpoint. When `saml.idp.singleLogoutService.url` is empty in the site configuration, `SettingsService` strips the SLO endpoint keys from the settings before passing them to the SAML library. `SlsBackendSamlMiddleware` detects the missing key and returns `null` immediately, so the standard TYPO3 logout handler runs as it did before v5.0.0 — no SAML protocol round-trip is attempted.

## Step 1 — Intercepting the logout

When the user clicks logout in the TYPO3 backend, `SlsBackendSamlMiddleware` intercepts the `/typo3/logout` route before the standard route dispatcher runs. It checks that the user was originally authenticated via SAML (`md_saml_source = 1`), that backend SAML login is enabled, and that the IdP has an SLO endpoint configured. It then builds a signed SAML `LogoutRequest` containing the user's `NameID` and `SessionIndex` — both stored in the `be_users` record at login time, since TYPO3 does not use PHP sessions.

## Step 2 — Terminating the local session and marking the context

Before redirecting to the IdP, the middleware:

1. **Terminates the local TYPO3 backend session immediately** (`BE_USER->logoff()`). This ensures the user is always logged out of TYPO3 even if the IdP SLO callback never arrives — for example due to a network failure, IdP timeout, or an IdP that nominally supports SLO but does not reliably send the callback.
2. **Clears the SAML session fields** (`md_saml_source`, `md_saml_nameid`, `md_saml_nameid_format`, `md_saml_session_index`) in `be_users`. This prevents a subsequent standard TYPO3 login from leaving stale values that would incorrectly trigger a SAML SLO round-trip on the next logout.
3. Sets a short-lived `HttpOnly` cookie (`md_saml_slo_context=BE`). This is necessary because ADFS does not preserve a custom `RelayState` from the `LogoutRequest`, so there would otherwise be no way to identify the returning callback as a backend SLO.

## Step 3 — IdP processes the logout

The browser follows the redirect to the IdP, which processes the `LogoutRequest` and sends a `LogoutResponse` back to the configured `sp.singleLogoutService.url`. In this setup, that URL points to the TYPO3 frontend — which is why the middleware is registered in both the backend and the frontend stack.

## Step 4 — Processing the callback

The IdP callback arrives at the frontend stack. `SlsBackendSamlMiddleware` (registered in the FE stack) detects the cookie and takes over. `SlsFrontendSamlMiddleware` sees the same cookie and skips processing entirely. The BE middleware calls `processSLO()` with `stay: true` to prevent the library from issuing an `exit()`. Since the session was already terminated in Step 2, `performLogoff()` is a safe no-op. If the IdP returns a non-success status (e.g. ADFS with Windows Integrated Authentication cannot terminate WIA sessions via SAML), the behaviour is unchanged — the user is already logged out of TYPO3.

## Step 5 — Redirect to backend login

After the callback is processed, the cookie is cleared and the user is redirected to the TYPO3 backend login page. The redirect URL is built using `NormalizedParams::getSitePath()` so that sub-directory installations (e.g. `https://www.domain.com/directory/`) are handled correctly — the path prefix is prepended to `typo3/?loginProvider=...`.

## Sequence diagram

```mermaid
sequenceDiagram
    actor User
    participant BE as TYPO3 Backend<br/>(BE Middleware Stack)
    participant FE as TYPO3 Frontend<br/>(FE Middleware Stack)
    participant ADFS as IdP (ADFS)

    User->>BE: GET /typo3/logout?token=...

    note over BE: SlsBackendSamlMiddleware (BE Stack)<br/>route = /logout, md_saml_source = 1 ✓<br/>idp.singleLogoutService present ✓

    alt IdP has no SLO endpoint
        note over BE: idp.singleLogoutService absent<br/>→ return null, standard TYPO3 logout runs
        BE-->>User: Standard TYPO3 logout response
    else IdP supports SLO
        BE->>BE: Auth::logout(nameId, sessionIndex, stay:true)
        BE->>BE: performLogoff() → BE_USER->logoff() (session terminated NOW)
        BE-->>User: 303 + Set-Cookie: md_saml_slo_context=BE<br/>Location: https://adfs/ls/?SAMLRequest=...

        User->>ADFS: GET /adfs/ls/?SAMLRequest=...
        note over ADFS: Processes LogoutRequest<br/>(may return Requester for WIA sessions)
        ADFS-->>User: 302 Location: /index.php?loginProvider=...&sls&SAMLResponse=...

        User->>FE: GET /index.php?...&sls&SAMLResponse=...<br/>Cookie: md_saml_slo_context=BE

        note over FE: SlsBackendSamlMiddleware (FE Stack)<br/>?sls + Cookie=BE detected

        FE->>FE: Auth::processSLO(stay:true)
        note over FE: performLogoff() → no-op, session already gone
        FE-->>User: 303 + Set-Cookie: md_saml_slo_context= (cleared)<br/>Location: [sitePath]typo3/?loginProvider=...

        note over FE: SlsFrontendSamlMiddleware (FE Stack)<br/>?sls + Cookie=BE → skips processing

        User->>BE: GET [sitePath]typo3/?loginProvider=...
        BE-->>User: TYPO3 Backend Login
    end
```

---

## IdP-initiated SLO

In IdP-initiated SLO the IdP sends a `LogoutRequest` directly to the SP without a prior request from TYPO3. This requires `be_users.saml.sp.singleLogoutService.url` to point to a TYPO3 backend URL (e.g. `/typo3/?loginProvider=1648123062&sls`) so that the request arrives in the backend middleware stack.

`SlsBackendSamlMiddleware` detects `?sls` without the `md_saml_slo_context=BE` cookie (which is only set during SP-initiated SLO) and delegates to the `SlsSamlMiddleware` base class. The base class validates the `LogoutRequest`, calls `performLogoff()` to terminate the local session and clear the SAML fields in `be_users`, and lets the onelogin library send a signed `LogoutResponse` back to the IdP via browser redirect.

### Sequence diagram

```mermaid
sequenceDiagram
    actor User
    participant BE as TYPO3 Backend<br/>(BE Middleware Stack)
    participant IdP

    IdP-->>User: 302 Location: /typo3/?loginProvider=...&sls&SAMLRequest=...

    User->>BE: GET /typo3/?loginProvider=...&sls&SAMLRequest=...

    note over BE: SlsBackendSamlMiddleware<br/>?sls, no md_saml_slo_context=BE cookie<br/>→ parent::process() (SlsSamlMiddleware)

    BE->>BE: Auth::processSLO(stay:true)
    note over BE: performLogoff() → BE_USER->logoff()<br/>md_saml_* fields cleared in be_users

    BE-->>User: 302 Location: https://idp/...?SAMLResponse=...

    User->>IdP: GET https://idp/...?SAMLResponse=...
    note over IdP: Processes LogoutResponse
```
