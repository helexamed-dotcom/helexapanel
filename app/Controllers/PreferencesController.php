<?php
declare(strict_types=1);

namespace HeleXa\Controllers;

use HeleXa\Core\Controller;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Services\Auth;
use HeleXa\Services\I18n;
use HeleXa\Services\Preferences;

/**
 * Appearance and language, for students and admins alike.
 */
final class PreferencesController extends Controller
{
    public function show(Request $request, array $params = []): Response
    {
        return $this->page('layouts.app', 'account.settings', [
            'title'   => t('ظاهر و زبان'),
            'prefs'   => Preferences::current(),
            'accents' => Preferences::ACCENTS,
            'modes'   => Preferences::MODES,
            'langs'   => I18n::LANGS,
        ]);
    }

    public function save(Request $request, array $params = []): Response
    {
        Preferences::save((int) Auth::id(), [
            'accent' => $request->string('accent'),
            'mode'   => $request->string('mode'),
            'lang'   => $request->string('lang'),
        ]);
        $this->flash('success', t('تنظیمات ذخیره شد.'));

        return $this->redirect('/account/settings');
    }

    /** The header's light/dark button remembers the choice on the account too. */
    public function mode(Request $request, array $params = []): Response
    {
        $mode = $request->string('mode');
        if (!in_array($mode, ['light', 'dark', 'system'], true)) {
            return $this->json(['ok' => false], 422);
        }
        Preferences::save((int) Auth::id(), ['mode' => $mode]);

        return $this->json(['ok' => true]);
    }
}