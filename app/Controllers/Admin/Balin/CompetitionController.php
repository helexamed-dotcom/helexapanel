<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin\Balin;

use HeleXa\Core\Controller;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Core\Str;
use HeleXa\Models\Balin\BalinCompetitionRepository;
use HeleXa\Models\Balin\BalinLeaderboardRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;
use HeleXa\Services\Balin\CompetitionService;
use HeleXa\Services\Balin\LeaderboardService;

/**
 * Weekly competitions and the rewards attached to them.
 *
 * Only one competition may be active at a time. Two overlapping active
 * windows would leave the ledger unable to say which one a piece of XP
 * belonged to, so the second is refused with an explanation.
 */
final class CompetitionController extends Controller
{
    public function __construct(
        private readonly BalinCompetitionRepository $competitions = new BalinCompetitionRepository(),
    ) {
    }

    public function index(Request $request, array $params = []): Response
    {
        return $this->page('layouts.app', 'admin.balin.competitions.index', [
            'title'        => 'رقابت هفتگی',
            'competitions' => $this->competitions->all(),
            'current'      => $this->competitions->current(),
        ]);
    }

    public function store(Request $request, array $params = []): Response
    {
        $data  = $this->collect($request);
        $error = $this->validate($data, null);

        if ($error !== null) {
            $this->flash('error', $error);
            return $this->redirect('/admin/balin/competitions');
        }

        $id = $this->competitions->create($data + [
            'uuid'       => Str::uuid4(),
            'created_by' => Auth::id(),
        ]);

        ActivityLogger::log('balin.competition.created', Auth::id(), 'balin_competition', $id,
            ['title' => $data['title']], 'notice', $request);
        $this->flash('success', 'رقابت ساخته شد.');

        return $this->redirect('/admin/balin/competitions/' . $this->competitions->findById($id)['uuid']);
    }

    public function show(Request $request, array $params = []): Response
    {
        $competition = $this->find((string) ($params['uuid'] ?? ''));

        $boards = new BalinLeaderboardRepository();

        return $this->page('layouts.app', 'admin.balin.competitions.show', [
            'title'       => $competition['title'],
            'competition' => $competition,
            'rewards'     => $this->competitions->rewards((int) $competition['id']),
            'standings'   => $boards->page('weekly_xp', null, 25, 0),
        ]);
    }

    public function update(Request $request, array $params = []): Response
    {
        $competition = $this->find((string) ($params['uuid'] ?? ''));
        $data        = $this->collect($request);
        $error       = $this->validate($data, (int) $competition['id']);

        if ($error !== null) {
            $this->flash('error', $error);
            return $this->redirect('/admin/balin/competitions/' . $competition['uuid']);
        }

        $this->competitions->update((int) $competition['id'], $data);
        $this->flash('success', 'رقابت ذخیره شد.');

        return $this->redirect('/admin/balin/competitions/' . $competition['uuid']);
    }

    public function setStatus(Request $request, array $params = []): Response
    {
        $competition = $this->find((string) ($params['uuid'] ?? ''));
        $status      = $request->string('status');

        if (!in_array($status, ['scheduled', 'active', 'ended', 'cancelled'], true)) {
            throw HttpException::notFound();
        }

        // Activating is the one transition that can collide.
        if ($status === 'active') {
            $conflict = $this->competitions->activeConflict(
                (string) $competition['start_date'],
                (string) $competition['end_date'],
                (int) $competition['id']
            );

            if ($conflict !== null) {
                $this->flash('error', 'رقابت «' . $conflict['title'] . '» در همین بازه فعال است. '
                                    . 'هم‌زمان بیش از یک رقابت فعال مجاز نیست.');
                return $this->redirect('/admin/balin/competitions/' . $competition['uuid']);
            }
        }

        $this->competitions->setStatus((int) $competition['id'], $status);
        CompetitionService::flush();

        // The weekly board is derived from which competition is running, so
        // it is rebuilt straight away rather than left stale until the next
        // scheduled pass.
        (new LeaderboardService())->rebuildAll();

        ActivityLogger::log('balin.competition.status', Auth::id(), 'balin_competition', (int) $competition['id'],
            ['status' => $status], 'notice', $request);
        $this->flash('success', 'وضعیت رقابت تغییر کرد.');

        return $this->redirect('/admin/balin/competitions/' . $competition['uuid']);
    }

    public function destroy(Request $request, array $params = []): Response
    {
        $competition = $this->find((string) ($params['uuid'] ?? ''));

        $this->competitions->delete((int) $competition['id']);
        CompetitionService::flush();

        ActivityLogger::log('balin.competition.deleted', Auth::id(), 'balin_competition',
            (int) $competition['id'], [], 'warning', $request);
        $this->flash('success', 'رقابت حذف شد.');

        return $this->redirect('/admin/balin/competitions');
    }

    // ------------------------------------------------------------- rewards

    public function storeReward(Request $request, array $params = []): Response
    {
        $competition = $this->find((string) ($params['uuid'] ?? ''));
        $title       = trim($request->string('title'));

        if ($title === '') {
            $this->flash('error', 'عنوان جایزه الزامی است.');
            return $this->redirect('/admin/balin/competitions/' . $competition['uuid']);
        }

        $this->competitions->createReward([
            'uuid'           => Str::uuid4(),
            'competition_id' => (int) $competition['id'],
            'title'          => $title,
            'description'    => trim($request->string('description')) ?: null,
            'rank_position'  => $request->int('rank_position') ?: null,
            'admin_note'     => trim($request->string('admin_note')) ?: null,
        ]);

        $this->flash('success', 'جایزه اضافه شد.');

        return $this->redirect('/admin/balin/competitions/' . $competition['uuid']);
    }

    public function assignReward(Request $request, array $params = []): Response
    {
        $competition = $this->find((string) ($params['uuid'] ?? ''));
        $status      = $request->string('status', 'assigned');

        if (!in_array($status, ['draft', 'assigned', 'delivered', 'cancelled'], true)) {
            $status = 'assigned';
        }

        $this->competitions->assignReward(
            $request->int('reward_id'),
            $request->int('winner_user_id') ?: null,
            $status,
            trim($request->string('admin_note')) ?: null
        );

        ActivityLogger::log('balin.reward.assigned', Auth::id(), 'balin_reward', $request->int('reward_id'),
            ['winner' => $request->int('winner_user_id'), 'status' => $status], 'notice', $request);
        $this->flash('success', 'جایزه به‌روزرسانی شد.');

        return $this->redirect('/admin/balin/competitions/' . $competition['uuid']);
    }

    public function destroyReward(Request $request, array $params = []): Response
    {
        $competition = $this->find((string) ($params['uuid'] ?? ''));

        $this->competitions->deleteReward($request->int('reward_id'));
        $this->flash('success', 'جایزه حذف شد.');

        return $this->redirect('/admin/balin/competitions/' . $competition['uuid']);
    }

    // ------------------------------------------------------------- helpers

    private function find(string $uuid): array
    {
        $competition = $this->competitions->findByUuid($uuid);

        if ($competition === null) {
            throw HttpException::notFound();
        }

        return $competition;
    }

    private function collect(Request $request): array
    {
        $visibility = $request->string('leaderboard_visibility', 'public');

        return [
            'title'       => trim($request->string('title')),
            'description' => trim($request->string('description')) ?: null,
            'start_date'  => $this->dateTime($request->string('start_date')),
            'end_date'    => $this->dateTime($request->string('end_date')),
            'status'      => 'scheduled',
            'leaderboard_visibility' => in_array($visibility, ['public', 'participants', 'admins'], true)
                                            ? $visibility
                                            : 'public',
        ];
    }

    private function validate(array $data, ?int $exceptId): ?string
    {
        if ($data['title'] === '') {
            return 'عنوان رقابت الزامی است.';
        }
        if ($data['start_date'] === null || $data['end_date'] === null) {
            return 'تاریخ شروع و پایان الزامی است.';
        }
        if (strtotime($data['start_date']) >= strtotime($data['end_date'])) {
            return 'تاریخ پایان باید بعد از تاریخ شروع باشد.';
        }

        return null;
    }

    private function dateTime(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }
}
