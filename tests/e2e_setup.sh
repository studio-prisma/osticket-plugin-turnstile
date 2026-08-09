#!/usr/bin/env bash
set -e
SRC="$(cd "$(dirname "$0")/.." && pwd)"
OST=$HOME/w/ost/upload
export LD_LIBRARY_PATH=$HOME/w/mdb/usr/lib/x86_64-linux-gnu
M="$HOME/w/mdb/usr/bin/mysql --socket=$HOME/w/run/my.sock -u root osticket"

rm -rf $OST/include/plugins/turnstile
mkdir -p $OST/include/plugins/turnstile
cp "$SRC/plugin.php" "$SRC/config.php" "$SRC/class.TurnstilePlugin.php" $OST/include/plugins/turnstile/
cp -r "$SRC/src" $OST/include/plugins/turnstile/

$M <<'SQL'
DELETE FROM ost_plugin_instance;
DELETE FROM ost_plugin;
DELETE FROM ost_config WHERE namespace LIKE 'plugin.%';
DELETE FROM ost_form_field WHERE type='turnstile';

INSERT INTO ost_plugin (id,name,install_path,isphar,isactive,version,notes,installed)
VALUES (1,'Cloudflare Turnstile','plugins/turnstile',0,1,'1.0.0','','2026-08-09 00:00:00');

INSERT INTO ost_plugin_instance (id,plugin_id,flags,name,notes,created,updated)
VALUES (1,1,1,'default','', NOW(), NOW());

INSERT INTO ost_config (namespace,`key`,value,updated) VALUES
 ('plugin.1.instance.1','cf_site_key','1x00000000000000000000AA',NOW()),
 ('plugin.1.instance.1','cf_secret_key','1x0000000000000000000000000000000AA',NOW()),
 ('plugin.1.instance.1','cf_hostname','',NOW()),
 ('plugin.1.instance.1','fail_mode','closed',NOW()),
 ('plugin.1.instance.1','timeout','5',NOW()),
 ('plugin.1.instance.1','theme','auto',NOW()),
 ('plugin.1.instance.1','size','normal',NOW()),
 ('plugin.1.instance.1','log_failures','1',NOW()),
 ('plugin.1.instance.1','protect_ticket','1',NOW()),
 ('plugin.1.instance.1','protect_client_register','0',NOW()),
 ('plugin.1.instance.1','protect_client_login','1',NOW()),
 ('plugin.1.instance.1','protect_staff_login','1',NOW());

INSERT INTO ost_form_field (form_id,flags,type,label,name,configuration,sort,hint,created,updated)
SELECT 2, flags, 'turnstile', 'Sicherheitsprüfung', 'turnstile', '{}', 9, '', NOW(), NOW()
FROM ost_form_field WHERE id=20;
SQL
echo "Plugin registriert. Feld:"; $M -e "SELECT id,form_id,type,name,sort FROM ost_form_field WHERE type='turnstile'"
