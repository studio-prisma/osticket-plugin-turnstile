<?php

declare(strict_types=1);

/**
 * Konfiguration des Turnstile-Plugins.
 *
 * Alle Schutzbereiche sind einzeln schaltbar. Default für Login-Bereiche ist
 * AUS — sie werden erst nach erfolgreichem Smoke-Test aktiviert (Aussperr-Schutz).
 */

require_once INCLUDE_DIR . 'class.plugin.php';
require_once INCLUDE_DIR . 'class.forms.php';

class TurnstileConfig extends PluginConfig
{
    /**
     * Übersetzungs-Helfer. osTicket lädt Plugin-Sprachdateien nicht
     * automatisch; wir bleiben bewusst bei Klartext-Deutsch.
     */
    public function getOptions()
    {
        return array(

            'sec_keys' => new SectionBreakField(array(
                'label' => 'Cloudflare Keys',
                'hint'  => 'Aus dem Cloudflare-Dashboard: Turnstile > Widget anlegen. '
                         . 'Der Secret Key wird ausschließlich serverseitig verwendet.',
            )),

            'cf_site_key' => new TextboxField(array(
                'label'         => 'Site Key',
                'required'      => true,
                'hint'          => 'Oeffentlich, landet im HTML.',
                'configuration' => array(
                    'size'         => 60,
                    'length'       => 80,
                    'autocomplete' => 'off',
                ),
            )),

            'cf_secret_key' => new TextboxField(array(
                'label'         => 'Secret Key',
                'required'      => true,
                'widget'        => 'PasswordWidget',
                'hint'          => 'Geheim. Wird nie im HTML, nie im Log und nie in einer '
                                 . 'Fehlermeldung ausgegeben.',
                'configuration' => array(
                    'size'   => 60,
                    'length' => 80,
                ),
            )),

            'cf_hostname' => new TextboxField(array(
                'label'         => 'Erwarteter Hostname (optional)',
                'required'      => false,
                'hint'          => 'z. B. support.example.com — wenn gesetzt, wird das '
                                 . 'Feld "hostname" der siteverify-Antwort dagegen geprüft. '
                                 . 'Verhindert, dass Tokens fremder Domains akzeptiert werden. '
                                 . 'Leer lassen, wenn mehrere Hostnames auf dieselbe Instanz zeigen.',
                'configuration' => array(
                    'size'         => 60,
                    'length'       => 120,
                    'autocomplete' => 'off',
                ),
            )),

            'sec_scope' => new SectionBreakField(array(
                'label' => 'Schutzbereiche',
                'hint'  => 'Jeder Bereich einzeln schaltbar. Reihenfolge der Aktivierung: '
                         . 'erst Ticketformular, dann Registrierung, dann Client-Login, '
                         . 'zuletzt Staff-Login.',
            )),

            'protect_ticket' => new BooleanField(array(
                'label'         => 'Gast-Ticketformular',
                'default'       => true,
                'configuration' => array(
                    'desc' => 'Erzwingt die Prüfung, sobald das Feld "Cloudflare Turnstile" '
                            . 'im Formular-Builder einem Formular zugeordnet ist. '
                            . 'Aus = Feld wird gerendert, aber nicht erzwungen (Break-Glass).',
                ),
            )),

            'protect_client_register' => new BooleanField(array(
                'label'         => 'Client-Registrierung',
                'default'       => false,
                'configuration' => array(
                    'desc' => 'Schützt account.php (Registrierung). Nutzt denselben '
                            . 'Formular-Builder-Mechanismus über das User-Formular.',
                ),
            )),

            'protect_client_login' => new BooleanField(array(
                'label'         => 'Client-Login',
                'default'       => false,
                'configuration' => array(
                    'desc' => 'Schützt login.php. Widget wird per Output-Buffer in das '
                            . 'Formular injiziert, Prüfung läuft vor der Authentifizierung.',
                ),
            )),

            'protect_staff_login' => new BooleanField(array(
                'label'         => 'Staff-Login (Vorsicht)',
                'default'       => false,
                'configuration' => array(
                    'desc' => 'Schützt scp/login.php. ACHTUNG: Bei Fail-Mode "closed" und '
                            . 'einer Cloudflare-Störung sperrt das ALLE Agenten aus. '
                            . 'Erst aktivieren, wenn der dokumentierte Kill-Switch getestet ist.',
                ),
            )),

            'sec_behaviour' => new SectionBreakField(array(
                'label' => 'Verhalten',
            )),

            'fail_mode' => new ChoiceField(array(
                'label'   => 'Verhalten bei nicht erreichbarem Cloudflare',
                'default' => 'closed',
                'choices' => array(
                    'closed' => 'Fail closed — Absenden wird blockiert (sicher)',
                    'open'   => 'Fail open — Absenden wird durchgelassen (verfügbar)',
                ),
                'hint'    => 'Betrifft ausschließlich Netzwerk-/Timeout-Fehler gegenüber '
                           . 'Cloudflare, NICHT ungültige oder fehlende Tokens. '
                           . 'Die werden immer abgelehnt.',
            )),

            'timeout' => new ChoiceField(array(
                'label'   => 'Timeout siteverify',
                'default' => '5',
                'choices' => array(
                    '3'  => '3 Sekunden',
                    '5'  => '5 Sekunden',
                    '10' => '10 Sekunden',
                ),
                'hint'    => 'Gesamt-Timeout. Connect-Timeout ist die Hälfte davon.',
            )),

            'theme' => new ChoiceField(array(
                'label'   => 'Widget-Theme',
                'default' => 'auto',
                'choices' => array(
                    'auto'  => 'Automatisch',
                    'light' => 'Hell',
                    'dark'  => 'Dunkel',
                ),
            )),

            'size' => new ChoiceField(array(
                'label'   => 'Widget-Größe',
                'default' => 'normal',
                'choices' => array(
                    'normal'   => 'Normal',
                    'flexible' => 'Flexibel (volle Breite)',
                    'compact'  => 'Kompakt',
                ),
            )),

            'log_failures' => new BooleanField(array(
                'label'         => 'Fehlversuche protokollieren',
                'default'       => true,
                'configuration' => array(
                    'desc' => 'Schreibt Fehlercode, Bereich und IP nach error_log(). '
                            . 'Niemals Secret Key, niemals das vollständige Token.',
                ),
            )),
        );
    }

    /**
     * Wird vor dem Speichern der Config aufgerufen.
     * Fängt die Konstellationen ab, die sonst erst im Produktivbetrieb auffallen.
     *
     * WICHTIG — osTicket wertet $errors als reinen Abbruch-Kanal aus
     * (include/class.plugin.php:127):
     *
     *     && $this->pre_save($clean, $errors)
     *     && count($errors) === 0
     *
     * JEDER Eintrag in $errors verhindert das Speichern, auch ein rein
     * informativer. Warnungen gehen deshalb über Messages::warning() und
     * niemals über $errors. Sonst lässt sich die Config nicht speichern
     * und das Plugin meldet anschliessend "nicht konfiguriert".
     */
    public function pre_save(&$config, &$errors)
    {
        if (!function_exists('curl_init')) {
            $errors['err'] = 'Die PHP-Extension cURL fehlt. Ohne sie kann das Token '
                           . 'nicht serverseitig geprüft werden. Installation: php-curl.';
            return false;
        }

        $site   = isset($config['cf_site_key']) ? trim((string) $config['cf_site_key']) : '';
        $secret = isset($config['cf_secret_key']) ? trim((string) $config['cf_secret_key']) : '';

        if ($site === '' || $secret === '') {
            $errors['err'] = 'Site Key und Secret Key sind beide erforderlich.';
            return false;
        }

        // Fail-Fast statt still-unsicherer Default: ohne Keys darf kein
        // Schutzbereich aktiv sein.
        $anyProtection = !empty($config['protect_ticket'])
            || !empty($config['protect_client_register'])
            || !empty($config['protect_client_login'])
            || !empty($config['protect_staff_login']);

        if (!$anyProtection) {
            $errors['warn'] = 'Kein Schutzbereich aktiv — das Plugin tut aktuell nichts.';
        }

        if (!empty($config['protect_staff_login']) && ($config['fail_mode'] ?? 'closed') === 'closed') {
            $errors['warn'] = 'Staff-Login ist geschützt und Fail-Mode ist "closed". '
                            . 'Eine Cloudflare-Störung sperrt damit alle Agenten aus. '
                            . 'Kill-Switch bereithalten (siehe EINSPIELEN.md).';
        }

        if (!empty($config['cf_hostname'])) {
            $host = trim((string) $config['cf_hostname']);
            if (!preg_match('/^[a-z0-9.-]+$/i', $host)) {
                $errors['err'] = 'Erwarteter Hostname enthält ungültige Zeichen. '
                               . 'Nur Buchstaben, Ziffern, Punkt und Bindestrich.';
                return false;
            }
            $config['cf_hostname'] = strtolower($host);
        }

        return true;
    }
}
