# Screenshots

The Matomo Marketplace builds the **Screenshots** tab of the plugin page from the image files in
this directory. Nothing else here is used: this README is ignored by the Marketplace, and the
`screenshots/` directory is stripped out of the installable zip automatically.

## Rules the Marketplace applies

- Format `.png`, `.jpg` or `.jpeg`.
- The **file name becomes the caption** shown under the image, so name them as sentences.
- Only letters, numbers, underscores and dashes in the name — underscores and dashes render as
  spaces.
- Files are shown in alphabetical order, so the `01_`, `02_` prefixes below control the sequence.
- A cover image must be named `_cover.png` and be **exactly 880×480 pixels**, or it may display
  incorrectly.

## What to capture

Take these on a real install with the plugin configured. Use a light theme unless you are
deliberately showing the dark one, crop to the Missivus section rather than the whole browser
window, and check every field before you export — **a screenshot of a filled-in settings page is a
screenshot of your tenant**.

| File name | What it should show |
| --- | --- |
| `01_The_Missivus_settings_page.png` | **Administration → System → General settings → Missivus**, filled in and switched on. Tenant ID and client ID must be redacted or replaced with obvious placeholder GUIDs; the client secret field renders as `******` on its own, but check it. |
| `02_Send_a_test_email_and_see_exactly_what_happened.png` | The **Send test email** row after a successful send, with the green success notification visible. |
| `03_Failures_show_the_exact_error_from_Microsoft.png` | The same row after a deliberate failure — e.g. a wrong tenant ID — showing the red notification with the `AADSTS…` text. This is the plugin's most persuasive screenshot: it is what makes a misconfigured tenant diagnosable. |
| `04_Credentials_can_live_in_config_ini_php_instead.png` | *(optional)* The settings page with a `[Missivus]` override in place, so fields show **set in config file** and are greyed out. |
| `_cover.png` | *(optional)* 880×480 cover. Suggestion: the Missivus name and the line "Matomo email through Microsoft Graph — no SMTP, no user account, no licence", over a plain background. Not a screenshot. |

## Before committing

- [ ] No real tenant ID, client ID, secret, mailbox address or internal host name is legible —
      including in the browser's URL bar, tab titles, or any notification still on screen.
- [ ] The images are the plugin, not the whole desktop.
- [ ] File names read as captions, because they will be.

Then delete this checklist's contents only if you are sure — the file itself is harmless to keep,
and the next person to add a screenshot will want it.
