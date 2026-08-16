# Missivus for Matomo — Implementation Plan

Spec: [docs/BRIEF.md](docs/BRIEF.md). This plan is the engineering answer to it. Every "closed
decision" in the brief is carried through unchanged; where research produced a fact the brief did
not have (notably the Graph permission needed by the large-attachment path), it is called out
explicitly under [Open items for the owner](#12-open-items-for-the-owner).

Everything below marked *(verified)* was checked empirically against the sources cited on
2026-08-16, not recalled.

---

## 1. What this plugin does

Matomo sends every outbound email through `Piwik\Mail::send()`, which resolves a transport out of
the DI container and hands it the `Piwik\Mail` object. Missivus replaces that transport with one
that POSTs to the Microsoft Graph `sendMail` endpoint using OAuth2 **client credentials** and the
**Mail.Send application** permission, sending as one configured shared mailbox. No user login, no
delegated OAuth, no SMTP.

Nothing else in Matomo changes: password resets, scheduled report PDFs, and alerts all flow through
`Piwik\Mail`, so they all go out over Graph automatically once the transport is swapped.

---

## 2. Architecture

Two layers, split on the portability boundary the brief mandates.

### 2.1 Portable layer — `libs/Solvetus/Missivus/` (namespace `Solvetus\Missivus`)

Dependency-free PHP. Knows nothing about Matomo, WordPress, PSR, or Composer. Depends only on the
`json` and `openssl` extensions. This is the directory the WordPress plugin vendors **unchanged**.

| Class | Responsibility |
| --- | --- |
| `Contract\HttpClientInterface` | The 2-method HTTP seam: `postJson()`, `putBinary()`. |
| `Contract\HttpResponse` | Value object: `status`, `body`, `headers`. |
| `Contract\TokenCacheInterface` | `get(key)` / `set(key, value, ttlSeconds)`. |
| `Contract\LoggerInterface` | `error()` / `warning()` / `info()`. Nulled by default. |
| `Exception\GraphException` | Carries HTTP status + the Graph error body (already redacted). |
| `Auth\Credentials` | Immutable tenant/client/secret-or-cert holder. `__toString`/`__debugInfo` neutered so a var_dump or stack trace can never leak. |
| `Auth\TokenProvider` | Client-credentials token acquisition + cache + refresh. Secret and certificate paths. |
| `Auth\ClientAssertion` | Builds and signs the certificate JWT (PS256, RS256 escape hatch). |
| `Message` | Transport-neutral message: from, to, cc, bcc, replyTo, subject, html, text, attachments. |
| `Attachment` | name, mimeType, bytes, `contentId` (inline) — plus `isLarge()`. |
| `GraphMailer` | The transport. Chooses the direct or draft+upload path, builds the Graph JSON, sends. |

The public entry point is one method:

```php
$mailer = new GraphMailer($credentials, $httpClient, $tokenCache, $logger, $options);
$mailer->send(Message $message);   // void; throws GraphException on any failure
```

### 2.2 Matomo layer — plugin root (namespace `Piwik\Plugins\Missivus`)

| Class | Responsibility |
| --- | --- |
| `Missivus` (`Missivus.php`) | The `Piwik\Plugin` subclass. Registers the autoloader and the Vue asset. |
| `SystemSettings` | The settings page. |
| `Config\Settings` | Resolves effective config: `config.ini.php [Missivus]` wins over the DB. Single source of truth for both the transport and the settings UI. |
| `Adapter\MatomoHttpClient` | `HttpClientInterface` over `Piwik\Http::sendHttpRequestBy()`. |
| `Adapter\MatomoTokenCache` | `TokenCacheInterface` over `Piwik\Cache::getLazyCache()`. |
| `Adapter\MatomoLogger` | `LoggerInterface` over Matomo's `Piwik\Log\LoggerInterface`. |
| `Mail\GraphTransport` | **The DI seam target.** Extends `Piwik\Mail\Transport`, maps `Piwik\Mail` → `Message`, calls `GraphMailer`, handles forced-From, fallback, and logging. |
| `API` | `Missivus.sendTestEmail`. |

### 2.3 Autoloading the portable namespace

Matomo autoloads `Piwik\Plugins\<Name>\*` from the plugin directory, and has **no** support for a
per-plugin `vendor/autoload.php` or `Bootstrap.php` *(verified: no such reference exists in
`core/Plugin/Manager.php` or `core/Plugin.php` on 5.x-dev)*. So the plugin ships a 12-line
`libs/autoload.php` that `spl_autoload_register`s the `Solvetus\Missivus\` prefix onto `libs/`.

It is `require_once`d from **two** places, because either can be reached first:

* `config/config.php` — evaluated by `ContainerFactory` when the DI container is built.
* `Missivus.php` — loaded by the plugin manager.

`require_once` makes the double call free. No Composer, no generated map.

---

## 3. DI wiring — the PR #14041 seam

`Piwik\Mail::send()` ends with *(verified, `core/Mail.php:297` on 5.x-dev)*:

```php
return StaticContainer::get('Piwik\Mail\Transport')->send($mail);
```

The container is asked for the **string key** `'Piwik\Mail\Transport'`. There is no binding for it
in `config/global.php` *(verified: `config/global.php` contains no `mail` or `transport` entry)*, so
PHP-DI autowires the concrete class. That string key is the seam introduced by
[matomo-org/matomo#14041](https://github.com/matomo-org/matomo/pull/14041) ("Add possibility to
change mail transport through DI", milestone 3.9.0).

`ContainerFactory::addPluginConfigs()` loads `plugins/<Plugin>/config/config.php` for every
activated plugin *(verified, `core/Container/ContainerFactory.php:157-160`)*. So Missivus overrides
the key from its own config file — no core edit, no monkey-patch:

```php
// plugins/Missivus/config/config.php
require_once __DIR__ . '/../libs/autoload.php';

return [
    'Piwik\Mail\Transport' => Piwik\DI::autowire(
        Piwik\Plugins\Missivus\Mail\GraphTransport::class
    ),
];
```

**Why `GraphTransport extends Piwik\Mail\Transport`:** the container key is a concrete class name,
so anything type-hinting `Piwik\Mail\Transport` still receives a valid instance; and the optional
fallback is then literally `parent::send($mail)` — the stock PHPMailer path, unmodified, with no
second container lookup and no risk of infinite recursion.

**Deactivation is automatic.** `getActivatedPlugins()` gates the config load, so deactivating
Missivus restores the stock transport with zero cleanup.

---

## 4. Settings model

### 4.1 Fields

| Setting | Type / control | Stored where | Notes |
| --- | --- | --- | --- |
| `enabled` | bool / checkbox | DB | Master switch. Default **off** — installing the plugin must not break mail before it is configured. |
| `tenantId` | string / text | DB or `[Missivus] tenant_id` | Directory (tenant) ID. |
| `clientId` | string / text | DB or `[Missivus] client_id` | Application (client) ID. |
| `authMethod` | string / select | DB or `[Missivus] auth_method` | `certificate` (recommended) or `secret`. |
| `clientSecret` | string / **password** | DB or `[Missivus] client_secret` | Write-only. Never rendered, never logged. |
| `certificatePath` | string / text | DB or `[Missivus] certificate_path` | Absolute path to a PEM holding the private key **and** the certificate. |
| `certificatePassphrase` | string / **password** | DB or `[Missivus] certificate_passphrase` | Optional. Write-only. |
| `senderMailbox` | string / email | DB or `[Missivus] sender_mailbox` | The shared mailbox. Becomes `/users/{sender}`. |
| `saveToSentItems` | bool / checkbox | DB | Default **off** — a no-reply mailbox filling its Sent Items is noise. |
| `fallbackToDefault` | bool / checkbox | DB | **Default off**, per brief. |
| `graphBaseUrl` | string | `[Missivus] graph_base_url` only | Sovereign-cloud / test override. Default `https://graph.microsoft.com`. |
| `loginBaseUrl` | string | `[Missivus] login_base_url` only | Default `https://login.microsoftonline.com`. |

`sendTestEmail` is not a setting — it is a `FieldConfig::TYPE_STRING` field whose
`customFieldComponent` renders the Vue button (§7).

### 4.2 The config-file override

`Config\Settings` is the only thing that reads config. For each overridable key:

```
value = config.ini.php [Missivus] <key>   if that key is present and non-empty
      = SystemSettings DB value           otherwise
```

`FieldConfig::UI_CONTROL_TEXT`/`PASSWORD` fields whose key is present in `[Missivus]` are rendered
**disabled**, with the title suffixed by `Missivus_SetInConfigFile` ("set in config file — edit
`config/config.ini.php` to change"), and the value shown as empty. The real value is never sent to
the browser.

`SystemSettings` also installs a `transform` on the two secret fields: when a config-file override
exists for that key, the transform returns the **existing stored value unchanged**, so a save from
the UI cannot write a secret into the `option` table. That satisfies the brief's *"no secret is ever
written to the option table when a file override exists"*.

Secrets are additionally read through `Credentials`, whose `__debugInfo()` and `__toString()` return
redacted placeholders, so no `var_dump`, `print_r`, or uncaught-exception trace can leak them.

### 4.3 Environment variables

Matomo's config layer resolves `[Section] key = "..."` only; it has **no** generic env-var
interpolation. Rather than invent one, `Config\Settings` reads `getenv('MISSIVUS_' . strtoupper($key))`
as a **third** tier, checked *before* the config file. That is 4 lines, is the standard 12-factor
shape, and matches the brief's "and env vars if Matomo's config layer allows it" — it allows it at
the plugin level even though core does not.

Precedence, highest first: **env var → `config.ini.php [Missivus]` → SystemSettings DB.**

---

## 5. Auth flow

`TokenProvider::getToken()`:

1. `TokenCacheInterface::get($cacheKey)` where `$cacheKey = 'missivus.token.' . sha1(tenantId . '|' . clientId . '|' . authMethod)`. Hit → return. The token is cached with a TTL of `expires_in - 300` seconds, so it is refreshed five minutes before Microsoft expires it (same margin as `solvetus-COM-www/src/worker.js`).
2. Miss → POST `{loginBaseUrl}/{tenantId}/oauth2/v2.0/token`, `Content-Type: application/x-www-form-urlencoded`.
3. Non-200, or a body with no `access_token` → throw `GraphException`. The Entra error body is included **after** redaction (any `client_secret`/`client_assertion`/`access_token` substring is replaced with `***`).
4. Cache, return.

The cached entry holds only the access token and its expiry. Refresh tokens do not exist in the
client-credentials grant — expiry simply triggers a new request.

**One retry on 401.** If Graph returns 401 on a send, `GraphMailer` invalidates the cache entry once
and retries exactly once. This covers a token revoked mid-life. A second 401 throws.

### 5.1 Client secret

```
grant_type=client_credentials
client_id={clientId}
client_secret={secret}
scope=https://graph.microsoft.com/.default
```

### 5.2 Certificate

```
grant_type=client_credentials
client_id={clientId}
scope=https://graph.microsoft.com/.default
client_assertion_type=urn:ietf:params:oauth:client-assertion-type:jwt-bearer
client_assertion={JWT}
```

The JWT is built by `Auth\ClientAssertion` *(field list verified against Microsoft's current
certificate-credentials reference, updated 2026-06-15)*:

Header
```json
{ "alg": "PS256", "typ": "JWT", "x5t#S256": "<base64url( sha256( DER of the certificate ) )>" }
```

Payload
```json
{
  "aud": "https://login.microsoftonline.com/{tenantId}/oauth2/v2.0/token",
  "iss": "{clientId}",
  "sub": "{clientId}",
  "jti": "<random v4 uuid>",
  "iat": <now>,
  "nbf": <now>,
  "exp": <now + 300>
}
```

Signature: RSASSA-PSS with SHA-256 and a salt length equal to the hash length.

**Implementation note.** PHP's `openssl_sign()` cannot produce PSS — it only does PKCS#1 v1.5. So
`ClientAssertion` performs EMSA-PSS-ENCODE itself (RFC 8017 §9.1.1) and applies the raw RSA
primitive via `openssl_private_encrypt(..., OPENSSL_NO_PADDING)`. This is ~50 lines and uses only
`openssl` + `hash`, both already required. It is verified in the test suite **against the `openssl`
CLI**, which is an independent implementation — see §8.

Microsoft's current reference says `alg` *should* be PS256. RS256 (plain `openssl_sign`) is still
accepted by Entra and is what most SDKs sent historically. Because this integration cannot be
tested against a real tenant inside this repo, a config-only escape hatch exists:
`[Missivus] certificate_algorithm = "RS256"` switches to RS256 with an `x5t` (base64url SHA-1)
header. It is documented in INSTALL.md as *"only if Entra rejects the assertion"*. There is no UI
for it — it is deliberately not a normal knob.

The PEM is read from `certificatePath` with `openssl_pkey_get_private()` (passphrase optional) and
the certificate block extracted with `openssl_x509_read()`. A path that does not exist, is not
readable, or does not parse throws a `GraphException` naming **the path only**, never the contents.

---

## 6. Sending

### 6.1 Mapping `Piwik\Mail` → `Message`

*(All getters verified against `core/Mail.php` on 5.x-dev.)*

| `Piwik\Mail` | `Message` |
| --- | --- |
| `getSubject()` | `subject` |
| `getBodyHtml()` | `html` |
| `getBodyText()` | `text` (becomes the body when `html` is empty) |
| `getRecipients()` → `[address => name]` | `toRecipients` |
| `getBccs()` → `[address => name]` | `bccRecipients` |
| `getReplyTos()` → `[address => name]` | `replyTo` |
| `getAttachments()` → `[['content','filename','mimetype','cid'], …]` | `attachments`; a non-empty `cid` becomes an inline attachment |
| `getFrom()` / `getFromName()` | compared against the configured sender — see below |

**CC:** `Piwik\Mail` has **no** CC API *(verified — there is no `addCc`/`getCcs` on the class)*.
`Message` still models `ccRecipients` because the portable class must be complete for the WordPress
sibling; the Matomo adapter simply never populates it. The brief's "CC/BCC" requirement is met for
everything Matomo can actually express.

### 6.2 Forced From

App-only Graph sends as `/users/{sender}` and Exchange rejects a mismatched `from`. So:

* `Message->from` is **always** the configured `senderMailbox`.
* If `$mail->getFrom()` is non-empty and does not case-insensitively equal the configured sender,
  log at **warning**: `Missivus: forcing From to {sender}; Matomo asked for {requested}` and carry
  the requested address into `replyTo` **only if no reply-to was already set** — so a password-reset
  reply still reaches a human instead of bouncing.
* `getFromName()` is preserved as the display name, which Exchange does honour.

INSTALL.md instructs setting `[General] noreply_email_address` to the shared mailbox so this warning
never fires in normal operation.

### 6.3 Failure policy

`GraphTransport::send()` wraps everything:

```
try { graph send }
catch (GraphException $e) {
    log ERROR "Missivus: Graph send failed: {status} {redactedBody}"
    if (fallbackToDefault) { log ERROR "Missivus: falling back to default transport"; return parent::send($mail); }
    throw;                                  // never swallowed
}
```

Not configured (`enabled` off, or any of tenant/client/sender/credential missing) is treated the
same way: an error-level log, then fallback-or-throw. Nothing is ever silently dropped, which is the
brief's hard requirement.

### 6.4 `PIWIK_TEST_MODE`

The stock transport short-circuits to a `Test.Mail.send` event when `PIWIK_TEST_MODE` is defined
*(verified, `core/Mail/Transport.php:106-113`)*. `GraphTransport` defers to `parent::send()` in that
case so Matomo's own integration tests keep passing with the plugin active.

---

## 7. Attachments

*(Limits verified against Microsoft's `user: sendMail` reference and the "Attach large files to
Outlook messages or events" guide, both current as of 2026.)*

* Graph's inline `fileAttachment` on `sendMail` is for files **under 3 MB**. Creating an upload
  session for anything smaller fails with `ErrorAttachmentSizeShouldNotBeLessThanMinimumSize`.
* The upload-session path covers **3 MB – 150 MB**.
* The whole `sendMail` request body is also bounded (4 MB), so the total matters, not just the
  largest file.

`GraphMailer` therefore decides **per message**:

```
totalAttachmentBytes = Σ strlen(attachment.bytes)
useDraftPath = any attachment ≥ LARGE_ATTACHMENT_THRESHOLD (3 MB)
            || totalAttachmentBytes ≥ TOTAL_INLINE_BUDGET (3 MB)
```

Both thresholds are class constants so the ceiling can be re-tuned in one place if Microsoft moves
it.

### 7.1 Direct path — `POST /v1.0/users/{sender}/sendMail`

```json
{ "message": { "subject": …, "body": {"contentType":"HTML","content":…},
               "toRecipients": [...], "ccRecipients": [...], "bccRecipients": [...],
               "replyTo": [...],
               "attachments": [ { "@odata.type": "#microsoft.graph.fileAttachment",
                                  "name": …, "contentType": …,
                                  "contentBytes": "<base64>",
                                  "isInline": true, "contentId": "…" } ] },
  "saveToSentItems": false }
```

Success is **HTTP 202**. Anything else throws.

### 7.2 Large path — draft → upload session → send

1. `POST /v1.0/users/{sender}/messages` with the message **minus** the large attachments (small and
   inline ones stay inline here, which keeps CID images working). → `201`, returns `id`.
2. For each large attachment:
   `POST /v1.0/users/{sender}/messages/{id}/attachments/createUploadSession`
   ```json
   { "AttachmentItem": { "attachmentType": "file", "name": "…", "size": <bytes>,
                         "isInline": false, "contentType": "application/pdf" } }
   ```
   → `201`, returns `uploadUrl`.
3. `PUT {uploadUrl}` in ordered chunks, **no `Authorization` header** (the URL is pre-authenticated
   and points at `outlook.office.com`), with
   `Content-Type: application/octet-stream`,
   `Content-Length: <n>`,
   `Content-Range: bytes {start}-{end}/{total}`.
   Chunk size **3,276,800 bytes** — under the 4 MB guidance and an exact multiple of 320 KiB, which
   is the alignment Microsoft's upload protocol expects. Intermediate chunks return `200`; the final
   one returns `201`.
4. `POST /v1.0/users/{sender}/messages/{id}/send` → `202`.

If any step after step 1 fails, the draft is deleted (`DELETE /v1.0/users/{sender}/messages/{id}`,
best-effort, failures logged not thrown) so a failed report does not leave orphaned drafts
accumulating in the shared mailbox. Then the original error is rethrown.

**Scheduled-report PDFs never fail on size** because the threshold check is automatic and total-aware
— there is no configuration that can put a large PDF down the inline path.

### 7.3 Permission consequence

Steps 1–3 are **not** covered by `Mail.Send`. Creating a draft and creating an attachment upload
session require the **`Mail.ReadWrite` application permission**. This is a fact the brief did not
account for; see §12.

---

## 8. Test strategy

### 8.1 What is tested

Unit tests only — no network, no Matomo, no tenant. A `FakeHttpClient` implements
`HttpClientInterface`, records every request, and returns scripted responses. That is "a mocked
Graph endpoint": every assertion is about the exact URL, headers, and JSON body Missivus would have
put on the wire.

| # | Test | Asserts |
| --- | --- | --- |
| 1 | plain send | `POST …/users/{sender}/sendMail`, 202 accepted, HTML+text body shape, `Authorization: Bearer` present |
| 2 | recipients | to / cc / bcc / replyTo all mapped, multiple recipients preserved |
| 3 | token cached | two sends → exactly **one** token request |
| 4 | token refresh | cache expiry → a second token request; TTL is `expires_in - 300` |
| 5 | 401 retry | a 401 on send invalidates the token and retries **once**; a second 401 throws |
| 6 | secret auth | token body carries `client_secret`, `grant_type=client_credentials`, `.default` scope |
| 7 | certificate auth | token body carries `client_assertion_type` + a JWT whose header is `PS256`/`x5t#S256` and whose claims are `aud`/`iss`/`sub`/`jti`/`nbf`/`exp` |
| 8 | **PS256 signature validity** | the produced signature verifies with the **`openssl` CLI** (`openssl dgst -sha256 -sigopt rsa_padding_mode:pss -sigopt rsa_pss_saltlen:-1 -verify`) — an independent implementation, which is what makes this a real check and not a tautology |
| 9 | RS256 escape hatch | switching the algorithm produces an `x5t` header and a signature that `openssl_verify` accepts |
| 10 | inline attachment | small file → single `sendMail` call, `isInline: true`, `contentId` set, base64 round-trips |
| 11 | large attachment | 4 MB file → draft, createUploadSession with the right `size`, chunked PUTs with correct `Content-Range` and **no** `Authorization`, then `/send`; total call sequence asserted in order |
| 12 | large-path failure cleanup | a failing upload triggers the draft `DELETE` and rethrows |
| 13 | forced From | a differing From is overridden to the sender, a warning is logged, and the requested address lands in `replyTo` |
| 14 | forced From no-clobber | an explicit reply-to is **not** overwritten |
| 15 | fallback OFF | Graph failure → exception propagates, error logged, stock transport **not** called |
| 16 | fallback ON | Graph failure → stock transport called, error still logged |
| 17 | no secret leakage | a Graph error body containing a secret is redacted before it reaches the log or the exception message |
| 18 | config precedence | env > `[Missivus]` > DB, and a file override blocks a DB write |

### 8.2 How it runs

Matomo plugin tests normally need a full Matomo checkout, Composer, and PHPUnit. Requiring that to
run the unit tests would make them nobody's habit. So the tests are written as ordinary
`PHPUnit\Framework\TestCase` subclasses — they drop straight into
`./console tests:run Missivus` on a real install — **and** the repo ships a ~90-line zero-dependency
runner, `tests/run.php`, that defines a minimal `TestCase` shim when PHPUnit is absent.

```
php tests/run.php          # no Composer, no PHPUnit, exits 0 or 1
```

Tests 1–18 exercise only the portable layer plus thin, hand-instantiable adapters, so neither mode
needs Matomo loaded.

### 8.3 PHP floor

`composer.json` on `5.x-dev` declares `"php": ">=7.2.5"` with a platform pin of `7.2.9` *(verified)*.
So **PHP 7.2.5 is the floor** and the portable class uses no syntax above it: no typed properties,
no arrow functions, no `??=`, no constructor promotion, no `match`, no trailing commas in argument
lists.

The local toolchain is PHP 8.5.9, which would happily parse 7.4+ syntax and hide a violation. So the
floor is verified **empirically** by linting every file inside a `php:7.2-cli` container:

```
docker run --rm -v "$PWD":/src -w /src php:7.2-cli \
  sh -c 'find . -name "*.php" -print0 | xargs -0 -n1 php -l'
```

and the standalone suite is run under the same image. `php -l` on 8.5 is run too, so both ends of
the supported range are covered.

### 8.4 End-to-end

One manual run against the real tenant, performed after deployment via the **Send test email**
button, and recorded in the runbook. It is deliberately not automated: it would require committing a
tenant credential.

---

## 9. The test-email button

`SystemSettings` has no button primitive, so:

* `API::sendTestEmail($to = false)` — `Piwik::checkUserHasSuperUserAccess()`, then builds a
  `Piwik\Mail`, forces it through `GraphTransport` **ignoring the fallback setting** (a test that
  silently succeeds over SMTP is worse than useless), and returns
  `['success' => bool, 'message' => string]`. On failure `message` carries the Graph status and the
  redacted error body, because that string is what makes a misconfigured tenant diagnosable.
  Reachable over `token_auth` like any Matomo API method.
* `vue/src/SendTestEmail/SendTestEmail.vue` — a button plus an inline success/error panel, calling
  `AjaxHelper.fetch({ method: 'Missivus.sendTestEmail' })`.
* Wired in via `FieldConfig::customFieldComponent = ['plugin' => 'Missivus', 'name' => 'SendTestEmail']`
  *(verified: `core/Settings/FieldConfig.php:131`)*.

Matomo serves `plugins/Missivus/vue/dist/Missivus.umd.min.js` *(verified,
`core/AssetManager/UIAssetFetcher/PluginUmdAssetFetcher.php:281`)* with dependencies declared in
`vue/dist/umd.metadata.json`. Building that file normally needs Matomo's Node toolchain, which is
not a reasonable prerequisite for a single button — so the repo ships a **hand-written, readable
UMD file** in the exact wrapper shape Matomo's own plugins use (verified against
`plugins/Feedback/vue/dist/Feedback.umd.min.js`), alongside the `vue/src` TypeScript so
`./console vue:build` regenerates it correctly for anyone who does have the toolchain.

---

## 10. Matomo internals depended on

Every coupling to Matomo, so an upgrade can be diffed against this table. All verified against
`matomo-org/matomo` branch `5.x-dev` (`Version::VERSION` = `5.14.0-alpha`) on 2026-08-16; the
deployment target is **5.12.0**, the current stable release.

| # | Class | Member used | How it is used | Breaks if |
| --- | --- | --- | --- | --- |
| 1 | `Piwik\Mail` | `send()` → `StaticContainer::get('Piwik\Mail\Transport')` | **The seam.** | The DI key is renamed or the lookup is inlined. |
| 2 | `Piwik\Mail\Transport` | `send(Mail $mail)` | Extended; `parent::send()` is the fallback. | Signature change, or the class becoming `final`. |
| 3 | `Piwik\Mail` | `getSubject()`, `getBodyHtml()`, `getBodyText()`, `getRecipients()`, `getBccs()`, `getReplyTos()`, `getAttachments()`, `getFrom()`, `getFromName()` | Message mapping. | Any getter renamed, or `getAttachments()` changing its `content/filename/mimetype/cid` array shape. |
| 4 | `Piwik\Http` | `sendHttpRequestBy($method, $url, $timeout, $userAgent, $destinationPath, $file, $followDepth, $acceptLanguage, $acceptInvalidSsl, $byteRange, $getExtendedInfo, $httpMethod, $user, $pass, $requestBody, $additionalHeaders)` | The HTTP adapter. Called with `$getExtendedInfo = true`, which returns `['status','headers','data']`. A **string** `$requestBody` is passed through verbatim; only arrays are query-encoded (`core/Http.php:370-372`). PUT with a body is supported (`core/Http.php:811`). | The positional signature changes — it has 19 parameters and no options array. **Highest-risk dependency.** |
| 5 | `Piwik\Cache` | `getLazyCache()` → `fetch/save/delete/contains` | Token cache. | Facade renamed. |
| 6 | `Piwik\Settings\Plugin\SystemSettings` | `makeSetting($name, $default, $type, Closure)` | Settings page. | Signature change. |
| 7 | `Piwik\Settings\FieldConfig` | `$title`, `$description`, `$uiControl`, `$availableValues`, `$validators`, `$transform`, `$customFieldComponent`, `UI_CONTROL_*`, `TYPE_*` | Field definitions + the Vue button. | `customFieldComponent` removed (it is documented but `@internal`-adjacent). |
| 8 | `Piwik\Config` | `getInstance()->__get('Missivus')`, `->General` | `[Missivus]` overrides; `noreply_email_address`. | Section access changing. |
| 9 | `Piwik\Log\LoggerInterface` | `error()`, `warning()` (PSR-3 shaped) | Logging. | PSR-3 shape change. |
| 10 | `Piwik\Piwik` | `checkUserHasSuperUserAccess()`, `translate()` | API guard, translations. | Rename. |
| 11 | `Piwik\Plugin` | `registerEvents()`, `AssetManager.getJavaScriptFiles` | Asset registration. | Event renamed. |
| 12 | `Piwik\DI` | `autowire()` | The DI binding in `config/config.php`. | Matomo 5 wraps PHP-DI here; a DI-library swap changes it. |
| 13 | Asset pipeline | `vue/dist/<Plugin>.umd.min.js` + `vue/dist/umd.metadata.json` | The Vue component. | Bundling scheme changes (it already changed once, in Matomo 4→5). |
| 14 | `Piwik\Container\StaticContainer` | `get()` | Used by the API to obtain the transport. | Rename. |

Item 4 is the one to check first on any Matomo upgrade.

---

## 11. Deployment path

Target: `analytics.solvetus.com`, Matomo **5.12.0**, Docker under Dokploy on **slvts-core-01**
(Ubuntu 24.04), reachable over the NetBird mesh. Marketplace install is unavailable until published,
so this is a bind-mount deploy.

**Not performed in this task** — the brief scopes deployment out. Recorded here so it is one
copy-paste when it is in scope.

1. **Bind-mount.** In the Dokploy compose for the Matomo service, add
   `- /opt/solvetus/matomo/plugins/Missivus:/var/www/html/plugins/Missivus:ro`.
   Read-only: the plugin never writes to its own directory.
2. **Place the code.** `git clone` (or `git pull`) the repo to
   `/opt/solvetus/matomo/plugins/Missivus` on slvts-core-01. Tagged release, not `main`.
3. **Certificate.** Put the PEM outside the plugin tree — `/opt/solvetus/matomo/secrets/missivus.pem`,
   mounted read-only, owned `root:www-data`, mode `0640`. Never inside a bind-mount that a plugin
   update would overwrite.
4. **Config.** Add to `config/config.ini.php` (which is already a persistent volume):
   ```ini
   [Missivus]
   tenant_id = "…"
   client_id = "…"
   auth_method = "certificate"
   certificate_path = "/var/www/html/secrets/missivus.pem"
   sender_mailbox = "noreply@solvetus.com"

   [General]
   noreply_email_address = "noreply@solvetus.com"
   emails_enabled = 1
   ```
   Values are placed by the owner. This agent never sees or prints them.
5. **Activate.** `docker compose exec -u www-data matomo ./console plugin:activate Missivus`
6. **Verify.** Administration → System → General settings → Missivus → **Send test email**. Then a
   real password-reset email as a second, independent check.
7. **Record.** Config (paths and IDs, never secrets) into
   `Solvetus/Reference/Matomo Server Runbook.md`, and an "implemented" line into
   `rjdsm/Ventures/Missivus/Missivus.md`.

Rollback is `./console plugin:deactivate Missivus` — §3 makes that restore the stock transport with
no other change.

---

## 12. Open items for the owner

1. **`Mail.ReadWrite` is required for the large-attachment path.** The brief specifies `Mail.Send`
   application permission *and* the create-draft → uploadSession → send path for files over ~3 MB.
   Microsoft's own reference states that creating a message and creating an attachment upload session
   need `Mail.ReadWrite`; `Mail.Send` alone does not cover them. Both are consistent with the brief's
   security model — the Exchange application access policy scopes **every** permission the app holds
   to the single shared mailbox, so `Mail.ReadWrite` grants nothing outside it. The plugin is built
   for both permissions, INSTALL.md asks for both, and a missing `Mail.ReadWrite` surfaces as a loud,
   named error rather than a silent failure. Flagging it because it changes what gets consented to.
2. **`certificate_algorithm`.** PS256 is what Microsoft's current reference specifies and is the
   default. The RS256 escape hatch exists because this repo cannot exercise a real Entra tenant. If
   the first end-to-end run succeeds on PS256, the escape hatch stays undocumented-in-UI and unused.
3. **Large attachments to shared mailboxes** carry a Microsoft-acknowledged known issue
   (developer.microsoft.com known-issue 13644). It concerns shared/delegated *access*, not
   application access to `/users/{id}`, so it is expected not to apply here — but it is worth
   watching on the first >3 MB scheduled report.

---

## 13. Self-review against the brief

Each constraint in `docs/BRIEF.md`, checked against this plan.

| Brief constraint | Where satisfied |
| --- | --- |
| GPLv3 | `LICENSE`, headers on every file |
| Own Entra app + own application access policy | INSTALL.md §Entra, §Exchange; never reuses the worker's registration |
| Client credentials, token cached & refreshed, `POST /users/{sender}/sendMail` | §5, §6 |
| Secret **and** certificate; certificate recommended | §5.1, §5.2; README + INSTALL recommend certificate |
| Application access policy documented as first-class | INSTALL.md gives it its own numbered step with the exact `New-ApplicationAccessPolicy` call |
| Settings: tenant, client, secret/cert, sender, save-to-Sent, test button, clear status | §4.1, §9 |
| Secrets write-only, never logged | §4.2 (`password` control, `transform` guard, `Credentials` redaction, log redaction) |
| HTML + plaintext, attachments, reply-to, CC/BCC, multiple recipients — or fail loudly | §6.1 (with the CC note), §6.3 |
| Fallback: error log, optional fallback, never swallowed | §6.3 |
| Fallback default OFF | §4.1 |
| Zero third-party runtime deps | §2.1; the standalone test runner is dev-only and also dependency-free |
| Unit tests vs mocked Graph + one documented E2E | §8 |
| Transport vendorable unchanged by WordPress | §2.1 — namespace `Solvetus\Missivus`, no Matomo symbol inside `libs/` |
| Inline ceiling ~3 MB verified; above it draft→uploadSession→send; both paths tested | §7 (3 MB confirmed), tests 10–12 |
| Scheduled-report PDFs never fail on size | §7.2 — automatic, total-aware, unconfigurable |
| Force From = sender, warn on mismatch | §6.2 |
| Docs instruct `[General] noreply_email_address` | §6.2, §11 step 4, INSTALL.md |
| Secret/cert path overridable via `[Missivus]`, file wins, UI shows "set in config file", no DB write | §4.2 |
| Env vars if the config layer allows | §4.3 |
| `Missivus.sendTestEmail` superuser + token_auth, Vue component, Graph error body on failure | §9 |
| HTTP adapter = 2 methods; Matomo wraps `Piwik\Http`; token cache behind a tiny interface | §2.1, §2.2 |
| PHP floor = Matomo 5.x minimum, verified | §8.3 — 7.2.5, verified in composer.json and enforced by a 7.2 container lint |
| Latest versions, verified empirically | Matomo 5.12.0 stable / 5.14.0-alpha dev; Graph limits from current MS docs; all "verified" notes above |
| DI seam of PR #14041, no monkey-patching | §3 |
| Deployment: Dokploy bind-mount, `plugin:activate` | §11 |
| Small logical commits on main, push when tests pass | Commit sequence below |
| Marketplace-ready README, INSTALL for a non-expert, one Solvetus support line | `README.md`, `docs/INSTALL.md` |
| Translations EN first, keys ready for PT/FR/ES/IT | `lang/en.json`, all UI strings keyed |
| Never print or commit secrets | `.gitignore` covers `*.pem`, `*.key`, `.env`; no fixture holds a real credential |

**Contradictions found and resolved during this review:**

* *Brief says "CC/BCC"; `Piwik\Mail` has no CC.* Resolved in §6.1 — `Message` models CC for the
  WordPress sibling, the Matomo adapter does not populate it. Not a silent omission.
* *Brief says `Mail.Send`; the mandated large-attachment path needs `Mail.ReadWrite`.* Resolved in
  §7.3 and escalated in §12.1 rather than quietly widened.
* *Brief says "env vars if Matomo's config layer allows it"; core has no env interpolation.*
  Resolved in §4.3 — implemented at the plugin level, which is where it is allowed.
* *Brief says "verify current limit, ~3 MB".* Verified: 3 MB is exact and current, and the 4 MB
  whole-request bound means the check must be total-aware, not just per-file — §7.

---

## 14. Commit sequence

1. `docs: add BRIEF and PLAN` *(BRIEF already present)*
2. `chore: scaffold Matomo 5 plugin (plugin.json, LICENSE, .gitignore)`
3. `feat(libs): dependency-free Graph transport, auth and contracts`
4. `feat(matomo): adapters, DI wiring, settings and transport`
5. `feat(ui): sendTestEmail API, Vue component, EN translations`
6. `test: unit suite against a mocked Graph endpoint + standalone runner`
7. `docs: README and INSTALL`
