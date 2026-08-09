<?php

declare(strict_types=1);

/**
 * Cloudflare Turnstile für osTicket 1.18.x
 *
 * Zwei getrennte Wirkmechanismen, weil osTicket zwei verschiedene
 * Rendering-Wege hat:
 *
 *   1. Formular-Builder-Feldtyp  -> Gast-Ticketformular, Client-Registrierung
 *      (alles, was über DynamicForm läuft)
 *   2. TurnstileLoginGate        -> Client-Login, Staff-Login
 *      (hartcodierte Templates ohne Hook)
 *
 * Jeder Bereich ist in der Plugin-Config einzeln schaltbar.
 *
 * @see docs/INSTALL.md für Deployment, CSP und Kill-Switch
 */

require_once INCLUDE_DIR . 'class.plugin.php';
require_once INCLUDE_DIR . 'class.forms.php';

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/TurnstileSettings.php';
require_once __DIR__ . '/src/TurnstileVerifier.php';
require_once __DIR__ . '/src/TurnstileMarkup.php';
require_once __DIR__ . '/src/TurnstileFormField.php';
require_once __DIR__ . '/src/TurnstileLoginGate.php';

class TurnstilePlugin extends Plugin
{
    var $config_class = 'TurnstileConfig';

    /**
     * Eine Instanz reicht. Mehrere Instanzen würden sich über den
     * gemeinsamen statischen Settings-Speicher gegenseitig überschreiben.
     */
    function isMultiInstance()
    {
        return false;
    }

    /**
     * Wird von PluginInstance::bootstrap() aufgerufen — nur für aktivierte
     * Instanzen, und früh genug, um vor der Login-Logik zu greifen
     * (osTicket::start() aus main.inc.php).
     */
    public function bootstrap(): void
    {
        $config = $this->getConfig();
        if (!$config) {
            return;
        }

        TurnstileSettings::load(array(
            'cf_site_key'             => $config->get('cf_site_key'),
            'cf_secret_key'           => $config->get('cf_secret_key'),
            'cf_hostname'             => $config->get('cf_hostname'),
            'fail_mode'               => $config->get('fail_mode'),
            'timeout'                 => $config->get('timeout'),
            'theme'                   => $config->get('theme'),
            'size'                    => $config->get('size'),
            'log_failures'            => $config->get('log_failures'),
            'protect_ticket'          => $config->get('protect_ticket'),
            'protect_client_register' => $config->get('protect_client_register'),
            'protect_client_login'    => $config->get('protect_client_login'),
            'protect_staff_login'     => $config->get('protect_staff_login'),
        ));

        // Kill-Switch: Datei anlegen, um das Plugin ohne DB-Zugriff
        // vollständig stillzulegen. Siehe docs/INSTALL.md, Abschnitt 8.
        if (file_exists(__DIR__ . '/DISABLED')) {
            error_log('[turnstile] disabled via DISABLED file');
            return;
        }

        // Feldtyp registrieren. Steht danach im Formular-Builder unter
        // Admin > Manage > Forms als "Cloudflare Turnstile" bereit.
        FormField::addFieldTypes('Verification', function () {
            return array(
                'turnstile' => array('Cloudflare Turnstile', 'TurnstileFormField'),
            );
        });

        TurnstileLoginGate::attach();
    }

    /**
     * Null guard: parent::getConfig() returns null when no instance exists,
     * which would otherwise cause a fatal error in bootstrap().
     */
    public function getConfig(?PluginInstance $instance = null, $defaults = array())
    {
        $config = parent::getConfig($instance, $defaults);
        if (!$config) {
            $config = new TurnstileConfig();
        }

        return $config;
    }

    /**
     * Harte Voraussetzungen. Verhindert eine Installation, die erst im
     * Betrieb auffällt.
     */
    public function isCompatible(): bool
    {
        if (defined('OSTICKET_VERSION') && version_compare(OSTICKET_VERSION, '1.18.0', '<')) {
            return false;
        }

        // 8.0 ist der Floor, den CI lintet und CONTRIBUTING.md festschreibt.
        // Ein niedrigeres Gate würde eine Laufzeit zulassen, die nie getestet wird.
        if (version_compare(PHP_VERSION, '8.0.0', '<')) {
            return false;
        }

        if (!function_exists('curl_init')) {
            return false;
        }

        return true;
    }
}
