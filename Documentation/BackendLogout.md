# Backend Single Logout (SP-initiated) — Flow

The diagram shows the SP-initiated SLO flow for a backend user who logged in via SAML.

## Step 1 — Intercepting the logout
When the user clicks logout in the TYPO3 backend, `SlsBackendSamlMiddleware` intercepts the `/typo3/logout` route before the standard route dispatcher runs. It checks that the user was originally authenticated via SAML (`md_saml_source = 1`) and builds a signed SAML `LogoutRequest` containing the user's `NameID` and `SessionIndex` — both stored in the `be_users` record at login time, since TYPO3 does not use PHP sessions.

## Step 2 — Marking the context

Before redirecting to the IdP, the middleware sets a short-lived `HttpOnly` cookie (`md_saml_slo_context=BE`). This is necessary because ADFS does not preserve a custom `RelayState` from the `LogoutRequest`, so there would otherwise be no way to identify the returning callback as a backend SLO.

## Step 3 — IdP processes the logout

The browser follows the redirect to ADFS, which processes the `LogoutRequest` and sends a `LogoutResponse` back to the configured `sp.singleLogoutService.url`. In this setup, that URL points to the TYPO3 frontend — which is why the middleware is registered in both the backend and the frontend stack.

## Step 4 — Processing the callback

The IdP callback arrives at the frontend stack. `SlsBackendSamlMiddleware` (registered in the FE stack) detects the cookie and takes over. `SlsFrontendSamlMiddleware` sees the same cookie and skips processing entirely. The BE middleware calls `processSLO()` with `stay: true` to prevent the library from issuing an `exit()`. If the IdP returns a non-success status (e.g. ADFS with Windows Integrated Authentication cannot terminate WIA sessions via SAML), the local TYPO3 session is terminated regardless.

## Step 5 — Redirect to backend login

After the session is terminated, the cookie is cleared and the user is redirected to the TYPO3 backend login page.

## Sequence diagram

```mermaid
sequenceDiagram
    actor User
    participant BE as TYPO3 Backend<br/>(BE Middleware Stack)
    participant FE as TYPO3 Frontend<br/>(FE Middleware Stack)
    participant ADFS as IdP (ADFS)

    User->>BE: GET /typo3/logout?token=...

    note over BE: SlsBackendSamlMiddleware (BE Stack)<br/>route = /logout<br/>md_saml_source = 1 ✓

    BE->>BE: Auth::logout(nameId, sessionIndex, stay:true)
    BE-->>User: 303 + Set-Cookie: md_saml_slo_context=BE<br/>Location: https://adfs/ls/?SAMLRequest=...

    User->>ADFS: GET /adfs/ls/?SAMLRequest=...
    note over ADFS: Processes LogoutRequest<br/>(may return Requester for WIA sessions)
    ADFS-->>User: 302 Location: /index.php?loginProvider=...&sls&SAMLResponse=...

    User->>FE: GET /index.php?...&sls&SAMLResponse=...<br/>Cookie: md_saml_slo_context=BE

    note over FE: SlsBackendSamlMiddleware (FE Stack)<br/>?sls + Cookie=BE detected

    FE->>FE: Auth::processSLO(stay:true)
    alt Status = Success
        FE->>FE: performLogoff() → BE_USER->logoff()
    else Status = logout_not_success (e.g. WIA)
        FE->>FE: performLogoff() called regardless
    end

    FE-->>User: 303 + Set-Cookie: md_saml_slo_context= (cleared)<br/>Location: /typo3/?loginProvider=...

    note over FE: SlsFrontendSamlMiddleware (FE Stack)<br/>?sls + Cookie=BE → skips processing

    User->>BE: GET /typo3/?loginProvider=...
    BE-->>User: TYPO3 Backend Login
```
