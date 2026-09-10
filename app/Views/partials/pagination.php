<?php /** @var \HeleXa\Core\Paginator $paginator */ ?>
<?php if ($paginator->lastPage > 1): ?>
<nav class="pager">
    <?php if ($paginator->hasPrevious()): ?>
        <a class="pager-item" href="<?= e($paginator->url($paginator->currentPage - 1)) ?>">قبلی</a>
    <?php endif; ?>
    <?php foreach ($paginator->window() as $page): ?>
        <a class="pager-item<?= $page === $paginator->currentPage ? ' is-current' : '' ?>"
           href="<?= e($paginator->url($page)) ?>"><?= e(fa((string) $page)) ?></a>
    <?php endforeach; ?>
    <?php if ($paginator->hasNext()): ?>
        <a class="pager-item" href="<?= e($paginator->url($paginator->currentPage + 1)) ?>">بعدی</a>
    <?php endif; ?>
    <span class="pager-total"><?= e(fa((string) $paginator->total)) ?> رکورد</span>
</nav>
<?php endif; ?>
