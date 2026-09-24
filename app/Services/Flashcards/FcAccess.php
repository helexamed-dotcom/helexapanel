<?php
declare(strict_types=1);

namespace HeleXa\Services\Flashcards;

use HeleXa\Core\Request;
use HeleXa\Models\Flashcards\FcCatalogRepository;
use HeleXa\Models\Flashcards\FcStudyRepository;
use HeleXa\Services\Settings;

/**
 * The one place that decides what a student may see and change.
 *
 *   personal deck   → only its owner, who may also edit it
 *   course session  → students who hold the course, while it is published;
 *                     read-only for all of them
 *
 * Every student route asks here, per request. A revoked grant or an
 * unpublished course takes effect on the next click.
 */
final class FcAccess
{
    /**
     * @return array{deck:array, editable:bool}|null  null when the student may not see it
     */
    public static function deck(int $userId, string $uuid): ?array
    {
        $deck = (new FcCatalogRepository())->deckByUuid($uuid);
        if ($deck === null) {
            return null;
        }

        if ($deck['owner_id'] !== null) {
            return (int) $deck['owner_id'] === $userId ? ['deck' => $deck, 'editable' => true] : null;
        }

        return self::course($userId, (int) $deck['course_id']) !== null
            ? ['deck' => $deck, 'editable' => false]
            : null;
    }

    /** The course row if this student may open it, else null. */
    public static function course(int $userId, int $courseId): ?array
    {
        $course = (new FcCatalogRepository())->courseById($courseId);

        if ($course === null || $course['status'] !== 'published') {
            return null;
        }

        return (new FcStudyRepository())->hasCourse($userId, $courseId) ? $course : null;
    }

    /**
     * Every deck id this student may study: their own, plus the sessions of
     * every published course they hold. Used by "review everything due".
     *
     * @return array<int,int>
     */
    public static function allDeckIds(int $userId): array
    {
        $catalog = new FcCatalogRepository();
        $ids     = array_map(static fn (array $d): int => (int) $d['id'], $catalog->decksOfOwner($userId));

        foreach ((new FcStudyRepository())->coursesFor($userId) as $course) {
            foreach ($catalog->decksOfCourse((int) $course['id']) as $deck) {
                $ids[] = (int) $deck['id'];
            }
        }

        return $ids;
    }

    public static function deckLimit(): int
    {
        return max(1, (int) Settings::get('fc_user_deck_limit', 60));
    }

    public static function cardLimit(): int
    {
        return max(10, (int) Settings::get('fc_user_card_limit', 3000));
    }

    public static function newPerSession(): int
    {
        return max(1, min((int) Settings::get('fc_new_per_session', 20), 200));
    }

    /**
     * Reads an import from either the file input or the paste box.
     *
     * @return array{cards:array, skipped:int, truncated:bool}
     */
    public static function readImport(Request $request): array
    {
        $file = $request->file('file');
        if ($file !== null && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            return CardImporter::fromUpload($file);
        }

        $text = (string) $request->input('text', '');
        if (trim($text) === '') {
            throw new \RuntimeException('فایلی انتخاب نشده و متنی هم چسبانده نشده است.');
        }

        return CardImporter::fromText($text);
    }

    /** The sentence shown after an import. */
    public static function importSummary(int $added, array $result): string
    {
        $parts = [fa((string) $added) . ' کارت افزوده شد.'];
        if ($result['skipped'] > 0) {
            $parts[] = fa((string) $result['skipped']) . ' ردیف به‌خاطر خالی بودن رو یا پشت کارت رد شد.';
        }
        if ($result['truncated']) {
            $parts[] = 'فایل بیش از ' . fa((string) CardImporter::maxRows()) . ' ردیف داشت؛ فقط همین تعداد خوانده شد.';
        }
        return implode(' ', $parts);
    }
}
