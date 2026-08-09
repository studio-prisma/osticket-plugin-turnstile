# Installation: Cloudflare Turnstile für osTicket 1.18.x

**[English version →](INSTALL.md)** · [zurück zum README](../README.de.md)

Zielverzeichnis: `include/plugins/turnstile/` deiner osTicket-Installation.
Als Beispiel-Hostname wird durchgehend `support.example.com` verwendet — durch deinen eigenen ersetzen.

Reihenfolge ist bindend. Jeder Schritt hat einen Abbruchpunkt.

---

## 0. Warum dieses Plugin und nicht `nhaskaris/turnstile_osticket`

| Punkt | Referenz-Repo | Dieses Plugin |
|---|---|---|
| cURL-Timeout | fehlt → Login hängt bei Cloudflare-Störung | `CURLOPT_TIMEOUT` + `CONNECTTIMEOUT` |
| Doppelvalidierung im selben Request | statisches `$was_validated`-Flag, überspringt danach jede weitere Prüfung | Ergebnis-Cache pro Token |
| Hostname-Prüfung | keine → Token fremder Domains gültig | optional erzwungen |
| Fehlermeldung | Cloudflare-Fehlercodes gehen 1:1 an den Nutzer | generische Meldung, Detail nur ins Log |
| Login-Seiten | nicht abgedeckt | `TurnstileLoginGate` |
| CSP | Core-Patch mit `'unsafe-inline'`-Erweiterung nötig | Plugin korrigiert den Header selbst |
| Schutzbereiche | keine Schalter | vier einzelne Schalter + Kill-Switch |

---

## 0b. Testabdeckung

Getestet auf **PHP 8.3.33** gegen ein **echtes osTicket 1.18.4** (Commit `8d38b06`)
mit MariaDB 10.6 — nicht nur gegen Stubs.

```bash
PHP_BIN=/pfad/zu/php8.3 tests/run-all.sh     # Lint, Unit, Fail-Mode, Integration
```

| Stufe | Umfang | Ergebnis |
|---|---|---|
| Lint | 8 Dateien `php -l` | 8/8 |
| Unit | Config, Settings, Markup, Verifier, LoginGate, FormField, Bootstrap | 95/95 |
| Fail-Mode | Endpoint auf Blackhole umgebogen | 9/9 |
| Integration | `php -S`, echte HTTP-Requests gegen ein Login-Nachbau | 25/25 |
| **E2E** | **echtes osTicket 1.18.4, Plugin über den Plugin-Manager aktiv** | **37/37** |

Die siteverify-Aufrufe gehen gegen die **echte** Cloudflare-API mit den offiziellen
Testkeys (`1x…` immer gültig, `2x…` immer ungültig, `3x…` liefert `timeout-or-duplicate`).
Kein Mock — der Netzwerkpfad wird durchlaufen.

### Was der E2E-Lauf belegt

- **`open.php` rendert die Ticketfelder gar nicht im initialen HTML.** osTicket lädt
  sie erst nach Wahl des Help Topics über `ajax.php/form/help-topic/<id>` nach.
  Genau deshalb arbeitet das Widget im `render=explicit`-Modus: der Auto-Modus
  rendert nur, was beim ersten Laden im DOM stand, und würde hier nichts tun.
- Im AJAX-Fragment sind Container, Re-Render-Funktion und Poller enthalten —
  genau ein Script-Tag, genau ein Container.
- Ticket anlegen **ohne** Token: exakte Plugin-Fehlermeldung, `ost_ticket` wächst nicht.
  **Mit** gültigem Token: Ticket wird angelegt (Zeilenzahl geprüft, nicht nur HTML).
- Staff-Login postet per AJAX auf sich selbst: ohne Token **403 mit HTML-Fragment**
  (keine ganze Seite, die das Frontend zerlegen würde), mit Token übernimmt osTicket.
- CSP: osTicket setzt `script-src 'self' 'unsafe-inline'` in `client/header.inc.php`
  **und** in `staff/login.header.php`. In beiden Antworten steht danach
  `challenges.cloudflare.com` in `script-src`, ohne neues `frame-src`.
  Der Output-Buffer gewinnt gegen den späteren `header()`-Aufruf — Core-Patch entfällt.
- Kill-Switch: `touch DISABLED` legt das Plugin im laufenden Betrieb still, der Login
  ist sofort wieder offen, `rm` reaktiviert.

Weiter abgedeckt: Blackhole-Endpoint antwortet bei `timeout=3` nach 2,0 s statt zu
hängen (Connect-Timeout greift zuerst); nach einem `fail_mode=open`-Durchlass
blockiert der nächste Request bei `closed` wieder; Token über 2048 Zeichen und
leere Tokens lösen **keinen** Outbound-Request aus.

### Was weiterhin offen ist

Ein Browser hat nichts davon ausgeführt. Verifiziert ist, dass das korrekte Markup
und der Re-Render-Hook ausgeliefert werden — **nicht**, dass Cloudflares `api.js`
im AJAX-Fragment tatsächlich anläuft. Ob osTicket das Fragment per jQuery `.html()`
einhängt (führt Scripts aus) oder per `innerHTML` (führt sie nicht aus), entscheidet
darüber. Als Absicherung läuft im Bootstrap-JS ein Poller, der 10 Sekunden lang
alle 250 ms nachrendert, sobald `window.turnstile` verfügbar ist.

Das bleibt Schritt 6.1 im Smoke-Test: Help Topic wechseln und zusehen, ob das
Widget erscheint.

---

## 1. Voraussetzungen prüfen

```bash
php -v                      # 8.0-8.4 unterstützt, produktiv verifiziert auf 8.3.33
php -m | grep -i curl       # muss "curl" liefern
```

osTicket-Version: Admin-Panel → Dashboard → System Information. Muss 1.18.x sein.

**Abbruch, wenn:** cURL fehlt. Ohne cURL keine serverseitige Prüfung, ohne serverseitige Prüfung kein Schutz.

---

## 2. Cloudflare-Widget anlegen

Cloudflare Dashboard → **Turnstile** → *Add widget*.

| Feld | Wert |
|---|---|
| Widget name | `osticket-production` |
| Hostname | `support.example.com` |
| Widget Mode | **Managed** |
| Pre-clearance | aus |

Ergebnis: **Site Key** (öffentlich) und **Secret Key** (geheim).

Der Secret Key geht ausschließlich in die osTicket-Plugin-Config. Nicht in eine Datei im Repo, nicht in ein Ticket, nicht in einen Chat.

---

## 3. Dateien hochladen

**Fertiges Paket: `turnstile-<version>.zip`** aus dem [aktuellen Release](https://github.com/studio-prisma/osticket-plugin-turnstile/releases/latest).
Enthält ausschließlich die acht Produktivdateien plus Lizenz — keine Tests, keine Doku.
Aus dem Quellcode selbst bauen: `./tools/build-zip.sh`.

Entpackt ergibt es direkt den Ordner `turnstile/`, der nach `include/plugins/` gehört:

```
include/plugins/turnstile/
├── plugin.php
├── config.php
├── class.TurnstilePlugin.php
└── src/
    ├── TurnstileSettings.php
    ├── TurnstileVerifier.php
    ├── TurnstileMarkup.php
    ├── TurnstileFormField.php
    └── TurnstileLoginGate.php
```

Per SFTP: `dist/turnstile/` hochladen. Per SSH:

```bash
cd /var/www/vhosts/.../httpdocs/include/plugins
unzip turnstile-<version>.zip
```

Owner und Rechte an die Nachbarordner unter `include/plugins/` angleichen.

```bash
cd /var/www/vhosts/.../httpdocs/include/plugins
chown -R <web-user>:<web-group> turnstile
find turnstile -type d -exec chmod 755 {} \;
find turnstile -type f -exec chmod 644 {} \;
```

**Syntax-Check auf dem Server — nicht überspringen:**

```bash
find turnstile -name '*.php' -exec php -l {} \;
```

Erwartet: `No syntax errors detected` für alle acht Dateien.

Lokal ist das bereits gegen PHP 8.3.33 gelaufen (Abschnitt 0b). Auf dem Server trotzdem
wiederholen: dort entscheidet die tatsächlich installierte PHP-Version und der
Extension-Satz, nicht der Testbuild.

---

## 4. Plugin installieren und konfigurieren

Admin-Panel → **Manage → Plugins** → *Add New Plugin* → „Cloudflare Turnstile" → *Install*.

Dann Instanz anlegen und konfigurieren:

| Einstellung | Startwert |
|---|---|
| Site Key | aus Schritt 2 |
| Secret Key | aus Schritt 2 |
| Erwarteter Hostname | `support.example.com` — **setzen** |
| Gast-Ticketformular | **an** |
| Client-Registrierung | aus |
| Client-Login | aus |
| Staff-Login | aus |
| Fail-Mode | `closed` |
| Timeout | 5 Sekunden |
| Fehlversuche protokollieren | an |

Instanz auf **Enabled** setzen.

Die drei Login-Bereiche bleiben aus, bis Schritt 6 grün ist. Das ist der Aussperr-Schutz.

**Den erwarteten Hostname nicht leer lassen.** Ohne ihn bestätigt siteverify nur, dass das Token echt ist — nicht, dass es zu dieser Seite gehört. Ein Token, das auf einer beliebigen anderen Domain desselben Widgets erzeugt wurde, wird dann akzeptiert. Leer nur, wenn mehrere Hostnames auf diese Instanz zeigen. Speichern mit aktivem Schutzbereich und ohne Hostname erzeugt eine Warnung; sie blockiert das Speichern nicht.

---

## 5. Feld in das Ticketformular einhängen

Admin-Panel → **Manage → Forms** → *Ticket Details* (bzw. das Formular des betroffenen Help Topics).

*Add Field* → Typ **Cloudflare Turnstile** → Label z. B. „Sicherheitsprüfung" → ans Ende des Formulars → *Save Changes*.

Ohne diesen Schritt passiert auf `open.php` nichts. Der Feldtyp wird vom Plugin nur registriert, zugeordnet wird er manuell.

---

## 6. Smoke-Test, in dieser Reihenfolge

**6.1 Gast-Ticketformular**

1. `https://support.example.com/open.php` im privaten Fenster öffnen
2. Widget sichtbar? Browser-Konsole leer? (keine CSP-Meldung zu `challenges.cloudflare.com`)
3. Ticket regulär anlegen → muss durchgehen
4. Help Topic wechseln (lädt das Formular per AJAX nach) → Widget muss erneut rendern

Bricht 6.2 ab, wenn hier etwas rot ist.

**6.2 Client-Registrierung**

Schalter „Client-Registrierung" an. Das Turnstile-Feld zusätzlich dem **User-Formular** zuordnen (Manage → Forms → *User Information*). Registrierung testen.

**6.3 Client-Login**

Schalter „Client-Login" an. `login.php` im privaten Fenster: Widget muss unter dem Passwortfeld erscheinen, Login muss funktionieren.

**6.4 Staff-Login — zuletzt, mit offener zweiter Session**

Vor dem Umlegen des Schalters: eine **zweite, bereits eingeloggte Agenten-Session** in einem anderen Browser offen halten. Diese Session ist der Rettungsweg, falls das Widget nicht rendert.

Schalter „Staff-Login" an → `scp/login.php` im privaten Fenster testen.

Wenn der Login scheitert: Kill-Switch (Abschnitt 8), **nicht** herumprobieren.

---

## 7. Content-Security-Policy

osTicket setzt die CSP an fünf Stellen hart per `header()`, unter anderem
`include/client/header.inc.php` und `include/staff/login.header.php`:

```
script-src 'self' 'unsafe-inline'
```

Damit wäre `challenges.cloudflare.com` blockiert und das Widget würde nicht laden.

**Das Plugin löst das selbst.** `TurnstileLoginGate::relaxCsp()` läuft im Output-Buffer-Callback, also nach osTickets `header()`-Aufruf und vor dem Senden der Header, und hängt genau eine Origin an `script-src` an. Kein Core-Patch, updatefest.

Bewusst wird **nur** `script-src` angefasst. osTicket setzt weder `default-src` noch `frame-src`; ein neu hinzugefügtes `frame-src` würde alle übrigen Frames der Seite einschränken statt etwas zu erlauben.

### Wenn das Widget trotzdem CSP-blockiert wird

Dann setzt eine Ebene davor eine eigene CSP. Prüfen:

```bash
curl -sI https://support.example.com/open.php | grep -i content-security-policy
```

Erscheint die Direktive **zweimal**, gewinnt der Browser mit der Schnittmenge — die restriktivere Variante blockiert. Dann an der Quelle korrigieren:

**Plesk → Apache & nginx Settings → Additional Apache directives:**

```apache
Header always set Content-Security-Policy "frame-ancestors 'self'; img-src 'self' data:; script-src 'self' 'unsafe-inline' https://challenges.cloudflare.com; object-src 'none'"
```

**Plesk → Additional nginx directives** (nur falls nginx direkt an PHP-FPM ausliefert):

```nginx
fastcgi_hide_header Content-Security-Policy;
add_header Content-Security-Policy "frame-ancestors 'self'; img-src 'self' data:; script-src 'self' 'unsafe-inline' https://challenges.cloudflare.com; object-src 'none'" always;
```

Ein Core-Patch von `include/client/header.inc.php` ist der letzte Ausweg und geht bei jedem osTicket-Update verloren. Falls doch unvermeidbar: den Patch außerhalb des osTicket-Verzeichnisses versioniert ablegen und nach jedem Update erneut anwenden.

---

## 8. Kill-Switch und Rollback

**Stufe 1 — Plugin still, Dateien bleiben liegen** (Sekunden, kein DB-Zugriff, funktioniert auch wenn du ausgesperrt bist):

```bash
touch include/plugins/turnstile/DISABLED
```

Das `bootstrap()` bricht danach sofort ab. Keine Prüfung, kein Widget, kein Output-Buffer. Zurücknehmen mit `rm`.

**Stufe 2 — einzelnen Bereich abschalten:** Admin-Panel → Plugin-Config → betroffenen Schalter aus.

**Stufe 3 — Fail-Mode umstellen:** bei anhaltender Cloudflare-Störung `fail_mode` auf `open`. Formulare gehen wieder durch, Spam-Schutz ist so lange aus.

**Stufe 4 — vollständig entfernen:** Plugin im Admin-Panel deaktivieren und deinstallieren, dann `include/plugins/turnstile/` löschen. Das Turnstile-Feld vorher aus den Formularen entfernen, sonst bleibt ein Feld mit unbekanntem Typ zurück.

---

## 9. Security-Gate vor der Freigabe

Jeder Punkt einmal real ausgeführt, nicht angenommen. Jeder Fall mit **frischem privaten Fenster** — ein wiederverwendeter Cookie-Jar erzeugt sonst falsche Ergebnisse.

| # | Fall | Erwartung |
|---|---|---|
| 1 | Formular absenden ohne Turnstile-Token (DevTools: `<div class="cf-turnstile">` entfernen) | abgelehnt |
| 2 | `cf-turnstile-response` auf Zufallsstring setzen | abgelehnt |
| 3 | Gültiges Token abfangen und ein zweites Mal senden | abgelehnt (`timeout-or-duplicate`) |
| 4 | Token > 2048 Zeichen | abgelehnt, **kein** Outbound-Request |
| 5 | Secret Key in der Config leeren | abgelehnt, kein stiller Pass |
| 6 | `challenges.cloudflare.com` per `/etc/hosts` auf `127.0.0.1` legen, `fail_mode=closed` | abgelehnt, Antwort nach spätestens 5 s |
| 7 | Dasselbe mit `fail_mode=open` | durchgelassen |
| 8 | Client-Response nach Fehlschlag prüfen | kein Cloudflare-Fehlercode, kein Pfad, kein Stacktrace |
| 9 | `grep -ri "<secret-key>" /var/log/ /tmp/` | 0 Treffer |
| 10 | `grep -i turnstile` im PHP-Error-Log | nur `area=`, `reason=`, `ip=`, gekürztes `detail=` |
| 11 | Erwarteten Hostname absichtlich falsch setzen, dann gültiges Token senden | abgelehnt, Log zeigt `reason=hostname_mismatch` |
| 12 | Grossen Anhang herunterladen, während ein Schutzbereich aktiv ist | streamt wie vorher, kein Speicher-Peak — ausserhalb von `login.php`, `scp/login.php`, `open.php`, `account.php` startet das Plugin keinen Output-Buffer |

Fall 3 ist der wichtigste — er beweist, dass der Request-Cache den Replay-Schutz nicht aushebelt. Fall 11 beweist, dass die Hostname-Prüfung wirklich scharf ist; ohne ihn gibt es dafür keinen Beleg.

---

## 10. Bekannte Grenzen

- **Kein Schutz ohne JavaScript.** Wer JS deaktiviert, bekommt kein Token und kann kein Ticket anlegen. Bei `fail_mode=closed` ist das eine harte Sperre. Für Barrierefreiheit ggf. einen alternativen Kanal (E-Mail-Ticket) offenhalten — der Mail-Eingang ist von diesem Plugin nicht betroffen.
- **E-Mail-Tickets und die API bleiben ungeschützt.** Turnstile ist ein Browser-Mechanismus. Spam über `api/tickets.json` oder über den Mail-Pipe braucht andere Maßnahmen.
- **Die Login-Injektion arbeitet auf dem gerenderten HTML.** Sie sucht das erste Formular mit einem Passwortfeld. Ein osTicket-Update, das die Login-Templates umbaut, kann das brechen — dann rendert kein Widget und der Login schlägt bei `fail_mode=closed` fehl. Nach jedem osTicket-Update Schritt 6.3 und 6.4 erneut fahren.
- **Der CSP-Rewrite läuft nur auf vier Skripten:** `login.php`, `scp/login.php`, `open.php`, `account.php`. Das ist Absicht — der Rewrite sitzt in einem Output-Buffer-Callback, und ein Buffer über einem Attachment-Download würde die ganze Datei im Speicher halten. Hängst du das Turnstile-Feld an ein Formular, das ein anderes Skript rendert, wird das Widget dort per CSP blockiert. Abhilfe: `challenges.cloudflare.com` auf Webserver-Ebene in `script-src` erlauben (Abschnitt 7), oder das Skript in `BUFFER_SCRIPTS` in `src/TurnstileLoginGate.php` ergänzen.
- **`CF-Connecting-IP` ist fälschbar**, weil der Origin unter seiner IP direkt erreichbar ist. Für das `remoteip`-Feld bei siteverify ist das folgenlos — es ist rein informativ. Sobald an anderer Stelle auf diese IP eine Entscheidung gebaut wird, muss sie gegen die Cloudflare-Ranges geprüft werden.
- **Datenschutz:** Turnstile lädt ein Skript von Cloudflare und überträgt IP-Adresse und Browser-Signale in die USA. Cloudflare setzt dabei keine Tracking-Cookies. In die Datenschutzerklärung von `support.example.com` gehört ein Absatz dazu, inklusive AV-Vertrag mit Cloudflare. *Ich bin kein Anwalt — bitte gegenprüfen lassen.*
