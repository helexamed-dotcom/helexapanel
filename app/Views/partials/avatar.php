<?php
/**
 * Profile picture, or a default drawn from the person's gender when they have
 * not uploaded one. Kept abstract on purpose: a flat silhouette in the brand
 * palette reads as a placeholder, while a cartoon face reads as a decision
 * somebody made about how this person looks.
 *
 * @var array  $person  user row
 * @var string $size    'sm' | 'lg'
 */
$gender = $person['gender'] ?? null;
$defaultImage = $gender === 'male'
    ? \HeleXa\Services\Settings::get('avatar_male_path', '')
    : ($gender === 'female' ? \HeleXa\Services\Settings::get('avatar_female_path', '') : '');
$accent = $gender === 'female' ? '#7c6cf3' : ($gender === 'male' ? '#2563eb' : '#0d9488');
?>
<?php if (!empty($person['avatar_path'])): ?>
    <img src="/account/avatar/<?= e($person['uuid']) ?>" alt="" loading="lazy">
<?php elseif ($defaultImage !== ''): ?>
    <img src="/assets/<?= e($defaultImage) ?>" alt="" loading="lazy">
<?php else: ?>
    <svg class="avatar-default" viewBox="0 0 48 48" role="img" aria-label="تصویر پیش‌فرض پروفایل">
        <circle cx="24" cy="24" r="24" fill="<?= e($accent) ?>" opacity=".12"/>
        <?php if ($gender === 'female'): ?>
            <path d="M14 22c0-6 4.4-10 10-10s10 4 10 10v3c0 1.2-.9 2-2 2h-1v-5c-2.6 1-5.2 1.5-7 1.5S18.6 22.5 16 21.5V27h-1c-1.1 0-2-.8-2-2v-3z"
                  fill="<?= e($accent) ?>" opacity=".85"/>
            <circle cx="24" cy="22" r="6.2" fill="<?= e($accent) ?>"/>
            <path d="M11 40c1.9-6.1 6.7-9.2 13-9.2s11.1 3.1 13 9.2z" fill="<?= e($accent) ?>"/>
        <?php else: ?>
            <path d="M15 21c0-5.2 4-9 9-9s9 3.8 9 9v1.5h-2.2c-2.1-2.6-4.2-3.9-6.8-3.9s-4.7 1.3-6.8 3.9H15V21z"
                  fill="<?= e($accent) ?>" opacity=".85"/>
            <circle cx="24" cy="22" r="6.2" fill="<?= e($accent) ?>"/>
            <path d="M11 40c1.9-6.1 6.7-9.2 13-9.2s11.1 3.1 13 9.2z" fill="<?= e($accent) ?>"/>
        <?php endif; ?>
    </svg>
<?php endif; ?>
