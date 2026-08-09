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
 */
final class TurnstileLoginGate
{
    const CF_ORIGIN = 'https://challenges.cloudflare.com';

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

        self::$area = self::detectArea();

        // Der CSP-Fix wird immer gebraucht, sobald irgendein Schutz aktiv ist —
        // auch auf open.php, wo das Formular-Builder-Feld rendert.
        $anyProtection = TurnstileSettings::protects('ticket')
            || TurnstileSettings::protects('register')
            || TurnstileSettings::protects('login')
            || TurnstileSettings::protects('staff');

        if (!$anyProtection) {
            return;
        }

        if (self::$area !== '' && TurnstileSettings::protects(self::$area)) {
            self::guardPost();
        }

        ob_start(array(__CLASS__, 'filter'));
    }

    /**
     * Leitet den Bereich aus dem laufenden Skript ab.
     * scp/login.php und login.php heissen beide "login.php" — der Pfad entscheidet.
     */
    private static function detectArea()
    {
        $script = isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '';
        $script = str_replace('\\', '/', $script);

        if (substr($script, -14) === '/scp/login.php') {
            return 'staff';
        }

        if (basename($script) === 'login.php') {
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
     * osTicket nutzt luser/lpasswd (Client) bzw. username/passwd (Staff).
     */
    private static function looksLikeLogin()
    {
        foreach (array('lpasswd', 'passwd', 'password') as $key) {
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

        return self::injectIntoLoginForm($buffer, $markup);
    }

    /**
     * Fügt das Markup vor dem </form> des Formulars ein, das ein
     * Passwortfeld enthält. Andere Formulare der Seite bleiben unberührt.
     */
    private static function injectIntoLoginForm($buffer, $markup)
    {
        $done = false;

        $result = preg_replace_callback(
            '#<form\b[^>]*>(.*?)</form>#is',
            function ($m) use ($markup, &$done) {
                if ($done) {
                    return $m[0];
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
