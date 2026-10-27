<?php

declare(strict_types=1);

namespace Volta\Funnel;

/**
 * Server-side HTTP Basic Auth gate — enforced before any page content is sent, and invisible
 * to view-source/DevTools. This replaces the OLD Volta_Analytics gate, which was a client-side
 * JS overlay (email typed into a form, checked against a list baked into the HTML): that one
 * shipped the full dataset to every browser regardless of "login" and was removed on request
 * (see the volta-analytics-auth history) because it was never real security. This is.
 *
 * Reads credentials from config.php's 'basic_auth' block. Absent block = gate disabled, so a
 * config.php without it does not lock everyone out by accident — add the block to turn it on.
 */
function requireBasicAuth(array $config): void
{
    $auth = $config['basic_auth'] ?? null;
    if (!$auth || empty($auth['username']) || empty($auth['password'])) {
        return;
    }

    [$user, $pass] = readBasicAuthCredentials();

    if ($user !== null && $pass !== null
        && hash_equals((string) $auth['username'], $user)
        && hash_equals((string) $auth['password'], $pass)
    ) {
        return;
    }

    header('WWW-Authenticate: Basic realm="Volta Reporting"');
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Access denied.');
}

/**
 * $_SERVER['PHP_AUTH_USER']/['PHP_AUTH_PW'] are populated automatically under Apache mod_php
 * and most PHP-FPM setups, but some Nginx+FPM configs don't forward the Authorization header to
 * PHP unless explicitly told to (fastcgi_param HTTP_AUTHORIZATION) — fall back to parsing the
 * raw header ourselves so this gate works regardless of what's actually running underneath.
 *
 * @return array{0: ?string, 1: ?string}
 */
function readBasicAuthCredentials(): array
{
    if (isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
        return [$_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']];
    }

    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;

    if (is_string($header) && stripos($header, 'Basic ') === 0) {
        $decoded = base64_decode(substr($header, 6), true);
        if ($decoded !== false && str_contains($decoded, ':')) {
            [$u, $p] = explode(':', $decoded, 2);
            return [$u, $p];
        }
    }

    return [null, null];
}
