# Contributing

Thanks for taking the time. This plugin sits in the authentication path of a helpdesk, so the bar for changes is verification, not volume.

## Before you open a pull request

1. **Open an issue first** for anything beyond a typo or a documentation fix. It is cheaper to discuss the approach than to review a rewrite.
2. **Security-relevant changes need a threat-model note.** If your change touches token validation, the siteverify call, the fail mode, the login gate, the CSP handling, or `pre_save` validation, describe in the PR what an attacker could now do that they could not do before, and why that is acceptable.
3. **Never report a vulnerability as a pull request.** See [SECURITY.md](SECURITY.md).

## Development setup

No build step, no dependencies. Clone the repository and point a PHP CLI at it.

```bash
git clone https://github.com/studio-prisma/osticket-plugin-turnstile.git
cd osticket-plugin-turnstile
PHP_BIN=/path/to/php8.3 tests/run-all.sh
```

The suite needs outbound HTTPS to `challenges.cloudflare.com` — the siteverify tests run against the real API with Cloudflare's official test keys, not against a mock.

To build the install package:

```bash
./tools/build-zip.sh          # writes dist/turnstile-<version>.zip
```

## What a change has to pass

| Check | Command |
|---|---|
| Syntax, all production files | `find . -name '*.php' -not -path './tests/*' -exec php -l {} \;` |
| Unit tests | `cd tests && PLUGIN_DIR=$(pwd)/.. php unit.php` |
| Fail-mode tests | via `tests/run-all.sh` |
| Integration tests | `cd tests && python3 integration.py` |

CI runs the same checks across PHP 8.0 to 8.4 on every push and pull request. A red pipeline is not mergeable.

For anything touching the login gate or the form field, also verify against a **real osTicket 1.18.x instance** — the stubs mirror osTicket's signatures but not its rendering behaviour. `tests/e2e_setup.sh` and `tests/e2e_osticket.py` automate that; see [tests/README.md](tests/README.md).

## Code conventions

- **PHP 8.0 compatible.** osTicket 1.18 supports PHP 8.1–8.2; the plugin stays one minor below that floor so it also runs on a 1.17 instance. No `match`, no enums, no readonly properties, no named arguments. CI enforces this by linting on 8.0.
- **No new dependencies.** The plugin ships eight files and uses only `curl` from the PHP core. Keep it that way.
- **No hardcoded hostnames, credentials, absolute paths, or customer data** — anywhere, including tests. Test credentials come from environment variables.
- **Failures are generic to the client, specific to the log.** Cloudflare error codes, file paths, and stack traces must never reach the browser.
- **Every new configuration option needs validation in `pre_save`** and a row in the configuration table of both READMEs.

## Documentation

User-facing changes need updates in all four documents that describe behaviour:

- `README.md` and `README.de.md`
- `docs/INSTALL.md` and `docs/INSTALL.de.md`

If you are not comfortable writing the German version, write the English one and say so in the PR — the translation will be handled during review rather than blocking your change.

Add a `CHANGELOG.md` entry under `## [Unreleased]` using the Keep a Changelog categories (`Added`, `Changed`, `Fixed`, `Removed`, `Security`).

## Commit messages

Conventional Commits, imperative mood:

```
fix(verifier): reject tokens above the length limit before the outbound call
feat(config): add widget size selector
docs(install): clarify the CSP intersection case
ci: pin actions to major versions
```

## Release process

Maintainers only:

1. Move the `## [Unreleased]` entries into a new `## [x.y.z] - YYYY-MM-DD` section.
2. Bump `'version'` in `plugin.php` to the same number. CI fails the release if the tag and the manifest disagree.
3. Commit, then tag: `git tag -a vx.y.z -m "vx.y.z"` and push the tag.
4. The `Release` workflow builds `dist/turnstile-x.y.z.zip`, extracts the changelog section, and publishes the GitHub release.

## License

By contributing you agree that your contribution is licensed under the [MIT License](LICENSE).
