# Changelog

All notable changes to Missivus for Matomo are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[semantic versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.1] — 2026-08-17

First round of fixes after the first live deployment, plus a security review
([docs/SECURITY.md](docs/SECURITY.md)).

### Fixed

- **The test-email button no longer lies about being ready.** The test sends with the *saved*
  settings, so filling the form in and clicking **Send test email** used to report that Missivus was
  switched off. The button now stays disabled until the stored configuration can actually send, says
  which part is missing, and re-checks itself when a settings save completes — no page reload.
- **The result box is readable in the dark theme.** It used hand-rolled pastel backgrounds that set a
  background colour but not a foreground one, so in dark mode the Graph error — the one thing worth
  reading — was light text on a light panel. It now uses Matomo's own `notification` /
  `notification-success` / `notification-error` / `notification-info` classes.
- A network failure while uploading a large attachment escaped as an unhandled exception, skipping
  the "fall back to Matomo's own transport" setting, and could write the pre-authenticated upload URL
  to the log. It is now a normal, redacted transport failure.

### Security

- A `graph_base_url` / `login_base_url` override is now refused unless it is a bare `https` origin,
  so a mis-set — or hostile — value can no longer send a client secret or a bearer token in clear
  text to another host.
- `Redactor` now also blanks `uploadUrl` values, which are pre-authenticated and therefore credentials.
- Every setting is validated: tenant and client IDs must be a GUID (or a tenant domain), the sender
  mailbox must be an email address, the certificate path must be absolute and readable, and a secret
  carrying whitespace or control characters — always a copy-paste accident — is rejected on entry.
- `Missivus.sendTestEmail` validates the recipient address and refuses anything but an HTTP POST, and
  the recipient now travels in the request body rather than the query string.
- Full audit of secret handling, authentication, CSRF and error-body disclosure written up in
  [docs/SECURITY.md](docs/SECURITY.md), including the three risks that were accepted rather than
  eliminated, with the reasoning.

### Added

- `Missivus.getTestEmailStatus` — superuser-only; reports whether the saved settings can send, and
  why not when they cannot. This is what gates the button.
- `docs/INSTALL.md` Part 6 gains **Upload via the Matomo UI (Docker or locked-down installs)**: why
  `enable_plugin_upload` is off by default, the console command to turn it on (with the
  `docker exec` form), the upload and activate steps, and the command to close it again afterwards.
- `docs/SECURITY.md` and this changelog.

### Tests

- 60 unit tests, all passing (was 50): the new `EndpointTest`, plus coverage for the upload-URL leak
  and its redaction.

## [0.1.0] — 2026-08-16

Initial release.

- Replaces Matomo's PHPMailer transport with Microsoft Graph `sendMail`, using OAuth2 client
  credentials and the `Mail.Send` application permission, through the DI seam from
  [matomo-org/matomo#14041](https://github.com/matomo-org/matomo/pull/14041).
- Client secret and certificate authentication (PS256, with an RS256 escape hatch), token caching
  with a five-minute refresh margin, and one retry on a 401.
- HTML and plaintext bodies, multiple recipients, BCC, reply-to, inline images, and attachments —
  automatically switching to the draft → upload session → send path above 3 MB so scheduled-report
  PDFs never fail on size.
- Settings page with a config-file and environment-variable override tier, write-only secrets, and a
  test-email button.
- Optional fallback to Matomo's own transport, off by default; nothing is ever swallowed.

[0.1.1]: https://github.com/Solvetus/missivus-COM-app-matomo/releases/tag/v0.1.1
[0.1.0]: https://github.com/Solvetus/missivus-COM-app-matomo/releases/tag/v0.1.0
