<?php

declare(strict_types=1);

/**
 * Turnstile als Feldtyp für den osTicket-Formular-Builder.
 *
 * Wirkungsbereich: alles, was über DynamicForm gerendert wird — also das
 * Gast-Ticketformular (open.php) und das User-Formular (Registrierung über
 * account.php). NICHT die Login-Seiten; die haben kein Dynamic-Form-Rendering
 * und werden vom TurnstileLoginGate abgedeckt.
 *
 * Registrierung erfolgt im Plugin-bootstrap() via FormField::addFieldTypes().
 * Danach steht "Cloudflare Turnstile" unter Admin > Manage > Forms als Feldtyp
 * zur Verfügung und muss dem gewünschten Formular manuell hinzugefügt werden.
 */
class TurnstileFormField extends FormField
{
    static $widget = 'TurnstileFieldWidget';

    /**
     * Serverseitige Prüfung. Das ist die einzige Stelle, die zählt —
     * das Widget im Browser ist reine Anzeige.
     */
    function validateEntry($value)
    {
        parent::validateEntry($value);

        $area = TurnstileSettings::currentArea();

        // Zuerst und ohne Ausnahme: Wo kein Widget rendern kann, wird nicht
        // geprüft — und vor allem nicht addError() gerufen.
        //
        // Das ist keine Bequemlichkeit, sondern Pflicht. osTicket verwirft für
        // origin='email' zwar alle Feldfehler (Ticket::create(), $field_filter),
        // aber FormField::addError() schreibt per Seiteneffekt direkt in die
        // Fehlerliste des Formulars (class.forms.php: $this->_form->addError()).
        // Form::isValid() gibt am Ende schlicht !$this->_errors zurück — der
        // Eintrag steht dort also schon, bevor der Filter überhaupt greift.
        // Ein Fehler von hier lässt damit jeden Ticket-Import aus dem Postfach
        // scheitern: Nachrichten werden gezählt, keine wird verarbeitet.
        if ($area === '') {
            return;
        }

        // Kill-Switch (DISABLED-Datei): Feldtyp bleibt registriert, damit die
        // Formulare instanziierbar bleiben — geprüft wird trotzdem nicht.
        if (TurnstileSettings::isKilled()) {
            return;
        }

        if (!TurnstileSettings::isLoaded()) {
            // Plugin nicht bootstrapped: kein stiller Pass.
            $this->addError('Die Sicherheitsprüfung ist nicht verfügbar. '
                . 'Bitte wenden Sie sich an den Betreiber.');
            return;
        }

        // Break-Glass: Feld bleibt sichtbar, Prüfung wird nicht erzwungen.
        if (!TurnstileSettings::protects($area)) {
            return;
        }

        $token = is_string($value) && $value !== ''
            ? $value
            : (isset($_POST['cf-turnstile-response']) ? $_POST['cf-turnstile-response'] : '');

        $result = TurnstileVerifier::verify($token, TurnstileSettings::verifierOptions($area));

        if (!$result['ok']) {
            $this->addError($result['message']);
        }
    }

    /**
     * Das Token ist ein Einmal-Nachweis, kein Ticketinhalt.
     * Es wird nicht in die Datenbank geschrieben.
     */
    function to_database($value)
    {
        return '';
    }

    function toString($value)
    {
        return '';
    }

    function display($value)
    {
        return '';
    }

    /**
     * Pro-Feld-Overrides im Formular-Builder. Leer = Plugin-Default.
     */
    function getConfigurationOptions()
    {
        return array(
            'theme' => new ChoiceField(array(
                'label'   => 'Theme',
                'default' => '',
                'choices' => array(
                    ''      => 'Plugin-Einstellung verwenden',
                    'auto'  => 'Automatisch',
                    'light' => 'Hell',
                    'dark'  => 'Dunkel',
                ),
            )),
            'size' => new ChoiceField(array(
                'label'   => 'Größe',
                'default' => '',
                'choices' => array(
                    ''         => 'Plugin-Einstellung verwenden',
                    'normal'   => 'Normal',
                    'flexible' => 'Flexibel',
                    'compact'  => 'Kompakt',
                ),
            )),
        );
    }
}

/**
 * Rendert den Widget-Container.
 */
class TurnstileFieldWidget extends Widget
{
    /**
     * Signatur an TextboxWidget::render() angeglichen (class.forms.php:4422).
     * Die Basisklasse Widget deklariert kein render(), Aufrufer übergeben aber
     * teilweise zwei Argumente.
     */
    function render($options = array(), $extraConfig = false)
    {
        if (!TurnstileSettings::isLoaded() || TurnstileSettings::isKilled()) {
            return;
        }

        $sitekey = TurnstileSettings::get('site_key', '');
        if ($sitekey === '') {
            // Ohne Site Key kein Widget. Die serverseitige Prüfung schlägt
            // dann fehl — das ist beabsichtigt (kein stiller Pass).
            echo '<em>Sicherheitsprüfung nicht konfiguriert.</em>';
            return;
        }

        $fieldConfig = array();
        if ($this->field && method_exists($this->field, 'getConfiguration')) {
            $fieldConfig = (array) $this->field->getConfiguration();
        }

        $theme = !empty($fieldConfig['theme'])
            ? $fieldConfig['theme']
            : TurnstileSettings::get('theme', 'auto');

        $size = !empty($fieldConfig['size'])
            ? $fieldConfig['size']
            : TurnstileSettings::get('size', 'normal');

        echo TurnstileMarkup::render(array(
            'sitekey' => $sitekey,
            'theme'   => $theme,
            'size'    => $size,
            'action'  => TurnstileSettings::currentArea(),
            'id'      => $this->id,
        ));
    }

    /**
     * Der Token kommt unter dem festen Namen cf-turnstile-response,
     * nicht unter dem osTicket-Feldnamen.
     */
    function getValue()
    {
        $data = $this->field ? $this->field->getSource() : null;

        if (is_array($data) && isset($data['cf-turnstile-response'])) {
            return $data['cf-turnstile-response'];
        }

        return isset($_POST['cf-turnstile-response']) ? $_POST['cf-turnstile-response'] : null;
    }
}
