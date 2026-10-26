<?php

/**
 * osTicket Plugin Manifest — Cloudflare Turnstile
 *
 * Getestet gegen osTicket 1.18.x (Plugin-API identisch zu 1.17.x).
 * Der Key 'plugin' wird von PluginManager per explode(':') zerlegt:
 * include/class.plugin.php:698
 */

return array(
    'id'          => 'studio-prisma:turnstile',
    'version'     => '1.0.3',
    'ost_version' => '1.18',
    'name'        => 'Cloudflare Turnstile',
    'author'      => 'Studio Prisma',
    'url'         => 'https://github.com/studio-prisma/osticket-plugin-turnstile',
    'description' => 'Cloudflare Turnstile als CAPTCHA für Gast-Ticketformular, '
                   . 'Client-Registrierung, Client-Login und Staff-Login. '
                   . 'Serverseitige Token-Validierung via siteverify, konfigurierbarer Fail-Mode.',
    'plugin'      => 'class.TurnstilePlugin.php:TurnstilePlugin',
);
