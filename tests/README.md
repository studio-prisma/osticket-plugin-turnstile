# Tests

## Quick run (no osTicket required)

```bash
PHP_BIN=/path/to/php8.3 ./run-all.sh
```

Lint · unit · fail-mode · integration. Requires outbound HTTPS to
`challenges.cloudflare.com` — the siteverify tests run against the real API using
Cloudflare's official test keys, not against a mock.

| File | Purpose |
|---|---|
| `unit.php` | config, settings, markup, verifier, login gate, form field, bootstrap |
| `failmode.php` | endpoint redirected to a blackhole: timeout, fail closed/open, cache |
| `integration.py` | `php -S` with a login rebuild: injection, CSP headers, 403 paths |
| `stubs/` | `FormField`, `Widget`, `Plugin`, `PluginConfig` — signatures from osTicket 1.18.4 |

`unit.php` and `failmode.php` resolve the plugin directory from `PLUGIN_DIR`,
falling back to the repository root. `run-all.sh` exports it for you.

## End-to-end against a real osTicket

`e2e_setup.sh` and `e2e_osticket.py` verify against a real installation: AJAX
reloading of the ticket form, ticket creation with and without a token, the staff
login AJAX path, CSP on both header paths, and the kill switch.

Prerequisites:

- osTicket 1.18.x extracted and installed, MySQL/MariaDB reachable
- PHP CLI with `mysqli`
- Cloudflare test keys in the plugin configuration

The harness expects the osTicket installation under `~/w/ost/upload` and the
MariaDB socket under `~/w/run/my.sock`. Adjust the paths at the top of both files
if your layout differs.

Staff credentials are **not** stored in the repository. Provide them via the
environment:

```bash
export OST_STAFF_USER=your-admin-login     # optional, defaults to "admin"
export OST_STAFF_PASS='your-admin-password'
./e2e_setup.sh
python3 e2e_osticket.py
```

`e2e_setup.sh` copies the plugin into the installation, registers it via SQL in
`ost_plugin` / `ost_plugin_instance`, writes the configuration into `ost_config`
(namespace `plugin.<id>.instance.<n>`), and attaches the Turnstile field to form 2.

**Important:** `install_path` in `ost_plugin` is relative to `INCLUDE_DIR`, so it is
`plugins/turnstile`, not `include/plugins/turnstile`. See
`include/class.plugin.php:315`.

## Boundary

No browser is involved. What is verified is that the markup and the re-render hook
are delivered — not that Cloudflare's `api.js` actually starts inside the AJAX
fragment. That is smoke test step 6.1 in [docs/INSTALL.md](../docs/INSTALL.md).
