<?php
/**
 * Playing a stage: the clinical scene, block by block.
 *
 * A question that has already been answered is rendered here in its resolved
 * state — the chosen option, the right one, and the explanation. The answer
 * key for an unanswered question is never in this page; it arrives only in
 * the response to submitting one.
 *
 * @var array $lesson
 * @var array $stage
 * @var array $blocks
 * @var bool  $replay
 * @var bool  $requirements
 */
$mediaUrl = static fn (string $uuid): string => '/student/balin/media/' . $uuid;
?>
<div class="balin balin-stage" data-stage="<?= e($stage['uuid']) ?>" data-csrf="<?= e($csrf_token) ?>"
     data-replay="<?= $replay ? '1' : '0' ?>">
    <nav class="balin-crumb" aria-label="مسیر">
        <a href="/student/balin">جزیره بالین</a>
        <span aria-hidden="true">›</span>
        <a href="/student/balin/lesson/<?= e($lesson['uuid']) ?>"><?= e($lesson['title']) ?></a>
        <span aria-hidden="true">›</span>
        <span><?= e($stage['title']) ?></span>
    </nav>

    <header class="balin-stage-head">
        <h2><?= e($stage['title']) ?></h2>
        <?php if ($stage['subtitle']): ?><p><?= e($stage['subtitle']) ?></p><?php endif; ?>
        <?php if ($replay): ?>
            <div class="balin-replay-note">
                این مرحله را قبلاً کامل کرده‌ای. می‌توانی دوباره مرورش کنی؛ امتیاز جدیدی ثبت نمی‌شود.
            </div>
        <?php endif; ?>
    </header>

    <?php
    /**
     * Every block is rendered. With JavaScript the scene is revealed one
     * block at a time from a button, which is what makes it read as a
     * conversation; without it the whole scene is simply there to read.
     */
    ?>
    <div class="balin-scene" data-scene>
        <?php foreach ($blocks as $block):
            $type = $block['block_type'];
            $side = $block['side_override'] ?: ($block['character_side'] ?: 'left');
        ?>

        <?php if ($type === 'chat'): ?>
            <div class="balin-bubble side-<?= e($side) ?>">
                <div class="balin-avatar" aria-hidden="true">
                    <?php if ($block['character_svg']): ?>
                        <img src="/assets/<?= e($block['character_svg']) ?>" alt="">
                    <?php else: ?>
                        <span><?= e($block['character_icon'] ?: '🧑') ?></span>
                    <?php endif; ?>
                </div>
                <div class="balin-bubble-body"
                     <?= $block['character_color'] ? 'style="--bubble-accent:' . e($block['character_color']) . '"' : '' ?>>
                    <div class="balin-bubble-name"><?= e($block['character_name'] ?: 'ناشناس') ?></div>
                    <div class="balin-bubble-text"><?= nl2br(e($block['body'])) ?></div>
                </div>
            </div>

        <?php elseif ($type === 'text'): ?>
            <div class="balin-note"><?= nl2br(e($block['body'])) ?></div>

        <?php elseif ($type === 'finding'): ?>
            <div class="balin-callout is-finding">
                <div class="balin-callout-label">یافته بالینی</div>
                <div><?= nl2br(e($block['body'])) ?></div>
            </div>

        <?php elseif ($type === 'hint'): ?>
            <div class="balin-callout is-hint">
                <div class="balin-callout-label">راهنما</div>
                <div><?= nl2br(e($block['body'])) ?></div>
            </div>

        <?php elseif ($type === 'warning'): ?>
            <div class="balin-callout is-warning">
                <div class="balin-callout-label">هشدار</div>
                <div><?= nl2br(e($block['body'])) ?></div>
            </div>

        <?php elseif ($type === 'system'): ?>
            <div class="balin-system"><?= nl2br(e($block['body'])) ?></div>

        <?php elseif ($type === 'divider'): ?>
            <hr class="balin-divider">

        <?php elseif ($type === 'image' && $block['media_uuid']): ?>
            <figure class="balin-media align-<?= e($block['media_position'] ?: 'center') ?>"
                    style="--media-width:<?= (int) ($block['media_width'] ?: 100) ?>%">
                <img src="<?= e($mediaUrl((string) $block['media_uuid'])) ?>"
                     alt="<?= e($block['media_alt'] ?? '') ?>" loading="lazy">
                <?php if ($block['media_caption']): ?>
                    <figcaption><?= e($block['media_caption']) ?></figcaption>
                <?php endif; ?>
            </figure>

        <?php elseif ($type === 'audio' && $block['media_uuid']): ?>
            <div class="balin-media is-audio">
                <audio controls preload="metadata"
                       <?= (int) $block['media_download'] === 1 ? '' : 'controlsList="nodownload"' ?>
                       src="<?= e($mediaUrl((string) $block['media_uuid'])) ?>"></audio>
                <?php if ($block['media_caption']): ?>
                    <div class="balin-media-caption"><?= e($block['media_caption']) ?></div>
                <?php endif; ?>
                <?php if ($block['media_transcript']): ?>
                    <details class="balin-transcript">
                        <summary>متن گفتار</summary>
                        <div><?= nl2br(e($block['media_transcript'])) ?></div>
                    </details>
                <?php endif; ?>
            </div>

        <?php elseif ($type === 'video' && $block['media_uuid']): ?>
            <div class="balin-media is-video" style="--media-width:<?= (int) ($block['media_width'] ?: 100) ?>%">
                <video controls playsinline preload="metadata"
                       <?= (int) $block['media_download'] === 1 ? '' : 'controlsList="nodownload"' ?>
                       src="<?= e($mediaUrl((string) $block['media_uuid'])) ?>"></video>
                <?php if ($block['media_caption']): ?>
                    <div class="balin-media-caption"><?= e($block['media_caption']) ?></div>
                <?php endif; ?>
            </div>

        <?php elseif ($type === 'checkpoint_anchor' && $block['exam_uuid']): ?>
            <a class="balin-exam-node" href="/student/balin/exam/<?= e($block['exam_uuid']) ?>">
                <span class="balin-exam-mark" aria-hidden="true">🎯</span>
                <span class="balin-exam-body">
                    <span class="balin-exam-title"><?= e($block['exam_title']) ?></span>
                    <span class="balin-exam-meta">آزمون مهارتی</span>
                </span>
            </a>

        <?php elseif ($type === 'question' && $block['question_id']):
            $answered = !empty($block['answered']);
            $answer   = $block['answer'] ?? null;
            $chosen   = $answered ? (int) ($answer['option_id'] ?? 0) : 0;
        ?>
            <section class="balin-question<?= $answered ? ' is-answered' : '' ?>"
                     data-question="<?= (int) $block['question_id'] ?>"
                     aria-labelledby="q-<?= (int) $block['question_id'] ?>">
                <div class="balin-question-head">
                    <span class="balin-question-tag">سؤال</span>
                    <?php if ($block['question_difficulty']): ?>
                        <span class="balin-chip chip-<?= e($block['question_difficulty']) ?>">
                            <?= e(['easy' => 'آسان', 'medium' => 'متوسط', 'hard' => 'دشوار',
                                   'expert' => 'تخصصی'][$block['question_difficulty']] ?? '') ?>
                        </span>
                    <?php endif; ?>
                </div>

                <h3 class="balin-question-prompt" id="q-<?= (int) $block['question_id'] ?>">
                    <?= nl2br(e($block['question_prompt'])) ?>
                </h3>

                <?php if (!$answered && $block['question_hint']): ?>
                    <div class="balin-hint-box">
                        <button type="button" class="btn btn-ghost btn-sm js-balin-hint">
                            نمایش راهنما (امتیاز این سؤال کم می‌شود)
                        </button>
                        <div class="balin-hint-text" hidden><?= nl2br(e($block['question_hint'])) ?></div>
                    </div>
                <?php endif; ?>

                <ul class="balin-options" role="list">
                    <?php foreach ($block['options'] as $option):
                        $isChosen = $answered && $chosen === (int) $option['id'];
                    ?>
                        <li>
                            <button type="button"
                                    class="balin-option<?= $isChosen ? ' is-chosen' : '' ?>"
                                    data-option="<?= (int) $option['id'] ?>"
                                    <?= $answered ? 'disabled' : '' ?>>
                                <span class="balin-option-label"><?= e($option['label']) ?></span>
                                <span class="balin-option-body"><?= e($option['body']) ?></span>
                            </button>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <div class="balin-feedback" <?= $answered ? '' : 'hidden' ?>>
                    <?php if ($answered): ?>
                        <div class="balin-feedback-line <?= (int) $answer['is_correct'] === 1 ? 'is-correct' : 'is-wrong' ?>">
                            <?php if ((int) $answer['is_correct'] === 1): ?>
                                ✅ پاسخ درست بود
                                <?php if ((int) $answer['xp_awarded'] > 0): ?>
                                    · <?= e(fa((int) $answer['xp_awarded'])) ?>+ امتیاز
                                <?php endif; ?>
                            <?php else: ?>
                                ❌ پاسخ صحیح نبود
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <div class="balin-explanation"></div>
                </div>
            </section>
        <?php endif; ?>

        <?php endforeach; ?>
    </div>

    <?php
    /**
     * The scene is collapsed here, in the same breath it was parsed, rather
     * than by the player further down the body: a script tag down there runs
     * after the browser has had its chance to paint, and a case that flashes
     * whole before collapsing is the very thing the player exists to stop.
     *
     * Rendering the blocks hidden server-side is not the answer either —
     * without JavaScript the scene has to be readable, and this script only
     * runs when there is JavaScript to run it. If the player never arrives,
     * the timer puts the scene back rather than leaving a student stranded
     * in front of an empty page.
     */
    ?>
    <script nonce="<?= e($cspNonce ?? '') ?>">
    (function () {
        var scene = document.querySelector('.balin-stage [data-scene]');
        if (!scene) { return; }

        var blocks = Array.prototype.slice.call(scene.children);
        blocks.forEach(function (el) { el.hidden = true; el.style.display = 'none'; });
        scene.setAttribute('data-stepping', 'armed');

        window.setTimeout(function () {
            if (scene.getAttribute('data-stepping') === 'ready') { return; }
            blocks.forEach(function (el) { el.hidden = false; el.style.display = ''; });
        }, 6000);
    }());
    </script>

    <?php /* Driven by the script; without JavaScript the scene is already whole. */ ?>
    <div class="balin-advance" data-advance hidden>
        <button type="button" class="btn btn-primary js-balin-next">
            <span data-advance-label>شروع چت</span>
        </button>
    </div>

    <?php /* Says why the conversation stopped, so a missing button is not a mystery. */ ?>
    <p class="balin-waiting" data-waiting hidden role="status">
        برای ادامه گفت‌وگو، اول به این سؤال پاسخ بده.
    </p>

    <footer class="balin-stage-foot">
        <?php if ($replay): ?>
            <a class="btn btn-ghost" href="/student/balin/lesson/<?= e($lesson['uuid']) ?>">بازگشت به نقشه</a>
        <?php else: ?>
            <form method="post" action="/student/balin/stage/<?= e($stage['uuid']) ?>/complete">
                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                <button class="btn btn-primary js-balin-finish" type="submit" <?= $requirements ? '' : 'disabled' ?>>
                    پایان مرحله
                </button>
            </form>
            <p class="balin-foot-note js-balin-remaining" <?= $requirements ? 'hidden' : '' ?>>
                برای پایان مرحله باید به همه سؤال‌های الزامی پاسخ بدهی.
            </p>
        <?php endif; ?>
    </footer>
</div>
