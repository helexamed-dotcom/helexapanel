<?php
/**
 * Security policy values that are code, not operator settings.
 * Runtime-tunable values (timeouts, attempt limits) live in the settings table.
 */
return [
    'password' => [
        // Minimum eight characters, English letters and digits only.
        // Length is the only factor that really matters here, so the floor is
        // a minimum rather than a fixed length: a longer password is always
        // allowed and always better.
        'min_length'        => 8,
        'admin_min_length'  => 8,
    ],
    'upload' => [
        'max_bytes'          => 20 * 1024 * 1024,
        'allowed_mime'       => [
            'text/html', 'image/png', 'image/jpeg', 'image/gif', 'image/webp',
            'image/svg+xml', 'text/css', 'text/javascript', 'application/javascript',
            'font/woff', 'font/woff2', 'application/pdf',
        ],
        'forbidden_extensions' => [
            'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'phps',
            'cgi', 'pl', 'py', 'sh', 'exe', 'htaccess', 'htpasswd', 'ini',
        ],
    ],
    'viewer' => [
        'token_ttl'          => 90,   // seconds
        'asset_token_ttl'    => 900,
        'heartbeat_interval' => 25,
        'heartbeat_grace'    => 15,
    ],
];
