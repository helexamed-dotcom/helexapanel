<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin\Balin;

use HeleXa\Core\Controller;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\Balin\BalinAccessRepository;
use HeleXa\Models\Balin\BalinStatsRepository;
use HeleXa\Models\Balin\BalinStatusLogRepository;
use HeleXa\Models\SettingRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;
use HeleXa\Services\Balin\BalinSettings;
use HeleXa\Services\Balin\LeaderboardService;
use HeleXa\Services\Settings;

/**
 * The island's control room: what state it is published in, and the numbers
 * that say whether it is working.
 *
 * Changing the publication state is the single most consequential action in
 * this whole module — it is what puts content in front of students — so it
 * is logged twice: to the general activity log and to balin_status_log,
 * which exists to answer "who published this, and when".
 */
final class DashboardController extends Controller
{
    public function __construct(
        private readonly BalinStatsRepository $stats = new BalinStatsRepository(),
        private readonly BalinStatusLogRepository $statusLog = new BalinStatusLogRepository(),
        private readonly SettingRepository $settings = new SettingRepository(),
    ) {
    }

    public function index(Request $request, array $params = []): Response
    {
        return $this->page('layouts.app', 'admin.balin.dashboard', [
            'title'      => 'جزیره بالین',
            'status'     => BalinSettings::status(),
            'totals'     => $this->stats->platformTotals(),
            'accessible' => (new BalinAccessRepository())->countEnabled(),
            'log'        => $this->statusLog->recent(12),
            'settings'   => [
                'coming_soon_text' => BalinSettings::comingSoonText(),
                'maintenance_text' => BalinSettings::maintenanceText(),
                'maintenance_eta'  => BalinSettings::maintenanceEta(),
                'timezone'         => BalinSettings::timezone()->getName(),
                'xp_per_correct'   => BalinSettings::xpPerCorrect(),
                'hint_penalty'     => BalinSettings::hintPenaltyPercent(),
                'rank_overflow'    => BalinSettings::rankOverflowMode(),
                'no_competition'   => BalinSettings::noCompetitionMode(),
                'weights'          => BalinSettings::difficultyWeights(),
            ],
        ]);
    }

    /**
     * Moves the island between coming_soon, published, maintenance and
     * disabled. Refuses anything else outright rather than storing a state
     * the access gate would not recognise.
     */
    public function setStatus(Request $request, array $params = []): Response
    {
        $to   = $request->string('status');
        $from = BalinSettings::status();

        if (!in_array($to, BalinSettings::STATUSES, true)) {
            $this->flash('error', 'وضعیت انتخابی معتبر نیست.');
            return $this->redirect('/admin/balin');
        }

        if ($to === $from) {
            return $this->redirect('/admin/balin');
        }

        $this->settings->set('balin_status', $to, 'string');
        Settings::flush();

        $note = trim($request->string('note'));
        $this->statusLog->record(Auth::id(), $from, $to, $note === '' ? null : $note);

        ActivityLogger::log('balin.status.changed', Auth::id(), 'balin', null,
            ['from' => $from, 'to' => $to, 'note' => $note], 'notice', $request);

        $this->flash('success', 'وضعیت جزیره بالین به «' . $this->label($to) . '» تغییر کرد.');

        return $this->redirect('/admin/balin');
    }

    /** The tunables: XP rates, mastery weights, timezone, fallback rules. */
    public function saveSettings(Request $request, array $params = []): Response
    {
        $strings = [
            'balin_coming_soon_text' => $request->string('coming_soon_text'),
            'balin_maintenance_text' => $request->string('maintenance_text'),
            'balin_maintenance_eta'  => $request->string('maintenance_eta'),
        ];

        foreach ($strings as $key => $value) {
            $this->settings->set($key, trim($value), 'string');
        }

        // A bad timezone would silently move every day boundary in the
        // module, so it is validated before it is stored.
        $timezone = $request->string('timezone', 'Asia/Tehran');
        if (in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            $this->settings->set('balin_timezone', $timezone, 'string');
        }

        $this->settings->set('balin_xp_per_correct', (string) max(0, $request->int('xp_per_correct', 10)), 'int');
        $this->settings->set(
            'balin_hint_penalty_percent',
            (string) max(0, min(100, $request->int('hint_penalty', 50))),
            'int'
        );

        foreach (['easy' => 1, 'medium' => 2, 'hard' => 3, 'expert' => 4] as $level => $default) {
            $this->settings->set(
                'balin_weight_' . $level,
                (string) max(1, $request->int('weight_' . $level, $default)),
                'int'
            );
        }

        $overflow = $request->string('rank_overflow', 'repeat_last');
        if (in_array($overflow, ['repeat_last', 'admin_defined'], true)) {
            $this->settings->set('balin_rank_overflow_mode', $overflow, 'string');
        }

        $noCompetition = $request->string('no_competition', 'empty');
        if (in_array($noCompetition, ['empty', 'last_ended'], true)) {
            $this->settings->set('balin_no_competition_mode', $noCompetition, 'string');
        }

        Settings::flush();

        ActivityLogger::log('balin.settings.updated', Auth::id(), 'balin', null, [], 'notice', $request);
        $this->flash('success', 'تنظیمات ذخیره شد.');

        return $this->redirect('/admin/balin');
    }

    /** Rebuilds the leaderboards on demand. */
    public function rebuildBoards(Request $request, array $params = []): Response
    {
        $written = (new LeaderboardService())->rebuildAll();

        ActivityLogger::log('balin.leaderboard.rebuilt', Auth::id(), 'balin', null,
            ['boards' => count($written)], 'info', $request);

        $this->flash('success', 'جدول‌های رتبه‌بندی بازسازی شدند.');

        return $this->redirect('/admin/balin');
    }

    private function label(string $status): string
    {
        return match ($status) {
            BalinSettings::STATUS_PUBLISHED   => 'منتشرشده',
            BalinSettings::STATUS_MAINTENANCE => 'در حال به‌روزرسانی',
            BalinSettings::STATUS_DISABLED    => 'غیرفعال',
            default                           => 'به‌زودی',
        };
    }
}
