<?php

declare(strict_types=1);

/**
 * Schutz der Login-Seiten und CSP-Anpassung.
 *
 * Hintergrund (verifiziert gegen osTicket 1.18.x):
 *
 *  - login.php und scp/login.php rendern hartcodiertes HTML. Es gibt kein
 *    Dynamic-Form-Rendering, also greift der Formular-Builder-Feldtyp dort nicht.
 *  - Es existiert KEIN Signal, das vor dem Rendern oder vor der Authentifizierung
 *    einer Login-Seite feuert. auth.login.failed feuert erst nach dem Fehlschlag
 *    und kann laut include/class.signal.php nichts abbrechen.
 *  - Ein zusätzliches AuthenticationBackend wird zu spät registriert: das
 *    Core-Backend liefert bei korrektem Passwort bereits einen User zurück.
 *
 * Daraus folgt der gewählte Weg: Plugin::bootstrap() läuft in
 * osTicket::start() und damit VOR der Login-Logik der jeweiligen Seite.
 * Dort wird
 *   a) bei POST das Token geprüft und der Request notfalls beendet,
 *   b) ein Output-Buffer registriert, der das Widget in das Formular injiziert
 *      und den Content-Security-Policy-Header nachträglich korrigiert.
 *
 * Der CSP-Trick funktioniert, weil header() später aufgerufen einen früheren
 * Header ersetzt und Header erst beim Flush des Buffers gesendet werden.
 * Der Callback läuft nach include/client/header.inc.php und gewinnt damit.
 * Das ersetzt den sonst üblichen Core-Patch.
 *
 * Der Buffer wird bewusst NUR auf den Skripten gestartet, auf denen ein
 * Widget rendern kann (BUFFER_SCRIPTS). ob_start() mit Callback und ohne
 * chunk_size hält die gesamte Ausgabe im Speicher, bis der Request endet —
 * über einem Attachment-Download (file.php, scp/file.php) würde damit die
 * komplette Datei in den PHP-Speicher gezogen, ohne dass der Callback dort
 * irgendetwas zu tun hätte.
 *
 * Grenze der Allowlist: wird das Feld "Cloudflare Turnstile" im
 * Formular-Builder einem Formular zugeordnet, das ein hier nicht gelistetes
 * Skript rendert, bleibt der CSP-Header dort unverändert und das Widget wird
 * blockiert. Dann gehört das Skript in die Liste.
 */
final class TurnstileLoginGate
{
    const CF_ORIGIN = 'https://challenges.cloudflare.com';

    /**
     * Skripte, auf denen ein Turnstile-Widget rendern kann und der Buffer
     * deshalb gebraucht wird. Verglichen wird der Basename, damit
     * scp/login.php von 'login.php' mit abgedeckt ist.
     *
     *   login.php    Client-Login (Injektion)   + scp/login.php Staff-Login
     *   tickets.php  Client-Login inline        (secure.inc.php bindet login.php ein)
     *   profile.php  Client-Login inline        (dito)
     *   view.php     Zugangslink-Formular       (E-Mail + Ticketnummer, postet an login.php)
     *   open.php     Gast-Ticketformular        (Formular-Builder-Feld, nur CSP)
     *   account.php  Client-Registrierung       (Formular-Builder-Feld, nur CSP)
     */
    const BUFFER_SCRIPTS = array('login.php', 'tickets.php', 'profile.php', 'view.php', 'open.php', 'account.php');

    /**
     * Client-Skripte, die ein Login- oder Zugangslink-Formular rendern können.
     * tickets.php und profile.php laden secure.inc.php; das bindet für
     * Abgemeldete login.php per require ein, ohne Redirect. SCRIPT_NAME bleibt
     * dabei das aufrufende Skript, und die Login-Logik läuft trotzdem.
     * view.php zeigt das Zugangslink-Formular (accesslink.inc.php).
     */
    const CLIENT_LOGIN_SCRIPTS = array('login.php', 'tickets.php', 'profile.php', 'view.php');

    /**
     * Felder, an denen ein Client-Formular als Anmeldung erkannt wird:
     * lpasswd im Passwort-Login, lticket im Zugangslink (E-Mail + Ticketnummer).
     * Die Ticketnummer wirkt dort wie ein Passwort.
     */
    const CLIENT_LOGIN_FIELDS = array('lpasswd', 'lticket');

    /** @var string '' | 'login' | 'staff' */
    private static $area = '';

    /** @var bool */
    private static $injected = false;

    /**
     * Wird aus Plugin::bootstrap() aufgerufen.
     */
    public static function attach()
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        $script = self::scriptName();

        self::$area = self::detectArea($script, $_POST);

        // Der CSP-Fix wird immer gebraucht, sobald irgendein Schutz aktiv ist —
        // auch auf open.php, wo das Formular-Builder-Feld rendert.
        $anyProtection = TurnstileSettings::protects('ticket')
            || TurnstileSettings::protects('register')
            || TurnstileSettings::protects('login')
            || TurnstileSettings::protects('staff');

        if (!$anyProtection) {
            return;
        }

        // Vor dem Buffer-Guard: die Token-Prüfung eines Login-POSTs darf nicht
        // davon abhängen, ob auf dieser Seite gepuffert wird.
        if (self::$area !== '' && TurnstileSettings::protects(self::$area)) {
            self::guardPost();
        }

        if (!self::needsBuffer($script)) {
            return;
        }

        ob_start(array(__CLASS__, 'filter'));
    }

    /**
     * Der Pfad des laufenden Skripts, auf Vorwärts-Slashes normalisiert.
     */
    private static function scriptName()
    {
        $script = isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '';

        return str_replace('\\', '/', $script);
    }

    /**
     * Braucht dieser Request einen Output-Buffer?
     *
     * Reine String-Prüfung und public — wie rewriteCspValue() ausgelagert,
     * damit sie ohne laufenden Webserver testbar ist.
     */
    public static function needsBuffer($scriptName)
    {
        $script = str_replace('\\', '/', (string) $scriptName);

        if ($script === '') {
            return false;
        }

        // In scp/ rendert nur login.php ein Widget; scp/tickets.php ist die
        // Ticketansicht der Agents und bleibt ungepuffert.
        if (strpos($script, '/scp/') !== false) {
            return basename($script) === 'login.php';
        }

        return in_array(basename($script), self::BUFFER_SCRIPTS, true);
    }

    /**
     * Leitet den Bereich aus dem laufenden Skript ab.
     * scp/login.php und login.php heissen beide "login.php" — der Pfad entscheidet.
     *
     * Der Skriptname allein reicht nicht: jedes Client-Skript, das
     * secure.inc.php lädt, führt für Abgemeldete die Login-Logik aus. Ein
     * POST mit Client-Login-Feldern zählt deshalb überall außerhalb von
     * scp/ und api/ als Login — sonst ginge er an tickets.php vorbei am Gate.
     *
     * @param string $script Bereits normalisierter Pfad aus scriptName().
     * @param mixed  $post   $_POST des Requests.
     */
    private static function detectArea($script, $post = array())
    {
        $script = (string) $script;

        if (substr($script, -14) === '/scp/login.php') {
            return 'staff';
        }

        if (strpos($script, '/scp/') !== false || strpos($script, '/api/') !== false) {
            return '';
        }

        if (in_array(basename($script), self::CLIENT_LOGIN_SCRIPTS, true)) {
            return 'login';
        }

        if (is_array($post)
            && (isset($post['luser']) || isset($post['lpasswd']) || isset($post['lticket']))
        ) {
            return 'login';
        }

        return '';
    }

    /**
     * Prüft das Token bei einem Login-POST.
     *
     * Bei Fehlschlag wird der Request beendet, statt die Zugangsdaten aus
     * $_POST zu entfernen. Grund: eine entschärfte POST-Anfrage läuft in
     * osTickets AuthStrike-Backend als Fehlversuch auf und würde einen
     * legitimen Nutzer nach mehreren CAPTCHA-Fehlern aussperren.
     */
    private static function guardPost()
    {
        if (!isset($_SERVER['REQUEST_METHOD']) || strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST') {
            return;
        }

        // Nur echte Login-Versuche prüfen, nicht jeden POST auf die Seite
        // (z. B. Passwort-vergessen-Formular).
        if (!self::looksLikeLogin()) {
            return;
        }

        $token = isset($_POST['cf-turnstile-response']) ? $_POST['cf-turnstile-response'] : '';

        $result = TurnstileVerifier::verify(
            $token,
            TurnstileSettings::verifierOptions(self::$area)
        );

        if ($result['ok']) {
            return;
        }

        self::deny($result['message']);
    }

    /**
     * Heuristik: enthält der POST Login-Felder?
     * osTicket nutzt luser/lpasswd (Client), lemail/lticket (Zugangslink)
     * bzw. username/passwd (Staff).
     */
    private static function looksLikeLogin()
    {
        foreach (array('lpasswd', 'lticket', 'passwd', 'password') as $key) {
            if (isset($_POST[$key])) {
                return true;
            }
        }

        foreach (array('luser', 'username', 'userid') as $key) {
            if (isset($_POST[$key]) && $_POST[$key] !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Bricht den Request ab. Kein Auth-Versuch, kein Strike-Zähler.
     */
    private static function deny($message)
    {
        if (!headers_sent()) {
            header('HTTP/1.1 403 Forbidden');
            header('Cache-Control: no-store');
        }

        $safe = htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8');

        // AJAX-Login (scp/login.php postet mit ajax=1): reine Textantwort,
        // damit das Frontend sie anzeigen kann.
        if (!empty($_POST['ajax']) || self::isXhr()) {
            if (!headers_sent()) {
                header('Content-Type: text/html; charset=UTF-8');
            }
            echo '<div class="error banner">' . $safe . '</div>';
            exit;
        }

        if (!headers_sent()) {
            header('Content-Type: text/html; charset=UTF-8');
        }

        $back = htmlspecialchars(
            (string) (isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/'),
            ENT_QUOTES,
            'UTF-8'
        );

        echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">'
           . '<meta name="viewport" content="width=device-width, initial-scale=1">'
           . '<title>Sicherheitsprüfung</title></head><body '
           . 'style="font-family:system-ui,sans-serif;max-width:34rem;margin:4rem auto;padding:0 1rem;">'
           . '<h1 style="font-size:1.25rem;">Sicherheitsprüfung nicht bestanden</h1>'
           . '<p>' . $safe . '</p>'
           . '<p><a href="' . $back . '">Zurück zur Anmeldung</a></p>'
           . '</body></html>';

        exit;
    }

    private static function isXhr()
    {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    /**
     * Output-Buffer-Callback.
     * Läuft nach dem gesamten Seitenaufbau, aber vor dem Senden der Header.
     */
    public static function filter($buffer)
    {
        self::relaxCsp();

        if (self::$area === '' || !TurnstileSettings::protects(self::$area)) {
            return $buffer;
        }

        if (self::$injected || !is_string($buffer) || $buffer === '') {
            return $buffer;
        }

        $sitekey = TurnstileSettings::get('site_key', '');
        if ($sitekey === '') {
            return $buffer;
        }

        $markup = TurnstileMarkup::render(array(
            'sitekey' => $sitekey,
            'theme'   => TurnstileSettings::get('theme', 'auto'),
            'size'    => TurnstileSettings::get('size', 'normal'),
            'action'  => self::$area === 'staff' ? 'staff-login' : 'client-login',
        ));

        // Client-Bereich: nur das Login- oder Zugangslink-Formular, nicht etwa
        // das Passwort-ändern-Formular auf profile.php.
        $fields = self::$area === 'login' ? self::CLIENT_LOGIN_FIELDS : array();

        return self::injectIntoLoginForm($buffer, $markup, $fields);
    }

    /**
     * Fügt das Markup vor dem </form> des ersten passenden Formulars ein.
     * Andere Formulare der Seite bleiben unberührt.
     *
     * @param string|array $fields Ohne Angabe: das Formular mit einem
     *                             Passwortfeld. Mit Angabe: das Formular, das
     *                             ein Feld mit genau einem dieser
     *                             name-Attribute enthält.
     */
    private static function injectIntoLoginForm($buffer, $markup, $fields = array())
    {
        $done = false;
        $fields = array_values(array_filter((array) $fields, 'strlen'));

        $result = preg_replace_callback(
            '#<form\b[^>]*>(.*?)</form>#is',
            function ($m) use ($markup, $fields, &$done) {
                if ($done) {
                    return $m[0];
                }

                if ($fields) {
                    $matched = false;
                    foreach ($fields as $field) {
                        if (preg_match('#\bname\s*=\s*["\']?' . preg_quote($field, '#') . '["\'\s>/]#i', $m[1])) {
                            $matched = true;
                            break;
                        }
                    }
                    if (!$matched) {
                        return $m[0];
                    }

                    $done = true;

                    return substr($m[0], 0, -7) . $markup . '</form>';
                }

                if (stripos($m[1], 'type="password"') === false
                    && stripos($m[1], "type='password'") === false
                    && stripos($m[1], 'type=password') === false
                ) {
                    return $m[0];
                }

                $done = true;

                return substr($m[0], 0, -7) . $markup . '</form>';
            },
            $buffer,
            -1,
            $count
        );

        // preg_replace_callback gibt bei Backtrack-Limit null zurück —
        // dann lieber die Originalseite ausliefern als eine leere.
        if ($result === null) {
            error_log('[turnstile] injection skipped: preg error ' . preg_last_error());
            return $buffer;
        }

        self::$injected = $done;

        return $result;
    }

    /**
     * Ergänzt challenges.cloudflare.com in der script-src-Direktive der CSP.
     *
     * Bewusst nur script-src: osTicket setzt weder default-src noch frame-src.
     * Ein neu hinzugefügtes frame-src würde alle übrigen Frames der Seite
     * einschränken statt etwas zu erlauben.
     */
    private static function relaxCsp()
    {
        if (headers_sent()) {
            return;
        }

        foreach (headers_list() as $header) {
            if (stripos($header, 'content-security-policy:') !== 0) {
                continue;
            }

            $value    = trim(substr($header, strlen('content-security-policy:')));
            $rewritten = self::rewriteCspValue($value);

            if ($rewritten !== $value) {
                header('Content-Security-Policy: ' . $rewritten, true);
            }

            return;
        }
    }

    /**
     * Reine String-Transformation der CSP — ausgelagert, damit sie ohne
     * laufenden Webserver testbar ist.
     */
    public static function rewriteCspValue($value)
    {
        $value = (string) $value;

        if (strpos($value, self::CF_ORIGIN) !== false) {
            return $value;
        }

        $directives = array_values(array_filter(array_map('trim', explode(';', $value)), 'strlen'));
        $rebuilt    = array();
        $touched    = false;

        foreach ($directives as $directive) {
            if (stripos($directive, 'script-src') === 0) {
                $directive .= ' ' . self::CF_ORIGIN;
                $touched = true;
            }
            $rebuilt[] = $directive;
        }

        if (!$touched) {
            $rebuilt[] = "script-src 'self' 'unsafe-inline' " . self::CF_ORIGIN;
        }

        return implode('; ', $rebuilt);
    }
}
