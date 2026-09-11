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
    private const ENDPOINT = 'https://rest.payamak-panel.com/api/SmartSMS/Send';
    private const TIMEOUT  = 15;

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
     * @return array{ok:bool, code:string, message:string, reference:?string}
     */
    public static function send(string $phone, string $text): array
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

        $payload = http_build_query([
            'username' => SmsSettings::username(),
            // MeliPayamak's "password" field is the panel's API key.
            'password' => SmsSettings::apiKey(),
            'to'       => Phone::forGateway(Phone::normalize($phone)),
            'text'     => $text,
            'from'     => SmsSettings::from(),
        ]);

        $handle = curl_init(self::ENDPOINT);
        curl_setopt_array($handle, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            // A gateway that presents a bad certificate is a gateway this
            // request has no business handing a credential to.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        $body   = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error  = curl_error($handle);
        curl_close($handle);

        if ($body === false) {
            // The URL is logged, the payload is not: it carries the API key.
            Logger::error('SMS transport failure', ['error' => $error]);
            return self::failure('TRANSPORT', 'ارتباط با سامانه پیامک برقرار نشد.');
        }
        if ($status < 200 || $status >= 300) {
            Logger::error('SMS gateway HTTP error', ['status' => $status]);
            return self::failure('HTTP_' . $status, 'سامانه پیامک پاسخ نامعتبر داد.');
        }

        return self::interpret((string) $body);
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
