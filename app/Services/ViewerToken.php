<?php
declare(strict_types=1);

namespace HeleXa\Services;

use HeleXa\Core\Request;
use HeleXa\Core\Str;
use HeleXa\Models\ViewerTokenRepository;

/**
 * Short-lived, single-use handles for the content stream.
 *
 * A token is bound to the user, the login session, the content, the user agent
 * and the /24 network prefix. Copying the viewer URL into another browser,
 * another account or another machine therefore fails, and the token expires in
 * well under two minutes even if it is never used.
 *
 * The raw token is returned once and never stored: only its SHA-256 lives in
 * the database, so a database read does not yield usable tokens.
 */
final class ViewerToken
{
    public static function issue(Request $request, int $userId, int $sessionId, int $contentId, string $purpose = 'document'): string
    {
        $raw = Str::token(32);
        $ttl = match ($purpose) {
            'asset'     => (int) \HeleXa\Core\Config::get('security.viewer.asset_token_ttl', 900),
            'heartbeat' => 7200,
            default     => Settings::int('viewer_token_ttl', 90),
        };

        (new ViewerTokenRepository())->store([
            'token_hash'     => Str::hash($raw),
            'user_id'        => $userId,
            'session_id'     => $sessionId,
            'content_id'     => $contentId,
            'purpose'        => $purpose,
            'ua_hash'        => Str::hmac($request->userAgent()),
            'ip_prefix_hash' => Str::hmac(Str::ipPrefix($request->ip())),
            'expires_at'     => date('Y-m-d H:i:s', time() + $ttl),
            'max_uses'       => $purpose === 'document' ? 1 : 500,
        ]);

        return $raw;
    }

    /**
     * @return array|null the token row when valid, null otherwise
     */
    public static function verify(Request $request, string $raw, int $userId, int $sessionId, string $purpose = 'document'): ?array
    {
        if ($raw === '' || strlen($raw) !== 64) {
            return null;
        }

        $repository = new ViewerTokenRepository();
        $row        = $repository->findUsable(Str::hash($raw), $purpose);

        if ($row === null) {
            return null;
        }
        if ((int) $row['user_id'] !== $userId || (int) $row['session_id'] !== $sessionId) {
            return null;
        }
        if (!hash_equals((string) $row['ua_hash'], Str::hmac($request->userAgent()))) {
            return null;
        }
        // Mobile networks rotate addresses, so only the network prefix is bound.
        if ($row['ip_prefix_hash'] !== null
            && !hash_equals((string) $row['ip_prefix_hash'], Str::hmac(Str::ipPrefix($request->ip())))) {
            return null;
        }

        $repository->consume((int) $row['id']);

        return $row;
    }
}
