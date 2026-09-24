<?php
declare(strict_types=1);

namespace HeleXa\Services;

use HeleXa\Core\Database;
use HeleXa\Models\Balin\BalinAccessRepository;
use HeleXa\Models\Balin\BalinLessonRepository;
use HeleXa\Models\CourseRepository;
use HeleXa\Models\Flashcards\FcCatalogRepository;
use HeleXa\Models\Flashcards\FcStudyRepository;
use HeleXa\Models\PackageRepository;
use HeleXa\Models\QuestionBank\QbAccessRepository;
use HeleXa\Models\QuestionBank\QbSubjectRepository;

/**
 * What a package hands over besides courses.
 *
 * A package can carry question bank subjects, Balin lessons and flashcard
 * courses, and one package can be marked "full access": everything that
 * exists, including whatever is added afterwards.
 *
 * Nothing new is invented here for reading access. Granting writes the very
 * rows the four modules already check on every request — a qb_student_access
 * row, a balin grant, an fc_access row — so a package is a way of filling
 * those in, never a second answer to "may this student open this".
 *
 * Every module is optional: a site that never ran the flashcards migration
 * still activates packages, it just has nothing to give from that module.
 */
final class PackageAccess
{
    /** Item kinds, mapped to what the admin calls them. */
    public const LABELS = [
        'qbank_subject'     => 'بانک سوال',
        'balin_lesson'      => 'درس جزیره بالین',
        'flashcard_course'  => 'فلش‌کارت',
    ];

    /* ================================================================ read */

    /**
     * The catalogue each kind is chosen from.
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    public static function catalogue(): array
    {
        return [
            'qbank_subject'    => self::optional(static fn (): array => (new QbSubjectRepository())->roots(false)),
            'balin_lesson'     => self::optional(static fn (): array => (new BalinLessonRepository())->all()),
            'flashcard_course' => self::optional(static fn (): array => (new FcCatalogRepository())->courses()),
        ];
    }

    /** @return array<int,int> every course id that exists, for a full package */
    public static function allCourseIds(): array
    {
        return array_map('intval', array_column((new CourseRepository())->all(), 'id'));
    }

    /**
     * The ids one package hands over, kind by kind. A full package hands over
     * the whole catalogue as it stands right now.
     *
     * @return array<string,array<int,int>>
     */
    public static function itemIdsOf(array $package): array
    {
        $packages = new PackageRepository();

        if ((int) ($package['is_full_access'] ?? 0) === 1) {
            $all = [];
            foreach (self::catalogue() as $type => $rows) {
                $all[$type] = array_map('intval', array_column($rows, 'id'));
            }
            return $all;
        }

        return $packages->itemsByType((int) $package['id']);
    }

    /* =============================================================== write */

    /**
     * Gives a student everything in a package except its courses, which
     * ActivationService enrols separately.
     *
     * @return array<string,int> how many of each kind were handed over
     */
    public static function apply(array $user, array $package, ?int $actorId): array
    {
        $userId = (int) $user['id'];
        $items  = self::itemIdsOf($package);
        $counts = ['qbank_subject' => 0, 'balin_lesson' => 0, 'flashcard_course' => 0];

        Database::transaction(static function () use ($userId, $items, $actorId, &$counts): void {
            $counts['qbank_subject'] = self::optionalInt(static function () use ($userId, $items, $actorId): int {
                $access = new QbAccessRepository();
                $given  = 0;
                foreach ($items['qbank_subject'] ?? [] as $subjectId) {
                    if (!$access->has($userId, $subjectId)) {
                        $access->grant($userId, $subjectId, $actorId);
                        $given++;
                    }
                }
                return $given;
            });

            $counts['balin_lesson'] = self::optionalInt(static function () use ($userId, $items, $actorId): int {
                $lessonIds = $items['balin_lesson'] ?? [];
                if ($lessonIds === []) {
                    return 0;
                }
                // Holding a lesson is pointless without the island itself.
                $island = new BalinAccessRepository();
                if (!$island->isEnabled($userId)) {
                    $island->grant($userId, $actorId, 'از طریق پکیج');
                }
                return self::openLessons($userId, $lessonIds, $actorId);
            });

            $counts['flashcard_course'] = self::optionalInt(static function () use ($userId, $items, $actorId): int {
                $wanted = $items['flashcard_course'] ?? [];
                if ($wanted === []) {
                    return 0;
                }
                $study   = new FcStudyRepository();
                $current = $study->grantedCourseIds($userId);
                $missing = array_values(array_diff($wanted, $current));
                if ($missing !== []) {
                    $study->syncAccess($userId, array_merge($current, $missing), $actorId);
                }
                return count($missing);
            });
        });

        return $counts;
    }

    /**
     * Takes back what a package gave, skipping anything the student also holds
     * through another package that is still live. Progress rows are never
     * touched: a re-activation puts them back exactly where they were.
     *
     * @return array<string,int>
     */
    public static function withdraw(array $user, array $package, ?int $actorId): array
    {
        $userId    = (int) $user['id'];
        $packageId = (int) $package['id'];
        $items     = self::itemIdsOf($package);
        $packages  = new PackageRepository();
        $counts    = ['qbank_subject' => 0, 'balin_lesson' => 0, 'flashcard_course' => 0];

        /** Only what no other live package of this student also grants. */
        $mine = static function (string $type) use ($items, $packages, $userId, $packageId): array {
            return array_values(array_filter(
                $items[$type] ?? [],
                static fn (int $id): bool => !$packages->itemHeldElsewhere($userId, $packageId, $type, $id)
            ));
        };

        Database::transaction(static function () use ($userId, $mine, &$counts): void {
            $counts['qbank_subject'] = self::optionalInt(static function () use ($userId, $mine): int {
                $access = new QbAccessRepository();
                $taken  = 0;
                foreach ($mine('qbank_subject') as $subjectId) {
                    if ($access->has($userId, $subjectId)) {
                        $access->revoke($userId, $subjectId);
                        $taken++;
                    }
                }
                return $taken;
            });

            $counts['balin_lesson'] = self::optionalInt(static function () use ($userId, $mine): int {
                $ids = $mine('balin_lesson');
                if ($ids === []) {
                    return 0;
                }
                (new BalinLessonRepository())->revokeGrants($userId, $ids);
                return count($ids);
            });

            $counts['flashcard_course'] = self::optionalInt(static function () use ($userId, $mine): int {
                $ids = $mine('flashcard_course');
                if ($ids === []) {
                    return 0;
                }
                $study   = new FcStudyRepository();
                $current = $study->grantedCourseIds($userId);
                $left    = array_values(array_diff($current, $ids));
                if (count($left) !== count($current)) {
                    $study->syncAccess($userId, $left, null);
                }
                return count($current) - count($left);
            });
        });

        return $counts;
    }

    /**
     * New content appeared. Everyone holding a full-access package gets it,
     * which is what "full" has to mean for it to stay true tomorrow.
     *
     * @return int students who received it
     */
    public static function contentAdded(string $type, int $itemId, ?int $actorId): int
    {
        if ($itemId <= 0 || !in_array($type, ['course', ...PackageRepository::ITEM_TYPES], true)) {
            return 0;
        }

        $holders = self::optional(static fn (): array => (new PackageRepository())->fullAccessHolderIds());
        if ($holders === []) {
            return 0;
        }

        foreach ($holders as $userId) {
            $userId = (int) $userId;
            self::optionalInt(static function () use ($type, $itemId, $userId, $actorId): int {
                switch ($type) {
                    case 'course':
                        (new \HeleXa\Models\EnrollmentRepository())
                            ->assign($userId, $itemId, ['status' => 'active'], $actorId);
                        return 1;
                    case 'qbank_subject':
                        $access = new QbAccessRepository();
                        if (!$access->has($userId, $itemId)) {
                            $access->grant($userId, $itemId, $actorId);
                        }
                        return 1;
                    case 'balin_lesson':
                        $island = new BalinAccessRepository();
                        if (!$island->isEnabled($userId)) {
                            $island->grant($userId, $actorId, 'پکیج کامل');
                        }
                        return self::openLessons($userId, [$itemId], $actorId);
                    case 'flashcard_course':
                        $study   = new FcStudyRepository();
                        $current = $study->grantedCourseIds($userId);
                        if (!in_array($itemId, $current, true)) {
                            $study->syncAccess($userId, array_merge($current, [$itemId]), $actorId);
                        }
                        return 1;
                }
                return 0;
            });
        }

        ActivityLogger::log('package.full_content_shared', $actorId, 'package', 0,
            ['type' => $type, 'item' => $itemId, 'students' => count($holders)], 'info');

        return count($holders);
    }

    /* ======================================================== free packages */

    /**
     * Gives a new student every free package — called when an account is
     * made, whether by the student at /register or by an admin.
     *
     * @return int packages activated
     */
    public static function grantFree(array $user, ?int $actorId): int
    {
        $given = 0;
        foreach (self::optional(static fn (): array => (new PackageRepository())->freePackages()) as $package) {
            ActivationService::activatePackage($user, $package, [
                'status' => 'active', 'starts_at' => null, 'ends_at' => null,
            ], $actorId);
            $given++;
        }
        return $given;
    }

    /**
     * Gives one free package to every active student, and brings its content
     * up to date for those who already hold it. A student whose copy an admin
     * suspended or cancelled is left alone — that was a decision about them.
     *
     * @return int students who hold it afterwards
     */
    public static function grantToEveryone(array $package, ?int $actorId): int
    {
        $packages = new PackageRepository();
        $students = (new \HeleXa\Models\UserRepository())
            ->paginate(['role' => 'student', 'status' => 'active'], 100000, 0);

        $count = 0;
        foreach ($students as $student) {
            $existing = $packages->activation((int) $student['id'], (int) $package['id']);
            if ($existing !== null && $existing['status'] !== 'active') {
                continue;
            }
            ActivationService::activatePackage($student, $package, [
                'status'    => 'active',
                'starts_at' => $existing['starts_at'] ?? null,
                'ends_at'   => $existing['ends_at'] ?? null,
            ], $actorId);
            $count++;
        }

        ActivityLogger::log('package.free_granted_all', $actorId, 'package', (int) $package['id'],
            ['students' => $count], 'notice');

        return $count;
    }

    /** "۳ دوره، ۲ درس بانک سوال" — only the parts that actually happened. */
    public static function summarise(array $result): string
    {
        $parts = [];
        if ((int) ($result['courses'] ?? 0) > 0) {
            $parts[] = fa((string) $result['courses']) . ' دوره';
        }
        foreach (['qbank_subject' => 'درس بانک سوال', 'balin_lesson' => 'درس جزیره',
                  'flashcard_course' => 'درس فلش‌کارت'] as $key => $label) {
            $n = (int) ($result['items'][$key] ?? 0);
            if ($n > 0) {
                $parts[] = fa((string) $n) . ' ' . $label;
            }
        }

        return $parts === [] ? 'بدون محتوای تازه (همه را از قبل داشت)' : implode('، ', $parts);
    }

    /* ============================================================= helpers */

    /**
     * Opens Balin lessons for a student whichever rule each one follows: a
     * lesson everyone may see is opened by lifting its block, a lesson given
     * out one by one by adding the grant.
     *
     * @param array<int,int> $lessonIds
     */
    private static function openLessons(int $userId, array $lessonIds, ?int $actorId): int
    {
        $lessons = new BalinLessonRepository();
        $ids     = array_map('intval', $lessonIds);

        $modes = [];
        foreach ($lessons->all() as $row) {
            $modes[(int) $row['id']] = (string) ($row['access_mode'] ?? 'open');
        }

        $grant   = [];
        $unblock = [];
        foreach ($ids as $id) {
            if (($modes[$id] ?? 'open') === 'granted') {
                $grant[] = $id;
            } else {
                $unblock[] = $id;
            }
        }

        $lessons->grant($userId, $grant, $actorId);
        if ($unblock !== []) {
            $blocked = $lessons->blockedIdsFor($userId);
            $left    = array_values(array_diff($blocked, $unblock));
            if (count($left) !== count($blocked)) {
                $lessons->syncBlocks($userId, $left, $actorId);
            }
        }

        return count($ids);
    }

    /** @return array<int,mixed> */
    private static function optional(callable $read): array
    {
        try {
            return $read();
        } catch (\PDOException) {
            return [];
        }
    }

    private static function optionalInt(callable $write): int
    {
        try {
            return $write();
        } catch (\PDOException) {
            return 0;
        }
    }
}
