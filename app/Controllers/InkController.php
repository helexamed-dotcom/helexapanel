<?php
declare(strict_types=1);

namespace HeleXa\Controllers;

use HeleXa\Core\Controller;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\InkRepository;
use HeleXa\Models\NoteRepository;
use HeleXa\Services\Auth;
use HeleXa\Services\ContentAccess;
use HeleXa\Services\InkData;
use HeleXa\Services\Settings;

/**
 * Handwriting on a lesson, and the note pins that sit on it.
 * Every call re-checks that the caller may read the lesson.
 */
final class InkController extends Controller
{
    public function show(Request $request, array $params = []): Response
    {
        $user    = Auth::user();
        $content = ContentAccess::authorize($user, (string) ($params['uuid'] ?? ''));

        return $this->json([
            'ok'      => true,
            'strokes' => (new InkRepository())->strokesFor((int) $user['id'], (int) $content['id']),
        ])->withHeader('Cache-Control', 'no-store, private');
    }

    public function save(Request $request, array $params = []): Response
    {
        $user    = Auth::user();
        $content = ContentAccess::authorize($user, (string) ($params['uuid'] ?? ''));

        if (!Auth::isStudent() || !Settings::bool('ink_enabled', true)) {
            return $this->json(['ok' => false, 'error' => 'INK_DISABLED'], 403);
        }

        $strokes = InkData::clean($request->input('strokes', []));
        try {
            $json = InkData::encode($strokes);
        } catch (\RuntimeException $e) {
            return $this->json(['ok' => false, 'error' => 'TOO_LARGE', 'message' => $e->getMessage()], 413);
        }

        (new InkRepository())->save((int) $user['id'], (int) $content['id'], $json, count($strokes));

        return $this->json(['ok' => true, 'count' => count($strokes)]);
    }

    /** Notes pinned on this lesson, for the viewer's list. */
    public function notes(Request $request, array $params = []): Response
    {
        $user    = Auth::user();
        $content = ContentAccess::authorize($user, (string) ($params['uuid'] ?? ''));

        return $this->json([
            'ok'    => true,
            'notes' => (new NoteRepository())->pinsFor((int) $user['id'], (int) $content['id']),
        ])->withHeader('Cache-Control', 'no-store, private');
    }
}