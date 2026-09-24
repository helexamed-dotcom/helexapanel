<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin;

use HeleXa\Core\Controller;
use HeleXa\Core\Database;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;
use HeleXa\Services\Countdowns;
use HeleXa\Services\Modules;
use HeleXa\Services\Settings;
use HeleXa\Services\StudentTypes;

/**
 * «صفحه اصلی دانشجو»: what every student's home and menu show.
 *
 *   روزشمار      — the countdowns on the dashboard (title, date, colour, for whom)
 *   بخش‌های سایت — sections switched off for everyone, and what a student
 *                  without an approved type sees.
 */
final class HomeScreenController extends Controller
{
    public function index(Request $request, array $params = []): Response
    {
        return $this->page('layouts.app', 'admin.home_screen', [
            'title'      => 'صفحه اصلی دانشجو',
            'countdowns' => Countdowns::all(),
            'colors'     => Countdowns::COLORS,
            'types'      => StudentTypes::all(),
            'catalog'    => Modules::CATALOG,
            'off'        => (array) Settings::get('modules_off', []),
            'defaults'   => (array) Settings::get('modules_default', []),
            'goal'       => Settings::int('daily_goal_minutes', 60),
        ]);
    }

    public function addCountdown(Request $request, array $params = []): Response
    {
        $types = $request->input('types', []);
        $result = Countdowns::add(
            $request->string('title'),
            $request->string('subtitle'),
            $request->string('date'),
            $request->string('time'),
            $request->string('color'),
            is_array($types) ? $types : []
        );
        if ($result['ok']) {
            ActivityLogger::log('countdown.added', Auth::id(), 'setting', null, ['title' => $request->string('title')], 'info', $request);
        }
        $this->flash($result['ok'] ? 'success' : 'error', $result['message']);

        return $this->redirect('/admin/home-screen');
    }

    public function removeCountdown(Request $request, array $params = []): Response
    {
        Countdowns::remove((string) ($params['id'] ?? ''));
        $this->flash('success', 'روزشمار حذف شد.');

        return $this->redirect('/admin/home-screen');
    }

    public function saveSections(Request $request, array $params = []): Response
    {
        $known = array_keys(Modules::CATALOG);
        $on    = array_values(array_intersect($known, array_map('strval', (array) $request->input('on', []))));
        $def   = array_values(array_intersect($known, array_map('strval', (array) $request->input('default', []))));

        $this->put('modules_off', array_values(array_diff($known, $on)), 'json');
        // Every section ticked means "no limit", which is what an empty list says.
        $this->put('modules_default', count($def) === count($known) ? [] : $def, 'json');
        $this->put('daily_goal_minutes', (string) max(10, min(600, $request->int('goal', 60))), 'int');
        $this->put('student_type_prompt', $request->bool('type_prompt') ? '1' : '0', 'bool');

        ActivityLogger::log('modules.saved', Auth::id(), 'setting', null, ['on' => $on], 'info', $request);
        $this->flash('success', 'بخش‌ها ذخیره شد.');

        return $this->redirect('/admin/home-screen#sections');
    }

    private function put(string $key, mixed $value, string $type): void
    {
        Database::execute(
            'INSERT INTO settings (setting_key, setting_value, value_type, is_public, updated_by, updated_at)
             VALUES (:k, :v, :t, 0, :u, NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), value_type = VALUES(value_type),
                                     updated_by = VALUES(updated_by), updated_at = NOW()',
            ['k' => $key, 'v' => is_array($value) ? json_encode($value) : (string) $value, 't' => $type, 'u' => Auth::id()]
        );
        Settings::flush();
    }
}
