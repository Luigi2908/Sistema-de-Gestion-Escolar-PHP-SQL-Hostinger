<?php
/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 */

// dynamic PWA manifest — name/theme follow the project's site branding.
require_once 'config.php';

header('Content-Type: application/manifest+json; charset=utf-8');

$b = function_exists('getSiteBranding') ? getSiteBranding() : ['site_name' => 'Dashboard System'];
$name = $b['site_name'] !== '' ? $b['site_name'] : 'Dashboard System';
// short_name <= 12 chars — tolerate hosts without mbstring
$len = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
$short = $len > 12 ? (function_exists('mb_substr') ? mb_substr($name, 0, 12) : substr($name, 0, 12)) : $name;

echo json_encode([
    'name'              => $name,
    'short_name'        => $short,
    'description'       => $name . ' — admin dashboard',
    'start_url'         => 'dashboard.php',
    'scope'             => './',
    'display'           => 'standalone',
    'display_override'  => ['standalone', 'minimal-ui'],
    'background_color'  => '#001f3f',
    'theme_color'       => '#001f3f',
    'lang'              => 'en',
    'categories'        => ['business', 'productivity'],
    'icons'             => [
        ['src' => 'icon-192.png',          'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'icon-512.png',          'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit();
