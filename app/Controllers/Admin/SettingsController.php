<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin;

use HeleXa\Core\Controller;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\SettingRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;
use HeleXa\Services\Settings;

/**
 * Security settings the operator may tune at runtime.
 * Every numeric value is clamped to a safe range: a timeout of 0 or an
 * attempt limit of 9999 would quietly disable a protection.
 */
final class SettingsController extends Controller
{
    private const NUMERIC = [
        // key                        => [min, max, default]
        'session_idle_timeout'        => [300, 86400, 1800],
        'session_absolute_timeout'    => [900, 604800, 7200],
        'admin_idle_timeout'          => [300, 86400, 900],
        'login_max_attempts'          => [3, 20, 5],
        'login_lockout_seconds'       => [60, 86400, 900],
        'viewer_token_ttl'            => [30, 600, 90],
        'heartbeat_interval'          => [10, 120, 25],
        'content_max_upload_mb'       => [1, 200, 25],
    ];

    private const BOOLEAN = [
        'single_device_enabled',
        'single_device_admins',
        'watermark_enabled',
        'print_protection_enabled',
        'viewer_local_font',
        'viewer_allow_external_fonts',
    ];

    public function index(Request $request, array $params = []): Response
    {
        $values = [];
        foreach (array_keys(self::NUMERIC) as $key) {
            $values[$key] = Settings::int($key, self::NUMERIC[$key][2]);
        }
        foreach (self::BOOLEAN as $key) {
            $values[$key] = Settings::bool($key, false);
        }
        $values['single_device_behavior'] = (string) Settings::get('single_device_behavior', 'block_new');
        $values['content_cache_mode']      = (string) Settings::get('content_cache_mode', 'revalidate');

        return $this->page('layouts.app', 'admin.settings', [
            'title'   => 'تنظیمات امنیتی',
            'values'  => $values,
            'ranges'  => self::NUMERIC,
            'brand'   => [
                'logo'          => (string) Settings::get('site_logo_path', ''),
                'avatar_male'   => (string) Settings::get('avatar_male_path', ''),
                'avatar_female' => (string) Settings::get('avatar_female_path', ''),
            ],
            'libraryImages' => \HeleXa\Services\ImageAssetStorage::listExisting(),
        ]);
    }

    public function update(Request $request, array $params = []): Response
    {
        $repository = new SettingRepository();
        $changed    = [];

        foreach (self::NUMERIC as $key => [$min, $max, $default]) {
            $value = $request->int($key, $default);
            $value = max($min, min($max, $value));
            $repository->set($key, (string) $value, 'int', Auth::id());
            $changed[$key] = $value;
        }

        foreach (self::BOOLEAN as $key) {
            $value = $request->bool($key) ? '1' : '0';
            $repository->set($key, $value, 'bool', Auth::id());
            $changed[$key] = $value;
        }

        $behavior = $request->string('single_device_behavior', 'block_new');
        if (!in_array($behavior, ['block_new', 'force_logout_previous'], true)) {
            $behavior = 'block_new';
        }
        $repository->set('single_device_behavior', $behavior, 'string', Auth::id());
        $changed['single_device_behavior'] = $behavior;

        $cacheMode = $request->string('content_cache_mode', 'revalidate');
        if (!in_array($cacheMode, ['revalidate', 'strict'], true)) {
            $cacheMode = 'revalidate';
        }
        $repository->set('content_cache_mode', $cacheMode, 'string', Auth::id());
        $changed['content_cache_mode'] = $cacheMode;

        Settings::flush();
        ActivityLogger::log('settings.updated', Auth::id(), 'settings', null, $changed, 'critical', $request);
        $this->flash('success', 'تنظیمات ذخیره شد.');

        return $this->redirect('/admin/settings');
    }

    /* --------------------------------------------------- brand & images */

    private const BRAND_SETTINGS = [
        'logo'          => 'site_logo_path',
        'avatar_male'   => 'avatar_male_path',
        'avatar_female' => 'avatar_female_path',
    ];

    /**
     * One handler for all three brand slots (logo, male/female default
     * avatar). Each accepts either a fresh upload or a pick from a file
     * already sitting in assets/images — including ones an admin dropped in
     * over FTP without ever touching this form.
     */
    public function updateBrandImage(Request $request, array $params = []): Response
    {
        $slot = (string) ($params['slot'] ?? '');
        if (!isset(self::BRAND_SETTINGS[$slot])) {
            throw \HeleXa\Core\HttpException::notFound();
        }

        $settingKey = self::BRAND_SETTINGS[$slot];
        $repository = new SettingRepository();
        $storage    = new \HeleXa\Services\ImageAssetStorage();

        $file     = $request->file('image');
        $existing = $request->string('existing_path');

        try {
            if ($file !== null) {
                $path = \HeleXa\Services\ImageAssetStorage::storeUploaded($file);
            } elseif ($existing !== '') {
                // Only a path this same picker already offered is accepted;
                // never trust a path invented by the client.
                $offered = array_column(\HeleXa\Services\ImageAssetStorage::listExisting(), 'path');
                if (!in_array($existing, $offered, true)) {
                    $this->flash('error', 'فایل انتخاب‌شده معتبر نیست.');
                    return $this->redirect('/admin/settings');
                }
                $path = $existing;
            } else {
                $this->flash('error', 'فایلی برای ذخیره انتخاب نشده است.');
                return $this->redirect('/admin/settings');
            }
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());
            return $this->redirect('/admin/settings');
        }

        $repository->set($settingKey, $path, 'string', Auth::id());
        Settings::flush();
        ActivityLogger::log('settings.brand_updated', Auth::id(), 'settings', null,
            ['slot' => $slot, 'path' => $path], 'notice', $request);
        $this->flash('success', 'تصویر به‌روزرسانی شد.');

        return $this->redirect('/admin/settings');
    }

    /** Clears a brand slot back to the built-in default; the file itself is left alone. */
    public function resetBrandImage(Request $request, array $params = []): Response
    {
        $slot = (string) ($params['slot'] ?? '');
        if (!isset(self::BRAND_SETTINGS[$slot])) {
            throw \HeleXa\Core\HttpException::notFound();
        }

        (new SettingRepository())->set(self::BRAND_SETTINGS[$slot], '', 'string', Auth::id());
        Settings::flush();
        $this->flash('success', 'به تصویر پیش‌فرض بازگشت.');

        return $this->redirect('/admin/settings');
    }
}
