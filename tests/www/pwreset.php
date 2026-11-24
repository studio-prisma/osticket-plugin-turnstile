<?php
// Nachbau von pwreset.php (Schritt 1, Passwort vergessen).
// protect_pwreset ist bewusst NICHT gesetzt: so verhaelt sich eine
// bestehende Installation direkt nach dem Update auf 1.2.0.
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
    echo "MARKER-RESET-REACHED\n";
}
?>
<!DOCTYPE html><html><body>
<form action="/search.php" method="get"><input type="text" name="q"></form>
<form action="pwreset.php" method="post" id="clientLogin">
  <input type="hidden" name="__CSRFToken__" value="x">
  <input type="hidden" name="do" value="sendmail"/>
  <input id="username" type="text" name="userid" size="30" value="">
  <input class="btn" type="submit" value="Send Email">
</form>
</body></html>
