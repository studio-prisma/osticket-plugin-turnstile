# Cloudflare Turnstile für osTicket

[![Validate](https://github.com/studio-prisma/osticket-plugin-turnstile/actions/workflows/validate.yml/badge.svg)](https://github.com/studio-prisma/osticket-plugin-turnstile/actions/workflows/validate.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![osTicket 1.18.x](https://img.shields.io/badge/osTicket-1.18.x-1c4e80.svg)](https://osticket.com/)
[![PHP 8.0–8.4](https://img.shields.io/badge/PHP-8.0%E2%80%938.4-777bb4.svg)](https://www.php.net/)

CAPTCHA-Schutz für osTicket mit [Cloudflare Turnstile](https://developers.cloudflare.com/turnstile/) — serverseitige Token-Validierung, einzeln schaltbare Schutzbereiche und ein dateibasierter Not-Aus, der auch dann greift, wenn du dich ausgesperrt hast.

**[English version →](README.md)**

---

## Was geschützt wird

Vier Bereiche, jeder einzeln schaltbar:

| Bereich | Pfad | Hinweis |
|---|---|---|
| Gast-Ticketformular | `open.php` | Turnstile-Feld muss dem Formular zugeordnet werden (siehe [INSTALL](docs/INSTALL.de.md) Schritt 5) |
| Client-Registrierung | `account.php` | Feld zusätzlich dem Formular *User Information* zuordnen |
| Client-Login | `login.php` | Widget wird in das gerenderte Login-Formular injiziert |
| Staff-Login | `scp/login.php` | Zuletzt aktivieren — siehe Aussperr-Warnung |

Mail-Eingang und die JSON-API sind **nicht** abgedeckt. Turnstile ist ein Browser-Mechanismus; diese Kanäle brauchen andere Maßnahmen.

---

## Warum dieses Plugin

Verglichen mit [`nhaskaris/turnstile_osticket`](https://github.com/nhaskaris/turnstile_osticket), der nächstliegenden bestehenden Implementierung:

| Punkt | Referenz-Plugin | Dieses Plugin |
|---|---|---|
| cURL-Timeout | fehlt → Login hängt bei Cloudflare-Störung | `CURLOPT_TIMEOUT` + `CURLOPT_CONNECTTIMEOUT` |
| Doppelvalidierung im selben Request | statisches `$was_validated`-Flag überspringt danach jede weitere Prüfung | Ergebnis-Cache pro Token |
| Hostname-Prüfung | keine → Tokens fremder Domains gültig | optional, geprüft gegen das Feld `hostname` der siteverify-Antwort |
| Fehlermeldung | Cloudflare-Fehlercodes gehen 1:1 an den Nutzer | generische Meldung, Detail nur ins Log |
| Login-Seiten | nicht abgedeckt | `TurnstileLoginGate` |
| Content-Security-Policy | Core-Patch nötig, um `challenges.cloudflare.com` zu erlauben | Plugin korrigiert den Header selbst, updatefest |
| Schutzbereiche | alles oder nichts | vier einzelne Schalter plus Kill-Switch |

---

## Voraussetzungen

- osTicket **1.18.x** (die Plugin-API ist in 1.17.x identisch, verifiziert ist nur 1.18.x)
- PHP **8.0–8.4** mit `curl`-Extension. Der Plugin-Code ist PHP-8.0-kompatibel, CI lintet und testet auf 8.0, 8.1, 8.2, 8.3 und 8.4. Produktiv läuft er auf **8.3.33**. Welche davon deine Instanz nutzen darf, entscheidet osTicket, nicht dieses Plugin — 1.18.2 und neuer unterstützen 8.3 und 8.4
- Ausgehendes HTTPS zu `challenges.cloudflare.com`
- Cloudflare-Account (Turnstile ist kostenlos)

---

## Installation

Vollständige Anleitung mit Abbruchpunkten, Smoke-Test und Security-Gate: **[docs/INSTALL.de.md](docs/INSTALL.de.md)**.

Kurzfassung:

1. `turnstile-<version>.zip` aus dem [aktuellen Release](https://github.com/studio-prisma/osticket-plugin-turnstile/releases/latest) laden.
2. Nach `include/plugins/` entpacken, sodass `include/plugins/turnstile/` entsteht.
3. Owner und Rechte an die Nachbarordner angleichen (Verzeichnisse `755`, Dateien `644`).
4. Admin-Panel → **Manage → Plugins → Add New Plugin** → *Cloudflare Turnstile* → **Install**.
5. Instanz anlegen, Site Key und Secret Key eintragen, vorerst **nur** das Gast-Ticketformular aktivieren.
6. Admin-Panel → **Manage → Forms** → Feld vom Typ **Cloudflare Turnstile** an das Ticketformular hängen.
7. Smoke-Test fahren, bevor ein Login-Bereich aktiviert wird.

> **Aussperr-Warnung.** Bevor du *Staff-Login* aktivierst: eine zweite, bereits eingeloggte Agenten-Session in einem anderen Browser offen halten. Rendert das Widget nicht, ist das dein Rettungsweg. Die Alternative ist der Kill-Switch.

---

## Kill-Switch

Funktioniert ohne Datenbankzugriff, ohne Admin-Panel und auch dann, wenn du ausgesperrt bist:

```bash
touch include/plugins/turnstile/DISABLED
```

Keine Prüfung, kein Widget, kein Output-Buffer. Zurücknehmen mit `rm`.

Der Feldtyp `Cloudflare Turnstile` bleibt dabei registriert. Das ist Absicht: würde er abgemeldet, wäre jede Formularzeile dieses Typs unauflösbar, und osTicket stirbt danach in `FormField::getImpl()` bei jedem Formular, das es baut — Agenten-UI und Kundenportal eingeschlossen. Der Kill-Switch legt das Plugin still, er meldet es nicht ab.

Weitere Rollback-Stufen: [INSTALL §8](docs/INSTALL.de.md#8-kill-switch-und-rollback).

---

## Konfiguration

| Einstellung | Standard | Zweck |
|---|---|---|
| Site Key | — | Öffentlicher Schlüssel aus dem Cloudflare-Turnstile-Dashboard |
| Secret Key | — | Geheim. Wird nie gerendert, nie geloggt, nie in einer Fehlermeldung ausgegeben |
| Erwarteter Hostname | leer — **setzen** | `hostname` in der siteverify-Antwort muss übereinstimmen. Leer gelassen wird ein Token akzeptiert, das auf einer beliebigen anderen Domain desselben Widgets erzeugt wurde |
| Gast-Ticketformular | an | Schützt `open.php` |
| Client-Registrierung | aus | Schützt `account.php` |
| Client-Login | aus | Schützt `login.php` |
| Staff-Login | aus | Schützt `scp/login.php` |
| Fail-Mode | `closed` | Verhalten bei nicht erreichbarem Cloudflare: `closed` lehnt ab, `open` lässt durch |
| Timeout siteverify | 5 s | Gesamt-cURL-Timeout inklusive Connect-Timeout |
| Widget-Theme | auto | `auto`, `light`, `dark` |
| Widget-Größe | normal | `normal`, `compact` |
| Fehlversuche protokollieren | an | Schreibt Bereich, Grund, IP und gekürztes Detail ins PHP-Error-Log |

Die Konfiguration wird beim Speichern validiert: leerer Site oder Secret Key wird abgelehnt, Hostnames werden auf Kleinschreibung normalisiert und syntaktisch geprüft. Zwei Konstellationen erzeugen eine Warnung, ohne das Speichern zu blockieren — *Staff-Login* zusammen mit `fail_mode = closed`, und ein aktiver Schutzbereich ohne erwarteten Hostname.

---

## Fail-Mode

`closed` ist der sichere Standard: Ist Cloudflare innerhalb des Timeouts nicht erreichbar, wird der Request abgelehnt. Bei einer länger anhaltenden Cloudflare-Störung auf `open` stellen, damit Formulare wieder durchgehen — der Spam-Schutz ist so lange aus.

Der Connect-Timeout greift zuerst: ein Blackhole-Endpoint antwortet bei 3 s Einstellung nach etwa 2 s, statt zu hängen.

---

## Content-Security-Policy

osTicket setzt `script-src 'self' 'unsafe-inline'` an fünf Stellen hart per `header()`. Damit wäre das Turnstile-Skript blockiert.

`TurnstileLoginGate::relaxCsp()` läuft im Output-Buffer-Callback — nach osTickets `header()`-Aufruf und vor dem Senden der Header — und hängt genau eine Origin an `script-src` an. Kein Core-Patch, updatefest.

Bewusst wird **nur** `script-src` angefasst. osTicket setzt weder `default-src` noch `frame-src`; ein neu hinzugefügtes `frame-src` würde alle übrigen Frames der Seite einschränken statt etwas zu erlauben.

Setzt eine Ebene davor eine eigene CSP, gewinnt der Browser mit der Schnittmenge und die restriktivere Variante blockiert. Dann an der Quelle korrigieren — siehe [INSTALL §7](docs/INSTALL.de.md#7-content-security-policy).

---

## Tests

```bash
PHP_BIN=/pfad/zu/php8.3 tests/run-all.sh     # Lint, Unit, Fail-Mode, Integration
```

Verifiziert auf PHP 8.3.33 gegen ein echtes osTicket 1.18.4 mit MariaDB 10.6 — nicht nur gegen Stubs.

| Stufe | Umfang | Ergebnis |
|---|---|---|
| Lint | 8 Dateien, `php -l` | 8/8 |
| Unit | Config, Settings, Markup, Verifier, LoginGate, FormField, Bootstrap | 95/95 |
| Fail-Mode | Endpoint auf Blackhole umgebogen | 9/9 |
| Integration | `php -S`, echte HTTP-Requests gegen einen Login-Nachbau | 25/25 |
| E2E | echtes osTicket 1.18.4, Plugin über den Plugin-Manager aktiv | 37/37 |

Die siteverify-Aufrufe gehen gegen die **echte** Cloudflare-API mit den offiziellen Testkeys (`1x…` immer gültig, `2x…` immer ungültig, `3x…` liefert `timeout-or-duplicate`). Kein Mock — der Netzwerkpfad wird durchlaufen.

Details und E2E-Setup: [tests/README.md](tests/README.md).

---

## Bekannte Grenzen

- **Kein Schutz ohne JavaScript.** Ohne JS gibt es kein Token, und bei `fail_mode = closed` ist das eine harte Sperre. Für Barrierefreiheit einen alternativen Kanal (E-Mail-Ticket) offenhalten — der Mail-Eingang ist von diesem Plugin nicht betroffen.
- **E-Mail-Tickets und die API bleiben ungeschützt.** Spam über `api/tickets.json` oder den Mail-Pipe braucht andere Maßnahmen.
- **Die Login-Injektion arbeitet auf dem gerenderten HTML.** Sie sucht das erste Formular mit einem Passwortfeld. Ein osTicket-Update, das die Login-Templates umbaut, kann das brechen — nach jedem osTicket-Update die Login-Smoke-Tests erneut fahren.
- **Der CSP-Rewrite ist auf vier Skripte begrenzt:** `login.php`, `scp/login.php`, `open.php`, `account.php`. Er läuft in einem Output-Buffer-Callback, und ein Buffer über einem Attachment-Download würde die ganze Datei im Speicher halten — der Buffer startet deshalb nur dort, wo ein Widget auftauchen kann. Hängt das Feld an einem anderswo gerenderten Formular, wird das Widget dort per CSP blockiert.
- **`CF-Connecting-IP` ist fälschbar**, wenn der Origin unter seiner IP direkt erreichbar ist. Für das rein informative `remoteip`-Feld bei siteverify folgenlos, aber keine Autorisierungsentscheidung darauf bauen.
- **Die Ausführung des AJAX-Fragments im Browser ist nicht automatisiert verifiziert.** Ausgeliefertes Markup und Re-Render-Hook sind getestet; ob Cloudflares `api.js` im Fragment tatsächlich anläuft, hängt davon ab, wie osTicket es einhängt. Ein Bootstrap-Poller rendert 10 s lang alle 250 ms nach. Smoke-Test 6.1 bestätigt es.
- **Die Admin-UI ist auf Deutsch.** Code, Dokumentation und Issue-Templates sind Englisch; die Labels der Plugin-Konfiguration sind noch nicht lokalisiert. Als Folgeaufgabe vorgemerkt.

## Datenschutz

Turnstile lädt ein Skript von Cloudflare und überträgt IP-Adresse und Browser-Signale in die USA. Cloudflare gibt an, dabei keine Tracking-Cookies zu setzen. Unter der DSGVO gehört ein entsprechender Absatz in die Datenschutzerklärung, inklusive Auftragsverarbeitungsvertrag mit Cloudflare. Das ist keine Rechtsberatung — bitte gegenprüfen lassen.

---

## Mitwirken

Bug-Reports, Kompatibilitätsmeldungen und Pull Requests sind willkommen. Vorher [CONTRIBUTING.md](CONTRIBUTING.md) lesen — dort stehen die Prüfschritte, die eine Änderung bestehen muss.

Sicherheitslücken: **kein öffentliches Issue.** Siehe [SECURITY.md](SECURITY.md).

## Lizenz

[MIT](LICENSE) © studio-prisma
