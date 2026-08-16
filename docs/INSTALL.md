# Installing Missivus

This guide assumes you have **never used Microsoft Entra before**. Every click is spelled out.

You will need:

- An account in your Microsoft 365 tenant that can create app registrations and grant admin consent
  — in practice, a **Global Administrator** or an **Application Administrator** who is also an
  **Exchange Administrator**.
- Access to the server Matomo runs on.

Set aside about thirty minutes. Nothing here is reversible-by-accident; every step can be undone.

Throughout, replace `example.com` with your own domain and `noreply@example.com` with whatever
address you want Matomo's email to come from.

---

## Part 1 — Create the app registration

This is the identity Matomo will use. It is not a user and has no password anyone types.

1. Go to <https://entra.microsoft.com> and sign in.
2. In the left-hand menu choose **Applications → App registrations**.
3. Click **+ New registration**.
4. Fill in the form:
   - **Name**: `missivus-matomo-<your Matomo hostname>` — for example
     `missivus-matomo-analytics.example.com`. Only administrators see this, and naming it after the
     tool and the host keeps it straight once your tenant holds several registrations.
   - **Supported account types**: **Accounts in this organizational directory only (Single tenant)**
   - **Redirect URI**: leave completely empty. Missivus never redirects a browser anywhere, and an
     empty value here is part of why this setup cannot be hijacked.
5. Click **Register**.

You now land on the app's **Overview** page. Two values on it go into Matomo. Copy them somewhere
safe — they are identifiers, not secrets, so a note is fine:

| On the Overview page | Goes into Matomo as |
| --- | --- |
| **Application (client) ID** | Client ID |
| **Directory (tenant) ID** | Tenant ID |

---

## Part 2 — Grant the permission to send mail

1. Still inside your app registration, choose **API permissions** in the left-hand menu.
2. Click **+ Add a permission**.
3. Choose **Microsoft Graph**.
4. Choose **Application permissions**. This is the important choice — *not* "Delegated permissions".
   Application permissions belong to the app itself, which is why no human ever has to sign in.
5. In the search box type `Mail.Send`. Tick **Mail.Send**.
6. **If Matomo will email attachments larger than 3 MB** — which scheduled PDF reports often are —
   also search for `Mail.ReadWrite` and tick it. Microsoft requires it for the large-attachment
   upload path: sending a big file means creating a draft first, and `Mail.Send` alone does not
   allow that. If you skip it, ordinary email still works and only oversized attachments fail, with
   an error naming this permission.
7. Click **Add permissions**.
8. Back on the API permissions page you will see your permissions with a warning triangle and the
   status **Not granted for &lt;your organisation&gt;**. Click
   **✓ Grant admin consent for &lt;your organisation&gt;**, then **Yes**.
9. Confirm the Status column now reads **Granted for &lt;your organisation&gt;** with a green tick.

> If the "Grant admin consent" button is greyed out, your account cannot consent. Ask a Global
> Administrator to click it. Nothing else in this guide requires their involvement.

You may also see **User.Read** listed as a delegated permission. Azure adds it automatically to new
registrations. Missivus does not use it and you can safely remove it.

---

## Part 3 — Give the app a credential

Pick **one** of these. If you are not sure, pick the client secret: it is two clicks, it is what the
rest of this guide assumes, and you can move to a certificate later without reinstalling anything.

### Option A — Client secret (start here)

1. In your app registration choose **Certificates & secrets**.
2. On the **Client secrets** tab click **+ New client secret**.
3. Give it a description following the pattern `missivus-matomo-<your Matomo hostname>` —
   for example `missivus-matomo-analytics.example.com` — then choose an expiry and click
   **Add**. A tenant accumulates app registrations and secrets quickly, and a name that says
   which tool and which host it belongs to is the difference between confidently rotating a
   secret and being afraid to touch it.
4. Copy the **Value** column immediately. **It is shown once and never again.** The *Secret ID*
   column is not the secret and is not what you need — this trips almost everyone up the first
   time.
5. Put the expiry date in a calendar. Mail stops on that day if the secret is not replaced.

That is the credential. Skip to Part 4.

### Option B — Certificate (optional hardening)

A certificate is more secure than a secret: it never travels in a request body, and it can be given
a longer life without the same exposure. It is worth doing if you are comfortable with `openssl` and
managing a key file on the server — but nothing in Missivus needs it, and a secret is not a second-
class option.

Run this on the Matomo server, or anywhere with `openssl` installed:

```bash
openssl req -x509 -newkey rsa:2048 -keyout missivus.key -out missivus.crt \
  -days 730 -nodes -subj "/CN=missivus-matomo"

# Missivus wants one file containing both parts:
cat missivus.key missivus.crt > missivus.pem
```

- `missivus.pem` is the file Matomo reads. **It is a credential — treat it like a password.**
- `missivus.crt` is the public half, the only part you upload to Microsoft.

Upload the public half:

1. In your app registration choose **Certificates & secrets**.
2. Select the **Certificates** tab, click **Upload certificate**.
3. Choose `missivus.crt`, add a description, click **Add**.
4. A thumbprint and an expiry date appear. **Put the expiry date in a calendar** — mail stops on
   that day if the certificate is not replaced.

Put the PEM somewhere outside your web root and outside the plugin directory, so a plugin update
cannot overwrite it and a web server misconfiguration cannot serve it:

```bash
sudo mkdir -p /etc/matomo/secrets
sudo mv missivus.pem /etc/matomo/secrets/missivus.pem
sudo chown root:www-data /etc/matomo/secrets/missivus.pem
sudo chmod 640 /etc/matomo/secrets/missivus.pem
rm missivus.key missivus.crt      # no longer needed on this machine
```

Use `www-data` or whichever user your web server runs as.

If you choose this option, set **Authentication method** to **Certificate** in Part 7 and give the
path to the PEM instead of a secret.

---

## Part 4 — Create the shared mailbox

A shared mailbox needs no licence, which is the point: Matomo gets a real, monitorable mailbox for
free.

Make it a **company-wide no-reply address rather than a Matomo-specific one** — `noreply@example.com`
with the display name set to your company name, not `matomo@` or `Matomo Analytics`. Recipients see
the display name, and "Example Ltd" reads better in an inbox than the name of your analytics
software. It also means the next tool that needs to send email can reuse this same mailbox: each
tool gets **its own Entra app registration**, scoped to this mailbox by the same kind of application
access policy, so their credentials stay completely separate while the address your customers see
stays consistent.

1. Go to <https://admin.microsoft.com>.
2. Choose **Teams & groups → Shared mailboxes**.
3. Click **+ Add a shared mailbox**.
4. Set the name to your company name — for example `Example Ltd` — with the address
   `noreply@example.com`.
5. Click **Save changes**. Give it a few minutes to appear everywhere.

Do not add any members. Nobody needs to sign into it.

Two optional bits of hardening while you are here. Both are worth doing and **neither changes
anything for Missivus**, which talks to Graph and needs none of these protocols:

- **Mailbox → Email apps**: switch off Outlook desktop (MAPI), Exchange web services, ActiveSync,
  IMAP, POP3 and Outlook on the web. That closes every sign-in route into the mailbox.
- **Mailbox → General → Hide from address list**: turn it on, so a no-reply address does not clutter
  colleagues' address pickers or invite replies.

---

## Part 5 — Lock the app to that one mailbox

**Do not skip this.** Until you do, the `Mail.Send` permission you granted in Part 2 lets the app
send as *anyone* in your tenant. An application access policy narrows it to the single shared
mailbox, and it is what makes this whole model safe.

You need Exchange Online PowerShell. On Windows PowerShell 7, or on macOS or Linux with PowerShell
installed:

```powershell
Install-Module -Name ExchangeOnlineManagement -Scope CurrentUser
Import-Module ExchangeOnlineManagement
Connect-ExchangeOnline -UserPrincipalName you@example.com
```

Create a mail-enabled security group holding just the shared mailbox, then scope the app to it. If
you later add another tool that sends from the same mailbox, reuse this group and create a second
policy for that tool's own app registration:

```powershell
New-DistributionGroup -Name "Missivus Mailboxes" `
  -Alias missivus-mailboxes `
  -Type Security `
  -Members "noreply@example.com"

New-ApplicationAccessPolicy `
  -AppId "<your Application (client) ID from Part 1>" `
  -PolicyScopeGroupId "missivus-mailboxes@example.com" `
  -AccessRight RestrictAccess `
  -Description "Missivus may only send as the Matomo shared mailbox"
```

Wait a few minutes — Exchange takes time to apply it — then check it worked:

```powershell
# Should say AccessCheckResult : Granted
Test-ApplicationAccessPolicy -Identity "noreply@example.com" -AppId "<your client ID>"

# Should say AccessCheckResult : Denied
Test-ApplicationAccessPolicy -Identity "someone-else@example.com" -AppId "<your client ID>"
```

If the second command says **Granted**, the policy has not taken effect yet. Wait and try again
before going any further.

```powershell
Disconnect-ExchangeOnline
```

---

## Part 6 — Install the plugin

Copy the plugin so it lands at `plugins/Missivus` inside your Matomo installation, then activate it.

From the release zip:

```bash
cd /path/to/matomo/plugins
unzip /path/to/Missivus-0.1.0.zip          # creates plugins/Missivus/
chown -R www-data:www-data Missivus
cd ..
./console plugin:activate Missivus
```

Or straight from git:

```bash
cd /path/to/matomo
git clone https://github.com/Solvetus/missivus-COM-app-matomo.git plugins/Missivus
./console plugin:activate Missivus
```

You can also upload the zip through **Administration → Plugins → Install a new plugin → Upload**,
and activate it from that same page, if you would rather not use a shell at all.

Run `./console` as your web server user (`sudo -u www-data ./console …`) so file ownership stays
consistent.

Activating the plugin on its own changes nothing: Missivus starts switched off, and Matomo keeps
using whatever mail settings it already had.

---

## Part 7 — Configure Matomo

### Tell Matomo the From address

Edit `config/config.ini.php` and make sure the `[General]` section contains:

```ini
[General]
emails_enabled = 1
noreply_email_address = "noreply@example.com"
```

Application-only sending can only send as the shared mailbox, so Missivus forces that From address
regardless. Setting it here means Matomo and Microsoft agree, and no warnings appear in your log.

### Enter the credentials

Go to **Administration → System → General settings** in Matomo and scroll to **Missivus**. Fill in:

| Field | Value |
| --- | --- |
| Directory (tenant) ID | from Part 1 |
| Application (client) ID | from Part 1 |
| Authentication method | **Client secret** (or **Certificate** if you chose Option B) |
| Client secret | the **Value** you copied in Part 3 |
| Sender mailbox | `noreply@example.com` from Part 4 |

Click **Save**. That is all the configuration Missivus needs — **you do not have to touch
`config.ini.php` for any of it.**

#### Optional: keep credentials in a file instead

If you would rather your credentials lived with your configuration than in Matomo's database — which
suits a server you deploy by pulling code — add a `[Missivus]` section to `config/config.ini.php`:

```ini
[Missivus]
tenant_id = "00000000-0000-0000-0000-000000000000"
client_id = "00000000-0000-0000-0000-000000000000"
auth_method = "secret"
client_secret = "the Value you copied in Part 3"
sender_mailbox = "noreply@example.com"
```

For a certificate instead, replace the last two credential lines with:

```ini
auth_method = "certificate"
certificate_path = "/etc/matomo/secrets/missivus.pem"
```

Anything set here appears in the settings page marked *set in config file*, cannot be edited from
the UI, and is never written to Matomo's database.

Each key can also come from an environment variable, which beats both: `MISSIVUS_TENANT_ID`,
`MISSIVUS_CLIENT_ID`, `MISSIVUS_AUTH_METHOD`, `MISSIVUS_CLIENT_SECRET`,
`MISSIVUS_CERTIFICATE_PATH`, `MISSIVUS_CERTIFICATE_PASSPHRASE`, `MISSIVUS_SENDER_MAILBOX`.

### Turn it on

In **Administration → System → General settings → Missivus**, tick **Send email through Microsoft
Graph** and click **Save**.

---

## Part 8 — Test it

On the same settings page, enter your own address next to **Send a test email** and click the
button.

- **Success** — you will have an email within a minute. Microsoft accepting the message is not
  quite the same as delivering it, so do check it actually arrives.
- **Failure** — the exact error Microsoft returned is shown on the page. See the table below.

As a second, independent check, sign out of Matomo and use **Lost your password?**. That exercises
the real `Piwik\Mail` path rather than the test button.

---

## When it does not work

| What you see | What it means | Fix |
| --- | --- | --- |
| `AADSTS7000215: Invalid client secret provided` | The secret is wrong, or you copied the Secret ID instead of the Value | Create a new secret and copy the **Value** column |
| `AADSTS700027` or `invalid_client` with a certificate | The certificate Matomo is using is not the one uploaded to Entra | Re-upload `missivus.crt`; check `certificate_path` points at the PEM holding **both** the key and the certificate |
| `AADSTS900023: Specified tenant identifier is not valid` | Wrong tenant ID | Recopy **Directory (tenant) ID** from the Overview page |
| `ErrorAccessDenied` / `Access is denied` on send | The application access policy does not cover this mailbox, or admin consent was never granted | Re-run `Test-ApplicationAccessPolicy` from Part 5; re-check the green tick in Part 2 |
| `ErrorAccessDenied` only on large attachments | `Mail.ReadWrite` is missing | Add it in Part 2 and grant admin consent again |
| `MailboxNotEnabledForRESTAPI` | The sender address is not a real Exchange Online mailbox | Check the shared mailbox exists and the address is spelled right |
| `The mailbox is either inactive, soft-deleted, or is hosted on-premise` | The mailbox has not finished provisioning | Wait fifteen minutes and try again |
| `certificate file is not readable at …` | File permissions | `chown root:www-data` and `chmod 640` the PEM, and check the path |
| `the private key … could not be loaded` | Wrong passphrase, or the PEM has no `PRIVATE KEY` block | Rebuild the PEM: `cat missivus.key missivus.crt > missivus.pem` |
| Nothing happens, no error | Missivus is switched off, so Matomo used its own settings | Tick **Send email through Microsoft Graph** |

Missivus writes every failure to Matomo's log at error level, so
**Administration → Diagnostic → System Check** and your Matomo log are the next place to look.
Secrets are redacted before anything is logged.

If Entra rejects the certificate assertion with a signature error and you have checked everything
above, add `certificate_algorithm = "RS256"` to the `[Missivus]` section. Missivus signs with PS256
by default, which is what Microsoft's current documentation specifies; RS256 is the older algorithm
that Entra also accepts. This should not be necessary — please open an issue if it is.

---

## Keeping it working

- **Diary the credential expiry.** A certificate or secret that expires stops mail dead. Replace it
  a week early: upload the new certificate, change `certificate_path`, test, then delete the old
  one from Entra.
- **After a Matomo upgrade**, press **Send test email** once. `PLAN.md` lists exactly which Matomo
  internals this plugin depends on if something does break.
- **To turn it off**, untick the setting, or run `./console plugin:deactivate Missivus`. Matomo
  reverts to its own mail settings immediately, with nothing to clean up.

---

## Getting help

Open an issue at
<https://github.com/Solvetus/missivus-COM-app-matomo/issues>. Never paste a client secret,
a certificate, or a PEM file into an issue.

If you would rather have this set up for you, [Solvetus](https://solvetus.com) does paid
installation and support.
