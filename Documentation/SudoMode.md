# Sudo Mode for SAML-authenticated Backend Users

## Background

Since TYPO3 13.4.13 (security fix TYPO3-CORE-SA-2025-013), TYPO3 requires
backend users to re-enter their password before performing certain elevated
actions — for example editing their own profile or changing their password.
This mechanism is called **sudo mode**.

SAML users authenticate via an external Identity Provider and have no TYPO3
password. Without intervention, the sudo mode dialog would permanently block
them from those actions with no way to proceed.

TYPO3 addresses this by providing `SudoModeRequiredEvent` (Feature #106743),
which allows external authentication extensions to skip the password dialog for
their users.

## How md_saml handles it

`SudoModeVerifyEventListener` listens to `SudoModeRequiredEvent` and calls
`setVerificationRequired(false)` when the current backend user has
`md_saml_source = 1` — i.e. was authenticated via SAML. Users without that
flag go through the normal TYPO3 verification flow unchanged.

On TYPO3 < 13.4.13 the event class does not exist and is never dispatched. The
listener is a no-op in that case: SAML users will still encounter the password
dialog (which they cannot complete), but TYPO3 will not crash.

## Security implications

Sudo mode exists as a **"proof of presence"** check. By bypassing it for SAML
users, the following protections are reduced:

| Threat | Normal TYPO3 user | SAML user (md_saml) |
|---|---|---|
| Stolen session cookie | Attacker cannot perform elevated actions without the password | Attacker with a valid session can perform elevated actions |
| Unattended browser session | A bystander cannot perform elevated actions without the password | A bystander can perform elevated actions if the session is active |
| CSRF targeting elevated actions | Blocked by the password re-entry requirement | Not blocked for SAML users |

### Why this trade-off is acceptable

- SAML users have already authenticated through the IdP, which may enforce its
  own session timeouts, MFA policies, and anomaly detection.
- The alternative — leaving the dialog in place — permanently locks SAML users
  out of standard backend actions with no workaround.
- TYPO3 itself introduced `SudoModeRequiredEvent` specifically to enable this
  pattern for SSO providers (see Feature #106743 in the TYPO3 changelog).
- The bypass is scoped strictly to `md_saml_source = 1`; non-SAML users are
  unaffected.

### Recommendations

- Configure your IdP to enforce **short session lifetimes** and **MFA** for
  users who have backend access in TYPO3.
- Enable **`wantAssertionsSigned`** in `settings.yaml` to ensure the IdP
  cryptographically signs every assertion (see the security note in
  `settings.yaml`).
- Keep TYPO3 and md_saml up to date to benefit from future security
  improvements.
