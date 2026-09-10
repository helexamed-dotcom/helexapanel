<?php
declare(strict_types=1);

namespace HeleXa\Core;

/**
 * Sends hardening headers on every response.
 * The CSP here governs the PANEL. The private content viewer gets its own,
 * looser policy in Phase 3 so real lesson HTML keeps working.
 */
final class SecurityHeaders
{
    public static function apply(Request $request): void
    {
        if (headers_sent()) {
            return;
        }

        header_remove('X-Powered-By');

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('X-Permitted-Cross-Domain-Policies: none');
        // Legacy header, still honoured by some proxies and old browsers.
        header('X-XSS-Protection: 0');

        $nonce = self::nonce();
        $csp = [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'self'",
            "form-action 'self'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "style-src 'self' 'unsafe-inline'",
            "script-src 'self' 'nonce-" . $nonce . "'",
            "connect-src 'self'",
            // The service worker and the manifest are ours and only ours.
            "worker-src 'self'",
            "manifest-src 'self'",
        ];
        header('Content-Security-Policy: ' . implode('; ', $csp));

        if ($request->isSecure()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    /** Per-request nonce so inline panel scripts do not require 'unsafe-inline'. */
    public static function nonce(): string
    {
        static $nonce = null;
        if ($nonce === null) {
            $nonce = base64_encode(random_bytes(16));
        }
        return $nonce;
    }
}
