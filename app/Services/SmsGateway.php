<?php
declare(strict_types=1);

namespace HeleXa\Services;

use HeleXa\Core\Logger;

/**
 * MeliPayamak (ملی پیامک), REST.
 *
 * Called only from the server. The credentials never reach a template, a
 * script tag or a JSON response — the browser asks for a code and is told
 * whether one was sent, nothing more.
 *
 * Failure posture: a reply this client does not recognise is a failure. The
 * alternative — assuming a text went out because nothing obviously said
 * otherwise — would leave a student staring at a code entry box waiting for a
 * message that was never sent, with no error anywhere to explain it.
 */
final class SmsGateway
{
    /** Text sending over a sender line the account owns. */
    private const ENDPOINT = 'https://rest.payamak-panel.com/api/SmartSMS/Send';

    /**
     * MeliPayamak's own one-time-code service. The API key is part of the
     * path, so the URL is assembled per call and must never be logged.
     */
    private const OTP_ENDPOINT = 'https://console.melipayamak.com/api/send/otp/';

    private const TIMEOUT = 15;

    /**
     * MeliPayamak's RetStatus table, rendered for the person reading the
     * screen. Anything absent from this map is reported as a generic gateway
     * failure rather than guessed at.
     */
    private const STATUS_MESSAGES = [
        0  => 'ارسال پیامک ناموفق بود.',
        2  => 'نام کاربری یا کلید API ملی پیامک نادرست است.',
        3  => 'اعتبار پنل ملی پیامک کافی نیست.',
        4  => 'سقف ارسال روزانه پنل ملی پیامک پر شده است.',
        5  => 'حجم ارسال پنل ملی پیامک محدود شده است.',
        6  => 'شماره فرستنده معتبر نیست.',
        7  => 'متن پیامک شامل کلمه فیلترشده است.',
        9  => 'ارسال از خطوط عمومی از طریق وب‌سرویس ممکن نیست.',
        10 => 'حساب ملی پیامک فعال نیست.',
        11 => 'پیامک ارسال نشد.',
        12 => 'مدارک حساب ملی پیامک کامل نیست.',
        35 => 'این شماره در فهرست سیاه مخابرات است.',
    ];

    /**
     * Asks MeliPayamak to mint a code and text it.
     *
     * The code comes back in the reply, which is the whole point: this panel
     * still owns verification. It hashes what it is given, stores it with a
     * lifetime and an attempt ceiling, and checks it later exactly as it would
     * check a code it had generated itself. Nothing about the security model
     * moves to the gateway — only the authorship of six digits.
     *
     * @return array{ok:bool, code:string, message:string, otp:?string, raw:?string}
     */
    public static function sendConsoleOtp(string $phone): array
    {
        $guard = self::preflight($phone);
        if ($guard !== null) {
            return $guard + ['otp' => null, 'raw' => null];
        }

        $response = self::post(
            self::OTP_ENDPOINT . SmsSettings::apiKey(),
            (string) json_encode(['to' => Phone::normalize($phone)]),
            ['Content-Type: application/json']
        );

        if (!$response['ok']) {
            return $response['result'] + ['otp' => null, 'raw' => null];
        }

        return self::interpretOtp($response['body']);
    }

    /**
     * Reads the one-time-code reply.
     *
     * MeliPayamak documents exactly two fields:
     *
     *     {"code": "3741437414", "status": "شرح خطا در صورت بروز"}
     *
     * `code` is the one it minted and texted, and which this panel is told to
     * store for checking later; `status` carries the explanation when
     * something went wrong. The documented sample is ten digits long, so no
     * length is assumed beyond "a plausible run of digits" — the panel's own
     * codes are six, MeliPayamak's are whatever the account is set to, and
     * hard-coding either would break the other.
     *
     * A reply with no readable code is a failure even if it looks cheerful. A
     * code this panel cannot read is a code it could never verify, so calling
     * that success would leave the student typing into a box that can never
     * accept anything.
     *
     * The raw body goes back for the admin's test page alone — never to a
     * student, and never into the log, because on success it contains a live
     * code and on failure it may still.
     *
     * @return array{ok:bool, code:string, message:string, otp:?string, raw:?string}
     */
    private static function interpretOtp(string $body): array
    {
        $trimmed = trim($body);
        $decoded = json_decode($trimmed, true);

        if (!is_array($decoded)) {
            Logger::error('SMS OTP reply was not JSON');

            return [
                'ok'      => false,
                'code'    => 'UNRECOGNISED',
                'message' => 'پاسخ سامانه پیامک قابل تفسیر نبود.',
                'otp'     => null,
                'raw'     => mb_substr($trimmed, 0, 500),
            ];
        }

        // 'code' is the documented name; the capitalised variant costs one
        // comparison and saves a support ticket if the casing ever differs.
        foreach (['code', 'Code'] as $field) {
            if (!isset($decoded[$field]) || !is_scalar($decoded[$field])) {
                continue;
            }
            $candidate = trim((string) $decoded[$field]);
            if (preg_match('/^\d{4,12}$/', $candidate) === 1) {
                return [
                    'ok'      => true,
                    'code'    => 'OK',
                    'message' => '',
                    'otp'     => $candidate,
                    'raw'     => null,
                ];
            }
        }

        // No code: `status` is where MeliPayamak puts the reason.
        $status = '';
        foreach (['status', 'Status', 'message', 'Message'] as $field) {
            if (isset($decoded[$field]) && is_scalar($decoded[$field])) {
                $status = trim((string) $decoded[$field]);
                break;
            }
        }

        Logger::error('SMS OTP reply carried no code', ['status' => $status]);

        return [
            'ok'      => false,
            'code'    => 'NO_CODE_IN_REPLY',
            'message' => $status !== ''
                ? 'سامانه پیامک پاسخ داد: ' . $status
                : 'سامانه پیامک کدی برنگرداند.',
            'otp'     => null,
            'raw'     => mb_substr($trimmed, 0, 500),
        ];
    }

    /**
     * The checks both providers share, before either opens a socket.
     *
     * @return array{ok:bool, code:string, message:string, reference:?string}|null null when the send may proceed
     */
    private static function preflight(string $phone): ?array
    {
        if (!Crypto::isAvailable()) {
            return self::failure('CRYPTO_UNAVAILABLE', 'سامانه پیامک پیکربندی نشده است.');
        }
        if (!SmsSettings::enabled()) {
            return self::failure('SMS_DISABLED', 'ارسال پیامک در تنظیمات غیرفعال است.');
        }
        if (!SmsSettings::isConfigured()) {
            return self::failure('SMS_NOT_CONFIGURED', 'تنظیمات پیامک کامل نیست.');
        }
        if (!Phone::isValid($phone)) {
            return self::failure('INVALID_PHONE', 'شماره موبایل معتبر نیست.');
        }
        if (!function_exists('curl_init')) {
            return self::failure('CURL_MISSING', 'سامانه پیامک روی این سرور در دسترس نیست.');
        }
        return null;
    }

    /**
     * One HTTP POST, with certificate verification left on.
     *
     * MeliPayamak's own sample turns verification off. Doing that here would
     * mean handing the account's API key to whatever answers the connection,
     * which is the one thing this request must not do. A host that cannot
     * verify the certificate needs its CA bundle fixed, not the check removed.
     *
     * @return array{ok:bool, body:string, result:array}
     */
    private static function post(string $url, string $payload, array $headers): array
    {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => array_merge($headers, ['Content-Length: ' . strlen($payload)]),
        ]);

        $body   = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error  = curl_error($handle);
        curl_close($handle);

        if ($body === false) {
            // Neither the URL nor the payload is logged: the first carries the
            // API key for one provider, the second for the other.
            Logger::error('SMS transport failure', ['error' => $error]);
            return ['ok' => false, 'body' => '', 'result' => self::failure('TRANSPORT', 'ارتباط با سامانه پیامک برقرار نشد.')];
        }
        if ($status < 200 || $status >= 300) {
            Logger::error('SMS gateway HTTP error', ['status' => $status]);
            return ['ok' => false, 'body' => (string) $body, 'result' => self::failure('HTTP_' . $status, 'سامانه پیامک پاسخ نامعتبر داد.')];
        }

        return ['ok' => true, 'body' => (string) $body, 'result' => []];
    }

    /**
     * @return array{ok:bool, code:string, message:string, reference:?string}
     */
    public static function send(string $phone, string $text): array
    {
        $guard = self::preflight($phone);
        if ($guard !== null) {
            return $guard;
        }

        $payload = http_build_query([
            'username' => SmsSettings::username(),
            // MeliPayamak's "password" field is the panel's API key.
            'password' => SmsSettings::apiKey(),
            'to'       => Phone::forGateway(Phone::normalize($phone)),
            'text'     => $text,
            'from'     => SmsSettings::from(),
        ]);

        $response = self::post(self::ENDPOINT, $payload, ['Content-Type: application/x-www-form-urlencoded']);
        if (!$response['ok']) {
            return $response['result'];
        }

        return self::interpret($response['body']);
    }

    /**
     * Reads MeliPayamak's reply.
     *
     * The REST family answers with {"Value": ..., "RetStatus": n,
     * "StrRetStatus": "..."}; RetStatus 1 is the only success. Some endpoints
     * answer with a bare message id instead, which is why a long numeric body
     * also counts — but only when it is unambiguously an id, never as a
     * catch-all.
     */
    private static function interpret(string $body): array
    {
        $trimmed = trim($body);
        $decoded = json_decode($trimmed, true);

        if (is_array($decoded) && array_key_exists('RetStatus', $decoded)) {
            $status    = (int) $decoded['RetStatus'];
            $reference = isset($decoded['Value']) ? (string) $decoded['Value'] : null;

            if ($status === 1) {
                return ['ok' => true, 'code' => 'OK', 'message' => '', 'reference' => $reference];
            }

            Logger::error('SMS gateway rejected the message', [
                'ret_status' => $status,
                'ret_text'   => (string) ($decoded['StrRetStatus'] ?? ''),
            ]);

            return self::failure(
                'GATEWAY_' . $status,
                self::STATUS_MESSAGES[$status] ?? 'ارسال پیامک از سوی سامانه پذیرفته نشد.'
            );
        }

        // A bare recId. MeliPayamak's ids are long; a short number here would
        // be an error code wearing the same clothes, so it is not accepted.
        $bare = trim($trimmed, '"');
        if (preg_match('/^\d{8,}$/', $bare) === 1) {
            return ['ok' => true, 'code' => 'OK', 'message' => '', 'reference' => $bare];
        }

        Logger::error('SMS gateway returned an unrecognised response', [
            'body' => mb_substr($trimmed, 0, 200),
        ]);

        return self::failure('UNRECOGNISED', 'پاسخ سامانه پیامک قابل تفسیر نبود.');
    }

    private static function failure(string $code, string $message): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message, 'reference' => null];
    }
}
