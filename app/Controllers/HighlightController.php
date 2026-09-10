<?php
declare(strict_types=1);

namespace HeleXa\Controllers;

use HeleXa\Core\Controller;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Core\Str;
use HeleXa\Models\HighlightRepository;
use HeleXa\Services\Auth;
use HeleXa\Services\ContentAccess;
use HeleXa\Services\Settings;

/**
 * A student's highlights on one lesson.
 *
 * Every route runs through ContentAccess first, so a highlight can only be
 * created on, listed from or removed from a lesson the caller may actually
 * read. Deletes and updates are additionally scoped by user_id in the query
 * itself: another student's uuid matches no row rather than being found and
 * then rejected.
 */
final class HighlightController extends Controller
{
    public const COLORS = ['yellow', 'green', 'blue', 'pink', 'purple'];

    public function index(Request $request, array $params = []): Response
    {
        $user    = Auth::user();
        $content = ContentAccess::authorize($user, (string) ($params['uuid'] ?? ''));

        return $this->json([
            'ok'         => true,
            'version'    => (string) ($content['checksum'] ?? ''),
            'highlights' => $this->decorate(
                (new HighlightRepository())->forContent((int) $user['id'], (int) $content['id'])
            ),
        ])->withHeader('Cache-Control', 'no-store, private');
    }

    public function store(Request $request, array $params = []): Response
    {
        $user    = Auth::user();
        $content = ContentAccess::authorize($user, (string) ($params['uuid'] ?? ''));

        if (!Settings::bool('highlight_enabled', true) || !Auth::isStudent()) {
            return $this->json(['ok' => false, 'error' => 'HIGHLIGHT_DISABLED'], 403);
        }

        $repository = new HighlightRepository();
        $limit      = max(10, min(2000, Settings::int('highlight_max_per_content', 300)));

        if ($repository->countFor((int) $user['id'], (int) $content['id']) >= $limit) {
            return $this->json(['ok' => false, 'error' => 'LIMIT_REACHED', 'limit' => $limit], 422);
        }

        $payload = $this->validate($request);
        if ($payload === null) {
            return $this->json(['ok' => false, 'error' => 'INVALID_HIGHLIGHT'], 422);
        }

        // The client proposes an id so an offline highlight keeps the same
        // identity when it is finally synced. A supplied id must be a real
        // v4 UUID or the request is refused outright: silently swapping in a
        // server-generated id would desync the client's local record from
        // what actually landed in the database, and the client would keep
        // trying to delete or recolour an id that no longer exists.
        $raw = $request->input('uuid');
        if ($raw !== null && $raw !== '') {
            $uuid = $this->uuid($raw);
            if ($uuid === null) {
                return $this->json(['ok' => false, 'error' => 'INVALID_ID'], 422);
            }
            if ($repository->exists((int) $user['id'], $uuid)) {
                return $this->json(['ok' => true, 'uuid' => $uuid, 'duplicate' => true]);
            }
        } else {
            $uuid = Str::uuid4();
        }

        $repository->create([
            'uuid'            => $uuid,
            'user_id'         => (int) $user['id'],
            'content_id'      => (int) $content['id'],
            'course_id'       => (int) $content['course_id'],
            'kind'            => $payload['kind'],
            'color'           => $payload['color'],
            'anchor'          => $payload['anchor'],
            'quote'           => $payload['quote'],
            'note'            => $payload['note'],
            'content_version' => (string) ($content['checksum'] ?? ''),
        ]);

        return $this->json(['ok' => true, 'uuid' => $uuid]);
    }

    public function destroy(Request $request, array $params = []): Response
    {
        $user = Auth::user();
        ContentAccess::authorize($user, (string) ($params['uuid'] ?? ''));

        $target = $this->uuid($params['highlight'] ?? null);
        if ($target === null) {
            return $this->json(['ok' => false, 'error' => 'INVALID_ID'], 422);
        }

        $removed = (new HighlightRepository())->delete((int) $user['id'], $target);

        return $this->json(['ok' => true, 'removed' => $removed]);
    }

    public function recolor(Request $request, array $params = []): Response
    {
        $user = Auth::user();
        ContentAccess::authorize($user, (string) ($params['uuid'] ?? ''));

        $target = $this->uuid($params['highlight'] ?? null);
        $color  = $request->string('color');

        if ($target === null || !in_array($color, self::COLORS, true)) {
            return $this->json(['ok' => false, 'error' => 'INVALID_INPUT'], 422);
        }

        return $this->json([
            'ok'      => true,
            'updated' => (new HighlightRepository())->updateColor((int) $user['id'], $target, $color),
        ]);
    }

    /* ------------------------------------------------------------ helpers */

    /**
     * @return array{kind:string, color:string, anchor:array, quote:?string, note:?string}|null
     */
    private function validate(Request $request): ?array
    {
        $kind  = $request->string('kind', 'text');
        $color = $request->string('color', 'yellow');
        $anchor = $request->input('anchor');

        // Text is the only kind there is: highlighting a region of an image was
        // removed from the product, so nothing may create one any more.
        if ($kind !== 'text' || !in_array($color, self::COLORS, true)) {
            return null;
        }
        if (!is_array($anchor)) {
            return null;
        }

        $clean = $this->textAnchor($anchor);
        if ($clean === null) {
            return null;
        }

        $quote = $request->string('quote');
        $note  = $request->string('note');

        return [
            'kind'   => $kind,
            'color'  => $color,
            'anchor' => $clean,
            'quote'  => $quote === '' ? null : mb_substr($quote, 0, 500),
            'note'   => $note === '' ? null : mb_substr($note, 0, 500),
        ];
    }

    /**
     * Text is anchored by character offset across the whole document, not by a
     * DOM path.
     *
     * Highlighting wraps text in <mark>, which changes the child counts of the
     * elements around it. A path-based anchor recorded before that wrap would
     * point somewhere else afterwards. Character offsets do not move, because
     * wrapping adds elements without adding or removing a single character.
     * The surrounding words are stored too, so a highlight can still be found
     * by search if the lesson file is later edited.
     */
    private function textAnchor(array $anchor): ?array
    {
        $start = $anchor['start'] ?? null;
        $end   = $anchor['end'] ?? null;

        if (!is_numeric($start) || !is_numeric($end)) {
            return null;
        }

        $start = (int) $start;
        $end   = (int) $end;

        // Ten million characters is far beyond any lesson; past that it is not
        // a real selection, it is someone probing the endpoint.
        if ($start < 0 || $end <= $start || $end > 10000000 || ($end - $start) > 20000) {
            return null;
        }

        return [
            'start'  => $start,
            'end'    => $end,
            'prefix' => mb_substr((string) ($anchor['prefix'] ?? ''), 0, 60),
            'suffix' => mb_substr((string) ($anchor['suffix'] ?? ''), 0, 60),
        ];
    }

    private function uuid(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1
            ? strtolower($value)
            : null;
    }

    /** Decodes the stored JSON so the client receives objects, not strings. */
    private function decorate(array $rows): array
    {
        foreach ($rows as $index => $row) {
            $rows[$index]['anchor'] = json_decode((string) $row['anchor'], true) ?: [];
        }
        return $rows;
    }
}
