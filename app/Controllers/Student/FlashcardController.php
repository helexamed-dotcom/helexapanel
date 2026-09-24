<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Student;

use HeleXa\Core\Controller;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\Flashcards\FcCatalogRepository;
use HeleXa\Models\Flashcards\FcStudyRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;
use HeleXa\Services\Flashcards\FcAccess;
use HeleXa\Services\Flashcards\Scheduler;

/**
 * The student's flashcards: ready-made courses, personal decks, and study.
 *
 * There is deliberately no export. Cards are shown to study them, and the
 * course cards are the admin's work; nothing here writes them out as a file.
 */
final class FlashcardController extends Controller
{
    private const STUDY_CAP = 200;

    private FcCatalogRepository $catalog;
    private FcStudyRepository $study;

    public function __construct()
    {
        $this->catalog = new FcCatalogRepository();
        $this->study   = new FcStudyRepository();
    }

    /* ============================================================= pages */

    public function index(Request $request, array $params = []): Response
    {
        $userId  = $this->userId();
        $courses = $this->study->coursesFor($userId);
        $decks   = $this->catalog->decksOfOwner($userId);

        return $this->page('layouts.app', 'student.flashcards.index', [
            'title'     => 'فلش‌کارت',
            'courses'   => $courses,
            'decks'     => $decks,
            'deckStats' => $this->study->deckStats($userId, array_map(static fn ($d) => (int) $d['id'], $decks)),
            'overview'  => $this->study->overview($userId, FcAccess::allDeckIds($userId)),
            'deckLimit' => FcAccess::deckLimit(),
        ]);
    }

    public function course(Request $request, array $params = []): Response
    {
        $userId = $this->userId();
        $course = $this->catalog->courseByUuid((string) ($params['uuid'] ?? ''));
        if ($course === null || FcAccess::course($userId, (int) $course['id']) === null) {
            throw HttpException::notFound();
        }

        $decks = $this->catalog->decksOfCourse((int) $course['id']);

        return $this->page('layouts.app', 'student.flashcards.course', [
            'title'     => $course['title'],
            'course'    => $course,
            'decks'     => $decks,
            'deckStats' => $this->study->deckStats($userId, array_map(static fn ($d) => (int) $d['id'], $decks)),
        ]);
    }

    public function deck(Request $request, array $params = []): Response
    {
        $userId = $this->userId();
        $access = $this->deckOr404($userId, (string) ($params['uuid'] ?? ''));
        $deck   = $access['deck'];

        return $this->page('layouts.app', 'student.flashcards.deck', [
            'title'     => $deck['title'],
            'deck'      => $deck,
            'editable'  => $access['editable'],
            'cards'     => $this->catalog->cardsOfDeck((int) $deck['id'], 2000),
            'stages'    => $this->study->stagesForDeck($userId, (int) $deck['id']),
            'stats'     => $this->study->deckStats($userId, [(int) $deck['id']])[(int) $deck['id']] ?? ['seen' => 0, 'mastered' => 0, 'due' => 0],
            'maxRows'   => \HeleXa\Services\Flashcards\CardImporter::maxRows(),
        ]);
    }

    /* ============================================================= study */

    /** Everything due, across every deck the student can reach. */
    public function studyAll(Request $request, array $params = []): Response
    {
        $userId = $this->userId();
        return $this->studyPage($request, $userId, FcAccess::allDeckIds($userId), 'مرور همه کارت‌های موعددار', '/student/flashcards');
    }

    public function studyCourse(Request $request, array $params = []): Response
    {
        $userId = $this->userId();
        $course = $this->catalog->courseByUuid((string) ($params['uuid'] ?? ''));
        if ($course === null || FcAccess::course($userId, (int) $course['id']) === null) {
            throw HttpException::notFound();
        }

        $ids = array_map(static fn ($d) => (int) $d['id'], $this->catalog->decksOfCourse((int) $course['id']));

        return $this->studyPage($request, $userId, $ids, $course['title'], '/student/flashcards/course/' . $course['uuid']);
    }

    public function studyDeck(Request $request, array $params = []): Response
    {
        $userId = $this->userId();
        $deck   = $this->deckOr404($userId, (string) ($params['uuid'] ?? ''))['deck'];

        return $this->studyPage($request, $userId, [(int) $deck['id']], $deck['title'], '/student/flashcards/deck/' . $deck['uuid']);
    }

    /** Records one rating. Answers with the new due label for the summary. */
    public function rate(Request $request, array $params = []): Response
    {
        $userId = $this->userId();
        $card   = $this->cardFor($userId, (string) ($params['uuid'] ?? ''));
        $rating = $request->int('rating');

        if ($card === null) {
            return $this->json(['ok' => false, 'message' => 'این کارت در دسترس نیست.'], 404);
        }
        if (!isset(Scheduler::LABELS[$rating])) {
            return $this->json(['ok' => false, 'message' => 'امتیاز نامعتبر است.'], 422);
        }

        $next = $this->study->rate($userId, (int) $card['id'], $rating);
        // One point per card per day, whatever the rating: showing up is the habit.
        \HeleXa\Services\Points::award($userId, \HeleXa\Services\Points::amount('flashcard_review', 2), 'flashcard_review',
            'fc_card', (int) $card['id'], 'fc:' . $userId . ':' . $card['id'] . ':' . date('Ymd'));

        return $this->json([
            'ok'       => true,
            'interval' => $next['interval_days'],
            'again'    => $rating === Scheduler::AGAIN,
        ]);
    }

    public function star(Request $request, array $params = []): Response
    {
        $userId = $this->userId();
        $card   = $this->cardFor($userId, (string) ($params['uuid'] ?? ''));

        if ($card === null) {
            return $this->json(['ok' => false, 'message' => 'این کارت در دسترس نیست.'], 404);
        }

        return $this->json(['ok' => true, 'starred' => $this->study->toggleStar($userId, (int) $card['id'])]);
    }

    public function resetDeck(Request $request, array $params = []): Response
    {
        $userId = $this->userId();
        $deck   = $this->deckOr404($userId, (string) ($params['uuid'] ?? ''))['deck'];

        $this->study->resetDeck($userId, (int) $deck['id']);
        $this->flash('success', 'پیشرفت شما در این دسته پاک شد و می‌توانید از صفر شروع کنید.');

        return $this->redirect('/student/flashcards/deck/' . $deck['uuid']);
    }

    /* ==================================================== personal decks */

    public function storeDeck(Request $request, array $params = []): Response
    {
        $userId = $this->userId();

        if ($this->catalog->countOwnerDecks($userId) >= FcAccess::deckLimit()) {
            $this->flash('error', 'به سقف ' . fa((string) FcAccess::deckLimit()) . ' دسته شخصی رسیده‌ای.');
            return $this->redirect('/student/flashcards');
        }

        try {
            $id = $this->catalog->createDeck(null, $userId, $request->string('title'), $request->string('description'));
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());
            return $this->redirect('/student/flashcards');
        }

        $deck = $this->catalog->deckById($id);
        $this->flash('success', 'دسته ساخته شد. حالا کارت اضافه کن یا از اکسل وارد کن.');

        return $this->redirect('/student/flashcards/deck/' . $deck['uuid']);
    }

    public function updateDeck(Request $request, array $params = []): Response
    {
        $deck = $this->ownDeckOr404((string) ($params['uuid'] ?? ''));

        try {
            $this->catalog->updateDeck((int) $deck['id'], $request->string('title'), $request->string('description'), (int) $deck['sort_order']);
            $this->flash('success', 'دسته ذخیره شد.');
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect('/student/flashcards/deck/' . $deck['uuid']);
    }

    public function destroyDeck(Request $request, array $params = []): Response
    {
        $deck = $this->ownDeckOr404((string) ($params['uuid'] ?? ''));
        $this->catalog->deleteDeck((int) $deck['id']);
        $this->flash('success', 'دسته «' . $deck['title'] . '» حذف شد.');

        return $this->redirect('/student/flashcards');
    }

    public function storeCard(Request $request, array $params = []): Response
    {
        $userId = $this->userId();
        $deck   = $this->ownDeckOr404((string) ($params['uuid'] ?? ''));

        if ($this->catalog->countOwnerCards($userId) >= FcAccess::cardLimit()) {
            $this->flash('error', 'به سقف ' . fa((string) FcAccess::cardLimit()) . ' کارت شخصی رسیده‌ای.');
            return $this->redirect('/student/flashcards/deck/' . $deck['uuid']);
        }

        try {
            $this->catalog->addCard((int) $deck['id'], (string) $request->input('front', ''), (string) $request->input('back', ''),
                (string) $request->input('hint', ''));
            $this->flash('success', 'کارت افزوده شد.');
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect('/student/flashcards/deck/' . $deck['uuid'] . '#add');
    }

    public function updateCard(Request $request, array $params = []): Response
    {
        $card = $this->ownCardOr404((string) ($params['uuid'] ?? ''));

        try {
            $this->catalog->updateCard((int) $card['id'], (string) $request->input('front', ''), (string) $request->input('back', ''),
                (string) $request->input('hint', ''));
            $this->flash('success', 'کارت ذخیره شد.');
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect('/student/flashcards/deck/' . $card['deck_uuid']);
    }

    public function destroyCard(Request $request, array $params = []): Response
    {
        $card = $this->ownCardOr404((string) ($params['uuid'] ?? ''));
        $this->catalog->deleteCard((int) $card['id']);
        $this->flash('success', 'کارت حذف شد.');

        return $this->redirect('/student/flashcards/deck/' . $card['deck_uuid']);
    }

    public function import(Request $request, array $params = []): Response
    {
        $userId = $this->userId();
        $deck   = $this->ownDeckOr404((string) ($params['uuid'] ?? ''));
        $back   = '/student/flashcards/deck/' . $deck['uuid'];

        try {
            $result = FcAccess::readImport($request);
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());
            return $this->redirect($back);
        }

        // The personal ceiling applies to imports too, or one file would be
        // a way around it.
        $room = FcAccess::cardLimit() - $this->catalog->countOwnerCards($userId);
        if ($room <= 0) {
            $this->flash('error', 'به سقف کارت‌های شخصی رسیده‌ای.');
            return $this->redirect($back);
        }
        $cards = array_slice($result['cards'], 0, $room);

        $added = $this->catalog->bulkInsert($cards, static fn (): int => (int) $deck['id']);

        ActivityLogger::log('flashcards.import', $userId, 'fc_deck', (int) $deck['id'], ['added' => $added], 'info', $request);

        $message = FcAccess::importSummary($added, $result);
        if (count($result['cards']) > $room) {
            $message .= ' بقیه به‌خاطر سقف کارت‌های شخصی وارد نشدند.';
        }
        $this->flash('success', $message);

        return $this->redirect($back);
    }

    /* =========================================================== helpers */

    /**
     * @param array<int,int> $deckIds  already authorised
     */
    private function studyPage(Request $request, int $userId, array $deckIds, string $title, string $backUrl): Response
    {
        $mode = $request->string('mode', 'due');
        if (!in_array($mode, ['due', 'all', 'starred', 'hard'], true)) {
            $mode = 'due';
        }
        $shuffle = $request->bool('shuffle');
        $limit   = max(5, min($request->int('limit', 50), self::STUDY_CAP));
        $now     = new \DateTimeImmutable();

        $rows  = $this->study->queue($userId, $deckIds, $mode, FcAccess::newPerSession(), $limit, $shuffle);
        $cards = array_map(static function (array $row) use ($now): array {
            $state = $row['reps'] === null ? null : $row;
            return [
                'id'       => $row['uuid'],
                'front'    => $row['front'],
                'back'     => $row['back'],
                'hint'     => $row['hint'],
                'starred'  => (int) ($row['starred'] ?? 0) === 1,
                'isNew'    => $state === null || $row['last_rating'] === null,
                'previews' => Scheduler::previews($state, $now),
            ];
        }, $rows);

        return $this->page('layouts.app', 'student.flashcards.study', [
            'title'   => 'مطالعه — ' . $title,
            'heading' => $title,
            'cards'   => $cards,
            'mode'    => $mode,
            'shuffle' => $shuffle,
            'limit'   => $limit,
            'backUrl' => $backUrl,
            'labels'  => Scheduler::LABELS,
        ]);
    }

    private function userId(): int
    {
        return (int) Auth::id();
    }

    private function deckOr404(int $userId, string $uuid): array
    {
        $access = FcAccess::deck($userId, $uuid);
        if ($access === null) {
            throw HttpException::notFound();
        }
        return $access;
    }

    private function ownDeckOr404(string $uuid): array
    {
        $access = FcAccess::deck($this->userId(), $uuid);
        if ($access === null || !$access['editable']) {
            throw HttpException::notFound();
        }
        return $access['deck'];
    }

    private function ownCardOr404(string $uuid): array
    {
        $card = $this->catalog->cardByUuid($uuid);
        if ($card === null || $card['owner_id'] === null || (int) $card['owner_id'] !== $this->userId()) {
            throw HttpException::notFound();
        }
        return $card;
    }

    /** A card the student may study, or null. */
    private function cardFor(int $userId, string $uuid): ?array
    {
        $card = $this->catalog->cardByUuid($uuid);
        if ($card === null) {
            return null;
        }

        return FcAccess::deck($userId, (string) $card['deck_uuid']) !== null ? $card : null;
    }

    /** Every flashcard page carries its own stylesheet and script. */
    protected function page(string $layout, string $template, array $data = [], int $status = 200): Response
    {
        return parent::page($layout, $template, $data + ['flashcards' => true], $status);
    }
}
