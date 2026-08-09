<?php
// Stellvertreter für ein Skript OHNE Widget — in osTicket ist das vor allem
// der Attachment-Download (file.php, scp/file.php). Erwartung: das Plugin
// startet hier keinen Output-Buffer und fasst den CSP-Header nicht an.
// Ein Buffer über einem Download würde die komplette Datei in den Speicher
// ziehen, bevor das erste Byte rausgeht.
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

$before = ob_get_level();
(new TurnstilePlugin())->bootstrap();
$after = ob_get_level();

// Derselbe CSP-Header wie auf den echten Seiten. Läuft der Filter, würde er
// hier challenges.cloudflare.com ergänzen — genau das darf nicht passieren.
header("Content-Security-Policy: frame-ancestors 'self'; img-src 'self' data:; "
     . "script-src 'self' 'unsafe-inline'; object-src 'none'");
header('Content-Type: text/plain; charset=UTF-8');

echo "OB-BEFORE=$before OB-AFTER=$after\n";
echo "MARKER-PAYLOAD\n";
