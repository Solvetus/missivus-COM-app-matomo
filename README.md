# Missivus for Matomo

**Send Matomo's email through Microsoft 365 without SMTP, without a user account, and without a
paid add-on.**

Matomo has no email API — it sends only through PHPMailer, over SMTP or PHP's `mail()`. Microsoft
has retired basic-authentication SMTP for Microsoft 365, and Matomo's own FAQ now treats the
Microsoft 365 SMTP path as unsupported. The result is a Matomo that quietly sends nothing: no
password resets, no scheduled reports, no alerts.

Missivus replaces Matomo's mail transport with one that posts to the Microsoft Graph API using
**OAuth2 client credentials** and the **Mail.Send application permission**, sending as one shared
mailbox you nominate.

## Why this and not a delegated-OAuth mailer

The usual Microsoft 365 mailers use *delegated* authentication: a multi-tenant app, a redirect URI,
and a human who clicks "Connect" so that mail goes out as their account. That is the wrong shape for
a server. It breaks when the person leaves, when their password changes, and when MFA policy
tightens.

Missivus is the opposite:

| | Delegated mailers | Missivus |
| --- | --- | --- |
| Who sends | A named person's account | A shared mailbox |
| Setup | A human clicks "Connect" | Nothing to click |
| Survives an employee leaving | No | Yes |
| Mailbox licence needed | Yes | No |
| Scope | Whatever that person can reach | One mailbox, enforced by Exchange |
| Cost | Often a paid extension | Free, GPLv3 |

The scoping is the part that makes this safe: an **Exchange application access policy** restricts
the app registration to the single shared mailbox, so the `Mail.Send` permission cannot touch any
other mailbox in your tenant. [docs/INSTALL.md](docs/INSTALL.md) treats that step as first-class.

## What it does

- Everything `Piwik\Mail` supports: HTML and plaintext, multiple recipients, BCC, Reply-To, and
  attachments — including the PDFs that scheduled reports generate.
- **Large attachments never fail on size.** Files under 3 MB go inline; anything larger is uploaded
  through a Graph upload session automatically. There is no setting that can get this wrong.
- **Certificate or client secret.** Certificates are recommended and are the default.
- **Secrets stay out of the database if you want them to.** Any value can be supplied from a
  `[Missivus]` section of `config.ini.php` or from an environment variable, which then wins over the
  settings UI and is never written to the option table.
- **Nothing fails silently.** A Graph failure is logged at error level and, unless you explicitly
  turn on the fallback, raised. Nothing is swallowed.
- **A test-email button** that shows you the exact error Microsoft returned.
- **No third-party runtime dependencies.** Nothing beyond what Matomo already ships, and nothing to
  `composer install`.

## Requirements

- Matomo 5.0 or later (developed and tested against 5.12.0)
- PHP 7.2.5 or later — Matomo 5's own floor — with the `openssl` and `json` extensions
- A Microsoft 365 tenant, and permission to create an app registration and run one Exchange Online
  PowerShell command

## Install

Full, step-by-step instructions written for someone who has not used Microsoft Entra before are in
**[docs/INSTALL.md](docs/INSTALL.md)**. In outline:

1. Create an app registration in Microsoft Entra.
2. Grant it the **Mail.Send** application permission — plus **Mail.ReadWrite** if you send
   attachments over 3 MB — and grant admin consent.
3. Add a certificate (recommended) or a client secret.
4. Create the shared mailbox you want Matomo to send from.
5. Create an **application access policy** in Exchange Online scoping the app to that one mailbox.
6. Copy the plugin into `plugins/Missivus`, run `./console plugin:activate Missivus`, fill in the
   settings, and press **Send test email**.

## Configuration

Everything can be set in **Administration → System → General settings → Missivus**.

Credentials can instead live in `config/config.ini.php`, which is often the better choice on a
server you deploy by pulling code:

```ini
[Missivus]
tenant_id = "00000000-0000-0000-0000-000000000000"
client_id = "00000000-0000-0000-0000-000000000000"
auth_method = "certificate"
certificate_path = "/etc/matomo/secrets/missivus.pem"
sender_mailbox = "noreply@example.com"

[General]
noreply_email_address = "noreply@example.com"
```

A value set there is shown in the settings page as *set in config file*, cannot be edited from the
UI, and is never copied into the database. Each key can also be supplied as an environment variable
— `MISSIVUS_TENANT_ID`, `MISSIVUS_CLIENT_SECRET`, and so on — which takes precedence over both.

Set `[General] noreply_email_address` to the same shared mailbox. Application-only sending cannot
use any other From address, so Missivus forces it and logs a warning whenever Matomo asked for
something different.

## Development

```
php tests/run.php                       # the unit suite, no Composer or PHPUnit needed
./console tests:run Missivus            # the same tests under Matomo's PHPUnit
```

[PLAN.md](PLAN.md) documents the architecture, the DI seam, and — usefully before a Matomo
upgrade — the exact list of Matomo internals this plugin depends on, with class, method and the
version they were verified against.

The Microsoft Graph transport in [`libs/Solvetus/Missivus/`](libs/Solvetus/Missivus/) is deliberately
free of any Matomo symbol: it depends only on `openssl`, `json`, a two-method HTTP interface and a
three-method cache interface, so the WordPress sibling plugin can vendor it unchanged.

## Licence

GPLv3 or later. See [LICENSE](LICENSE).

## Support

Missivus is free and open source. If you would rather not do the Entra and Exchange setup yourself,
[Solvetus](https://solvetus.com) offers paid installation and support.
