# Installation: Cloudflare Turnstile for osTicket 1.18.x

**[Deutsche Fassung →](INSTALL.de.md)** · [back to README](../README.md)

Target directory: `include/plugins/turnstile/` of your osTicket installation.
`support.example.com` is used as the example hostname throughout — replace it with your own.

The order is binding. Every step has an abort point.

---

## 0. Why this plugin and not `nhaskaris/turnstile_osticket`

| Concern | Reference repo | This plugin |
|---|---|---|
| cURL timeout | missing → login hangs during a Cloudflare outage | `CURLOPT_TIMEOUT` + `CONNECTTIMEOUT` |
| Repeated validation in one request | static `$was_validated` flag skips every later check | result cache keyed per token |
| Hostname check | none → tokens from foreign domains are valid | optionally enforced |
| Error message | Cloudflare error codes go straight to the user | generic message, detail only in the log |
| Login pages | not covered | `TurnstileLoginGate` |
| CSP | core patch with an `'unsafe-inline'` extension required | plugin fixes the header itself |
| Protected areas | no switches | four individual switches plus kill switch |

---

## 0b. Test coverage

Verified on **PHP 8.3.33** against a real **osTicket 1.18.4** (commit `8d38b06`)
with MariaDB 10.6 — not only against stubs.

```bash
PHP_BIN=/path/to/php8.3 tests/run-all.sh     # lint, unit, fail-mode, integration
```

| Stage | Scope | Result |
|---|---|---|
| Lint | 8 files, `php -l` | 8/8 |
| Unit | config, settings, markup, verifier, login gate, form field, bootstrap | 95/95 |
| Fail-mode | endpoint redirected to a blackhole | 9/9 |
| Integration | `php -S`, real HTTP requests against a login rebuild | 25/25 |
| **End-to-end** | **real osTicket 1.18.4, plugin active via the plugin manager** | **37/37** |

The siteverify calls hit the **real** Cloudflare API using the official test keys
(`1x…` always valid, `2x…` always invalid, `3x…` returns `timeout-or-duplicate`).
No mock — the network path is exercised.

### What the end-to-end run proves

- **`open.php` does not render the ticket fields in the initial HTML at all.** osTicket
  loads them only after a help topic is chosen, via `ajax.php/form/help-topic/<id>`.
  That is exactly why the widget runs in `render=explicit` mode: auto mode renders
  only what was in the DOM on first load and would do nothing here.
- The AJAX fragment contains the container, the re-render function, and the poller —
  exactly one script tag, exactly one container.
- Creating a ticket **without** a token: the plugin's exact error message, `ost_ticket`
  does not grow. **With** a valid token: the ticket is created (row count verified,
  not just HTML).
- Staff login posts to itself via AJAX: without a token it returns **403 with an HTML
  fragment** (not a full page, which would break the frontend); with a token osTicket
  takes over.
- CSP: osTicket sets `script-src 'self' 'unsafe-inline'` in `client/header.inc.php`
  **and** in `staff/login.header.php`. In both responses, `challenges.cloudflare.com`
  is present in `script-src` afterwards, with no new `frame-src`. The output buffer
  wins against the later `header()` call — no core patch needed.
- Kill switch: `touch DISABLED` silences the plugin at runtime, login is immediately
  open again, `rm` reactivates it.

Also covered: a blackholed endpoint responds after 2.0 s at `timeout=3` instead of
hanging (the connect timeout fires first); after one `fail_mode=open` pass-through,
the next request is blocked again under `closed`; tokens longer than 2048 characters
and empty tokens trigger **no** outbound request.

### What remains open

No browser executed any of this. What is verified is that the correct markup and the
re-render hook are delivered — **not** that Cloudflare's `api.js` actually starts
inside the AJAX fragment. Whether osTicket injects the fragment via jQuery `.html()`
(executes scripts) or via `innerHTML` (does not) decides that. As a safety net, the
bootstrap JS runs a poller that re-renders every 250 ms for 10 seconds as soon as
`window.turnstile` becomes available.

That stays step 6.1 of the smoke test: switch the help topic and watch whether the
widget appears.

---

## 1. Check prerequisites

```bash
php -v                      # 8.0-8.4 supported, verified in production on 8.3.33
php -m | grep -i curl       # must return "curl"
```

osTicket version: admin panel → Dashboard → System Information. Must be 1.18.x.

**Abort if:** cURL is missing. No cURL, no server-side verification. No server-side verification, no protection.

---

## 2. Create the Cloudflare widget

Cloudflare dashboard → **Turnstile** → *Add widget*.

| Field | Value |
|---|---|
| Widget name | `osticket-production` |
| Hostname | `support.example.com` |
| Widget Mode | **Managed** |
| Pre-clearance | off |

Result: a **site key** (public) and a **secret key** (private).

The secret key goes exclusively into the osTicket plugin configuration. Not into a file in the repository, not into a ticket, not into a chat.

---

## 3. Upload the files

**Ready-made package: `turnstile-<version>.zip`** from the [latest release](https://github.com/studio-prisma/osticket-plugin-turnstile/releases/latest).
It contains only the eight production files plus the license — no tests, no docs.
To build from source: `./tools/build-zip.sh`.

Unzipped it yields the folder `turnstile/`, which belongs in `include/plugins/`:

```
include/plugins/turnstile/
├── plugin.php
├── config.php
├── class.TurnstilePlugin.php
└── src/
    ├── TurnstileSettings.php
    ├── TurnstileVerifier.php
    ├── TurnstileMarkup.php
    ├── TurnstileFormField.php
    └── TurnstileLoginGate.php
```

Via SFTP: upload the `turnstile/` folder. Via SSH:

```bash
cd /var/www/vhosts/.../httpdocs/include/plugins
unzip turnstile-<version>.zip
```

Match owner and permissions to the neighbouring folders under `include/plugins/`.

```bash
cd /var/www/vhosts/.../httpdocs/include/plugins
chown -R <web-user>:<web-group> turnstile
find turnstile -type d -exec chmod 755 {} \;
find turnstile -type f -exec chmod 644 {} \;
```

**Syntax check on the server — do not skip:**

```bash
find turnstile -name '*.php' -exec php -l {} \;
```

Expected: `No syntax errors detected` for all eight files.

This already ran locally against PHP 8.3 (section 0b). Repeat it on the server anyway: what matters there is the actually installed PHP version and extension set, not the test build.

---

## 4. Install and configure the plugin

Admin panel → **Manage → Plugins** → *Add New Plugin* → "Cloudflare Turnstile" → *Install*.

Then create an instance and configure it:

| Setting | Starting value |
|---|---|
| Site key | from step 2 |
| Secret key | from step 2 |
| Expected hostname | `support.example.com` — **set this** |
| Guest ticket form | **on** |
| Client registration | off |
| Client login | off |
| Staff login | off |
| Fail mode | `closed` |
| Timeout | 5 seconds |
| Log failures | on |

Set the instance to **Enabled**.

The three login areas stay off until step 6 is green. That is the lockout protection.

**Do not leave the expected hostname empty.** Without it, siteverify only confirms that the token is genuine — not that it was minted for this site. A token generated on any other domain that uses the same widget is then accepted. Leave it empty only if several hostnames point at this instance. Saving with an active protection area and no hostname raises a warning; the warning does not block the save.

---

## 5. Attach the field to the ticket form

Admin panel → **Manage → Forms** → *Ticket Details* (or the form of the affected help topic).

*Add Field* → type **Cloudflare Turnstile** → label e.g. "Security check" → move to the end of the form → **leave *Required* unticked** → *Save Changes*.

Without this step, nothing happens on `open.php`. The plugin only registers the field type; assigning it is manual.

> **The *Required* flag must stay off.** It protects nothing and breaks ticket creation on every channel that is not a browser.
>
> Presence of the token is already enforced by the plugin: an empty token never reaches Cloudflare, it is rejected locally as `missing`. The osTicket flag adds a second, redundant check — but that one lives in `FormField::validateEntry()` and runs for every origin. `Ticket::create()` discards field errors only for `origin = email`; for the API and for agent-created tickets it keeps them.
>
> Symptom when it is ticked:
>
> ```
> POST /api/tickets.json → HTTP 500
> Unable to create new ticket :48 Security check is a required field
> ```
>
> The 500 rather than a 400 is osTicket's doing, not the plugin's — `include/api.tickets.php` ends its error path with `exerr($errors['errno'] ?: 500, …)`, and validation errors carry no `errno`.
>
> No payload fixes this. The token is submitted as `cf-turnstile-response`, not under the field's variable name, and `to_database()` deliberately stores nothing — so there is no key an API client could set. That is intentional and matches `SECURITY.md`: email intake and the JSON API are out of scope for this plugin.

---

## 6. Smoke test, in this order

**6.1 Guest ticket form**

1. Open `https://support.example.com/open.php` in a private window
2. Widget visible? Browser console clean? (no CSP message about `challenges.cloudflare.com`)
3. Create a ticket normally → must succeed
4. Switch the help topic (reloads the form via AJAX) → the widget must render again

Abort before 6.2 if anything here is red.

**6.2 Client registration**

Turn on the "Client registration" switch. Additionally assign the Turnstile field to the **user form** (Manage → Forms → *User Information*). Test registration.

**6.3 Client login**

Turn on the "Client login" switch. Open `login.php` in a private window: the widget must appear below the password field, and login must work.

**6.4 Staff login — last, with a second session open**

Before flipping the switch: keep a **second, already authenticated agent session** open in a different browser. That session is your way back in if the widget does not render.

Turn on the "Staff login" switch → test `scp/login.php` in a private window.

If login fails: use the kill switch (section 8), **do not** experiment.

---

## 7. Content-Security-Policy

osTicket sets the CSP in five places directly via `header()`, among them
`include/client/header.inc.php` and `include/staff/login.header.php`:

```
script-src 'self' 'unsafe-inline'
```

That would block `challenges.cloudflare.com` and the widget would not load.

**The plugin solves this itself.** `TurnstileLoginGate::relaxCsp()` runs in the output buffer callback — after osTicket's `header()` call and before the headers are sent — and appends exactly one origin to `script-src`. No core patch, update-safe.

Deliberately, **only** `script-src` is touched. osTicket sets neither `default-src` nor `frame-src`; a newly added `frame-src` would restrict every other frame on the page instead of permitting anything.

### If the widget is still CSP-blocked

Then a layer in front sets its own CSP. Check:

```bash
curl -sI https://support.example.com/open.php | grep -i content-security-policy
```

If the directive appears **twice**, the browser applies the intersection — the stricter variant blocks. Fix it at the source:

**Plesk → Apache & nginx Settings → Additional Apache directives:**

```apache
Header always set Content-Security-Policy "frame-ancestors 'self'; img-src 'self' data:; script-src 'self' 'unsafe-inline' https://challenges.cloudflare.com; object-src 'none'"
```

**Plesk → Additional nginx directives** (only if nginx serves PHP-FPM directly):

```nginx
fastcgi_hide_header Content-Security-Policy;
add_header Content-Security-Policy "frame-ancestors 'self'; img-src 'self' data:; script-src 'self' 'unsafe-inline' https://challenges.cloudflare.com; object-src 'none'" always;
```

A core patch of `include/client/header.inc.php` is the last resort and is lost with every osTicket update. If unavoidable anyway: keep the patch under version control outside the osTicket directory and reapply it after every update.

---

## 8. Kill switch and rollback

**Stage 1 — plugin silenced, files stay in place** (seconds, no database access, works even if you are locked out):

```bash
touch include/plugins/turnstile/DISABLED
```

No verification, no widget, no output buffer. Undo with `rm`.

The `Cloudflare Turnstile` field type stays registered while the file is present — the kill switch silences the plugin, it does not unregister it. That distinction matters: see stage 4.

**Stage 2 — disable a single area:** admin panel → plugin config → turn off the affected switch.

**Stage 3 — change the fail mode:** during a prolonged Cloudflare outage, set `fail_mode` to `open`. Forms work again; spam protection is off for that period.

**Stage 4 — remove completely:** remove the Turnstile field from every form **first** (Admin → Manage → Forms), then disable and uninstall the plugin in the admin panel, and only then delete `include/plugins/turnstile/`.

> **Do not reverse this order.** Once the folder is gone, no plugin code runs, so the `turnstile` field type can no longer be resolved. Any row of that type left in `ost_form_field` makes `FormField::getImpl()` fatal with `Class name must be a valid object or a string`, and it fatals for *every* form osTicket builds: agent panel, customer portal, and ticket creation from email all return HTTP 500 at once. Recovery means putting the folder back, removing the field through the UI, and then deleting the folder — or editing the row out of the database. Use the `DISABLED` kill switch instead if you only want the plugin silenced; it leaves the field type registered.

---

## 9. Security gate before go-live

Execute every item for real once, do not assume it. Every case with a **fresh private window** — a reused cookie jar produces false results.

| # | Case | Expectation |
|---|---|---|
| 1 | Submit the form without a Turnstile token (DevTools: remove `<div class="cf-turnstile">`) | rejected |
| 2 | Set `cf-turnstile-response` to a random string | rejected |
| 3 | Intercept a valid token and send it a second time | rejected (`timeout-or-duplicate`) |
| 4 | Token > 2048 characters | rejected, **no** outbound request |
| 5 | Clear the secret key in the config | rejected, no silent pass |
| 6 | Point `challenges.cloudflare.com` to `127.0.0.1` via `/etc/hosts`, `fail_mode=closed` | rejected, response within 5 s at the latest |
| 7 | Same with `fail_mode=open` | passed through |
| 8 | Inspect the client response after a failure | no Cloudflare error code, no path, no stack trace |
| 9 | `grep -ri "<secret-key>" /var/log/ /tmp/` | 0 hits |
| 10 | `grep -i turnstile` in the PHP error log | only `area=`, `reason=`, `ip=`, truncated `detail=` |
| 11 | Set the expected hostname to a value that is deliberately wrong, then submit a valid token | rejected, log shows `reason=hostname_mismatch` |
| 12 | Download a large attachment while a protection area is active | streams as before, no memory spike — the plugin starts no output buffer outside `login.php`, `scp/login.php`, `open.php`, `account.php` |

Case 3 is the most important one — it proves that the request cache does not defeat replay protection. Case 11 proves the hostname check is actually armed; skip it and you have no evidence that it is.

---

## 10. Known limitations

- **No protection without JavaScript.** Anyone who disables JS gets no token and cannot create a ticket. At `fail_mode=closed` that is a hard block. For accessibility, keep an alternative channel (email ticket) open — email intake is unaffected by this plugin.
- **Email tickets and the API stay unprotected.** Turnstile is a browser mechanism. Spam via `api/tickets.json` or the mail pipe needs different measures.
- **The login injection operates on rendered HTML.** It looks for the first form containing a password field. An osTicket update that rebuilds the login templates can break this — then no widget renders and login fails under `fail_mode=closed`. Re-run steps 6.3 and 6.4 after every osTicket update.
- **The CSP rewrite only runs on four scripts:** `login.php`, `scp/login.php`, `open.php`, `account.php`. That is deliberate — the rewrite lives in an output-buffer callback, and a buffer over an attachment download would hold the whole file in memory. If you attach the Turnstile field to a form that some other script renders, the widget is CSP-blocked there. Fix it by allowing `challenges.cloudflare.com` in `script-src` at the web server level (section 7), or by adding the script to `BUFFER_SCRIPTS` in `src/TurnstileLoginGate.php`.
- **`CF-Connecting-IP` is forgeable**, because the origin is reachable directly by its IP. For the `remoteip` field of siteverify this is inconsequential — it is purely informational. As soon as a decision is built on that IP elsewhere, it has to be checked against the Cloudflare ranges.
- **Privacy:** Turnstile loads a script from Cloudflare and transmits the IP address and browser signals to the United States. Cloudflare states it sets no tracking cookies. If you operate under the GDPR, your privacy policy for `support.example.com` needs a paragraph about this, including a data processing agreement with Cloudflare. *This is not legal advice — have it reviewed.*
