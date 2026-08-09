# Security Policy

## Supported Versions

Only the latest minor release line is actively maintained. Older minor releases do not receive patches.

| Version | Status |
|---------|---------------|
| 1.0.x   | ✅ Supported |
| < 1.0   | ❌ End of life |

## Reporting a Vulnerability

This plugin sits directly in the authentication and form-submission path of an osTicket instance. Please treat findings accordingly and report them **privately**, never as a public issue.

1. Open the [Security tab](https://github.com/studio-prisma/osticket-plugin-turnstile/security)
2. Click **Report a vulnerability**
3. Include a clear description, affected version, and reproduction steps

You will get a first response within **14 days**. Resolution timeline is best-effort — this is a project maintained by a single person.

Please do **not** include Cloudflare secret keys, ticket contents, or customer data in a report.

## In Scope

Findings in these areas are the ones that matter most:

- **Token validation bypass** — any path that lets a request through without a valid, unused Turnstile token
- **Replay** — reuse of an already redeemed token, or a cache that defeats `timeout-or-duplicate`
- **Hostname enforcement bypass** — accepting a token minted for a different domain while a hostname is configured
- **Fail-mode bypass** — a request passing under `fail_mode = closed` while Cloudflare is unreachable
- **Secret key exposure** — the secret key appearing in rendered HTML, an error message, a log line, or a build artefact
- **Information disclosure** — Cloudflare error codes, filesystem paths, or stack traces reaching the client
- **CSP handling** — the header rewrite weakening the policy beyond the single added `script-src` origin
- **Lockout without recourse** — a state in which the `DISABLED` kill switch does not restore access

## Out of Scope

- **Email intake and the JSON API.** Turnstile is a browser mechanism; `api/tickets.json` and the mail pipe are explicitly not covered by this plugin. Spam through those channels is not a vulnerability in this codebase.
- **Absence of protection without JavaScript.** Documented and intentional. At `fail_mode = closed` this is a hard block by design.
- **`CF-Connecting-IP` being forgeable.** Documented. The value is passed to siteverify as informational `remoteip` only and no decision is built on it.
- Vulnerabilities in osTicket core — report those to the [osTicket project](https://github.com/osTicket/osTicket/security).
- Issues that only affect a version outside the supported line.
- Dependency findings already tracked by Dependabot for the GitHub Actions used in CI.

## Handling of Secrets

The secret key is stored in the osTicket plugin configuration and never leaves the server. It is not rendered into HTML, not written to logs, and not included in error messages. `tools/build-zip.sh` refuses to build if a literal secret is found in the staged files. If you believe you have found a path where it leaks, that is an in-scope finding — report it privately.

## Disclosure

Once a fix is merged and a release is published, the affected version is referenced in the [CHANGELOG](CHANGELOG.md) and the corresponding GitHub release notes. Reporters who wish to be credited will be acknowledged in the release notes.
