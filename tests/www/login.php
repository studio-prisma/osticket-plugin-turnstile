<?php
// Minimal-Nachbau der osTicket-Reihenfolge:
//   main.inc.php -> osTicket::start() -> Plugin::bootstrap()
//   DANN erst include/client/header.inc.php mit dem CSP-header()
define('INCLUDE_DIR', getenv('STUB_DIR'));
define('OSTICKET_VERSION', '1.18.4');
require_once getenv('PLUGIN_DIR') . '/class.TurnstilePlugin.php';

$pc = new TurnstileConfig();
$pc->set('cf_site_key',   '1x00000000000000000000AA');
$pc->set('cf_secret_key', getenv('CF_SECRET'));
$pc->set('protect_client_login', 1);
$pc->set('protect_staff_login', 1);
$pc->set('fail_mode', getenv('FAIL_MODE') ?: 'closed');
$pc->set('timeout', 5);
$pc->set('theme', 'auto'); $pc->set('size', 'normal'); $pc->set('log_failures', 0);
Plugin::$testConfig = $pc;

(new TurnstilePlugin())->bootstrap();

// ---- ab hier: was osTicket selbst ausgibt ----
header("Content-Security-Policy: frame-ancestors 'self'; img-src 'self' data:; "
     . "script-src 'self' 'unsafe-inline'; object-src 'none'");

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // Wenn wir hier ankommen, hat das Gate den Request DURCHGELASSEN.
    echo "MARKER-AUTH-REACHED\n";
}
?>
<!DOCTYPE html><html><body>
<form action="/search.php" method="get"><input type="text" name="q"></form>
<form method="post" action="login.php">
  <input type="text" name="luser">
  <input type="password" name="lpasswd">
  <input type="submit" value="Anmelden">
</form>
</body></html>
