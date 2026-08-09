<?php
$PLUGIN = getenv('PLUGIN_DIR') ?: dirname(__DIR__);
define('INCLUDE_DIR', __DIR__ . '/stubs/');
define('OSTICKET_VERSION', '1.18.4');
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/open.php';
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

$PASS=0; $FAIL=0; $GROUP='';
function grp($n){ global $GROUP; $GROUP=$n; echo "\n== $n ==\n"; }
function ok($cond,$name,$extra=''){ global $PASS,$FAIL;
  if($cond){ $PASS++; echo "  PASS  $name\n"; }
  else { $FAIL++; echo "  FAIL  $name" . ($extra!==''?"  -> $extra":"") . "\n"; } }
function eq($a,$b,$name){ ok($a===$b,$name, "erwartet=".var_export($b,true)." bekommen=".var_export($a,true)); }

// ---------- T1: Laden ohne Fatal ----------
grp('T1 Laden / Signatur-Kompatibilität (PHP '.PHP_VERSION.')');
require_once $PLUGIN . '/class.TurnstilePlugin.php';
ok(class_exists('TurnstilePlugin'),'TurnstilePlugin geladen');
ok(class_exists('TurnstileConfig'),'TurnstileConfig geladen');
ok(class_exists('TurnstileFormField'),'TurnstileFormField geladen');
ok(class_exists('TurnstileFieldWidget'),'TurnstileFieldWidget geladen');
ok(class_exists('TurnstileVerifier'),'TurnstileVerifier geladen');
ok(class_exists('TurnstileLoginGate'),'TurnstileLoginGate geladen');
$manifest = include $PLUGIN . '/plugin.php';
eq($manifest['plugin'],'class.TurnstilePlugin.php:TurnstilePlugin','Manifest zeigt auf existierende Klasse');
$f = new TurnstileFormField(); $w = new TurnstileFieldWidget($f);
ok($f instanceof FormField && $w instanceof Widget,'Instanziierung Field+Widget ohne Fatal');
$r = new ReflectionMethod('TurnstileFieldWidget','render');
eq($r->getNumberOfParameters(),2,'Widget::render nimmt 2 Parameter (wie TextboxWidget)');

// ---------- T2: Config ----------
grp('T2 Config');
$cfg = new TurnstileConfig();
$opts = $cfg->getOptions();
foreach (['cf_site_key','cf_secret_key','cf_hostname','protect_ticket','protect_client_register',
          'protect_client_login','protect_staff_login','fail_mode','timeout','theme','size','log_failures'] as $k)
  ok(isset($opts[$k]), "Option vorhanden: $k");
$e=[]; $c=['cf_site_key'=>'','cf_secret_key'=>'x','protect_ticket'=>1];
eq($cfg->pre_save($c,$e),false,'pre_save lehnt leeren Site Key ab');
$e=[]; $c=['cf_site_key'=>'a','cf_secret_key'=>'b','cf_hostname'=>'evil.com/../x','protect_ticket'=>1];
eq($cfg->pre_save($c,$e),false,'pre_save lehnt ungültigen Hostname ab');
$e=[]; $c=['cf_site_key'=>'a','cf_secret_key'=>'b','cf_hostname'=>'Service.EXAMPLE.COM','protect_ticket'=>1];
ok($cfg->pre_save($c,$e)===true && $c['cf_hostname']==='service.example.com','pre_save normalisiert Hostname auf lowercase');
$e=[]; $c=['cf_site_key'=>'a','cf_secret_key'=>'b','protect_staff_login'=>1,'fail_mode'=>'closed'];
$r=$cfg->pre_save($c,$e);
// osTicket bricht das Speichern bei count($errors)!==0 ab (class.plugin.php store()).
// Eine Warnung in $errors würde die Config also unspeicherbar machen.
ok($r===true && $e===[],'pre_save gibt Warnungen NICHT über $errors aus (Config bleibt speicherbar)',var_export($e,true));
ok(count(TurnstileConfig::$lastWarnings)>=1,'pre_save warnt bei Staff-Login + fail closed');
$e=[]; $c=['cf_site_key'=>'a','cf_secret_key'=>'b','protect_ticket'=>1];
ok($cfg->pre_save($c,$e)===true && $e===[] && count(TurnstileConfig::$lastWarnings)===1
   && strpos(TurnstileConfig::$lastWarnings[0],'Hostname')!==false,
   'pre_save warnt bei aktivem Schutz ohne Hostname',var_export(TurnstileConfig::$lastWarnings,true));
$e=[]; $c=['cf_site_key'=>'a','cf_secret_key'=>'b','cf_hostname'=>'support.example.com','protect_ticket'=>1];
ok($cfg->pre_save($c,$e)===true && TurnstileConfig::$lastWarnings===[],'mit Hostname + Schutz: keine Warnung',var_export(TurnstileConfig::$lastWarnings,true));

// ---------- T3: Settings ----------
grp('T3 Settings');
TurnstileSettings::load([
  'cf_site_key'=>'1x00000000000000000000AA','cf_secret_key'=>'sec','cf_hostname'=>'',
  'fail_mode'=>'closed','timeout'=>5,'theme'=>'dark','size'=>'compact','log_failures'=>false,
  'protect_ticket'=>1,'protect_client_register'=>0,'protect_client_login'=>1,'protect_staff_login'=>0,
]);
ok(TurnstileSettings::isLoaded(),'isLoaded');
eq(TurnstileSettings::protects('ticket'),true,'protects(ticket)');
eq(TurnstileSettings::protects('register'),false,'protects(register) aus');
eq(TurnstileSettings::protects('staff'),false,'protects(staff) aus');
eq(TurnstileSettings::protects('unsinn'),false,'unbekannter Bereich = false');
$vo = TurnstileSettings::verifierOptions('ticket');
eq($vo['fail_mode'],'closed','verifierOptions fail_mode');
$_SERVER['SCRIPT_NAME']='/account.php'; eq(TurnstileSettings::currentArea(),'register','currentArea account.php');
$_SERVER['SCRIPT_NAME']='/open.php';    eq(TurnstileSettings::currentArea(),'ticket','currentArea open.php');

// ---------- T4: Markup / XSS ----------
grp('T4 Markup');
TurnstileMarkup::reset();
$html = TurnstileMarkup::render(['sitekey'=>'ab"><script>alert(1)</script>','theme'=>'dark','size'=>'compact','action'=>'ticket']);
ok(strpos($html,'<script>alert(1)')===false,'Sitekey-XSS wird escaped');
ok(strpos($html,'&quot;&gt;&lt;script&gt;')!==false,'Escaping tatsächlich angewendet');
ok(substr_count($html,'challenges.cloudflare.com/turnstile/v0/api.js')===1,'api.js genau einmal');
ok(strpos($html,'render=explicit')!==false,'expliziter Render-Modus');
$html2 = TurnstileMarkup::render(['sitekey'=>'k']);
eq($html2,'','zweiter render() liefert nichts: ein Widget pro Request');
ok(strpos(TurnstileMarkup::bootstrap(),'ostTurnstileRender()')!==false,'bootstrap() allein triggert nur Re-Render');
ok(strpos(TurnstileMarkup::bootstrap(),'api.js')===false,'bootstrap() lädt api.js nicht erneut');
ok(strpos($html,'setInterval')!==false,'Poller-Sicherheitsnetz im Bootstrap-JS');
TurnstileMarkup::reset();
$h3 = TurnstileMarkup::container(['sitekey'=>'k','theme'=>'HACK','size'=>'HACK']);
ok(strpos($h3,'data-theme="auto"')!==false && strpos($h3,'data-size="normal"')!==false,'ungültige theme/size fallen auf Default');
eq(TurnstileMarkup::container(['sitekey'=>'']),'','ohne Sitekey kein Container');

// ---------- T5: Verifier Guards (ohne Netz) ----------
grp('T5 Verifier Guards');
$base = ['secret'=>'1x0000000000000000000000000000000AA','timeout'=>5,'hostname'=>'','fail_mode'=>'closed','log'=>false,'area'=>'test'];
TurnstileVerifier::resetCache();
$t0=microtime(true); $r1=TurnstileVerifier::verify('',$base); $d1=microtime(true)-$t0;
eq($r1['reason'],'missing','leeres Token -> missing');
ok($d1<0.05,'leeres Token macht keinen Netzaufruf',sprintf('%.3fs',$d1));
$t0=microtime(true); $r2=TurnstileVerifier::verify(str_repeat('A',2049),$base); $d2=microtime(true)-$t0;
eq($r2['reason'],'too_long','2049 Zeichen -> too_long');
ok($d2<0.05,'überlanges Token macht keinen Netzaufruf',sprintf('%.3fs',$d2));
$r3=TurnstileVerifier::verify('XXXX.DUMMY.TOKEN.XXXX',array_merge($base,['secret'=>'']));
eq($r3['reason'],'misconfigured','leeres Secret -> misconfigured, kein stiller Pass');
eq($r3['ok'],false,'leeres Secret lässt nicht durch');
$r4=TurnstileVerifier::verify(['array'],$base);
eq($r4['reason'],'missing','Nicht-String-Token -> missing');
ok(strpos($r3['message'],'secret')===false && strpos($r3['message'],'Secret')===false,'Fehlermeldung nennt kein Secret');

// ---------- T6: echte siteverify-Calls ----------
grp('T6 siteverify (echte Calls, Cloudflare-Testkeys)');
$DUMMY='XXXX.DUMMY.TOKEN.XXXX';
TurnstileVerifier::resetCache();
$pass=TurnstileVerifier::verify($DUMMY, array_merge($base,['secret'=>'1x0000000000000000000000000000000AA']));
eq($pass['ok'],true,'always-pass Secret -> ok');
eq($pass['reason'],'ok','reason=ok');
TurnstileVerifier::resetCache();
$fail=TurnstileVerifier::verify($DUMMY, array_merge($base,['secret'=>'2x0000000000000000000000000000000AA']));
eq($fail['ok'],false,'always-fail Secret -> abgelehnt');
eq($fail['reason'],'rejected','reason=rejected');
TurnstileVerifier::resetCache();
$spent=TurnstileVerifier::verify($DUMMY, array_merge($base,['secret'=>'3x0000000000000000000000000000000AA']));
eq($spent['ok'],false,'"token already spent" Secret -> abgelehnt');
ok(in_array('timeout-or-duplicate',$spent['codes'],true),'Replay-Fehlercode timeout-or-duplicate',implode(',',$spent['codes']));
ok(strpos($spent['message'],'timeout-or-duplicate')===false,'Cloudflare-Fehlercode leakt NICHT an den Nutzer',$spent['message']);
ok(strpos($fail['message'],'invalid-input')===false,'kein Fehlercode-Leak (always-fail)',$fail['message']);

// Hostname-Gegenprüfung
TurnstileVerifier::resetCache();
$hostBad=TurnstileVerifier::verify($DUMMY, array_merge($base,['secret'=>'1x0000000000000000000000000000000AA','hostname'=>'nicht-meine-domain.invalid']));
eq($hostBad['ok'],false,'falscher erwarteter Hostname -> abgelehnt');

// ---------- T7: Request-Cache / Replay ----------
grp('T7 Request-Cache');
TurnstileVerifier::resetCache();
$a=TurnstileVerifier::verify($DUMMY, array_merge($base,['secret'=>'1x0000000000000000000000000000000AA']));
$b=TurnstileVerifier::verify($DUMMY, array_merge($base,['secret'=>'2x0000000000000000000000000000000AA']));
ok($a['ok']===true && $b['ok']===true,'2. Aufruf desselben Tokens kommt aus dem Cache (kein 2. HTTP-Call)');
TurnstileVerifier::resetCache();
$c1=TurnstileVerifier::verify($DUMMY, array_merge($base,['secret'=>'2x0000000000000000000000000000000AA']));
eq($c1['ok'],false,'nach resetCache greift wieder der echte Call');

// ---------- T8: LoginGate ----------
grp('T8 LoginGate');
$rm=new ReflectionMethod('TurnstileLoginGate','detectArea'); $rm->setAccessible(true);
$_SERVER['SCRIPT_NAME']='/scp/login.php'; eq($rm->invoke(null),'staff','detectArea scp/login.php');
$_SERVER['SCRIPT_NAME']='/login.php';     eq($rm->invoke(null),'login','detectArea login.php');
$_SERVER['SCRIPT_NAME']='/open.php';      eq($rm->invoke(null),'','detectArea open.php = kein Login');
$_SERVER['SCRIPT_NAME']='/support/scp/login.php'; eq($rm->invoke(null),'staff','detectArea im Unterverzeichnis');

$lk=new ReflectionMethod('TurnstileLoginGate','looksLikeLogin'); $lk->setAccessible(true);
$_POST=[]; eq($lk->invoke(null),false,'leerer POST ist kein Login');
$_POST=['luser'=>'a','lpasswd'=>'b']; eq($lk->invoke(null),true,'Client-Login erkannt');
$_POST=['username'=>'a','passwd'=>'b']; eq($lk->invoke(null),true,'Staff-Login erkannt');
$_POST=['email'=>'a@b.de']; eq($lk->invoke(null),false,'Passwort-vergessen ist kein Login');
$_POST=[];

$inj=new ReflectionMethod('TurnstileLoginGate','injectIntoLoginForm'); $inj->setAccessible(true);
$M='[WIDGET]';
$page='<html><body><form action="/search"><input type="text" name="q"></form>'
    . '<form method="post" action="login.php"><input type="text" name="luser">'
    . '<input type="password" name="lpasswd"><input type="submit"></form></body></html>';
$out=$inj->invoke(null,$page,$M);
eq(substr_count($out,$M),1,'Widget genau einmal injiziert');
ok(strpos($out,'<input type="submit"></form>')===false,'Widget sitzt vor </form>');
$loginForm=substr($out,strpos($out,'login.php'));
ok(strpos($loginForm,$M)!==false,'Widget landet im Login-Formular');
$searchForm=substr($out,0,strpos($out,'login.php'));
ok(strpos($searchForm,$M)===false,'Suchformular bleibt unberührt');
$noPw='<form><input type="text"></form>';
eq($inj->invoke(null,$noPw,$M),$noPw,'Seite ohne Passwortfeld bleibt unverändert');
$sq="<form><input type='password' name='p'></form>";
ok(strpos($inj->invoke(null,$sq,$M),$M)!==false,'einfache Anführungszeichen werden erkannt');
$uq='<form><input type=password name=p></form>';
ok(strpos($inj->invoke(null,$uq,$M),$M)!==false,'Attribut ohne Anführungszeichen wird erkannt');

// ---------- T9: CSP ----------
grp('T9 CSP-Rewrite');
$orig = "frame-ancestors 'self'; img-src 'self' data:; script-src 'self' 'unsafe-inline'; object-src 'none'";
$new  = TurnstileLoginGate::rewriteCspValue($orig);
ok(strpos($new,"script-src 'self' 'unsafe-inline' https://challenges.cloudflare.com")!==false,'script-src erweitert');
ok(strpos($new,'frame-src')===false,'kein frame-src hinzugefügt (würde sonst einschränken)');
ok(substr_count($new,'object-src')===1 && substr_count($new,'img-src')===1,'andere Direktiven unverändert');
eq(TurnstileLoginGate::rewriteCspValue($new),$new,'idempotent: zweiter Durchlauf ändert nichts');
$noScript="frame-ancestors 'self'; object-src 'none'";
ok(strpos(TurnstileLoginGate::rewriteCspValue($noScript),"script-src 'self' 'unsafe-inline' https://challenges.cloudflare.com")!==false,'fehlende script-src wird ergänzt');
$staff="frame-ancestors 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; object-src 'none'";
ok(strpos(TurnstileLoginGate::rewriteCspValue($staff),"'unsafe-eval' https://challenges.cloudflare.com")!==false,'Staff-CSP mit unsafe-eval bleibt erhalten');

// ---------- T10: FormField-Integration ----------
grp('T10 FormField');
$_SERVER['SCRIPT_NAME']='/open.php'; $_SERVER['REQUEST_METHOD']='POST';
TurnstileSettings::load(['cf_site_key'=>'k','cf_secret_key'=>'1x0000000000000000000000000000000AA',
  'fail_mode'=>'closed','timeout'=>5,'log_failures'=>false,'protect_ticket'=>1]);
TurnstileVerifier::resetCache();
$f1=new TurnstileFormField(); $f1->validateEntry('');
ok(count($f1->errors())>0,'fehlendes Token -> Fehler am Feld');
TurnstileVerifier::resetCache();
$f2=new TurnstileFormField(); $f2->validateEntry($DUMMY);
eq(count($f2->errors()),0,'gültiges Token -> kein Fehler');
eq($f2->to_database('secret-token-wert'),'','Token wird nicht in die DB geschrieben');
eq($f2->toString('x'),'','toString leer');
$_POST=['cf-turnstile-response'=>$DUMMY];
$w2=new TurnstileFieldWidget(new TurnstileFormField());
eq($w2->getValue(),$DUMMY,'Widget liest cf-turnstile-response aus dem POST');
// Break-Glass
TurnstileSettings::load(['cf_site_key'=>'k','cf_secret_key'=>'2x0000000000000000000000000000000AA','protect_ticket'=>0]);
TurnstileVerifier::resetCache();
$f3=new TurnstileFormField(); $f3->validateEntry('kaputt');
eq(count($f3->errors()),0,'Break-Glass: Schutz aus -> keine Erzwingung');
$_POST=[];

// ---------- T11: Plugin-Bootstrap ----------
grp('T11 Plugin-Bootstrap');
$pc = new TurnstileConfig();
foreach (['cf_site_key'=>'1x00000000000000000000AA','cf_secret_key'=>'1x0000000000000000000000000000000AA',
          'protect_ticket'=>1,'fail_mode'=>'closed','timeout'=>5,'theme'=>'auto','size'=>'normal'] as $k=>$v) $pc->set($k,$v);
Plugin::$testConfig = $pc;
$p = new TurnstilePlugin();
eq($p->isMultiInstance(),false,'isMultiInstance = false');
eq($p->isCompatible(),true,'isCompatible auf PHP '.PHP_VERSION.' / osTicket '.OSTICKET_VERSION);
$_SERVER['SCRIPT_NAME']='/open.php'; $_SERVER['REQUEST_METHOD']='GET';
$before=ob_get_level();
ob_start(); $p->bootstrap(); $lvl=ob_get_level();
while (ob_get_level()>$before) ob_end_clean();
// PHP_SAPI==='cli' -> attach() steigt bewusst aus (Cron-Schutz), also KEIN zusätzlicher Buffer.
// Der Web-Pfad wird von itest.py gegen einen laufenden Server geprüft.
ok($lvl===$before+1,'CLI: LoginGate registriert korrekt KEINEN Output-Buffer',"vorher=$before nachher=$lvl");
$ra=new ReflectionMethod('TurnstileLoginGate','attach');
ok($ra->isStatic() && $ra->isPublic(),'attach() ist public static');
$types = FormField::allTypes();
ok(isset($types['turnstile']) && $types['turnstile'][1]==='TurnstileFormField','Feldtyp "turnstile" registriert');
eq(TurnstileSettings::get('site_key'),'1x00000000000000000000AA','Settings aus Config übernommen');

// ---------- T12: Buffer-Allowlist ----------
// ob_start() mit Callback puffert die gesamte Ausgabe im Speicher. Auf
// Skripten ohne Widget (allen voran Attachment-Downloads) darf deshalb
// gar kein Buffer entstehen.
grp('T12 Buffer-Allowlist');
$bufCases = [
  ['/login.php',            true,  'Client-Login'],
  ['/scp/login.php',        true,  'Staff-Login'],
  ['/open.php',             true,  'Gast-Ticketformular'],
  ['/account.php',          true,  'Client-Registrierung'],
  ['/support/open.php',     true,  'Unterverzeichnis-Installation'],
  ['\\scp\\login.php',      true,  'Backslash-Pfad wird normalisiert'],
  ['/file.php',             false, 'Attachment-Download Client'],
  ['/scp/file.php',         false, 'Attachment-Download Staff'],
  ['/ajax.php',             false, 'AJAX-Endpoint'],
  ['/scp/index.php',        false, 'Staff-Dashboard'],
  ['/tickets.php',          false, 'Client-Ticketansicht'],
  ['/',                     false, 'Verzeichnis ohne Skript'],
  ['',                      false, 'leerer SCRIPT_NAME'],
];
foreach ($bufCases as $c) {
  eq(TurnstileLoginGate::needsBuffer($c[0]), $c[1], "needsBuffer('{$c[0]}') = ".var_export($c[1],true)." — {$c[2]}");
}
$rb = new ReflectionMethod('TurnstileLoginGate','needsBuffer');
ok($rb->isStatic() && $rb->isPublic(),'needsBuffer() ist public static (ohne Webserver testbar)');
// Kein Widget-Skript -> attach() darf keinen Buffer aufmachen. Der CLI-Guard
// greift hier vorher, deshalb wird nur die Entscheidung selbst geprüft.
ok(!TurnstileLoginGate::needsBuffer('/file.php'),'Attachment-Download bekommt keinen Output-Buffer');

echo "\n".str_repeat('-',52)."\n";
printf("PHP %s   PASS: %d   FAIL: %d\n", PHP_VERSION, $PASS, $FAIL);
exit($FAIL>0?1:0);
