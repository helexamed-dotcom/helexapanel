<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Student;

use HeleXa\Core\Controller;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\Balin\BalinCompetitionRepository;
use HeleXa\Models\Balin\BalinLessonRepository;
use HeleXa\Models\Balin\BalinMediaRepository;
use HeleXa\Models\Balin\BalinSkillTrackRepository;
use HeleXa\Services\Auth;
use HeleXa\Services\Balin\BalinMediaStorage;
use HeleXa\Services\Balin\CompetitionService;
use HeleXa\Services\Balin\LeaderboardService;
use HeleXa\Services\Balin\ProfileService;

/**
 * The student's own numbers: profile, skill radar, leaderboards, and the
 * controller that serves private case media.
 */
final class BalinProfileController extends Controller
{
    public function profile(Request $request, array $params = []): Response
    {
        $userId = (int) Auth::id();

        return $this->page('layouts.app', 'student.balin.profile', [
            'balinGame' => true,
            'title'       => 'پروفایل بالینی من',
            'profile'     => (new ProfileService())->forStudent($userId),
            'competition' => (new CompetitionService())->currentOrFallback(),
            'rewards'     => (new BalinCompetitionRepository())->rewardsForStudent($userId),
        ]);
    }

    /**
     * Leaderboards. The snapshot is refreshed here when it has aged out, so
     * an installation without a cron job still shows current standings
     * without every visitor paying for a rebuild.
     */
    public function leaderboard(Request $request, array $params = []): Response
    {
        $userId = (int) Auth::id();

        // The ranking section follows the switch on «مرور جزیره»: switched off
        // it does not exist for students; on "own rank" they see only their
        // own standing — never another student.
        $mode = \HeleXa\Services\Balin\MyRank::mode();
        if ($mode === 'off') {
            $this->flash('error', 'بخش رتبه‌بندی جزیره فعلاً فعال نیست.');
            return $this->redirect('/student/balin');
        }
        if ($mode === 'self') {
            return $this->page('layouts.app', 'student.balin.myrank', [
                'balinGame' => true,
                'title'     => 'رتبه من',
                'rank'      => \HeleXa\Services\Balin\MyRank::forStudent($userId),
            ]);
        }

        $boards = new LeaderboardService();
        $boards->rebuildIfStale();

        $type    = $request->string('board', 'overall_xp');
        $scopeId = $request->int('scope') ?: null;

        if (!array_key_exists($type, \HeleXa\Models\Balin\BalinLeaderboardRepository::TYPES)) {
            $type = 'overall_xp';
        }
        // Only the two scoped boards take a scope; ignoring it elsewhere
        // keeps a crafted query string from splitting a board in two.
        if (!in_array($type, ['lesson', 'skill'], true)) {
            $scopeId = null;
        }

        $page = $boards->page($type, $scopeId, $request->int('page', 1), $userId);

        $profiles = new ProfileService();
        foreach ($page['rows'] as $index => $row) {
            $page['rows'][$index]['rank_title'] = $profiles->rankFor((int) $row['level'])['title'];
        }

        return $this->page('layouts.app', 'student.balin.leaderboard', [
            'balinGame' => true,
            'title'       => 'جدول رتبه‌بندی',
            'board'       => $type,
            'scope'       => $scopeId,
            'page'        => $page,
            'lessons'     => (new BalinLessonRepository())->all(),
            'tracks'      => (new BalinSkillTrackRepository())->all(true),
            'competition' => (new CompetitionService())->currentOrFallback(),
        ]);
    }

    /**
     * Serves a private media file.
     *
     * Reached only through the Balin access middleware, so the session has
     * already been checked. The path comes from the database row rather than
     * the URL, and is re-resolved under the media root before anything is
     * read, so a crafted uuid cannot reach a file outside it.
     */
    public function media(Request $request, array $params = []): Response
    {
        $media = (new BalinMediaRepository())->findByUuid((string) ($params['uuid'] ?? ''));

        if ($media === null) {
            throw HttpException::notFound();
        }

        $resolved = BalinMediaStorage::resolve((string) $media['storage_path'], (string) $media['mime']);
        if ($resolved === null) {
            throw HttpException::notFound();
        }

        $contents = file_get_contents($resolved['path']);
        if ($contents === false) {
            throw HttpException::notFound();
        }

        return Response::make($contents, 200, [
            'Content-Type'           => $resolved['mime'],
            'Content-Length'         => (string) strlen($contents),
            'Cache-Control'          => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            // A case image is teaching material, not something to be framed
            // by another site or sniffed into an executable type.
            'Content-Disposition'    => ((int) $media['allow_download'] === 1 ? 'attachment' : 'inline')
                                        . '; filename="' . rawurlencode((string) ($media['original_name'] ?? 'media')) . '"',
        ]);
    }
}
