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
    /** @var array|null */
    private static $data = null;

    public static function load(array $values)
    {
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
     * Leitet den Schutzbereich aus dem laufenden Skript ab.
     * Wird von Formularfeldern genutzt, die selbst nicht wissen, wo sie stehen.
     */
    public static function currentArea()
    {
        $script = isset($_SERVER['SCRIPT_NAME']) ? basename((string) $_SERVER['SCRIPT_NAME']) : '';

        if ($script === 'account.php') {
            return 'register';
        }

        return 'ticket';
    }
}
