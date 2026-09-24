<?php
declare(strict_types=1);

namespace HeleXa\Controllers;

use HeleXa\Core\Controller;
use HeleXa\Core\Database;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\ProfileRepository;
use HeleXa\Services\Auth;
use HeleXa\Services\MediaStore;
use HeleXa\Services\Notify;
use HeleXa\Services\Points;

/**
 * The student profile, Instagram-like: the score (level, league, streak),
 * posts, followers — and the owner deciding who sees which part of it.
 */
final class ProfileController extends Controller
{
    private ProfileRepository $profiles;

    public function __construct()
    {
        $this->profiles = new ProfileRepository();
    }

    /* =========================================================== pages */

    /** /account/profile — the student's own page; admins keep the edit form. */
    public function me(Request $request, array $params = []): Response
    {
        if (!Auth::isStudent() || !ProfileRepository::ready()) {
            return (new AccountController())->showEdit($request, $params);
        }
        $me = (int) Auth::id();
        return $this->render($this->profiles->personById($me) ?? [], $me, 'self', $request);
    }

    /** /u/{handle-or-uuid} — someone else's page. */
    public function show(Request $request, array $params = []): Response
    {
        $this->ready();
        $person = $this->profiles->person((string) ($params['key'] ?? ''));
        if ($person === null || $person['role_slug'] !== 'student') {
            throw HttpException::notFound();
        }
        $viewer = (int) Auth::id();
        if ((int) $person['id'] === $viewer) {
            return $this->redirect('/account/profile');
        }
        $relation = !Auth::isStudent() ? 'admin' : ($this->profiles->followStatus($viewer, (int) $person['id']) ?? 'none');
        return $this->render($person, $viewer, $relation, $request);
    }

    private function render(array $person, int $viewer, string $relation, Request $request): Response
    {
        $ownerId  = (int) $person['id'];
        $settings = $this->profiles->settings($ownerId);
        $full     = in_array($relation, ['self', 'admin'], true);
        $locked   = !$full && $settings['is_private'] && $relation !== 'accepted';
        $can      = [];
        foreach ($settings['visibility'] as $k => $on) {
            $can[$k] = $full || (!$locked && $on);
        }
        $audiences = $full ? ['everyone', 'followers', 'me'] : ($locked ? [] : ($relation === 'accepted' ? ['everyone', 'followers'] : ['everyone']));
        if (!$can['posts']) {
            $audiences = [];
        }
        $summary = ($can['level'] || $can['league'] || $can['streak']) ? Points::summary($ownerId) : null;

        return $this->page('layouts.app', 'profile.show', [
            'title'        => $relation === 'self' ? 'پروفایل من' : (string) $person['full_name'],
            'person'       => $person,
            'settings'     => $settings,
            'relation'     => $relation,
            'locked'       => $locked,
            'can'          => $can,
            'counts'       => $this->profiles->counts($ownerId),
            'postCount'    => $this->profiles->postCount($ownerId),
            'posts'        => $this->profiles->posts($ownerId, $audiences, $viewer),
            'summary'      => $summary,
            'stats'        => $can['stats'] ? $this->profiles->studyStats($ownerId) : null,
            'badges'       => $can['badges'] ? $this->badges($ownerId) : [],
            'place'        => $can['league'] ? $this->weeklyPlace($ownerId) : null,
            'tab'          => in_array($request->string('tab'), ['posts', 'badges', 'stats'], true) ? $request->string('tab') : 'posts',
            'extraCss'     => ['profile'],
            'extraJs'      => ['profile'],
        ]);
    }

    /** /account/privacy */
    public function privacy(Request $request, array $params = []): Response
    {
        $this->ready();
        $me = (int) Auth::id();
        return $this->page('layouts.app', 'profile.privacy', [
            'title'    => 'حریم خصوصی و نمایش',
            'settings' => $this->profiles->settings($me),
            'sections' => ProfileRepository::SECTIONS,
            'tones'    => ProfileRepository::TONES,
            'requests' => $this->profiles->requests($me),
            'extraCss' => ['profile'],
        ]);
    }

    public function savePrivacy(Request $request, array $params = []): Response
    {
        $this->ready();
        $me = (int) Auth::id();
        $handle = strtolower(trim($request->string('handle')));
        $handle = $handle === '' ? null : $handle;
        if ($handle !== null && preg_match('/^[a-z0-9_.]{3,30}$/', $handle) !== 1) {
            $this->flash('error', 'نام کاربری پروفایل فقط حروف انگلیسی کوچک، عدد، نقطه و _ و ۳ تا ۳۰ کاراکتر است.');
            return $this->redirect('/account/privacy');
        }
        if ($handle !== null && $this->profiles->handleTaken($handle, $me)) {
            $this->flash('error', '«@' . $handle . '» را کس دیگری برداشته است.');
            return $this->redirect('/account/privacy');
        }
        $vis = [];
        $picked = array_map('strval', (array) ($request->input('visible') ?? []));
        foreach (array_keys(ProfileRepository::SECTIONS) as $k) {
            $vis[$k] = in_array($k, $picked, true);
        }
        $wasPrivate = $this->profiles->settings($me)['is_private'];
        $private = $request->bool('is_private');
        $this->profiles->save($me, [
            'handle'              => $handle,
            'bio'                 => mb_substr(trim($request->string('bio')), 0, 300) ?: null,
            'tone'                => in_array($request->string('tone'), ProfileRepository::TONES, true) ? $request->string('tone') : 'indigo',
            'is_private'          => $private,
            'show_in_leaderboard' => $request->bool('show_in_leaderboard'),
            'visibility'          => $vis,
        ]);
        // Going public accepts everyone who was waiting.
        if ($wasPrivate && !$private) {
            Database::execute("UPDATE profile_follows SET status = 'accepted' WHERE followee_id = :u AND status = 'pending'", ['u' => $me]);
        }
        $this->flash('success', 'تنظیمات پروفایل ذخیره شد.');
        return $this->redirect('/account/privacy');
    }

    /** /student/people — find classmates, follow requests, followers. */
    public function people(Request $request, array $params = []): Response
    {
        $this->ready();
        $me = (int) Auth::id();
        $q = mb_substr(trim($request->string('q')), 0, 60);
        $list = in_array($request->string('list'), ['followers', 'following'], true) ? $request->string('list') : '';
        return $this->page('layouts.app', 'profile.people', [
            'title'    => 'هم‌کلاسی‌ها',
            'q'        => $q,
            'list'     => $list,
            'results'  => $q !== '' ? $this->profiles->search($q, $me) : [],
            'people'   => $list !== '' ? $this->profiles->people($me, $list) : [],
            'requests' => $this->profiles->requests($me),
            'counts'   => $this->profiles->counts($me),
            'feed'     => $q === '' && $list === '' ? $this->profiles->feed($me, 30) : [],
            'extraCss' => ['profile'],
            'extraJs'  => ['profile'],
        ]);
    }

    /** /student/leaderboard — the weekly league, all time, and friends. */
    public function leaderboard(Request $request, array $params = []): Response
    {
        $me = (int) Auth::id();
        $tab = in_array($request->string('tab'), ['week', 'all', 'friends'], true) ? $request->string('tab') : 'week';
        $rows = match ($tab) {
            'all'     => $this->allTimeBoard(100),
            'friends' => $this->friendsBoard($me),
            default   => Points::weeklyBoard(100),
        };
        $settings = ProfileRepository::ready() ? $this->profiles->settings($me) : ['show_in_leaderboard' => true];
        $handles = $this->handles(array_map(static fn (array $r): int => (int) $r['user_id'], $rows));

        return $this->page('layouts.app', 'profile.leaderboard', [
            'title'    => 'رتبه‌بندی',
            'tab'      => $tab,
            'rows'     => $rows,
            'handles'  => $handles,
            'me'       => $me,
            'summary'  => Points::summary($me),
            'hidden'   => !$settings['show_in_leaderboard'],
            'leagues'  => Points::LEAGUES,
            'extraCss' => ['profile'],
        ]);
    }

    /* ========================================================== actions */

    public function createPost(Request $request, array $params = []): Response
    {
        $this->ready();
        $me = (int) Auth::id();
        $body = trim(mb_substr($request->string('body'), 0, 2000));
        $image = null;
        $file = $request->file('image');
        if ($file !== null && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $image = (new MediaStore('posts'))->store($file);
            } catch (\RuntimeException $e) {
                $this->flash('error', $e->getMessage());
                return $this->redirect('/account/profile');
            }
        }
        if ($body === '' && $image === null) {
            $this->flash('error', 'یک متن یا تصویر برای پست لازم است.');
            return $this->redirect('/account/profile');
        }
        $audience = in_array($request->string('audience'), ['everyone', 'followers', 'me'], true) ? $request->string('audience') : 'everyone';
        $tone = in_array($request->string('tone'), ProfileRepository::TONES, true) ? $request->string('tone') : 'indigo';
        $this->profiles->createPost($me, $body !== '' ? $body : null, $image, $tone, $audience);
        $this->flash('success', 'پست منتشر شد ✨');
        return $this->redirect('/account/profile');
    }

    public function deletePost(Request $request, array $params = []): Response
    {
        $this->ready();
        $post = $this->profiles->findPost((string) ($params['uuid'] ?? ''));
        if ($post === null || (int) $post['user_id'] !== (int) Auth::id()) {
            throw HttpException::notFound();
        }
        $this->profiles->deletePost((int) $post['id']);
        if ($post['image_path']) {
            (new MediaStore('posts'))->forget($post['image_path']);
        }
        if ($request->isAjax()) {
            return $this->json(['ok' => true]);
        }
        $this->flash('success', 'پست حذف شد.');
        return $this->redirect('/account/profile');
    }

    public function like(Request $request, array $params = []): Response
    {
        $this->ready();
        $post = $this->profiles->findPost((string) ($params['uuid'] ?? ''));
        if ($post === null || !$this->maySeePost($post, (int) Auth::id())) {
            return $this->json(['ok' => false], 404);
        }
        return $this->json(['ok' => true] + $this->profiles->toggleLike((int) $post['id'], (int) Auth::id()));
    }

    public function follow(Request $request, array $params = []): Response
    {
        $this->ready();
        $me = (int) Auth::id();
        $target = $this->profiles->person((string) ($params['uuid'] ?? ''));
        if ($target === null || $target['role_slug'] !== 'student' || (int) $target['id'] === $me) {
            return $this->answer($request, false, 'این پروفایل پیدا نشد.', null);
        }
        $current = $this->profiles->followStatus($me, (int) $target['id']);
        if ($current !== null) {
            $this->profiles->unfollow($me, (int) $target['id']);
            return $this->answer($request, true, $current === 'pending' ? 'درخواست لغو شد.' : 'دیگر دنبال نمی‌کنی.', null);
        }
        $private = $this->profiles->settings((int) $target['id'])['is_private'];
        $status = $this->profiles->follow($me, (int) $target['id'], $private);
        $mine = $this->profiles->personById($me);
        Notify::user((int) $target['id'],
            $status === 'pending' ? ($mine['full_name'] ?? '') . ' می‌خواهد دنبالت کند' : ($mine['full_name'] ?? '') . ' دنبالت کرد',
            $status === 'pending' ? 'در «حریم خصوصی» درخواست را قبول یا رد کن.' : 'به پروفایلش سر بزن.',
            $status === 'pending' ? '/account/privacy' : '/u/' . ($mine['uuid'] ?? ''), 'message');
        return $this->answer($request, true, $status === 'pending' ? 'درخواست فرستاده شد.' : 'دنبال شد ✓', $status);
    }

    public function decide(Request $request, array $params = []): Response
    {
        $this->ready();
        $me = (int) Auth::id();
        $who = $this->profiles->person((string) ($params['uuid'] ?? ''));
        if ($who !== null) {
            if ($request->string('decision') === 'accept') {
                $this->profiles->accept((int) $who['id'], $me);
            } else {
                $this->profiles->unfollow((int) $who['id'], $me);
            }
        }
        return $this->redirect($request->string('back') === 'people' ? '/student/people' : '/account/privacy');
    }

    /** Post images: whoever may see the post. */
    public function media(Request $request, array $params = []): Response
    {
        $name = (string) ($params['name'] ?? '');
        $post = Database::selectOne('SELECT * FROM profile_posts WHERE image_path = :n AND deleted_at IS NULL LIMIT 1', ['n' => $name]);
        if ($post === null || !$this->maySeePost($post, (int) Auth::id())) {
            throw HttpException::notFound();
        }
        $res = (new MediaStore('posts'))->response($name);
        if ($res === null) {
            throw HttpException::notFound();
        }
        return $res;
    }

    /* ======================================================== internals */

    private function ready(): void
    {
        if (!ProfileRepository::ready()) {
            throw HttpException::notFound();
        }
    }

    private function maySeePost(array $post, int $viewer): bool
    {
        $owner = (int) $post['user_id'];
        if ($owner === $viewer || (!Auth::isStudent() && Auth::can('points.manage'))) {
            return true;
        }
        if ($post['hidden_at'] !== null || $post['audience'] === 'me') {
            return false;
        }
        $s = $this->profiles->settings($owner);
        if (!$s['visibility']['posts']) {
            return false;
        }
        $rel = $this->profiles->followStatus($viewer, $owner);
        if ($s['is_private'] || $post['audience'] === 'followers') {
            return $rel === 'accepted';
        }
        return true;
    }

    private function answer(Request $request, bool $ok, string $message, ?string $status): Response
    {
        if ($request->isAjax()) {
            return $this->json(['ok' => $ok, 'message' => $message, 'status' => $status], $ok ? 200 : 422);
        }
        $this->flash($ok ? 'success' : 'error', $message);
        $back = $request->string('back');
        return $this->redirect(preg_match('~^/(u|student/people)(/[\w\-.]*)?$~', $back) === 1 ? $back : '/student/people');
    }

    /** The student's Balin badges, best tier of each. */
    private function badges(int $userId): array
    {
        try {
            return array_values((new \HeleXa\Models\Balin\BalinAchievementRepository())->bestTiers($userId));
        } catch (\Throwable) {
            return [];
        }
    }

    private function weeklyPlace(int $userId): ?int
    {
        foreach (Points::weeklyBoard(200) as $r) {
            if ((int) $r['user_id'] === $userId) {
                return (int) $r['place'];
            }
        }
        return null;
    }

    private function allTimeBoard(int $limit): array
    {
        try {
            $rows = Database::select(
                "SELECT u.id AS user_id, u.uuid, u.full_name, u.username, u.avatar_path, u.gender, SUM(x.amount) AS xp
                 FROM balin_xp_transactions x
                 JOIN users u ON u.id = x.user_id AND u.deleted_at IS NULL AND u.status = 'active'
                 LEFT JOIN user_profiles p ON p.user_id = u.id
                 WHERE COALESCE(p.show_in_leaderboard, 1) = 1
                 GROUP BY u.id HAVING xp > 0 ORDER BY xp DESC, u.id LIMIT " . max(1, min(200, $limit))
            );
        } catch (\PDOException) {
            return [];
        }
        foreach ($rows as $i => &$r) {
            $r['place'] = $i + 1;
            $r['xp'] = (int) $r['xp'];
        }
        return $rows;
    }

    /** The viewer and everyone they follow, this week — hidden or not, they chose each other. */
    private function friendsBoard(int $me): array
    {
        if (!ProfileRepository::ready()) {
            return [];
        }
        $ids = implode(',', array_map('intval', array_merge([$me], $this->profiles->followingIds($me))));
        try {
            $rows = Database::select(
                "SELECT u.id AS user_id, u.uuid, u.full_name, u.username, u.avatar_path, u.gender,
                        COALESCE((SELECT SUM(x.amount) FROM balin_xp_transactions x WHERE x.user_id = u.id AND x.created_at >= :w), 0) AS xp
                 FROM users u WHERE u.id IN ({$ids}) AND u.deleted_at IS NULL ORDER BY xp DESC, u.id",
                ['w' => Points::weekStart()]
            );
        } catch (\PDOException) {
            return [];
        }
        foreach ($rows as $i => &$r) {
            $r['place'] = $i + 1;
            $r['xp'] = (int) $r['xp'];
        }
        return $rows;
    }

    /** @return array<int,string> user id => handle */
    private function handles(array $ids): array
    {
        if ($ids === [] || !ProfileRepository::ready()) {
            return [];
        }
        $out = [];
        foreach (Database::select('SELECT user_id, handle FROM user_profiles WHERE handle IS NOT NULL AND user_id IN (' . implode(',', array_map('intval', $ids)) . ')') as $r) {
            $out[(int) $r['user_id']] = (string) $r['handle'];
        }
        return $out;
    }
}
