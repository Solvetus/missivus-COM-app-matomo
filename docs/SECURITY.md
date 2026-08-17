# Missivus — security review

Review performed 2026-08-17 against the v0.1.1 tree, after the first live deployment.

Scope, as commissioned: the Graph transport, the settings model, the `Missivus.sendTestEmail` API
method, and the Vue component — audited for **secret leakage** through logs, API responses, HTML
source and the browser console; **authentication and authorisation** on the API method;
**CSRF** on the test call; **input validation** on every setting; and whether **Graph error bodies**
can reach anyone but a superuser.

Everything marked *(verified)* was checked against Matomo's own source at
`matomo-org/matomo@5.x-dev`, not recalled. Nothing in this document contains a credential.

---

## Summary

| # | Finding | Severity | Status |
| --- | --- | --- | --- |
| 1 | A base-URL override could aim the client secret at any host, over plain HTTP | Medium | **Fixed** |
| 2 | A network failure mid-upload escaped as an unhandled exception, carrying a pre-authenticated upload URL | Low–Medium | **Fixed** |
| 3 | No input validation on any setting | Low | **Fixed** |
| 4 | `sendTestEmail` accepted any string as a recipient, and was reachable by GET | Low | **Fixed** |
| 5 | Secrets in HTML source / JS console | — | Verified clean |
| 6 | CSRF on the test call | — | Verified protected, plus a second lock added |
| 7 | `token_auth` and superuser gating; Graph error bodies | — | Verified correct |
| 8 | Secret redaction in logs and exceptions | — | Verified correct |
| 9 | The Graph access token is cached in Matomo's file-backed cache | Low | **Accepted, documented** |
| 10 | A superuser can send test mail to any address, unthrottled | Low | **Accepted, documented** |
| 11 | A DB-stored client secret is stored in plaintext in the `option` table | Low | **Mitigated, not eliminated** |

---

## Fixed

### 1. A base-URL override could aim credentials at any host — Medium

`graph_base_url` and `login_base_url` are overridable from `[Missivus]` in `config.ini.php` or from
`MISSIVUS_*` environment variables, so that sovereign clouds and the test suite work. Neither value
was validated. `login_base_url = "http://attacker.example"` would have sent the client secret, in a
form-encoded body, in clear text, to that host — and `graph_base_url` would have done the same with
a live bearer token.

Writing `config.ini.php` already implies owning the installation, so this is not a privilege
escalation on its own. It matters because the environment-variable tier can be set by anything that
can influence the PHP process environment, which is a wider set than "can edit Matomo's config", and
because a typo (`http` for `https`) silently downgraded the credential to clear text with no
warning.

**Fix.** New `Solvetus\Missivus\Endpoint::normalise()`, applied in the `TokenProvider` and
`GraphMailer` constructors — the two places a base URL turns into a request. Anything that is not a
bare `https` origin (no `http`, no embedded credentials, no query string, no malformed host) throws
a `GraphException` *before* any request is built. Because the constructors run inside
`GraphTransport::deliver()`, the refusal follows the normal failure policy: logged at error level,
and either surfaced or handed to the fallback. Eight tests in `tests/Unit/EndpointTest.php`,
including two that assert nothing left the process.

### 2. A mid-upload network failure escaped unhandled and named the upload URL — Low–Medium

In the large-attachment path, `GraphMailer::uploadLargeAttachment()` called `$this->http->put()`
directly. Every other call site wraps the HTTP adapter, which throws `\RuntimeException` on a
transport failure, and converts it into a redacted `GraphException`. This one did not, with two
consequences:

* `GraphTransport::send()` catches `GraphException`. A `RuntimeException` sailed past it, so the
  "fall back to Matomo's own transport" setting was silently skipped for that failure mode, and the
  operator got an unhandled exception instead of a logged, classified failure.
* The exception message from `Piwik\Http` can contain the URL being fetched. That URL is the Graph
  **upload session URL**, which is pre-authenticated: anyone holding it can write to that draft
  message without a token. It would have landed in the Matomo log in clear.

**Fix.** A `GraphMailer::put()` wrapper mirroring the existing `post()` one: it converts the
transport failure into a `GraphException` and masks the upload URL out of the message. `Redactor`
also now blanks a `"uploadUrl": "…"` field in any JSON body it is given, so an echoed Graph response
cannot reintroduce it. Covered by `testATransportFailureWhileUploadingIsAGraphFailureAndKeepsTheUploadUrlOut`
and `testAnUploadUrlIsBlankedBecauseItIsItselfACredential`.

### 3. No input validation on any setting — Low

Every field accepted any string. The tenant ID goes into a URL path, the sender mailbox goes into a
URL path *and* becomes the `From` on every email Matomo sends, and the certificate path is opened
from disk. `rawurlencode()` was already doing the injection-prevention work at the point of use, so
this was not exploitable — but a garbage value failed later, at Microsoft, as an opaque `AADSTS…`
code, which is a bad way to learn you pasted the Secret ID instead of the Secret Value.

**Fix.** `FieldConfig::$validate` on every field that takes one *(verified: `core/Settings/FieldConfig.php`
documents this closure)*:

| Setting | Rule |
| --- | --- |
| `tenantId` | GUID, or a DNS domain (`contoso.onmicrosoft.com`) |
| `clientId` | GUID |
| `senderMailbox` | `Piwik::isValidEmailString()` |
| `certificatePath` | absolute path, no NUL byte, and readable right now |
| `clientSecret`, `certificatePassphrase` | ≤ 1024 bytes, no whitespace or control characters — which is always a copy-paste accident, and one that is miserable to diagnose because nothing is allowed to print the value back |

Emptiness is never an error: the plugin's master switch is off by default precisely so a half-filled
settings page is a legitimate state.

### 4. `sendTestEmail` took any recipient, and answered a GET — Low

`$to` was passed to `Mail::addTo()` after nothing more than a `trim()`, and the method — like every
Matomo API method — answered a GET as readily as a POST.

**Fix.** The recipient is now rejected unless `Piwik::isValidEmailString()` accepts it, and the
method refuses anything but an HTTP POST when not running under the console. The Vue component now
uses `AjaxHelper.post()` with the recipient in the request body rather than the query string, so the
address also stops appearing in web-server access logs. See finding 6 for why the POST rule is a
second lock rather than the first one.

---

## Verified clean

### 5. Secrets in the HTML source, the API response, or the JS console

`clientSecret` and `certificatePassphrase` are declared `FieldConfig::UI_CONTROL_PASSWORD`. Matomo
serialises system settings for the browser through `SettingsMetadata::formatSettings()`, which
replaces the value of any password-controlled field with the literal `******` *(verified:
`plugins/CorePluginsAdmin/SettingsMetadata.php` — the placeholder is substituted on the way out, and
a submitted value equal to the placeholder is skipped on the way back in, so a save does not blank
the stored secret)*. So the real value never reaches the page, the DOM, the JSON response, or
anything the console can read.

Two plugin-side guards sit behind that, so the property does not depend on core alone:

* When a key is overridden in `config.ini.php` or the environment, `SystemSettings` renders the field
  disabled and installs a `transform` that returns the previously stored value — a save from the UI
  cannot copy a file-managed secret into the database.
* `Auth\Credentials` neuters `__toString()` and `__debugInfo()`, so a `var_dump`, a `print_r`, or a
  stack trace (PHP puts constructor arguments in traces) renders `credential=redacted`.

The Vue component itself never handles a secret: it sends a recipient address and renders a status
string.

### 6. CSRF on `Missivus.sendTestEmail`

Matomo does not authenticate `module=API` requests from the session cookie. `FrontController::makeSessionAuthenticator()`
returns `null` when the module is `API` and the action is absent or `index` *(verified,
`core/FrontController.php`)*, so an API call must present a `token_auth`. The UI supplies it from
`Matomo.token_auth`, a value printed into the page and readable only by same-origin script
*(verified, `plugins/CoreHome/vue/src/AjaxHelper/AjaxHelper.ts` — every API request gets
`token_auth` plus `force_api_session=1` as POST fields)*. An attacker's page can make the browser
issue the request, but cannot read the token, so the call arrives unauthenticated and dies at
`Piwik::checkUserHasSuperUserAccess()`.

That is the real protection and it is core's, not ours. The POST-only rule added in finding 4 is
defence in depth: sending mail is a state change, and a state change should not be reachable by a
URL that a browser can be talked into visiting — an `<img src>` or a stray link. Note that every
`AjaxHelper` request is already an HTTP POST *(verified, `buildAjaxCall()` sets `type: 'POST'`)*, so
the rule costs the UI nothing.

### 7. `token_auth`, superuser gating, and who sees a Graph error body

Both API methods — `sendTestEmail` and the new `getTestEmailStatus` — call
`Piwik::checkUserHasSuperUserAccess()` as their first statement. They are reachable over `token_auth`
like any Matomo API method, and that token must belong to a superuser.

The Graph error body is deliberately returned to the caller, because it is the single most useful
thing for diagnosing a broken app registration — and it is returned *only* from behind that
superuser check. It is redacted first (finding 8). The same string is written to Matomo's log at
error level, which is a filesystem-level disclosure to whoever can already read the server's logs.

`getTestEmailStatus` returns a configuration *problem description* ("missing sender mailbox",
"Missivus is switched off"). It names no value, and it too is superuser-only.

### 8. Redaction on the way to a log or an exception

Every string Missivus logs or puts in an exception passes through `Redactor` first, which works in
two layers: the literal secrets we hold are blanked wherever they appear — which catches a server
echoing a submitted value back at us — and shape matching blanks `access_token` / `client_secret` /
`client_assertion` / `uploadUrl` JSON fields, form-encoded credentials, `Bearer` headers, and bare
JWTs, which catches values we were never given. A `preg_replace` failure (a huge body hitting the
backtrack limit) returns the mask rather than the input: it fails closed. Bodies are truncated at
2 KB before redaction.

`Adapter\MatomoLogger` passes the message as a PSR-3 context value rather than interpolating it, so
a brace inside a Graph error body cannot be mistaken for a placeholder.

Nine tests in `RedactorTest` hold this, including one where an Entra error deliberately echoes our
own secret back.

---

## Accepted, and not fully fixed

### 9. The access token is cached in Matomo's cache backend

`Adapter\MatomoTokenCache` stores the Graph bearer token in `Piwik\Cache::getLazyCache()`, which is
by default a file-backed cache under Matomo's `tmp/` directory. Anyone who can read files as the web
server user can read a live token, and that token can send mail as the shared mailbox until it
expires.

Not fixed, and here is the reasoning rather than a shrug: the alternative stores are the `option`
table (same exposure, different medium, plus it survives longer) or no cache at all (a token
round-trip to Microsoft on every single email, which is worse for both reliability and rate limits).
The mitigations that do apply are real — the token lives at most 55 minutes, it is scoped by the
Exchange application access policy to one mailbox, and Matomo's `tmp/` is not web-readable in a
correct install. **Anyone who can read that directory can already read `config/config.ini.php`,
which may hold the client secret itself**, so the token is not the weakest link in that scenario.

Operator action: keep `tmp/` unreadable to other users on the host (`chmod 750`, owned by the web
server user), which Matomo's own System Check already asks for.

### 10. Test emails are unthrottled

A superuser can click **Send test email** repeatedly, to any address, and each click sends a message
from the shared mailbox. The subject and body are fixed, the recipient is the only attacker-supplied
part, and the actor must already be a superuser — who could in any case reconfigure Matomo's mail
settings outright. It is therefore a nuisance vector, not a privilege one, and no rate limit was
added. Exchange Online's own outbound throttling is the backstop. Worth revisiting if the plugin ever
gains a non-superuser path to sending.

### 11. A client secret stored in the database is stored in clear

If the operator enters the client secret in the settings page rather than in `config.ini.php` or the
environment, it is stored in Matomo's `option` table as plain text. Matomo's settings storage offers
no at-rest encryption, and inventing one here would be a false comfort: the key would have to live
next to the data.

Mitigated rather than eliminated: the `[Missivus]` config-file and `MISSIVUS_*` environment tiers
exist precisely so the secret need never touch the database, they take precedence over it, and when
one is present the UI refuses to write a secret to the DB at all. `docs/index.md` documents that
route, and certificate authentication — where nothing secret is stored by Matomo at all, only a path
— remains the strongest option.

---

## Not in scope, worth stating

* **The Exchange application access policy is what bounds the blast radius.** Every finding above is
  contained by it: even full compromise of the credential only permits sending as one shared mailbox.
  `docs/index.md` Part 5 treats it as a required step, with a verification command, for that reason.
* **`enable_plugin_upload`.** `docs/index.md` Part 6 now documents the UI upload route and asks the
  operator to switch the setting back off afterwards, because it is a standing remote-code-execution
  path for any superuser session.
* **Transport security to Microsoft.** All requests go through `Piwik\Http`, so Matomo's CA bundle,
  proxy configuration and TLS behaviour apply; `acceptInvalidSslCertificate` is passed as `false`,
  and after finding 1 the scheme can no longer be anything but `https`.

## Reporting a vulnerability

Email <hello@solvetus.com> with the details. Please do not open a public issue for a security
problem until it has been fixed.
