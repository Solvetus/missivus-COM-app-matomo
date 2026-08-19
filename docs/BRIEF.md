# Missivus for Matomo — Build Brief

This file is the spec. `/goal` conditions reference it. Read it fully before planning.

## What Missivus is

A Solvetus Labs plugin family that sends an application's outbound email through the Microsoft
Graph API using APPLICATION permissions and a shared mailbox — no user login, no delegated OAuth,
no paid extension. This repo is the Matomo plugin (`Solvetus/missivus-matomo`, GPLv3).
A sibling repo `missivus-wordpress` will later vendor the same Graph transport class
unchanged.

## Why

Matomo has no email API. It sends only via PHPMailer (SMTP or PHP mail()). Microsoft is retiring
basic-auth SMTP for Microsoft 365 — disabled by default for existing tenants from end of December
2026, unavailable to new tenants from 2027 — and Matomo's own FAQ
(https://matomo.org/faq/how-to/faq_93/) already says the old M365 SMTP setup "is no longer
supported" because Matomo has no OAuth2 SMTP path; matomo-org/matomo issue #23651 (Oct 2025) is an
open feature request for exactly this with no implementation. On WordPress, the existing option (Post SMTP's Office 365 mailer) is
a PAID extension using DELEGATED auth — multi-tenant app, redirect URI, a human clicks "Connect",
mail goes out as that person's account. That is the wrong architecture for a server and breaks
when the person leaves. Missivus is the opposite and that is the entire differentiator: client
credentials, Mail.Send application permission, an Exchange application access policy scoping it
to ONE shared mailbox, no license needed for that mailbox, nothing to click. Free and open
source; Solvetus sells installation and support.

First deployment: a Matomo 5.12.0 instance with NO mail configured — password resets, scheduled
reports and alerts silently don't send — on a tenant that keeps Microsoft 365 long-term. The
specifics of that instance live in the operator's own runbook, not in this repository.

## Read first

Matomo's mail architecture: `Piwik\Mail` (every send goes through it), how PHPMailer is wired,
and the DI seam from matomo-org/matomo PR #14041 ("change mail transport through DI") — the
intended extension point; use it, don't monkey-patch. The Matomo 5 plugin guides: skeleton,
SystemSettings API, DI config, translations, marketplace requirements.

There is prior art to read in-house: an existing Cloudflare Worker that relays a contact form via
Graph with the same app model and Mail.Send permission. Reuse its auth and error-handling patterns
only; do NOT reuse that app registration.

## Design goals (decided)

- Auth: OAuth2 client credentials, token cached and refreshed; POST /users/{sender}/sendMail.
  Support client secret AND certificate; recommend certificate in docs. Document the Exchange
  application access policy step as first-class — it's what makes the model safe.
- SystemSettings page: tenant ID, client ID, secret/cert reference, sender mailbox, save-to-Sent
  toggle, "send test email" button, clear status. Secrets write-only, never logged.
- Full fidelity: HTML + plaintext, attachments (scheduled-report PDFs), reply-to, CC/BCC, multiple
  recipients — everything Piwik\Mail supports works, or the plugin fails loudly, never silently.
- Fallback: unconfigured or token failure → error-level log, optional fallback to Matomo's
  default transport, never swallowed.
- Zero third-party runtime dependencies beyond what Matomo ships.
- Tests: unit tests against a mocked Graph endpoint; one documented end-to-end run on a real
  tenant.
- The Graph transport is a small dependency-free PHP class the WordPress plugin can vendor
  unchanged; platform-specific packaging stays separate per repo.

## Constraints and closed decisions (do not re-open)

- License: GPLv3.
- App registration: Missivus gets its own Entra app registration and its own Exchange
  application access policy.
- Attachments: Graph sendMail inline fileAttachment has a size ceiling (verify current limit,
  ~3 MB). Above it, use create-draft → uploadSession → send. Scheduled-report PDFs must never
  fail on size; both paths need tests.
- Sender/From: app-only Graph sends as /users/{sender}. Force From = configured sender mailbox;
  log a warning when Piwik\Mail carries a different From. Docs must instruct setting
  `[General] noreply_email_address` to the shared mailbox.
- Secrets storage: tenant ID, client ID and sender may live in SystemSettings. Client secret and
  certificate path must be overridable via a `[Missivus]` section in config.ini.php (and env
  vars if Matomo's config layer allows it); a config-file value wins over the DB value and the
  settings UI shows "set in config file" instead of the value. Nothing secret is ever logged; no
  secret is written to the option table when a file override exists.
- Test-email button: SystemSettings has no button primitive. Implement a plugin API method
  `Missivus.sendTestEmail` (superuser only, token_auth) plus a small Vue component rendered in
  the settings page. Show the result inline with the Graph error body on failure.
- Portability boundary: the vendorable Graph transport class depends only on PHP openssl/json
  and a 2-method HTTP adapter interface (post JSON with headers → status + body). Matomo adapter
  wraps `Piwik\Http`; the WordPress adapter later wraps `wp_remote_post`. Token cache is behind a
  tiny interface (Matomo: `Piwik\Cache` or Option table).
- Fallback: default OFF. When ON and Graph fails, delegate to the original transport and log at
  error level either way.
- Deployment target: a containerised Matomo behind a reverse proxy on Linux. Bind-mounted plugins
  directory preferred; activate via `./console plugin:activate Missivus`. Marketplace install is
  not available until published. Host names, orchestration and network specifics stay in the
  operator's runbook, out of this repository.
- PHP floor: match the minimum PHP version Matomo 5.x supports (verify in Matomo's composer.json
  / docs) — no syntax above that floor in the vendorable class.
- Latest versions always. Verify empirically.

## House rules

- Secrets: owner places them in .env / config files; agent references by path only, never prints
  values, never commits them.
- Repo: `Solvetus/missivus-matomo` (Solvetus GitHub org). Small logical commits on
  main. Push when tests pass.
- Docs: marketplace-ready README, `docs/index.md` with the Azure/Entra and Exchange Online
  steps written for a non-expert (that guide is also the paid-install playbook), one tasteful
  line pointing to Solvetus for installation and support. Translations EN first, keys ready for
  PT/FR/ES/IT.
- Vault: after deployment, record the instance's configuration in the internal Matomo server
  runbook and add an "implemented" log line to the internal Missivus venture note. Those notes are
  the only place server specifics belong — never this repository.

## Deliverables in this repo

- `PLAN.md` — architecture, DI wiring, settings model, auth flow (secret + certificate),
  attachment strategy, test strategy, deployment path, and the exact list of Matomo internals
  depended on (class + method + Matomo version seen) so upgrades can be checked against it.
- The plugin, tests, LICENSE, README.md, docs/index.md, .gitignore.
