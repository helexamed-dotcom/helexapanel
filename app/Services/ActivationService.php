<?php
declare(strict_types=1);

namespace HeleXa\Services;

use HeleXa\Core\Database;
use HeleXa\Core\Str;
use HeleXa\Models\CourseRepository;
use HeleXa\Models\EnrollmentRepository;
use HeleXa\Models\PackageRepository;

/**
 * Granting access.
 *
 * Two rules shape this class.
 *
 * First, the grant and the record of the grant happen together or not at all,
 * so a half-activated package cannot exist. The transaction covers the
 * enrolment rows and the activation row.
 *
 * Second, the announcement happens *after* the transaction commits. A student
 * who was granted access must keep it even if the notification layer is having
 * a bad day; the reverse — losing access because a message failed — would be
 * indefensible.
 */
final class ActivationService
{
    /**
     * @return array{enrolled:bool, notified:bool}
     */
    public static function activateCourse(array $user, array $course, array $window, ?int $actorId): array
    {
        $enrollments = new EnrollmentRepository();

        Database::transaction(static function () use ($enrollments, $user, $course, $window, $actorId): void {
            $enrollments->assign((int) $user['id'], (int) $course['id'], [
                'status'    => $window['status'] ?? 'active',
                'starts_at' => $window['starts_at'] ?? null,
                'ends_at'   => $window['ends_at'] ?? null,
            ], $actorId);
        });

        ActivityLogger::log('course.activated', $actorId, 'course', (int) $course['id'], [
            'student' => (int) $user['id'],
        ], 'notice');

        // Only an activation that actually opens access is worth announcing.
        $notified = false;
        if (($window['status'] ?? 'active') === 'active') {
            $notified = NotificationService::courseActivated($user, $course, $actorId)['created'];
        }

        return ['enrolled' => true, 'notified' => $notified];
    }

    /**
     * Activates a package: every course inside it, plus the question bank
     * subjects, Balin lessons and flashcard courses it carries. A package
     * marked "full access" carries the whole catalogue.
     *
     * @return array{courses:int, granted:int, items:array<string,int>, notified:bool, activation:array}
     */
    public static function activatePackage(array $user, array $package, array $window, ?int $actorId): array
    {
        $packages    = new PackageRepository();
        $enrollments = new EnrollmentRepository();
        $courseIds   = (int) ($package['is_full_access'] ?? 0) === 1
            ? PackageAccess::allCourseIds()
            : $packages->courseIds((int) $package['id']);

        $activation = Database::transaction(
            static function () use ($packages, $enrollments, $user, $package, $window, $actorId, $courseIds): array {
                foreach ($courseIds as $courseId) {
                    $enrollments->assign((int) $user['id'], $courseId, [
                        'status'    => $window['status'] ?? 'active',
                        'starts_at' => $window['starts_at'] ?? null,
                        'ends_at'   => $window['ends_at'] ?? null,
                    ], $actorId);
                }

                return $packages->upsertActivation([
                    'uuid'         => Str::uuid4(),
                    'user_id'      => (int) $user['id'],
                    'package_id'   => (int) $package['id'],
                    'status'       => $window['status'] ?? 'active',
                    'starts_at'    => $window['starts_at'] ?? null,
                    'ends_at'      => $window['ends_at'] ?? null,
                    'course_count' => count($courseIds),
                    'activated_by' => $actorId,
                ]);
            }
        );

        // The rest of the package — everything that is not a course — is
        // granted after the enrolments, through the modules' own tables.
        $items = ($window['status'] ?? 'active') === 'active'
            ? PackageAccess::apply($user, $package, $actorId)
            : ['qbank_subject' => 0, 'balin_lesson' => 0, 'flashcard_course' => 0];

        ActivityLogger::log('package.activated', $actorId, 'package', (int) $package['id'], [
            'student' => (int) $user['id'],
            'courses' => count($courseIds),
            'items'   => $items,
        ], 'notice');

        // One announcement for the package, never one per course inside it.
        $notified = false;
        if (($window['status'] ?? 'active') === 'active') {
            $notified = NotificationService::packageActivated(
                $user,
                $package,
                count($courseIds),
                $actorId
            )['created'];
        }

        return [
            'courses'    => count($courseIds),
            'granted'    => count($courseIds),
            'items'      => $items,
            'notified'   => $notified,
            'activation' => $activation,
        ];
    }

    /**
     * A course was added to a package after people already held it.
     * Whether they receive it is the package's own setting, because widening
     * access to existing members should be a decision, not a side effect.
     *
     * @return int number of students who gained the course
     */
    public static function backfillCourse(array $package, int $courseId, ?int $actorId): int
    {
        if ((int) $package['auto_grant_new_courses'] !== 1) {
            return 0;
        }

        $packages    = new PackageRepository();
        $enrollments = new EnrollmentRepository();
        $members     = $packages->activeMemberIds((int) $package['id']);
        $course      = (new CourseRepository())->findById($courseId);

        if ($course === null || $members === []) {
            return 0;
        }

        Database::transaction(static function () use ($enrollments, $members, $courseId, $actorId): void {
            foreach ($members as $userId) {
                $enrollments->assign($userId, $courseId, ['status' => 'active'], $actorId);
            }
        });

        ActivityLogger::log('package.course_backfilled', $actorId, 'package', (int) $package['id'], [
            'course'   => $courseId,
            'students' => count($members),
        ], 'notice');

        // After the commit, like every other announcement here.
        NotificationService::packageCourseAdded($package, $course, $actorId);

        return count($members);
    }

    /**
     * Suspending a package suspends the courses it granted, unless the student
     * also holds them through something else. Access is checked against
     * student_courses on every request, so this takes effect immediately.
     */
    public static function setPackageStatus(array $activation, array $package, string $status, ?int $actorId): void
    {
        $packages    = new PackageRepository();
        $enrollments = new EnrollmentRepository();
        $courseIds   = (int) ($package['is_full_access'] ?? 0) === 1
            ? PackageAccess::allCourseIds()
            : $packages->courseIds((int) $package['id']);
        $student     = ['id' => (int) $activation['user_id']];

        $affected = Database::transaction(static function () use ($packages, $enrollments, $activation, $courseIds, $status): int {
            $packages->setActivationStatus((int) $activation['id'], $status);

            // Only courses the student already holds are touched. Creating a
            // row here for a course they never received would hand it to them
            // the moment the package was reactivated.
            return $enrollments->setStatusForCourses(
                (int) $activation['user_id'],
                $courseIds,
                $status,
                $activation['starts_at'],
                $activation['ends_at']
            );
        });

        // The rest of the package follows the same decision: suspending hands
        // it back, re-activating gives it again — but only what this package
        // granted and no other live package of theirs also grants.
        $items = $status === 'active'
            ? PackageAccess::apply($student, $package, $actorId)
            : PackageAccess::withdraw($student, $package, $actorId);

        ActivityLogger::log('package.status_changed', $actorId, 'package', (int) $package['id'], [
            'student'  => (int) $activation['user_id'],
            'status'   => $status,
            'affected' => $affected,
            'items'    => $items,
        ], 'warning');
    }
}
