<?php

declare(strict_types=1);

/**
 * Serverseitige Validierung eines Turnstile-Tokens gegen die siteverify-API.
 *
 * Eigenschaften laut Cloudflare-Doku (Stand 2026-05-05):
 *  - Token ist maximal 2048 Zeichen lang
 *  - Token ist 300 Sekunden gültig
 *  - Token ist EINMALIG verwendbar; ein Replay liefert 'timeout-or-duplicate'
 *
 * Aus der Einmaligkeit folgt der Request-Cache: osTicket validiert ein
 * Formular innerhalb eines Requests mehrfach (Validierung + Speichern).
 * Ohne Cache scheitert der zweite Aufruf mit 'timeout-or-duplicate' und
 * das Formular wäre nie absendbar.
 *
 * Bewusst NICHT verwendet: idempotency_key. Ein deterministischer Key würde
 * Cloudflare dazu bringen, bei einem echten Replay über Request-Grenzen
 * hinweg das ursprüngliche Erfolgsergebnis zurückzugeben — also genau den
 * Replay-Schutz aushebeln, den wir haben wollen.
 *
 * @see https://developers.cloudflare.com/turnstile/get-started/server-side-validation/
 */
final class TurnstileVerifier
{
    /** Konstante Endpoint-URL. Wird nie aus Config oder Request gebaut. */
    const ENDPOINT = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    const MAX_TOKEN_LENGTH = 2048;

    /** Ergebnis-Cache pro Request: sha256(token) => Ergebnis-Array. */
    private static $cache = array();

    /** Deckel gegen unbegrenztes Wachstum des Caches. */
    const CACHE_LIMIT = 32;

    /**
     * Prüft ein Token.
     *
     * @param mixed $token   Rohwert aus dem Request (nicht vertrauenswürdig).
     * @param array $options secret, timeout, hostname, fail_mode, log, area
     *
     * @return array{ok:bool, reason:string, codes:array, message:string}
     *         reason: ok | missing | too_long | rejected | unreachable | misconfigured
     */
    public static function verify($token, array $options)
    {
        $secret   = isset($options['secret']) ? trim((string) $options['secret']) : '';
        $timeout  = isset($options['timeout']) ? (int) $options['timeout'] : 5;
        $hostname = isset($options['hostname']) ? strtolower(trim((string) $options['hostname'])) : '';
        $failMode = isset($options['fail_mode']) ? (string) $options['fail_mode'] : 'closed';
        $doLog    = !empty($options['log']);
        $area     = isset($options['area']) ? (string) $options['area'] : 'unknown';

        // Fail-Fast: ohne Secret niemals stillschweigend durchlassen.
        if ($secret === '') {
            self::log($doLog, $area, 'misconfigured', 'secret key empty');
            return self::result(false, 'misconfigured',
                'Die Sicherheitsprüfung ist nicht vollständig konfiguriert. '
                . 'Bitte wenden Sie sich an den Betreiber.');
        }

        if (!is_string($token)) {
            $token = '';
        }
        $token = trim($token);

        if ($token === '') {
            return self::result(false, 'missing',
                'Bitte bestätigen Sie die Sicherheitsprüfung.');
        }

        // Längen-Cap vor jedem weiteren Verarbeiten: verhindert, dass ein
        // Riesen-Payload Speicher und einen Outbound-Request kostet.
        if (strlen($token) > self::MAX_TOKEN_LENGTH) {
            self::log($doLog, $area, 'too_long', 'length=' . strlen($token));
            return self::result(false, 'too_long',
                'Die Sicherheitsprüfung ist ungültig. Bitte laden Sie die Seite neu.');
        }

        $cacheKey = hash('sha256', $token);
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $http = self::call($secret, $token, $timeout);

        if ($http['transport_error']) {
            // Netzwerk-/Timeout-Fehler: hier — und nur hier — greift der Fail-Mode.
            self::log($doLog, $area, 'unreachable', $http['error']);

            if ($failMode === 'open') {
                $res = self::result(true, 'unreachable', '');
                // Fail-open-Ergebnisse werden NICHT gecacht: der nächste
                // Request soll wieder einen echten Versuch machen.
                return $res;
            }

            return self::cache($cacheKey, self::result(false, 'unreachable',
                'Die Sicherheitsprüfung ist momentan nicht erreichbar. '
                . 'Bitte versuchen Sie es in einigen Minuten erneut.'));
        }

        $body = json_decode($http['body'], true);

        if (!is_array($body)) {
            self::log($doLog, $area, 'unreachable', 'unparseable response, http=' . $http['status']);
            if ($failMode === 'open') {
                return self::result(true, 'unreachable', '');
            }
            return self::cache($cacheKey, self::result(false, 'unreachable',
                'Die Sicherheitsprüfung konnte nicht abgeschlossen werden. '
                . 'Bitte versuchen Sie es erneut.'));
        }

        $codes = isset($body['error-codes']) && is_array($body['error-codes'])
            ? array_map('strval', $body['error-codes'])
            : array();

        if (empty($body['success'])) {
            self::log($doLog, $area, 'rejected', implode(',', $codes));
            return self::cache($cacheKey, self::result(false, 'rejected',
                self::userMessage($codes), $codes));
        }

        // Erfolg laut Cloudflare — jetzt die Zusatzprüfung.
        // Ohne Hostname-Prüfung wäre ein Token, das auf einer beliebigen
        // anderen Domain desselben Widgets erzeugt wurde, hier gültig.
        if ($hostname !== '') {
            $got = isset($body['hostname']) ? strtolower((string) $body['hostname']) : '';
            if ($got !== $hostname) {
                self::log($doLog, $area, 'hostname_mismatch',
                    'expected=' . $hostname . ' got=' . $got);
                return self::cache($cacheKey, self::result(false, 'rejected',
                    'Die Sicherheitsprüfung gehört nicht zu dieser Seite. '
                    . 'Bitte laden Sie die Seite neu.'));
            }
        }

        return self::cache($cacheKey, self::result(true, 'ok', ''));
    }

    /**
     * Führt den HTTPS-POST aus. Kapselt cURL, damit der Aufrufer
     * Transport- von Fachfehlern trennen kann.
     *
     * @return array{transport_error:bool, error:string, status:int, body:string}
     */
    private static function call($secret, $token, $timeout)
    {
        $timeout = max(2, min(30, (int) $timeout));

        $payload = array(
            'secret'   => $secret,
            'response' => $token,
        );

        $ip = self::clientIp();
        if ($ip !== null) {
            $payload['remoteip'] = $ip;
        }

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL            => self::ENDPOINT,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($payload, '', '&'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => max(1, (int) ceil($timeout / 2)),
            // TLS-Verifikation bleibt an. Niemals abschalten.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // Keine Redirects: der Endpoint ist fix, ein Redirect wäre ein Angriffssignal.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER     => array(
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
                'Expect:',
            ),
            CURLOPT_USERAGENT      => 'osTicket-Turnstile/1.0',
        ));

        $body   = curl_exec($ch);
        $errNo  = curl_errno($ch);
        $errMsg = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errNo !== 0 || $body === false) {
            return array(
                'transport_error' => true,
                // curl_error enthält keine Secrets — Payload wird nicht mitgeloggt.
                'error'           => 'curl(' . $errNo . '): ' . $errMsg,
                'status'          => $status,
                'body'            => '',
            );
        }

        if ($status !== 200) {
            return array(
                'transport_error' => true,
                'error'           => 'http status ' . $status,
                'status'          => $status,
                'body'            => '',
            );
        }

        return array(
            'transport_error' => false,
            'error'           => '',
            'status'          => $status,
            'body'            => (string) $body,
        );
    }

    /**
     * Ermittelt die Client-IP für das optionale remoteip-Feld.
     *
     * Hinweis: Der Origin ist unter seiner IP direkt erreichbar, CF-Connecting-IP
     * ist damit fälschbar. Das ist hier hinnehmbar, weil remoteip bei siteverify
     * rein informativ ist und keine Entscheidung beeinflusst. Verlassen wir uns
     * an anderer Stelle auf die IP, muss sie gegen die Cloudflare-Ranges
     * geprüft werden.
     */
    private static function clientIp()
    {
        $candidates = array();

        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $candidates[] = $_SERVER['HTTP_CF_CONNECTING_IP'];
        }
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            $candidates[] = $_SERVER['REMOTE_ADDR'];
        }

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Übersetzt Cloudflare-Fehlercodes in eine Meldung für den Endnutzer.
     * Interne Codes werden bewusst NICHT durchgereicht.
     */
    private static function userMessage(array $codes)
    {
        foreach ($codes as $code) {
            switch ($code) {
                case 'timeout-or-duplicate':
                case 'invalid-input-response':
                    return 'Die Sicherheitsprüfung ist abgelaufen. '
                         . 'Bitte laden Sie die Seite neu und versuchen Sie es erneut.';

                case 'missing-input-response':
                    return 'Bitte bestätigen Sie die Sicherheitsprüfung.';

                case 'missing-input-secret':
                case 'invalid-input-secret':
                case 'bad-request':
                    // Konfigurationsfehler auf unserer Seite — Detail nur ins Log.
                    return 'Die Sicherheitsprüfung ist nicht korrekt konfiguriert. '
                         . 'Bitte wenden Sie sich an den Betreiber.';
            }
        }

        return 'Die Sicherheitsprüfung war nicht erfolgreich. Bitte versuchen Sie es erneut.';
    }

    /**
     * Schreibt eine Zeile nach error_log().
     * Enthält niemals den Secret Key und niemals das vollständige Token.
     */
    private static function log($enabled, $area, $reason, $detail)
    {
        if (!$enabled) {
            return;
        }

        $ip = self::clientIp();

        error_log(sprintf(
            '[turnstile] area=%s reason=%s ip=%s detail=%s',
            preg_replace('/[^a-z0-9_.-]/i', '', (string) $area),
            preg_replace('/[^a-z0-9_.-]/i', '', (string) $reason),
            $ip !== null ? $ip : '-',
            preg_replace('/[^a-z0-9_,.:= ()-]/i', '', substr((string) $detail, 0, 200))
        ));
    }

    private static function result($ok, $reason, $message, array $codes = array())
    {
        return array(
            'ok'      => (bool) $ok,
            'reason'  => (string) $reason,
            'codes'   => $codes,
            'message' => (string) $message,
        );
    }

    private static function cache($key, array $result)
    {
        if (count(self::$cache) >= self::CACHE_LIMIT) {
            array_shift(self::$cache);
        }
        self::$cache[$key] = $result;

        return $result;
    }

    /** Nur für Tests. */
    public static function resetCache()
    {
        self::$cache = array();
    }
}
