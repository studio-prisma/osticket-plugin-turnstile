# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Planned

- Localisation of the admin UI strings (currently German only)
- Optional allowlist for IPs that bypass the challenge

## [1.0.1] - 2026-08-09

### Fixed

- Configuration could not be saved in two valid states. `pre_save()` reported
  warnings through the `$errors` array, but osTicket treats a non-empty
  `$errors` as an abort (`count($errors) === 0` in `PluginConfig::store()`).
  Enabling staff login with `fail_mode = closed` — the combination the install
  guide recommends — therefore blocked the save. Warnings now go through
  `osTicket::setWarning()` and no longer prevent saving.
- The output buffer is no longer started on every request. It is limited to the
  scripts that can render a widget (`login.php`, `scp/login.php`, `open.php`,
  `account.php`). `ob_start()` with a callback holds the whole response in
  memory until the request ends, so on an attachment download the entire file
  was buffered although the callback had nothing to do there.
- Replaced three references to a file that does not exist in this repository
  with `docs/INSTALL.md`. One of them was rendered in the admin panel.
- Corrected the PHP version claims, which contradicted each other across the
  badge, the manifest gate, and CI: the plugin is PHP 8.0 compatible, CI lints
  and tests 8.0 to 8.4, production runs 8.3.33. The claim that osTicket 1.18
  supports only PHP 8.1–8.2 was wrong; 1.18.2 and newer support 8.3 and 8.4.
- `README` listed the guest ticket form as off by default; it is on.

### Changed

- `isCompatible()` now requires PHP 8.0 instead of 7.4, matching the CI floor.
  A runtime that is never tested is no longer accepted.
- The expected hostname is presented as recommended rather than optional. Saving
  with an active protection area and no hostname raises a warning, and the field
  hint states the consequence: a token minted on another domain using the same
  widget is accepted.

### Added

- Security gate cases 11 (hostname mismatch is rejected) and 12 (large
  attachment download starts no output buffer) in the installation guide.
- Tests: `needsBuffer()` allowlist table in `unit.php`, integration case I7
  proving that a script without a widget gets neither a buffer nor a CSP rewrite.

## [1.0.0] - 2026-08-09

First public release.

### Added

- Cloudflare Turnstile verification for four independently switchable areas:
  guest ticket form, client registration, client login, and staff login.
- Server-side token validation against the Cloudflare `siteverify` endpoint,
  with a per-token result cache so a repeated validation inside the same request
  cannot be skipped or replayed.
- Optional hostname enforcement — the `hostname` field of the siteverify response
  is matched against the configured value, rejecting tokens minted for other domains.
- Configurable fail mode (`closed` / `open`) governing behaviour when Cloudflare
  is unreachable, plus a configurable siteverify timeout covering both the total
  and the connect timeout.
- `TurnstileLoginGate`, which injects the widget into the rendered client and staff
  login forms and relaxes osTicket's hardcoded Content-Security-Policy from within
  an output-buffer callback — no core patch, survives osTicket updates.
- `TurnstileFormField`, registering a `Cloudflare Turnstile` field type usable on any
  osTicket form, rendered in `render=explicit` mode with a re-render poller so it also
  works when osTicket loads the form via AJAX.
- File-based kill switch: `touch include/plugins/turnstile/DISABLED` aborts `bootstrap()`
  immediately, without database access and while locked out.
- Configuration validation on save: empty site or secret key rejected, hostname
  normalised to lowercase and syntax-checked, explicit warning when staff login is
  enabled together with `fail_mode = closed`.
- Failure logging with area, reason, IP, and a truncated detail — the secret key
  and Cloudflare error codes never reach the client.
- Guard rails against pointless outbound requests: tokens over 2048 characters and
  empty tokens are rejected locally.
- Test suite: lint, unit, fail-mode, integration, and an end-to-end harness against a
  real osTicket installation. siteverify is exercised against the real Cloudflare API
  using the official test keys.
- Installation guide with abort points, smoke test, and a ten-case security gate
  (English and German).

[Unreleased]: https://github.com/studio-prisma/osticket-plugin-turnstile/compare/v1.0.1...HEAD
[1.0.1]: https://github.com/studio-prisma/osticket-plugin-turnstile/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/studio-prisma/osticket-plugin-turnstile/releases/tag/v1.0.0
