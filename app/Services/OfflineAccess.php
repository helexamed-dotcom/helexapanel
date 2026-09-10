<?php
declare(strict_types=1);

namespace HeleXa\Services;

use HeleXa\Core\HttpException;
use HeleXa\Core\Str;
use HeleXa\Models\ContentRepository;
use HeleXa\Models\EnrollmentRepository;
use HeleXa\Models\SectionRepository;

/**
 * Rules for taking authorised content offline.
 *
 * Storing a lesson on a device does not create a new right; it caches an
 * existing one. So every offline decision runs through the same
 * ContentAccess gate the online viewer uses, and the result carries a lease:
 * a date after which the device must come back online and be re-authorised.
 *
 * The lease is honest about what it is. The bytes are already on the device,
 * so a determined owner of that device can keep them. What the lease buys is
 * that the *application* stops serving them, that revoked enrolment stops
 * mattering within days rather than never, and that a lost or shared device
 * does not become a permanent library.
 */
final class OfflineAccess
{
    public static function isEnabled(): bool
    {
        return Settings::bool('offline_enabled', true);
    }

    /**
     * Metadata describing one downloadable lesson.
     *
     * @return array<string,mixed>
     * @throws HttpException when the caller may not take this content offline
     */
    public static function package(array $user, string $contentUuid): array
    {
        if (!self::isEnabled()) {
            throw HttpException::forbidden('مطالعه آفلاین در حال حاضر غیرفعال است.');
        }

        // Same gate as the online viewer. No shortcut, no second code path.
        $content = ContentAccess::authorize($user, $contentUuid);

        if (!Auth::isStudent()) {
            throw HttpException::forbidden('ذخیره آفلاین فقط برای دانشجویان است.');
        }
        if ((int) ($content['offline_enabled'] ?? 1) !== 1) {
            throw HttpException::forbidden('این محتوا برای مطالعه آفلاین در دسترس نیست.');
        }

        return [
            'content_uuid' => (string) $content['uuid'],
            'course_uuid'  => (string) $content['course_uuid'],
            'course_title' => (string) $content['course_title'],
            'title'        => (string) $content['title'],
            'content_type' => (string) $content['content_type'],
            // The stored file's SHA-256 is already the content version.
            // Nothing new had to be added to the schema for this.
            'version'      => (string) ($content['checksum'] ?? ''),
            'byte_size'    => (int) ($content['byte_size'] ?? 0),
            'updated_at'   => (string) ($content['updated_at'] ?? $content['created_at']),
            'stream_url'   => '/content/' . $content['uuid'] . '/stream',
            'lease'        => self::lease($user, $content),
            'is_printable' => (int) $content['is_printable'] === 1,
        ];
    }

    /**
     * How long this device may keep serving the lesson without checking in.
     * Never longer than the enrolment itself.
     *
     * @return array{expires_at:string, days:int}
     */
    public static function lease(array $user, array $content): array
    {
        $days  = max(1, min(90, Settings::int('offline_lease_days', 14)));
        $until = time() + ($days * 86400);

        $enrollment = (new EnrollmentRepository())
            ->activeEnrollment((int) $user['id'], (int) $content['course_id']);

        if ($enrollment !== null && !empty($enrollment['ends_at'])) {
            $endsAt = strtotime((string) $enrollment['ends_at']);
            if ($endsAt !== false && $endsAt < $until) {
                $until = $endsAt;
            }
        }

        return [
            'expires_at' => date('c', $until),
            'days'       => (int) max(0, ceil(($until - time()) / 86400)),
        ];
    }

    /**
     * The whole navigable tree a student may keep offline: their courses,
     * sections and published lessons. Only identifiers and titles; no content
     * bytes, so this stays small enough to refresh on every online visit.
     */
    public static function catalogue(array $user): array
    {
        $courses  = (new EnrollmentRepository())->coursesForStudent((int) $user['id']);
        $sections = new SectionRepository();
        $contents = new ContentRepository();
        $out      = [];

        foreach ($courses as $course) {
            $tree = [];
            foreach ($sections->forCourse((int) $course['id']) as $section) {
                if ($section['status'] !== 'published') {
                    continue;
                }
                $tree[] = [
                    'id'        => (int) $section['id'],
                    'parent_id' => $section['parent_id'] === null ? null : (int) $section['parent_id'],
                    'title'     => (string) $section['title'],
                    'depth'     => (int) $section['depth'],
                ];
            }
            $visible = array_column($tree, 'id');

            $lessons = [];
            foreach ($contents->forCourse((int) $course['id']) as $content) {
                if ($content['status'] !== 'published') {
                    continue;
                }
                if ($content['section_id'] !== null && !in_array((int) $content['section_id'], $visible, true)) {
                    continue;
                }
                $lessons[] = [
                    'uuid'            => (string) $content['uuid'],
                    'section_id'      => $content['section_id'] === null ? null : (int) $content['section_id'],
                    'title'           => (string) $content['title'],
                    'content_type'    => (string) $content['content_type'],
                    'byte_size'       => (int) ($content['byte_size'] ?? 0),
                    'version'         => (string) ($content['checksum'] ?? ''),
                    'offline_enabled' => (int) ($content['offline_enabled'] ?? 1) === 1,
                ];
            }

            $out[] = [
                'uuid'     => (string) $course['uuid'],
                'title'    => (string) $course['title'],
                'color'    => $course['color'],
                'ends_at'  => $course['ends_at'],
                'sections' => $tree,
                'lessons'  => $lessons,
            ];
        }

        return $out;
    }

    /**
     * An opaque per-user handle the client stores so it can tell, offline,
     * whose data is on this device. It is derived, carries no secret, and
     * changes if the app key is rotated.
     */
    public static function deviceScope(array $user): string
    {
        return substr(Str::hmac('offline-scope:' . $user['uuid']), 0, 32);
    }
}
