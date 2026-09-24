<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin;

use HeleXa\Core\Controller;
use HeleXa\Core\Database;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\QuestionBank\QbTagRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;

/**
 * «برچسب‌های مشترک»: one set of tags for درسنامه‌ها, questions and the figure
 * game's hotspots. A tag joins them: a student who misses a question tagged
 * «چرخه قلبی» is sent to the درسنامه and the figure game with that tag.
 */
final class SharedTagController extends Controller
{
    public function index(Request $request, array $params = []): Response
    {
        $tags = (new QbTagRepository())->all(false);
        $count = static function (string $sql): array {
            $out = [];
            try {
                foreach (Database::select($sql) as $r) {
                    $out[(int) $r['tag_id']] = (int) $r['c'];
                }
            } catch (\PDOException) {
            }
            return $out;
        };

        return $this->page('layouts.app', 'admin.lessons.tags', [
            'title'     => 'برچسب‌های مشترک',
            'tags'      => $tags,
            'lessons'   => $count('SELECT tag_id, COUNT(*) AS c FROM lesson_page_tags GROUP BY tag_id'),
            'decks'     => $count('SELECT tag_id, COUNT(*) AS c FROM fc_deck_tags GROUP BY tag_id'),
            'balin'     => $count('SELECT tag_id, COUNT(*) AS c FROM balin_lesson_tags GROUP BY tag_id'),
            'maps'      => $count('SELECT tag_id, COUNT(*) AS c FROM mindmap_tags GROUP BY tag_id'),
            'questions' => $count('SELECT tag_id, COUNT(*) AS c FROM qb_question_tags GROUP BY tag_id'),
            'spots'     => $count('SELECT tag_id, COUNT(*) AS c FROM figure_spots WHERE tag_id IS NOT NULL GROUP BY tag_id'),
        ]);
    }

    public function store(Request $request, array $params = []): Response
    {
        try {
            $id = (new QbTagRepository())->create(mb_substr(trim($request->string('title')), 0, 96), $request->string('color'), $request->int('sort_order'));
            ActivityLogger::log('tag.created', Auth::id(), 'qb_tag', $id, [], 'info', $request);
            $this->flash('success', 'برچسب ساخته شد.');
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }
        return $this->redirect('/admin/lesson-tags');
    }

    public function update(Request $request, array $params = []): Response
    {
        $repo = new QbTagRepository();
        $tag = $repo->find((int) ($params['id'] ?? 0));
        if ($tag !== null) {
            try {
                $repo->update((int) $tag['id'], mb_substr(trim($request->string('title')), 0, 96), $request->string('color'),
                    $request->int('sort_order'), $request->bool('is_active'));
                $this->flash('success', 'ذخیره شد.');
            } catch (\RuntimeException $e) {
                $this->flash('error', $e->getMessage());
            }
        }
        return $this->redirect('/admin/lesson-tags');
    }

    public function destroy(Request $request, array $params = []): Response
    {
        (new QbTagRepository())->delete((int) ($params['id'] ?? 0));
        ActivityLogger::log('tag.deleted', Auth::id(), 'qb_tag', (int) ($params['id'] ?? 0), [], 'warning', $request);
        $this->flash('success', 'برچسب حذف شد.');
        return $this->redirect('/admin/lesson-tags');
    }
}
