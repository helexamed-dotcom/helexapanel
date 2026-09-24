<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin\Flashcards;

use HeleXa\Core\Controller;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\Flashcards\FcCatalogRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;
use HeleXa\Services\Flashcards\CardImporter;
use HeleXa\Services\Flashcards\FcAccess;

/**
 * Ready-made flashcards: درس → جلسه → کارت.
 *
 * A course can be filled one card at a time, one session at a time from a
 * spreadsheet, or all at once: a course-level import reads a fourth column
 * naming the session and creates the sessions it has not seen yet, so a
 * whole 24-session course can arrive as one Excel file.
 */
final class FlashcardController extends Controller
{
    private const CARDS_PER_PAGE = 100;

    private FcCatalogRepository $catalog;

    public function __construct()
    {
        $this->catalog = new FcCatalogRepository();
    }

    /* =========================================================== courses */

    public function index(Request $request, array $params = []): Response
    {
        return $this->page('layouts.app', 'admin.flashcards.index', [
            'title'   => 'فلش‌کارت',
            'courses' => $this->catalog->courses(),
            'colors'  => FcCatalogRepository::COLORS,
        ]);
    }

    public function storeCourse(Request $request, array $params = []): Response
    {
        try {
            $id = $this->catalog->saveCourse(null, $this->courseInput($request), Auth::id());
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());
            return $this->redirect('/admin/flashcards');
        }

        ActivityLogger::log('flashcards.course.created', Auth::id(), 'fc_course', $id, [], 'info', $request);
        \HeleXa\Services\PackageAccess::contentAdded('flashcard_course', $id, Auth::id());
        $course = $this->catalog->courseById($id);
        $this->flash('success', 'درس ساخته شد. حالا جلسه‌ها را اضافه کن.');

        return $this->redirect('/admin/flashcards/course/' . $course['uuid']);
    }

    public function course(Request $request, array $params = []): Response
    {
        $course = $this->courseOr404((string) ($params['uuid'] ?? ''));

        return $this->page('layouts.app', 'admin.flashcards.course', [
            'title'   => $course['title'],
            'course'  => $course,
            'decks'   => $this->catalog->decksOfCourse((int) $course['id']),
            'colors'  => FcCatalogRepository::COLORS,
            'maxRows' => CardImporter::maxRows(),
        ]);
    }

    /** The session's shared tags: every review of its cards counts toward them in the analysis. */
    public function deckTags(Request $request, array $params = []): Response
    {
        $deck = $this->deckOr404((string) ($params['uuid'] ?? ''));
        \HeleXa\Services\SharedTags::sync('fc_deck_tags', (int) $deck['id'], \HeleXa\Services\SharedTags::fromRequest($request));
        $this->flash('success', 'برچسب‌های جلسه ذخیره شد.');

        return $this->redirect('/admin/flashcards/deck/' . $deck['uuid']);
    }

    public function updateCourse(Request $request, array $params = []): Response
    {
        $course = $this->courseOr404((string) ($params['uuid'] ?? ''));

        try {
            $this->catalog->saveCourse((int) $course['id'], $this->courseInput($request), Auth::id());
            $this->flash('success', 'درس ذخیره شد.');
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect('/admin/flashcards/course/' . $course['uuid']);
    }

    public function destroyCourse(Request $request, array $params = []): Response
    {
        $course = $this->courseOr404((string) ($params['uuid'] ?? ''));
        $this->catalog->deleteCourse((int) $course['id']);

        ActivityLogger::log('flashcards.course.deleted', Auth::id(), 'fc_course', (int) $course['id'],
            ['title' => $course['title']], 'warning', $request);
        $this->flash('success', 'درس «' . $course['title'] . '» با همه جلسه‌ها و کارت‌هایش حذف شد.');

        return $this->redirect('/admin/flashcards');
    }

    /** One spreadsheet for a whole course; column D names the session. */
    public function importCourse(Request $request, array $params = []): Response
    {
        $course = $this->courseOr404((string) ($params['uuid'] ?? ''));
        $back   = '/admin/flashcards/course/' . $course['uuid'];

        try {
            $result = FcAccess::readImport($request);
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());
            return $this->redirect($back);
        }

        $fallback = trim($request->string('default_session')) ?: 'عمومی';
        $cache    = [];
        $courseId = (int) $course['id'];

        $added = $this->catalog->bulkInsert($result['cards'], function (array $card) use (&$cache, $courseId, $fallback): int {
            $name = $card['session'] ?? $fallback;
            return $cache[$name] ??= $this->catalog->sessionNamed($courseId, $name);
        });

        ActivityLogger::log('flashcards.course.import', Auth::id(), 'fc_course', $courseId,
            ['added' => $added, 'sessions' => count($cache)], 'notice', $request);

        $this->flash('success', FcAccess::importSummary($added, $result)
            . ' (' . fa((string) count($cache)) . ' جلسه)');

        return $this->redirect($back);
    }

    /* ====================================================== sessions */

    public function storeDeck(Request $request, array $params = []): Response
    {
        $course = $this->courseOr404((string) ($params['uuid'] ?? ''));

        try {
            $this->catalog->createDeck((int) $course['id'], null, $request->string('title'), $request->string('description'),
                $request->string('sort_order') === '' ? null : $request->int('sort_order'));
            $this->flash('success', 'جلسه افزوده شد.');
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect('/admin/flashcards/course/' . $course['uuid']);
    }

    public function deck(Request $request, array $params = []): Response
    {
        $deck  = $this->deckOr404((string) ($params['uuid'] ?? ''));
        $page  = max(1, $request->int('page', 1));
        $total = (int) $deck['card_count'];

        return $this->page('layouts.app', 'admin.flashcards.deck', [
            'title'   => $deck['course_title'] . ' — ' . $deck['title'],
            'deck'    => $deck,
            'cards'   => $this->catalog->cardsOfDeck((int) $deck['id'], self::CARDS_PER_PAGE, ($page - 1) * self::CARDS_PER_PAGE),
            'page'    => $page,
            'pages'   => max(1, (int) ceil($total / self::CARDS_PER_PAGE)),
            'offset'  => ($page - 1) * self::CARDS_PER_PAGE,
            'maxRows' => CardImporter::maxRows(),
            'tagIds'  => \HeleXa\Services\SharedTags::idsFor('fc_deck_tags', (int) $deck['id']),
            'allTags' => \HeleXa\Services\SharedTags::all(),
        ]);
    }

    public function updateDeck(Request $request, array $params = []): Response
    {
        $deck = $this->deckOr404((string) ($params['uuid'] ?? ''));

        try {
            $this->catalog->updateDeck((int) $deck['id'], $request->string('title'), $request->string('description'), $request->int('sort_order'));
            $this->flash('success', 'جلسه ذخیره شد.');
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect($this->safeBack($request, '/admin/flashcards/deck/' . $deck['uuid']));
    }

    public function destroyDeck(Request $request, array $params = []): Response
    {
        $deck = $this->deckOr404((string) ($params['uuid'] ?? ''));
        $this->catalog->deleteDeck((int) $deck['id']);

        ActivityLogger::log('flashcards.deck.deleted', Auth::id(), 'fc_deck', (int) $deck['id'],
            ['title' => $deck['title']], 'notice', $request);
        $this->flash('success', 'جلسه «' . $deck['title'] . '» حذف شد.');

        return $this->redirect('/admin/flashcards/course/' . $deck['course_uuid']);
    }

    public function importDeck(Request $request, array $params = []): Response
    {
        $deck = $this->deckOr404((string) ($params['uuid'] ?? ''));
        $back = '/admin/flashcards/deck/' . $deck['uuid'];

        try {
            $result = FcAccess::readImport($request);
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());
            return $this->redirect($back);
        }

        $added = $this->catalog->bulkInsert($result['cards'], static fn (): int => (int) $deck['id']);

        ActivityLogger::log('flashcards.deck.import', Auth::id(), 'fc_deck', (int) $deck['id'], ['added' => $added], 'notice', $request);
        $this->flash('success', FcAccess::importSummary($added, $result));

        return $this->redirect($back);
    }

    /* ============================================================= cards */

    public function storeCard(Request $request, array $params = []): Response
    {
        $deck = $this->deckOr404((string) ($params['uuid'] ?? ''));

        try {
            $this->catalog->addCard((int) $deck['id'], (string) $request->input('front', ''),
                (string) $request->input('back', ''), (string) $request->input('hint', ''));
            $this->flash('success', 'کارت افزوده شد.');
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect('/admin/flashcards/deck/' . $deck['uuid'] . '#add');
    }

    public function updateCard(Request $request, array $params = []): Response
    {
        $card = $this->courseCardOr404((string) ($params['uuid'] ?? ''));

        try {
            $this->catalog->updateCard((int) $card['id'], (string) $request->input('front', ''),
                (string) $request->input('back', ''), (string) $request->input('hint', ''));
            $this->flash('success', 'کارت ذخیره شد.');
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect($this->safeBack($request, '/admin/flashcards/deck/' . $card['deck_uuid']));
    }

    public function destroyCard(Request $request, array $params = []): Response
    {
        $card = $this->courseCardOr404((string) ($params['uuid'] ?? ''));
        $this->catalog->deleteCard((int) $card['id']);
        $this->flash('success', 'کارت حذف شد.');

        return $this->redirect($this->safeBack($request, '/admin/flashcards/deck/' . $card['deck_uuid']));
    }

    /* =========================================================== helpers */

    private function courseInput(Request $request): array
    {
        return [
            'title'       => mb_substr($request->string('title'), 0, 191),
            'description' => $request->string('description'),
            'color'       => $request->string('color'),
            'icon'        => $request->string('icon'),
            'sort_order'  => $request->int('sort_order'),
            'status'      => $request->string('status'),
        ];
    }

    private function courseOr404(string $uuid): array
    {
        $course = $this->catalog->courseByUuid($uuid);
        if ($course === null) {
            throw HttpException::notFound();
        }
        return $course;
    }

    /**
     * Admins manage course sessions only. A student's personal deck is theirs;
     * nothing here opens one, even by uuid.
     */
    private function deckOr404(string $uuid): array
    {
        $deck = $this->catalog->deckByUuid($uuid);
        if ($deck === null || $deck['course_id'] === null) {
            throw HttpException::notFound();
        }
        return $deck;
    }

    private function courseCardOr404(string $uuid): array
    {
        $card = $this->catalog->cardByUuid($uuid);
        if ($card === null || $card['course_id'] === null) {
            throw HttpException::notFound();
        }
        return $card;
    }

    /**
     * Where to return after a card edit. Named return_to, not "back": the
     * card form already has a field called back — the answer side of the card.
     */
    private function safeBack(Request $request, string $fallback): string
    {
        $back = $request->string('return_to');
        return str_starts_with($back, '/admin/flashcards/') && !str_contains($back, '//') ? $back : $fallback;
    }

    protected function page(string $layout, string $template, array $data = [], int $status = 200): Response
    {
        return parent::page($layout, $template, $data + ['flashcards' => true], $status);
    }
}
