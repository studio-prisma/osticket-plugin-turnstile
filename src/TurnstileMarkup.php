<?php

declare(strict_types=1);

/**
 * Erzeugt das HTML für das Turnstile-Widget.
 *
 * Wird von zwei Stellen genutzt: vom Formular-Builder-Feld (Ticketformular,
 * Registrierung) und vom LoginGate (Client-/Staff-Login). Deshalb ausgelagert
 * — ein Ort, an dem sich Markup und Rendering-Logik ändern.
 *
 * Rendering-Modus ist bewusst "explicit" und nicht der Auto-Modus:
 * osTicket lädt Formulare per AJAX nach (Hilfe-Thema wechseln), und der
 * Auto-Modus rendert nur Widgets, die beim initialen Laden im DOM standen.
 */
final class TurnstileMarkup
{
    const API_BASE = 'https://challenges.cloudflare.com/turnstile/v0/api.js';

    /** @var bool Bootstrap-Script nur einmal pro Seite ausgeben. */
    private static $bootstrapEmitted = false;

    /**
     * @var bool Nur EIN Widget pro Request.
     *
     * Gegen osTicket 1.18.4 gemessen wird das Feld pro Request genau einmal
     * gerendert — die Sperre ist also aktuell nicht nötig, sondern Absicherung:
     * Turnstile schreibt immer in dasselbe Feld cf-turnstile-response. Wären
     * zwei Challenges auf einer Seite, gewänne die zuletzt gelöste und die
     * andere wäre ein verbranntes Token. Greift, sobald ein Formular das Feld
     * doppelt einbindet oder osTicket sein Rendering ändert.
     */
    private static $containerEmitted = false;

    /**
     * Vollständiger Block: Container + (einmalig) Loader-Script.
     *
     * @param array $opts sitekey, theme, size, action, id
     */
    public static function render(array $opts)
    {
        $container = self::container($opts);

        // Kein Container -> auch kein Script. Verhindert, dass ein zweiter
        // Render-Versuch api.js ein weiteres Mal nachlädt.
        if ($container === '') {
            return '';
        }

        return $container . self::bootstrap();
    }

    /**
     * Nur der Container-DIV.
     */
    public static function container(array $opts)
    {
        $sitekey = (string) ($opts['sitekey'] ?? '');
        if ($sitekey === '' || self::$containerEmitted) {
            return '';
        }

        self::$containerEmitted = true;

        $theme  = self::pick((string) ($opts['theme'] ?? 'auto'), array('auto', 'light', 'dark'), 'auto');
        $size   = self::pick((string) ($opts['size'] ?? 'normal'), array('normal', 'flexible', 'compact'), 'normal');
        $action = preg_replace('/[^a-z0-9_-]/i', '', (string) ($opts['action'] ?? ''));
        $id     = preg_replace('/[^a-z0-9_-]/i', '', (string) ($opts['id'] ?? ''));

        $attrs = sprintf(
            'class="cf-turnstile" data-sitekey="%s" data-theme="%s" data-size="%s"',
            self::esc($sitekey),
            self::esc($theme),
            self::esc($size)
        );

        if ($action !== '') {
            $attrs .= ' data-action="' . self::esc($action) . '"';
        }
        if ($id !== '') {
            $attrs .= ' id="' . self::esc($id) . '"';
        }

        return '<div class="turnstile-wrap" style="margin:10px 0;"><div ' . $attrs . '></div></div>';
    }

    /**
     * Loader- und Render-Script. Gibt beim zweiten Aufruf einen leichten
     * Re-Render-Trigger zurück statt das komplette Script erneut zu laden.
     */
    public static function bootstrap()
    {
        if (self::$bootstrapEmitted) {
            return '<script>if(window.ostTurnstileRender){window.ostTurnstileRender();}</script>';
        }

        self::$bootstrapEmitted = true;

        $js = <<<'JS'
(function(){
  if (window.ostTurnstileRender) { return; }
  window.ostTurnstileRender = function () {
    if (!window.turnstile) { return; }
    var nodes = document.querySelectorAll('.cf-turnstile');
    for (var i = 0; i < nodes.length; i++) {
      var el = nodes[i];
      if (el.getAttribute('data-ost-rendered')) { continue; }
      el.setAttribute('data-ost-rendered', '1');
      try {
        window.turnstile.render(el, {
          sitekey: el.getAttribute('data-sitekey'),
          theme:   el.getAttribute('data-theme') || 'auto',
          size:    el.getAttribute('data-size')  || 'normal',
          action:  el.getAttribute('data-action') || undefined,
          'response-field-name': 'cf-turnstile-response'
        });
      } catch (e) {
        el.removeAttribute('data-ost-rendered');
      }
    }
  };
  window.ostTurnstileInit = window.ostTurnstileRender;

  // Sicherheitsnetz für AJAX-nachgeladene Formulare (osTicket lädt das
  // Ticketformular erst nach Auswahl des Help Topics über ajax.php nach).
  // Wird das Fragment per innerHTML eingehängt, feuert der onload-Callback
  // von api.js unter Umständen nicht mehr — dann greift dieser Poller.
  var tries = 0;
  var iv = setInterval(function () {
    tries++;
    if (window.turnstile) { window.ostTurnstileRender(); }
    if (tries > 40) { clearInterval(iv); }
  }, 250);
})();
JS;

        $src = self::API_BASE . '?onload=ostTurnstileInit&render=explicit';

        return '<script>' . $js . '</script>'
             . '<script src="' . self::esc($src) . '" async defer></script>';
    }

    /**
     * Setzt den Emitted-Flag zurück. Nur für Tests.
     */
    public static function reset()
    {
        self::$bootstrapEmitted = false;
        self::$containerEmitted = false;
    }

    private static function pick($value, array $allowed, $fallback)
    {
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private static function esc($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
