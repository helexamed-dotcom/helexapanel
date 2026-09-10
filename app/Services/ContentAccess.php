<?php
declare(strict_types=1);

namespace HeleXa\Services;

use HeleXa\Core\HttpException;
use HeleXa\Models\ContentRepository;
use HeleXa\Models\EnrollmentRepository;

/**
 * The single authorization gate for private lesson content.
 *
 * Every path that can produce lesson bytes calls this: the viewer shell, the
 * stream, the state endpoint and the study heartbeat. Changing a UUID in the
 * URL therefore cannot reach content the student is not enrolled in, because
 * enrollment is re-checked on every single request rather than at page load.
 */
final class ContentAccess
{
    /**
     * @return array the content row joined with its course
     * @throws HttpException 403/404
     */
    public static function authorize(array $user, string $contentUuid): array
    {
        $content = (new ContentRepository())->findByUuid($contentUuid);

        // Same response for "does not exist" and "not yours": a 404 that only
        // appears for unauthorised users would itself leak the catalogue.
        if ($content === null) {
            throw HttpException::notFound('محتوا یافت نشد.');
        }

        $isAdmin = in_array($user['role_slug'], ['admin', 'super_admin'], true);

        if ($isAdmin) {
            // Admins preview their own material, but only with the content permission.
            if (!Auth::can('manage_content')) {
                throw HttpException::forbidden();
            }
            return $content;
        }

        if ($content['status'] !== 'published' || $content['course_status'] !== 'published') {
            throw HttpException::notFound('محتوا یافت نشد.');
        }

        $enrollment = (new EnrollmentRepository())->activeEnrollment((int) $user['id'], (int) $content['course_id']);
        if ($enrollment === null) {
            ActivityLogger::log('content.access_denied', (int) $user['id'], 'content', (int) $content['id'],
                ['uuid' => $contentUuid], 'warning');
            throw HttpException::notFound('محتوا یافت نشد.');
        }

        return $content;
    }

    /** Course-level check, used by the course tree page. */
    public static function authorizeCourse(array $user, string $courseUuid): array
    {
        $course = (new \HeleXa\Models\CourseRepository())->findByUuid($courseUuid);
        if ($course === null) {
            throw HttpException::notFound('دوره یافت نشد.');
        }

        if (in_array($user['role_slug'], ['admin', 'super_admin'], true)) {
            if (!Auth::can('manage_content') && !Auth::can('manage_courses')) {
                throw HttpException::forbidden();
            }
            return $course;
        }

        if ($course['status'] !== 'published') {
            throw HttpException::notFound('دوره یافت نشد.');
        }
        if ((new EnrollmentRepository())->activeEnrollment((int) $user['id'], (int) $course['id']) === null) {
            throw HttpException::notFound('دوره یافت نشد.');
        }

        return $course;
    }
}
