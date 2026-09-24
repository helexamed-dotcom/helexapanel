<?php
declare(strict_types=1);

namespace HeleXa\Services\Shop;

use HeleXa\Core\Config;
use HeleXa\Core\Logger;
use HeleXa\Services\Settings;

/**
 * Online payment through ZarinPal (REST v4, amounts in تومان).
 *
 * request() asks ZarinPal for an authority and returns the page to send the
 * student to; verify() is called from the callback and is the only thing
 * that may say an order was paid. The callback's own «Status=OK» is never
 * trusted on its own: the amount is re-sent and ZarinPal must confirm it.
 *
 * Certificate verification stays on, for the same reason as the SMS client:
 * the merchant id is the account.
 */
final class Gateway
{
    private const TIMEOUT = 20;

    public static function enabled(): bool
    {
        return Settings::get('shop_gateway', 'none') === 'zarinpal' && self::merchant() !== '' && function_exists('curl_init');
    }

    public static function label(): string
    {
        return 'زرین‌پال';
    }

    private static function merchant(): string
    {
        return trim((string) Settings::get('shop_zarinpal_merchant', ''));
    }

    private static function sandbox(): bool
    {
        return Settings::bool('shop_zarinpal_sandbox', false);
    }

    private static function base(): string
    {
        return self::sandbox() ? 'https://sandbox.zarinpal.com' : 'https://payment.zarinpal.com';
    }

    /** The public address the gateway sends the student back to. */
    public static function callbackUrl(string $orderUuid): string
    {
        $base = rtrim((string) Config::get('app.app.url', ''), '/');
        return $base . '/shop/pay/callback?order=' . rawurlencode($orderUuid);
    }

    /**
     * @return array{ok:bool, redirect?:string, authority?:string, message:string}
     */
    public static function request(array $order, string $description): array
    {
        if (!self::enabled()) {
            return ['ok' => false, 'message' => 'درگاه پرداخت آنلاین فعال نیست.'];
        }
        $meta = array_filter([
            'mobile' => (string) ($order['user']['mobile'] ?? ''),
            'email'  => (string) ($order['user']['email'] ?? ''),
            'order_id' => (string) $order['number'],
        ]);
        $res = self::post('/pg/v4/payment/request.json', [
            'merchant_id'  => self::merchant(),
            'amount'       => (int) $order['total'],
            'currency'     => 'IRT',
            'description'  => mb_substr($description, 0, 250),
            'callback_url' => self::callbackUrl((string) $order['uuid']),
            'metadata'     => $meta,
        ]);
        $data = $res['data'] ?? [];
        if ((int) ($data['code'] ?? 0) === 100 && !empty($data['authority'])) {
            return [
                'ok'        => true,
                'authority' => (string) $data['authority'],
                'redirect'  => self::base() . '/pg/StartPay/' . rawurlencode((string) $data['authority']),
                'message'   => '',
            ];
        }
        return ['ok' => false, 'message' => self::error($res)];
    }

    /**
     * @return array{ok:bool, ref?:string, card?:string, message:string}
     */
    public static function verify(array $order, string $authority): array
    {
        $res = self::post('/pg/v4/payment/verify.json', [
            'merchant_id' => self::merchant(),
            'amount'      => (int) $order['total'],
            'currency'    => 'IRT',
            'authority'   => $authority,
        ]);
        $data = $res['data'] ?? [];
        $code = (int) ($data['code'] ?? 0);
        // 101: already verified — the student refreshed the callback page.
        if ($code === 100 || $code === 101) {
            return ['ok' => true, 'ref' => (string) ($data['ref_id'] ?? ''), 'card' => (string) ($data['card_pan'] ?? ''), 'message' => ''];
        }
        return ['ok' => false, 'message' => self::error($res)];
    }

    private static function error(array $res): string
    {
        $code = (int) ($res['errors']['code'] ?? $res['data']['code'] ?? 0);
        return match ($code) {
            -9     => 'اطلاعات ارسالی به درگاه نامعتبر است.',
            -10, -11 => 'کد پذیرنده درگاه نامعتبر یا غیرفعال است.',
            -50, -51 => 'پرداخت ناموفق بود یا مبلغ آن با سفارش یکی نیست.',
            -54    => 'این پرداخت نامعتبر است.',
            0      => (string) ($res['_transport'] ?? 'پاسخی از درگاه دریافت نشد.'),
            default => 'درگاه خطای ' . $code . ' برگرداند.',
        };
    }

    private static function post(string $path, array $payload): array
    {
        $json = (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
        $h = curl_init(self::base() . $path);
        curl_setopt_array($h, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json', 'Content-Length: ' . strlen($json)],
        ]);
        $body = curl_exec($h);
        $error = curl_error($h);
        curl_close($h);
        if ($body === false) {
            Logger::error('Payment gateway transport failure', ['error' => $error]);
            return ['_transport' => 'ارتباط با درگاه پرداخت برقرار نشد.'];
        }
        $decoded = json_decode((string) $body, true);
        return is_array($decoded) ? $decoded : ['_transport' => 'درگاه پاسخ نامعتبر داد.'];
    }
}
