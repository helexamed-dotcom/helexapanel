<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin;

use HeleXa\Core\Config;
use HeleXa\Core\Controller;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\SettingRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;
use HeleXa\Services\Crypto;
use HeleXa\Services\Phone;
use HeleXa\Services\Settings;
use HeleXa\Services\SmsGateway;
use HeleXa\Services\SmsSettings;

/**
 * The SMS gateway and the one-time-code policy, as panel settings.
 *
 * Reachable only with manage_settings, enforced by the route table rather
 * than by anything on this page — hiding the menu entry would not stop a
 * direct request.
 *
 * The gateway password never leaves the server. The form renders it masked,
 * and submitting the mask unchanged is understood as "leave it alone" rather
 * than as a new value, so an admin editing the sender number does not have to
 * retype a credential they may not have to hand.
 */
final class SmsController extends Controller
{
    /** key => [min, max, default] — each clamped so a typo cannot disable a protection. */
    private const OTP_NUMERIC = [
        'otp_ttl_seconds'         => [30, 900, 120],
        'otp_resend_seconds'      => [15, 600, 60],
        'otp_max_attempts'        => [1, 10, 5],
        'otp_max_per_hour'        => [1, 50, 5],
        'otp_max_per_ip_per_hour' => [1, 200, 20],
    ];

    public function index(Request $request, array $params = []): Response
    {
        return $this->page('layouts.app', 'admin.sms', $this->viewData());
    }

    private function viewData(array $extra = []): array
    {
        $otp = [];
        foreach (self::OTP_NUMERIC as $key => [, , $default]) {
            $otp[$key] = Settings::int($key, $default);
        }

        return array_merge([
            'title' => 'تنظیمات پیامک',
            'sms'   => [
                'enabled'  => SmsSettings::enabled(),
                'provider' => SmsSettings::provider(),
                'mints'    => SmsSettings::providerMintsCode(),
                'username' => SmsSettings::username(),
                'from'     => SmsSettings::from(),
                // Masked, always. The real key is never put into a template.
                'api_key_masked' => SmsSettings::maskedApiKey(),
                'has_api_key'    => SmsSettings::apiKey() !== '',
                'configured'     => SmsSettings::isConfigured(),
                'operational'    => SmsSettings::isOperational(),
            ],
            'otp' => array_merge($otp, [
                'enabled'              => Settings::bool('otp_enabled', true),
                'registration_enabled' => Settings::bool('otp_registration_enabled', true),
                'template'             => (string) Settings::get('otp_message_template', 'کد ورود شما: {code}'),
            ]),
            'ranges'          => self::OTP_NUMERIC,
            'cryptoAvailable' => Crypto::isAvailable(),
        ], $extra);
    }

    /* ------------------------------------------------------------- saving */

    public function update(Request $request, array $params = []): Response
    {
        $username  = trim($request->string('sms_username'));
        $from      = trim($request->string('sms_from'));
        $submitted = trim((string) $request->input('sms_api_key', ''));

        /**
         * The key field is rendered empty, never pre-filled — a form that
         * shows a credential is a form that leaks one into browser history,
         * autofill and any screenshot of the page.
         *
         * So an empty box means "leave the stored key alone", which is what
         * an admin editing the sender number is doing. Removing a key is a
         * deliberate, separate act, because "I did not retype it" and "I want
         * it gone" must not be the same gesture.
         */
        $apiKey = $submitted !== '' ? $submitted : null;
        if ($request->bool('sms_api_key_clear')) {
            $apiKey = '';
        }

        if ($apiKey !== null && !Crypto::isAvailable()) {
            $this->flash('error', 'رمزنگاری روی این سرور در دسترس نیست؛ کلید API ذخیره نشد.');
            return $this->redirect('/admin/sms');
        }

        SmsSettings::setProvider($request->string('sms_provider'), Auth::id());
        SmsSettings::saveCredentials($username, $apiKey, $from, Auth::id());
        SmsSettings::setEnabled($request->bool('sms_enabled'), Auth::id());

        $this->saveOtpSettings($request);

        Settings::flush();

        // The key itself is never logged, only the fact that it changed.
        ActivityLogger::log('settings.sms_updated', Auth::id(), 'settings', null, [
            'provider'     => SmsSettings::provider(),
            'username_set' => $username !== '',
            'from_set'     => $from !== '',
            'api_key'      => $apiKey === null ? 'unchanged' : ($apiKey === '' ? 'cleared' : 'replaced'),
            'sms_enabled'  => $request->bool('sms_enabled'),
        ], 'critical', $request);

        $this->flash('success', 'تنظیمات پیامک ذخیره شد.');

        return $this->redirect('/admin/sms');
    }

    private function saveOtpSettings(Request $request): void
    {
        $repository = new SettingRepository();

        foreach (self::OTP_NUMERIC as $key => [$min, $max, $default]) {
            $value = max($min, min($max, $request->int($key, $default)));
            $repository->set($key, (string) $value, 'int', Auth::id());
        }

        $repository->set('otp_enabled', $request->bool('otp_enabled') ? '1' : '0', 'bool', Auth::id());
        $repository->set('otp_registration_enabled',
            $request->bool('otp_registration_enabled') ? '1' : '0', 'bool', Auth::id());

        // A template with no {code} would send a text with no code in it, so
        // the placeholder is restored rather than the value rejected.
        $template = trim($request->string('otp_message_template'));
        if ($template === '') {
            $template = 'کد ورود شما: {code}';
        }
        if (!str_contains($template, '{code}')) {
            $template .= ' {code}';
        }
        $repository->set('otp_message_template', mb_substr($template, 0, 400), 'string', Auth::id());
    }

    /* ------------------------------------------------------------ testing */

    /**
     * Sends one real text to a number the admin types.
     *
     * The reply says whether it worked and, when the gateway named a reason
     * the operator can act on (bad credentials, no credit), which one — but
     * never the credential, the endpoint or a raw error body.
     */
    public function test(Request $request, array $params = []): Response
    {
        $phone = Phone::normalize($request->string('test_phone'));

        if ($phone === '') {
            $this->flash('error', 'شماره موبایل آزمایشی معتبر نیست.');
            return $this->redirect('/admin/sms');
        }

        if (!SmsSettings::isConfigured()) {
            $this->flash('error', 'ابتدا نام کاربری، کلید API و شماره فرستنده را ذخیره کن.');
            return $this->redirect('/admin/sms');
        }

        // Deliberately sent even while the switch is off, so an operator can
        // prove the credentials work before turning SMS on for students.
        $wasEnabled = SmsSettings::enabled();
        if (!$wasEnabled) {
            SmsSettings::setEnabled(true, Auth::id());
        }

        try {
            // The test uses whichever provider is configured, because a test
            // that exercised the other one would prove nothing about the path
            // students will actually take.
            $result = SmsSettings::providerMintsCode()
                ? SmsGateway::sendConsoleOtp($phone)
                : SmsGateway::send(
                    $phone,
                    'پیامک آزمایشی ' . (string) Config::get('app.app.name', 'HeleXa Med')
                );
        } finally {
            if (!$wasEnabled) {
                SmsSettings::setEnabled(false, Auth::id());
            }
        }

        ActivityLogger::log('settings.sms_test', Auth::id(), 'settings', null, [
            'provider' => SmsSettings::provider(),
            'phone'    => Phone::mask($phone),
            'ok'       => $result['ok'],
            'reason'   => $result['ok'] ? null : $result['code'],
        ], 'notice', $request);

        if ($result['ok']) {
            $this->flash('success', 'پیامک آزمایشی به ' . Phone::mask($phone) . ' ارسال شد.');
            return $this->redirect('/admin/sms');
        }

        /**
         * When the gateway answered with something this panel could not read,
         * the reply itself is the only thing that explains why — so it is
         * shown, once, to the administrator who just pressed the button.
         *
         * It is never logged and never shown to a student: on the one-time-code
         * provider a successful body contains a live code, and a body that
         * failed to parse might still contain one.
         */
        $detail = $result['raw'] ?? null;
        $this->flash('error', 'ارسال آزمایشی ناموفق بود: ' . $result['message']
            . ($detail !== null ? ' — پاسخ خام سامانه: ' . $detail : ''));

        return $this->redirect('/admin/sms');
    }
}
