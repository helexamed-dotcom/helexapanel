<?php
declare(strict_types=1);

/**
 * HeleXa Med — external security audit.
 *
 * Run it against a live installation from any machine that can reach the site:
 *
 *     php tests/security_audit.php https://yourdomain.com
 *
 * Everything here is unauthenticated and read-only. It checks the things that
 * actually go wrong on shared hosting: leftover installers, private folders
 * exposed on the web, missing headers, and pages reachable without a session.
 *
 * A pass here is not a security certificate. It means the obvious mistakes are
 * absent, which is a floor, not a ceiling.
 */

$base = rtrim((string) ($argv[1] ?? ''), '/');

if ($base === '' || filter_var($base, FILTER_VALIDATE_URL) === false) {
    fwrite(STDERR, "usage: php tests/security_audit.php https://yourdomain.com\n");
    exit(1);
}

$pass = 0;
$fail = 0;
$warn = 0;

/** @return array{status:int, headers:array<string,string>, body:string} */
function request(string $url, string $method = 'GET', array $data = [], bool $follow = false): array
{
    $handle = curl_init();
    curl_setopt_array($handle, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'HeleXa-Security-Audit/1.0',
    ]);
    if ($method === 'POST') {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($data));
    }

    $raw    = (string) curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $size   = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    curl_close($handle);

    $headers = [];
    foreach (explode("\r\n", substr($raw, 0, $size)) as $line) {
        if (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
    }

    return ['status' => $status, 'headers' => $headers, 'body' => substr($raw, $size)];
}

function result(string $label, bool $ok, string $detail, bool $warnOnly = false): void
{
    global $pass, $fail, $warn;

    if ($ok) {
        $pass++;
        printf("  [ OK ]   %-48s %s\n", $label, $detail);
        return;
    }
    if ($warnOnly) {
        $warn++;
        printf("  [WARN]   %-48s %s\n", $label, $detail);
        return;
    }
    $fail++;
    printf("  [FAIL]   %-48s %s\n", $label, $detail);
}

echo "\nHeleXa Med — security audit of {$base}\n";
echo str_repeat('-', 92), "\n\n";

/* ---------------------------------------------------------- transport */

echo "Transport\n";
$home = request($base . '/', 'GET', [], true);
result('site responds', $home['status'] > 0, 'HTTP ' . $home['status']);

if (str_starts_with($base, 'https://')) {
    $plain = request(str_replace('https://', 'http://', $base) . '/');
    $redirects = in_array($plain['status'], [301, 302, 307, 308], true)
        && str_starts_with(strtolower($plain['headers']['location'] ?? ''), 'https://');
    result('HTTP redirects to HTTPS', $redirects, 'HTTP ' . $plain['status']);

    result(
        'HSTS header',
        isset($home['headers']['strict-transport-security']),
        $home['headers']['strict-transport-security'] ?? 'missing'
    );
} else {
    result('HTTPS in use', false, 'audit was run against a plain HTTP URL');
}

/* ------------------------------------------------------------ headers */

echo "\nSecurity headers\n";
$login = request($base . '/login');

foreach ([
    'content-security-policy'  => null,
    'x-content-type-options'   => 'nosniff',
    'x-frame-options'          => null,
    'referrer-policy'          => null,
    'permissions-policy'       => null,
] as $header => $expected) {
    $value = $login['headers'][$header] ?? '';
    $ok    = $value !== '' && ($expected === null || strtolower($value) === $expected);
    result($header, $ok, $ok ? substr($value, 0, 46) : 'missing');
}

result(
    'X-Powered-By removed',
    !isset($login['headers']['x-powered-by']),
    $login['headers']['x-powered-by'] ?? 'absent'
);

$cookie = $login['headers']['set-cookie'] ?? '';
if ($cookie !== '') {
    result('session cookie HttpOnly', stripos($cookie, 'httponly') !== false, 'Set-Cookie inspected');
    result('session cookie SameSite', stripos($cookie, 'samesite') !== false, 'Set-Cookie inspected');
    result(
        'session cookie Secure',
        stripos($cookie, 'secure') !== false,
        str_starts_with($base, 'https://') ? 'Set-Cookie inspected' : 'expected to be missing over plain HTTP',
        !str_starts_with($base, 'https://')
    );
}

/* -------------------------------------------------- exposed artefacts */

echo "\nExposed files and folders\n";
$mustNotBeReadable = [
    '/install.php'                    => 'installer',
    '/config/config.php'              => 'database credentials',
    '/config/security.php'            => 'security policy',
    '/app/Core/Database.php'          => 'application source',
    '/database/schema.sql'            => 'database schema',
    '/storage/logs/'                  => 'log directory',
    '/storage/private/lessons/'       => 'private lessons',
    '/storage/installed.lock'         => 'install lock',
    '/bootstrap/bootstrap.php'        => 'bootstrap',
    '/routes/web.php'                 => 'route table',
    '/.env'                           => 'environment file',
    '/README.md'                      => 'readme',
];

foreach ($mustNotBeReadable as $path => $what) {
    $response = request($base . $path);
    // 200 with a body is a leak; 403/404 or a redirect to the app is fine.
    $leaked = $response['status'] === 200
        && !str_contains(strtolower($response['body']), '<title>ورود')
        && strlen($response['body']) > 0;
    result('not readable: ' . $path, !$leaked, 'HTTP ' . $response['status'] . ' — ' . $what);
}

$assets = request($base . '/assets/');
result(
    'directory listing disabled',
    $assets['status'] !== 200 || !str_contains($assets['body'], 'Index of'),
    'HTTP ' . $assets['status']
);

/* ---------------------------------------------------- path traversal */

echo "\nPath traversal\n";
foreach ([
    '/content/../../config/config.php',
    '/content/%2e%2e%2f%2e%2e%2fconfig/config.php',
    '/account/avatar/..%2f..%2fconfig%2fconfig.php',
] as $path) {
    $response = request($base . $path);
    $leaked   = str_contains($response['body'], 'password') && str_contains($response['body'], 'database');
    result('blocked: ' . substr($path, 0, 44), !$leaked, 'HTTP ' . $response['status']);
}

/* ------------------------------------------------ unauthenticated access */

echo "\nAccess control (no session)\n";
foreach ([
    '/student'                => 'student dashboard',
    '/student/courses'        => 'course list',
    '/student/analytics'      => 'analytics',
    '/admin'                  => 'admin dashboard',
    '/admin/students'         => 'student management',
    '/admin/settings'         => 'settings',
    '/admin/security'         => 'security review',
    '/account/profile'        => 'profile',
] as $path => $what) {
    $response = request($base . $path);
    $blocked  = in_array($response['status'], [301, 302, 401, 403, 404], true);
    result('guest blocked from ' . $path, $blocked, 'HTTP ' . $response['status'] . ' — ' . $what);
}

$stream = request($base . '/content/00000000-0000-4000-8000-000000000000/stream');
result(
    'guest blocked from content stream',
    in_array($stream['status'], [301, 302, 401, 403, 404], true),
    'HTTP ' . $stream['status']
);

/* ----------------------------------------------------------------- PWA */

echo "\nPWA surface\n";

$manifest = request($base . '/manifest.webmanifest');
result('manifest is served', $manifest['status'] === 200,
    'HTTP ' . $manifest['status'] . ' — ' . ($manifest['headers']['content-type'] ?? 'no content-type'));
result('manifest is valid JSON',
    $manifest['status'] === 200 && json_decode($manifest['body'], true) !== null,
    'parsed');

$worker = request($base . '/sw.js');
result('service worker is served', $worker['status'] === 200, 'HTTP ' . $worker['status']);
result('service worker is not cached',
    str_contains(strtolower($worker['headers']['cache-control'] ?? ''), 'no-cache')
    || str_contains(strtolower($worker['headers']['cache-control'] ?? ''), 'no-store'),
    $worker['headers']['cache-control'] ?? 'missing Cache-Control',
    true);

// Offline study was removed: its pages must be gone, not half there.
foreach (['/offline', '/api/offline/catalogue'] as $gone) {
    $response = request($base . $gone);
    result('removed page is gone: ' . $gone,
        in_array($response['status'], [301, 302, 401, 403, 404], true),
        'HTTP ' . $response['status']);
}

// Private pictures and personal panels need a session: receipts, posts,
// the cart, lesson and map images, the rankings and the privacy page.
foreach ([
    '/api/session/state',
    '/hub/cart',
    '/shop/cart',
    '/shop/orders',
    '/media/receipts/20260101-aaaaaaaaaaaaaaaaaaaa.png',
    '/media/posts/20260101-aaaaaaaaaaaaaaaaaaaa.png',
    '/media/lessons/20260101-aaaaaaaaaaaaaaaaaaaa.png',
    '/media/mindmaps/20260101-aaaaaaaaaaaaaaaaaaaa.png',
    '/media/figures/20260101-aaaaaaaaaaaaaaaaaaaa.png',
    '/student/leaderboard',
    '/account/privacy',
] as $endpoint) {
    $response = request($base . $endpoint);
    result('guest blocked from ' . $endpoint,
        in_array($response['status'], [301, 302, 401, 403], true),
        'HTTP ' . $response['status']);
}

foreach (['/api/sync/study', '/api/sync/status', '/shop/cart', '/shop/checkout', '/profile/posts'] as $endpoint) {
    $response = request($base . $endpoint, 'POST', ['events' => []]);
    result('guest POST refused: ' . $endpoint,
        in_array($response['status'], [301, 302, 401, 403, 419], true),
        'HTTP ' . $response['status']);
}

// The Telegram webhook answers only Telegram (its secret header).
$tg = request($base . '/telegram/webhook', 'POST', ['update_id' => 1]);
result('telegram webhook refuses a request without its secret',
    in_array($tg['status'], [403, 404, 419], true),
    'HTTP ' . $tg['status']);

// The payment return address must never mark anything paid on its own.
$cb = request($base . '/shop/pay/callback?order=00000000-0000-4000-8000-000000000000&Authority=A0000000000000000000000000000000000&Status=OK');
result('payment callback without a real order does nothing',
    in_array($cb['status'], [301, 302], true),
    'HTTP ' . $cb['status']);

/* ---------------------------------------------------------------- CSRF */

echo "\nCSRF\n";
$post = request($base . '/login', 'POST', ['identifier' => 'audit', 'password' => 'audit']);
result(
    'login POST without a token is refused',
    in_array($post['status'], [419, 403, 302], true),
    'HTTP ' . $post['status']
);

/* -------------------------------------------------------------- report */

echo "\n", str_repeat('-', 92), "\n";
printf("passed: %d   warnings: %d   failed: %d\n\n", $pass, $warn, $fail);

if ($fail > 0) {
    echo "Fix every FAIL before giving students access.\n\n";
    exit(2);
}
echo "No obvious exposure found. This is a floor, not a guarantee.\n\n";
exit(0);
