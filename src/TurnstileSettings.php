<?php

declare(strict_types=1);

/**
 * Aufgelöste Plugin-Konfiguration, zentral erreichbar.
 *
 * osTicket instanziiert FormField-Klassen ohne Zugriff auf die Plugin-Instanz.
 * Statt die Keys als lose static-Properties über mehrere Klassen zu verteilen
 * (so macht es das Referenz-Plugin, und es bricht bei mehreren Instanzen),
 * gibt es genau einen Ort. Das Plugin setzt ihn einmal in bootstrap().
 */
final class TurnstileSettings
{
    /**
     * Skripte, auf denen ein Formularfeld-Widget überhaupt rendern kann —
     * und der Bereich, den sie bedienen.
     *
     * Alles andere kann prinzipbedingt kein Token mitliefern: api/cron.php
     * (Mail-Abruf), api/http.php (JSON-API), ajax.php, scp/*, CLI. Dort darf
     * nicht erzwungen werden. Siehe SECURITY.md, "Out of Scope": Mail-Eingang
     * und JSON-API sind ausdrücklich nicht Aufgabe dieses Plugins.
     */
    const ENFORCE_SCRIPTS = array(
        'open.php'    => 'ticket',
        'account.php' => 'register',
    );

    /** @var array|null */
    private static $data = null;

    /** @var bool Kill-Switch: Erzwingung aus, Feldtyp bleibt registriert. */
    private static $killed = false;

    public static function load(array $values)
    {
        self::$killed = false;
        self::$data = array(
            'site_key'     => trim((string) ($values['cf_site_key'] ?? '')),
            'secret_key'   => trim((string) ($values['cf_secret_key'] ?? '')),
            'hostname'     => strtolower(trim((string) ($values['cf_hostname'] ?? ''))),
            'fail_mode'    => ($values['fail_mode'] ?? 'closed') === 'open' ? 'open' : 'closed',
            'timeout'      => (int) ($values['timeout'] ?? 5),
            'theme'        => (string) ($values['theme'] ?? 'auto'),
            'size'         => (string) ($values['size'] ?? 'normal'),
            'log'          => !empty($values['log_failures']),
            'areas'        => array(
                'ticket'   => !empty($values['protect_ticket']),
                'register' => !empty($values['protect_client_register']),
                'login'    => !empty($values['protect_client_login']),
                'staff'    => !empty($values['protect_staff_login']),
            ),
        );
    }

    public static function isLoaded()
    {
        return self::$data !== null;
    }

    public static function get($key, $default = null)
    {
        if (self::$data === null) {
            return $default;
        }

        return array_key_exists($key, self::$data) ? self::$data[$key] : $default;
    }

    /** Ist der Schutz für einen Bereich aktiv? */
    public static function protects($area)
    {
        if (self::$data === null) {
            return false;
        }

        return !empty(self::$data['areas'][$area]);
    }

    /** Options-Array für TurnstileVerifier::verify(). */
    public static function verifierOptions($area)
    {
        return array(
            'secret'    => self::get('secret_key', ''),
            'timeout'   => self::get('timeout', 5),
            'hostname'  => self::get('hostname', ''),
            'fail_mode' => self::get('fail_mode', 'closed'),
            'log'       => self::get('log', true),
            'area'      => $area,
        );
    }

    /**
     * Legt die Erzwingung still, ohne den Feldtyp abzumelden.
     *
     * Ein bootstrap(), das vor FormField::addFieldTypes() aussteigt, macht den
     * Typ 'turnstile' unauflösbar. Jede Formular-Instanziierung stirbt dann in
     * FormField::getImpl() ("Class name must be a valid object or a string") —
     * Agenten-UI, Kundenportal und Mail-Import gleichermaßen. Der Kill-Switch
     * darf also nur die Prüfung abschalten, nie die Registrierung.
     */
    public static function kill()
    {
        self::$killed = true;
    }

    public static function isKilled()
    {
        return self::$killed;
    }

    /**
     * Reine Entscheidung aus einem Skriptpfad: Welcher Bereich wird hier
     * bedient, und darf hier überhaupt erzwungen werden?
     *
     * Public und ohne $_SERVER-Zugriff, damit sie sich wie
     * TurnstileLoginGate::needsBuffer() ohne Webserver testen lässt.
     *
     * @return string '' (nicht erzwingbar) | 'ticket' | 'register'
     */
    public static function areaForScript($scriptName)
    {
        $script = str_replace('\\', '/', (string) $scriptName);

        if ($script === '') {
            return '';
        }

        $base = basename($script);

        return isset(self::ENFORCE_SCRIPTS[$base])
            ? self::ENFORCE_SCRIPTS[$base]
            : '';
    }

    /**
     * Schutzbereich des laufenden Requests.
     * Wird von Formularfeldern genutzt, die selbst nicht wissen, wo sie stehen.
     *
     * '' bedeutet: hier kann kein Widget rendern, also wird auch nicht geprüft.
     */
    public static function currentArea()
    {
        return self::areaForScript(
            isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : ''
        );
    }
}
