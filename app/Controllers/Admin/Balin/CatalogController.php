<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin\Balin;

use HeleXa\Core\Controller;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Core\Str;
use HeleXa\Models\Balin\BalinCharacterRepository;
use HeleXa\Models\Balin\BalinMediaRepository;
use HeleXa\Models\Balin\BalinRankTierRepository;
use HeleXa\Models\Balin\BalinSkillTrackRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;
use HeleXa\Services\Balin\BalinMediaStorage;
use HeleXa\Services\ImageAssetStorage;

/**
 * The reusable pieces that are not tied to one lesson: characters, skill
 * tracks, rank tiers and the media library.
 *
 * Grouped into one controller because each is a small, flat list with the
 * same shape of editing — four controllers of forty lines each would be
 * more files saying the same thing.
 */
final class CatalogController extends Controller
{
    private const STALE = 'این رکورد توسط مدیر دیگری تغییر کرده است. صفحه را دوباره بارگذاری کن.';

    // ---------------------------------------------------------- characters

    public function characters(Request $request, array $params = []): Response
    {
        return $this->page('layouts.app', 'admin.balin.characters', [
            'title'      => 'شخصیت‌ها',
            'characters' => (new BalinCharacterRepository())->all(),
        ]);
    }

    public function storeCharacter(Request $request, array $params = []): Response
    {
        $repository = new BalinCharacterRepository();
        $name       = trim($request->string('name'));

        if ($name === '') {
            $this->flash('error', 'نام شخصیت الزامی است.');
            return $this->redirect('/admin/balin/characters');
        }

        $id = $repository->create($this->collectCharacter($request) + [
            'uuid'     => Str::uuid4(),
            'name'     => $name,
            'svg_path' => $this->storeSvg($request),
        ]);

        ActivityLogger::log('balin.character.created', Auth::id(), 'balin_character', $id,
            ['name' => $name], 'info', $request);
        $this->flash('success', 'شخصیت اضافه شد.');

        return $this->redirect('/admin/balin/characters');
    }

    public function updateCharacter(Request $request, array $params = []): Response
    {
        $repository = new BalinCharacterRepository();
        $character  = $repository->findByUuid((string) ($params['uuid'] ?? ''));

        if ($character === null) {
            throw HttpException::notFound();
        }

        $name = trim($request->string('name'));
        if ($name === '') {
            $this->flash('error', 'نام شخصیت الزامی است.');
            return $this->redirect('/admin/balin/characters');
        }

        $saved = $repository->update((int) $character['id'], $this->collectCharacter($request) + [
            'name'     => $name,
            'svg_path' => $this->storeSvg($request)
                ?? ($request->bool('remove_image') ? null : $character['svg_path']),
        ], $request->int('version'));

        $this->flash($saved ? 'success' : 'error', $saved ? 'شخصیت ذخیره شد.' : self::STALE);

        return $this->redirect('/admin/balin/characters');
    }

    public function destroyCharacter(Request $request, array $params = []): Response
    {
        $repository = new BalinCharacterRepository();
        $character  = $repository->findByUuid((string) ($params['uuid'] ?? ''));

        if ($character === null) {
            throw HttpException::notFound();
        }

        // Deleting would blank the speaker on every line they have; the
        // admin is told to deactivate instead, which keeps the content whole.
        $used = $repository->usageCount((int) $character['id']);
        if ($used > 0) {
            $this->flash('error', 'این شخصیت در ' . $used . ' بلوک استفاده شده است. '
                                . 'به‌جای حذف، آن را غیرفعال کن.');
            return $this->redirect('/admin/balin/characters');
        }

        $repository->delete((int) $character['id']);
        $this->flash('success', 'شخصیت حذف شد.');

        return $this->redirect('/admin/balin/characters');
    }

    // -------------------------------------------------------- skill tracks

    public function skillTracks(Request $request, array $params = []): Response
    {
        return $this->page('layouts.app', 'admin.balin.skill_tracks', [
            'title'      => 'مهارت‌های بالینی',
            'tracks'     => (new BalinSkillTrackRepository())->all(),
            'categories' => BalinSkillTrackRepository::CATEGORIES,
        ]);
    }

    public function storeSkillTrack(Request $request, array $params = []): Response
    {
        $repository = new BalinSkillTrackRepository();
        $data       = $this->collectTrack($request);

        if ($data['name'] === '') {
            $this->flash('error', 'نام مهارت الزامی است.');
            return $this->redirect('/admin/balin/skill-tracks');
        }
        if ($repository->slugExists($data['slug'])) {
            $this->flash('error', 'این نشانی یکتا قبلاً استفاده شده است.');
            return $this->redirect('/admin/balin/skill-tracks');
        }

        $id = $repository->create($data + ['uuid' => Str::uuid4()]);

        ActivityLogger::log('balin.skill_track.created', Auth::id(), 'balin_skill_track', $id,
            ['name' => $data['name']], 'notice', $request);
        $this->flash('success', 'مهارت اضافه شد.');

        return $this->redirect('/admin/balin/skill-tracks');
    }

    public function updateSkillTrack(Request $request, array $params = []): Response
    {
        $repository = new BalinSkillTrackRepository();
        $track      = $repository->findById((int) ($params['id'] ?? 0));

        if ($track === null) {
            throw HttpException::notFound();
        }

        $data = $this->collectTrack($request);
        if ($data['name'] === '') {
            $this->flash('error', 'نام مهارت الزامی است.');
            return $this->redirect('/admin/balin/skill-tracks');
        }
        if ($repository->slugExists($data['slug'], (int) $track['id'])) {
            $this->flash('error', 'این نشانی یکتا قبلاً استفاده شده است.');
            return $this->redirect('/admin/balin/skill-tracks');
        }

        $saved = $repository->update((int) $track['id'], $data, $request->int('version'));

        ActivityLogger::log('balin.skill_track.updated', Auth::id(), 'balin_skill_track', (int) $track['id'],
            ['name' => $data['name']], 'notice', $request);
        $this->flash($saved ? 'success' : 'error', $saved ? 'مهارت ذخیره شد.' : self::STALE);

        return $this->redirect('/admin/balin/skill-tracks');
    }

    public function destroySkillTrack(Request $request, array $params = []): Response
    {
        $repository = new BalinSkillTrackRepository();
        $track      = $repository->findById((int) ($params['id'] ?? 0));

        if ($track === null) {
            throw HttpException::notFound();
        }

        $repository->delete((int) $track['id']);

        ActivityLogger::log('balin.skill_track.deleted', Auth::id(), 'balin_skill_track', (int) $track['id'],
            ['name' => $track['name']], 'warning', $request);
        $this->flash('success', 'مهارت حذف شد. تسلط درسی دانشجویان تغییری نکرده است.');

        return $this->redirect('/admin/balin/skill-tracks');
    }

    // ---------------------------------------------------------- rank tiers

    public function rankTiers(Request $request, array $params = []): Response
    {
        return $this->page('layouts.app', 'admin.balin.rank_tiers', [
            'title' => 'عنوان سطح‌ها',
            'tiers' => (new BalinRankTierRepository())->all(),
        ]);
    }

    public function storeRankTier(Request $request, array $params = []): Response
    {
        $repository = new BalinRankTierRepository();
        $data       = $this->collectTier($request);

        $error = $this->validateTier($repository, $data, null);
        if ($error !== null) {
            $this->flash('error', $error);
            return $this->redirect('/admin/balin/rank-tiers');
        }

        $id = $repository->create($data);

        ActivityLogger::log('balin.rank_tier.created', Auth::id(), 'balin_rank_tier', $id,
            $data, 'notice', $request);
        $this->flash('success', 'رتبه اضافه شد.');

        return $this->redirect('/admin/balin/rank-tiers');
    }

    public function updateRankTier(Request $request, array $params = []): Response
    {
        $repository = new BalinRankTierRepository();
        $tier       = $repository->find((int) ($params['id'] ?? 0));

        if ($tier === null) {
            throw HttpException::notFound();
        }

        $data  = $this->collectTier($request);
        $error = $this->validateTier($repository, $data, (int) $tier['id']);

        if ($error !== null) {
            $this->flash('error', $error);
            return $this->redirect('/admin/balin/rank-tiers');
        }

        $saved = $repository->update((int) $tier['id'], $data, $request->int('version'));

        ActivityLogger::log('balin.rank_tier.updated', Auth::id(), 'balin_rank_tier', (int) $tier['id'],
            $data, 'notice', $request);
        $this->flash($saved ? 'success' : 'error', $saved ? 'رتبه ذخیره شد.' : self::STALE);

        return $this->redirect('/admin/balin/rank-tiers');
    }

    public function destroyRankTier(Request $request, array $params = []): Response
    {
        $repository = new BalinRankTierRepository();
        $tier       = $repository->find((int) ($params['id'] ?? 0));

        if ($tier === null) {
            throw HttpException::notFound();
        }

        $repository->delete((int) $tier['id']);
        $this->flash('success', 'رتبه حذف شد.');

        return $this->redirect('/admin/balin/rank-tiers');
    }

    // ------------------------------------------------------- media library

    public function media(Request $request, array $params = []): Response
    {
        return $this->page('layouts.app', 'admin.balin.media', [
            'title' => 'کتابخانه رسانه',
            'items' => (new BalinMediaRepository())->all($request->string('kind') ?: null),
            'kind'  => $request->string('kind'),
        ]);
    }

    public function uploadMedia(Request $request, array $params = []): Response
    {
        $kind = $request->string('kind', 'image');
        $file = $request->file('file');

        if ($file === null) {
            $this->flash('error', 'فایلی انتخاب نشده است.');
            return $this->redirect('/admin/balin/media');
        }

        $alt = trim($request->string('alt_text'));

        // Alt text is mandatory for images. A clinical image with no
        // description is unusable for a student relying on a screen reader,
        // and there is no sensible default to invent here.
        if ($kind === 'image' && $alt === '') {
            $this->flash('error', 'برای تصویر، متن جایگزین (Alt) الزامی است.');
            return $this->redirect('/admin/balin/media');
        }

        try {
            $stored = BalinMediaStorage::store($file, $kind);
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
            return $this->redirect('/admin/balin/media');
        }

        $id = (new BalinMediaRepository())->create($stored + [
            'uuid'          => Str::uuid4(),
            'visibility'    => 'private',
            'original_name' => (string) ($file['name'] ?? ''),
            'alt_text'      => $alt ?: null,
            'caption'       => trim($request->string('caption')) ?: null,
            'transcript'    => trim($request->string('transcript')) ?: null,
            'width_percent' => max(10, min(100, $request->int('width_percent', 100))),
            'position'      => in_array($request->string('position'), ['start', 'center', 'end'], true)
                                    ? $request->string('position')
                                    : 'center',
            'allow_download'=> $request->bool('allow_download'),
            'uploaded_by'   => Auth::id(),
        ]);

        ActivityLogger::log('balin.media.uploaded', Auth::id(), 'balin_media', $id,
            ['kind' => $kind], 'info', $request);
        $this->flash('success', 'فایل آپلود شد.');

        return $this->redirect('/admin/balin/media');
    }

    public function updateMedia(Request $request, array $params = []): Response
    {
        $repository = new BalinMediaRepository();
        $media      = $repository->findByUuid((string) ($params['uuid'] ?? ''));

        if ($media === null) {
            throw HttpException::notFound();
        }

        $alt = trim($request->string('alt_text'));
        if ($media['kind'] === 'image' && $alt === '') {
            $this->flash('error', 'برای تصویر، متن جایگزین (Alt) الزامی است.');
            return $this->redirect('/admin/balin/media');
        }

        $repository->updateMeta((int) $media['id'], [
            'alt_text'       => $alt ?: null,
            'caption'        => trim($request->string('caption')) ?: null,
            'transcript'     => trim($request->string('transcript')) ?: null,
            'width_percent'  => max(10, min(100, $request->int('width_percent', 100))),
            'position'       => in_array($request->string('position'), ['start', 'center', 'end'], true)
                                    ? $request->string('position')
                                    : 'center',
            'allow_download' => $request->bool('allow_download'),
        ]);

        $this->flash('success', 'اطلاعات رسانه ذخیره شد.');

        return $this->redirect('/admin/balin/media');
    }

    public function destroyMedia(Request $request, array $params = []): Response
    {
        $repository = new BalinMediaRepository();
        $media      = $repository->findByUuid((string) ($params['uuid'] ?? ''));

        if ($media === null) {
            throw HttpException::notFound();
        }

        $used = $repository->usageCount((int) $media['id']);
        if ($used > 0) {
            $this->flash('error', 'این فایل در ' . $used . ' بلوک استفاده شده است و حذف نشد.');
            return $this->redirect('/admin/balin/media');
        }

        BalinMediaStorage::delete((string) $media['storage_path']);
        $repository->delete((int) $media['id']);

        $this->flash('success', 'فایل حذف شد.');

        return $this->redirect('/admin/balin/media');
    }

    // ------------------------------------------------------------- helpers

    private function collectCharacter(Request $request): array
    {
        $type   = $request->string('char_type', 'student');
        $gender = $request->string('gender', 'male');
        $side   = $request->string('side', 'left');

        return [
            'char_type'     => in_array($type, ['teacher', 'student', 'doctor', 'patient', 'nurse', 'other'], true)
                                    ? $type
                                    : 'student',
            'gender'        => $gender === 'female' ? 'female' : 'male',
            'icon'          => trim($request->string('icon')) ?: null,
            'side'          => $side === 'right' ? 'right' : 'left',
            'color'         => trim($request->string('color')) ?: null,
            'is_active'     => $request->bool('is_active'),
            'display_order' => max(1, $request->int('display_order', 100)),
        ];
    }

    private function collectTrack(Request $request): array
    {
        $name     = trim($request->string('name'));
        $slug     = trim($request->string('slug'));
        $category = $request->string('category', 'clinical_reasoning');

        return [
            'name'        => $name,
            'name_en'     => trim($request->string('name_en')) ?: null,
            'slug'        => $slug !== '' ? Str::slug($slug) : Str::slug($name),
            'icon'        => trim($request->string('icon')) ?: null,
            'color'       => trim($request->string('color')) ?: null,
            'description' => trim($request->string('description')) ?: null,
            'category'    => array_key_exists($category, BalinSkillTrackRepository::CATEGORIES)
                                ? $category
                                : 'clinical_reasoning',
            'display_order' => max(1, $request->int('display_order', 100)),
            'min_questions_for_reliable_mastery' => max(1, $request->int('min_questions', 10)),
            'badge_thresholds' => [
                'bronze'   => max(1, $request->int('bronze', 10)),
                'silver'   => max(1, $request->int('silver', 30)),
                'gold'     => max(1, $request->int('gold', 75)),
                'platinum' => max(1, $request->int('platinum', 150)),
            ],
            'is_active' => $request->bool('is_active'),
        ];
    }

    private function collectTier(Request $request): array
    {
        return [
            'min_level'     => max(0, $request->int('min_level')),
            'max_level'     => max(0, $request->int('max_level')),
            'title'         => trim($request->string('title')),
            'icon'          => trim($request->string('icon')) ?: null,
            'color'         => trim($request->string('color')) ?: null,
            'description'   => trim($request->string('description')) ?: null,
            'display_order' => max(1, $request->int('display_order', 1)),
        ];
    }

    /**
     * Tiers must not overlap and must not run backwards, or a level would
     * either have two names or none.
     */
    private function validateTier(BalinRankTierRepository $repository, array $data, ?int $exceptId): ?string
    {
        if ($data['title'] === '') {
            return 'عنوان رتبه الزامی است.';
        }
        if ($data['min_level'] > $data['max_level']) {
            return 'سطح شروع نمی‌تواند بزرگ‌تر از سطح پایان باشد.';
        }
        if ($repository->overlaps($data['min_level'], $data['max_level'], $exceptId)) {
            return 'این بازه با یک رتبه دیگر هم‌پوشانی دارد.';
        }

        return null;
    }

    /** Character icons are public art, so they use the shared asset store. */
    private function storeSvg(Request $request): ?string
    {
        $file = $request->file('svg');

        if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null;
        }

        try {
            return ImageAssetStorage::storeUploaded($file, 'balin');
        } catch (\Throwable $e) {
            $this->flash('error', 'آپلود عکس شخصیت ناموفق بود: ' . $e->getMessage());
            return null;
        }
    }
}
