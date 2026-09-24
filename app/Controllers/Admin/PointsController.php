<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin;

use HeleXa\Core\Controller;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\ProfileRepository;
use HeleXa\Models\SettingRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;
use HeleXa\Services\Points;
use HeleXa\Services\Settings;

/**
 * «امتیاز، لیگ و پست‌ها»: what each action pays, where each league starts,
 * this week's table, and the students' posts for moderation.
 */
final class PointsController extends Controller
{
    /** action => [label, default, icon, tone] — what Points::amount() reads. */
    public const ACTIONS = [
        'lesson_read'      => ['خواندن کامل یک درسنامه', 15, 'lesson', 'indigo'],
        'exam_finished'    => ['تمام کردن یک آزمون شخصی', 20, 'exam', 'amber'],
        'flashcard_review' => ['مرور هر فلش‌کارت (روزی یک بار)', 2, 'cards', 'pink'],
        'figure_correct'   => ['پاسخ درست در بازی با شکل', 5, 'figure', 'teal'],
        'mindmap_done'     => ['مرور کامل یک نقشه ذهنی', 10, 'mindmap', 'violet'],
    ];

    public function index(Request $request, array $params = []): Response
    {
        $amounts = [];
        foreach (self::ACTIONS as $k => [, $default]) {
            $amounts[$k] = Points::amount($k, $default);
        }
        $leagues = [];
        foreach (Points::LEAGUES as $k => [$title, $min, $color, $emoji]) {
            $leagues[$k] = ['title' => $title, 'min' => max(0, Settings::int('league_' . $k, $min)), 'color' => $color, 'emoji' => $emoji];
        }
        return $this->page('layouts.app', 'admin.points', [
            'title'    => 'امتیاز، لیگ و پست‌ها',
            'actions'  => self::ACTIONS,
            'amounts'  => $amounts,
            'leagues'  => $leagues,
            'board'    => array_slice(Points::weeklyBoard(10), 0, 10),
            'posts'    => ProfileRepository::ready() ? (new ProfileRepository())->recentAll(40) : [],
            'extraCss' => ['shop-admin'],
        ]);
    }

    public function save(Request $request, array $params = []): Response
    {
        $repo = new SettingRepository();
        $actor = (int) Auth::id();
        foreach (array_keys(self::ACTIONS) as $k) {
            $repo->set('points_' . $k, (string) max(0, min(500, $request->int('points_' . $k))), 'int', $actor);
        }
        $prev = -1;
        foreach (array_keys(Points::LEAGUES) as $i => $k) {
            $v = $i === 0 ? 0 : max(0, min(100000, $request->int('league_' . $k)));
            if ($v <= $prev) {
                $this->flash('error', 'آستانه هر لیگ باید از لیگ پایین‌تر بیشتر باشد.');
                return $this->redirect('/admin/points');
            }
            $prev = $v;
            $repo->set('league_' . $k, (string) $v, 'int', $actor);
        }
        Settings::flush();
        ActivityLogger::log('points.settings', $actor, 'settings', null, [], 'notice', $request);
        $this->flash('success', 'امتیازها و لیگ‌ها ذخیره شد.');
        return $this->redirect('/admin/points');
    }

    /** Hide a post from everyone but its author, or bring it back. */
    public function moderate(Request $request, array $params = []): Response
    {
        if (!ProfileRepository::ready()) {
            throw HttpException::notFound();
        }
        $repo = new ProfileRepository();
        $post = $repo->findPost((string) ($params['uuid'] ?? ''));
        if ($post === null) {
            throw HttpException::notFound();
        }
        $hide = $request->string('action') === 'hide';
        $repo->setHidden((int) $post['id'], $hide ? (int) Auth::id() : null);
        ActivityLogger::log($hide ? 'profile.post_hidden' : 'profile.post_restored', Auth::id(), 'profile_post', (int) $post['id'], [], 'notice', $request);
        $this->flash('success', $hide ? 'پست پنهان شد.' : 'پست دوباره نمایش داده می‌شود.');
        return $this->redirect('/admin/points#posts');
    }
}
