# Cloudflare Turnstile for osTicket

[![Validate](https://github.com/studio-prisma/osticket-plugin-turnstile/actions/workflows/validate.yml/badge.svg)](https://github.com/studio-prisma/osticket-plugin-turnstile/actions/workflows/validate.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![osTicket 1.18.x](https://img.shields.io/badge/osTicket-1.18.x-1c4e80.svg)](https://osticket.com/)
[![PHP 8.1–8.2](https://img.shields.io/badge/PHP-8.1%E2%80%938.2-777bb4.svg)](https://www.php.net/)

CAPTCHA protection for osTicket using [Cloudflare Turnstile](https://developers.cloudflare.com/turnstile/) — with server-side token validation, a per-area kill switch, and a file-based emergency off switch that works even when you have locked yourself out.

**[Deutsche Fassung →](README.de.md)**

---

## What it protects

Four areas, each toggled independently:

| Area | Path | Notes |
|---|---|---|
| Guest ticket form | `open.php` | Requires the Turnstile field to be added to the form (see [INSTALL](docs/INSTALL.md) step 5) |
| Client registration | `account.php` | Requires the field on the *User Information* form |
| Client login | `login.php` | Widget is injected into the rendered login form |
| Staff login | `scp/login.php` | Enable last — see the lockout warning below |

Email intake and the JSON API are **not** covered. Turnstile is a browser mechanism; those channels need different controls.

---

## Why this and not the existing plugin

Compared to [`nhaskaris/turnstile_osticket`](https://github.com/nhaskaris/turnstile_osticket), the closest existing implementation:

| Concern | Reference plugin | This plugin |
|---|---|---|
| cURL timeout | none — login hangs during a Cloudflare outage | `CURLOPT_TIMEOUT` + `CURLOPT_CONNECTTIMEOUT` |
| Repeated validation in one request | static `$was_validated` flag skips every later check | result cache keyed per token |
| Hostname check | none — tokens minted for other domains are accepted | optional, enforced against the `hostname` field of the siteverify response |
| Error messages | Cloudflare error codes are shown to the user verbatim | generic message to the user, detail only in the log |
| Login pages | not covered | `TurnstileLoginGate` |
| Content-Security-Policy | needs a core patch to allow `challenges.cloudflare.com` | the plugin rewrites its own header, update-safe |
| Scope control | all or nothing | four independent switches plus a kill switch |

---

## Requirements

- osTicket **1.18.x** (the plugin API is identical in 1.17.x, but only 1.18.x is verified)
- PHP **8.1–8.2** with the `curl` extension — the range osTicket 1.18 supports. The plugin code itself is PHP 8.0-compatible and additionally verified on 8.3 and 8.4
- Outbound HTTPS to `challenges.cloudflare.com`
- A Cloudflare account (Turnstile is free)

---

## Install

Full step-by-step guide with abort points, smoke test, and security gate: **[docs/INSTALL.md](docs/INSTALL.md)** ([deutsch](docs/INSTALL.de.md)).

Short version:

1. Download `turnstile-<version>.zip` from the [latest release](https://github.com/studio-prisma/osticket-plugin-turnstile/releases/latest).
2. Unzip into `include/plugins/` so the result is `include/plugins/turnstile/`.
3. Match owner and permissions to the neighbouring plugin folders (`755` dirs, `644` files).
4. Admin panel → **Manage → Plugins → Add New Plugin** → *Cloudflare Turnstile* → **Install**.
5. Create an instance, enter the site key and secret key, enable **only** the guest ticket form for now.
6. Admin panel → **Manage → Forms** → add a field of type **Cloudflare Turnstile** to the ticket form.
7. Run the smoke test before enabling any login area.

> **Lockout warning.** Before enabling *Staff login*, keep a second, already authenticated agent session open in a different browser. If the widget fails to render, that session is your way back in. The alternative is the kill switch below.

---

## Kill switch

Works without database access, without the admin panel, and while you are locked out:

```bash
touch include/plugins/turnstile/DISABLED
```

`bootstrap()` aborts immediately — no validation, no widget, no output buffer. Remove the file to re-enable.

Further rollback stages are documented in [INSTALL §8](docs/INSTALL.md#8-kill-switch-and-rollback).

---

## Configuration

| Setting | Default | Purpose |
|---|---|---|
| Site key | — | Public key from the Cloudflare Turnstile dashboard |
| Secret key | — | Private key. Never rendered, never logged, never in an error message |
| Expected hostname | empty | If set, the `hostname` in the siteverify response must match. Blocks tokens minted for other domains |
| Guest ticket form | off | Protects `open.php` |
| Client registration | off | Protects `account.php` |
| Client login | off | Protects `login.php` |
| Staff login | off | Protects `scp/login.php` |
| Fail mode | `closed` | Behaviour when Cloudflare is unreachable: `closed` rejects, `open` lets through |
| siteverify timeout | 5 s | Total cURL timeout, connect timeout included |
| Widget theme | auto | `auto`, `light`, `dark` |
| Widget size | normal | `normal`, `compact` |
| Log failures | on | Writes area, reason, IP, and a truncated detail to the PHP error log |

Configuration is validated on save: an empty site or secret key is rejected, hostnames are normalised to lowercase and syntax-checked, and enabling *Staff login* together with `fail_mode = closed` raises an explicit warning.

---

## Fail mode

`closed` is the safe default: if Cloudflare cannot be reached within the timeout, the request is rejected. During a prolonged Cloudflare outage, switch to `open` to keep forms usable — spam protection is off for that period.

The connect timeout fires first, so a blackholed endpoint answers in about 2 s at a 3 s setting rather than hanging.

---

## Content-Security-Policy

osTicket hardcodes `script-src 'self' 'unsafe-inline'` via `header()` in five places, which blocks the Turnstile script.

`TurnstileLoginGate::relaxCsp()` runs inside an output-buffer callback — after osTicket's `header()` call and before headers are sent — and appends exactly one origin to `script-src`. No core patch, survives osTicket updates.

Only `script-src` is touched. osTicket sets neither `default-src` nor `frame-src`; adding a `frame-src` would restrict every other frame on the page rather than permit anything.

If a reverse proxy or web server sets its own CSP, the browser applies the intersection and the stricter policy wins. Fix it at that layer — see [INSTALL §7](docs/INSTALL.md#7-content-security-policy).

---

## Testing

```bash
PHP_BIN=/path/to/php8.3 tests/run-all.sh     # lint, unit, fail-mode, integration
```

Verified on PHP 8.3.33 against a real osTicket 1.18.4 with MariaDB 10.6 — not only against stubs.

| Stage | Scope | Result |
|---|---|---|
| Lint | 8 files, `php -l` | 8/8 |
| Unit | config, settings, markup, verifier, login gate, form field, bootstrap | 95/95 |
| Fail-mode | endpoint redirected to a blackhole | 9/9 |
| Integration | `php -S`, real HTTP requests against a login rebuild | 25/25 |
| End-to-end | real osTicket 1.18.4, plugin active via the plugin manager | 37/37 |

The siteverify calls hit the **real** Cloudflare API using the official test keys (`1x…` always valid, `2x…` always invalid, `3x…` returns `timeout-or-duplicate`). No mock — the network path is exercised.

Details and the end-to-end setup: [tests/README.md](tests/README.md).

---

## Known limitations

- **No protection without JavaScript.** Without JS there is no token, and at `fail_mode = closed` that is a hard block. Keep an alternative channel (email ticket) open for accessibility — email intake is unaffected by this plugin.
- **Email tickets and the API stay unprotected.** Spam via `api/tickets.json` or the mail pipe needs different controls.
- **The login injection operates on rendered HTML.** It targets the first form containing a password field. An osTicket update that rebuilds the login templates can break this — re-run the login smoke tests after every osTicket update.
- **`CF-Connecting-IP` is forgeable** when the origin is reachable directly by IP. Harmless for the `remoteip` field of siteverify, which is purely informational, but do not build authorisation decisions on it.
- **Browser execution of the AJAX fragment is not automatically verified.** The delivered markup and re-render hook are covered by tests; whether Cloudflare's `api.js` actually starts inside the fragment depends on how osTicket injects it. A bootstrap poller re-renders every 250 ms for 10 s as a safety net. Smoke test step 6.1 confirms it.
- **The admin UI strings are German.** The code, documentation, and issue templates are English; the plugin configuration labels are not yet localised. Tracked as a follow-up.

## Privacy

Turnstile loads a script from Cloudflare and transmits the visitor's IP address and browser signals to the United States. Cloudflare states it sets no tracking cookies. If you operate under the GDPR, add a corresponding paragraph to your privacy policy and put a data processing agreement with Cloudflare in place. This is not legal advice — have it reviewed.

---

## Contributing

Bug reports, compatibility reports, and pull requests are welcome. Please read [CONTRIBUTING.md](CONTRIBUTING.md) first — it covers the verification steps a change has to pass.

Security issues: **do not open a public issue.** See [SECURITY.md](SECURITY.md).

## License

[MIT](LICENSE) © studio-prisma
