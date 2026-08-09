# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Planned

- Localisation of the admin UI strings (currently German only)
- Optional allowlist for IPs that bypass the challenge

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

[Unreleased]: https://github.com/studio-prisma/osticket-plugin-turnstile/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/studio-prisma/osticket-plugin-turnstile/releases/tag/v1.0.0
