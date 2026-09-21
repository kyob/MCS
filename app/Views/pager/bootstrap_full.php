<?php

use CodeIgniter\Pager\PagerRenderer;

/**
 * Bootstrap 5.3 full pagination.
 *
 * @var PagerRenderer $pager
 */
$pager->setSurroundCount(2);
?>

<nav aria-label="<?= esc(lang('Pager.pageNavigation'), 'attr') ?>">
    <ul class="pagination mb-0">
        <?php if ($pager->hasPreviousPage()) : ?>
            <li class="page-item">
                <a class="page-link" href="<?= $pager->getFirst() ?>" aria-label="<?= esc(lang('Pager.first'), 'attr') ?>">
                    <i class="bi bi-chevron-double-left" aria-hidden="true"></i>
                </a>
            </li>
            <li class="page-item">
                <a class="page-link" href="<?= $pager->getPreviousPage() ?>" aria-label="<?= esc(lang('Pager.previous'), 'attr') ?>">
                    <i class="bi bi-chevron-left" aria-hidden="true"></i>
                </a>
            </li>
        <?php else : ?>
            <li class="page-item disabled" aria-disabled="true">
                <span class="page-link">
                    <i class="bi bi-chevron-double-left" aria-hidden="true"></i>
                    <span class="visually-hidden"><?= esc(lang('Pager.first')) ?></span>
                </span>
            </li>
            <li class="page-item disabled" aria-disabled="true">
                <span class="page-link">
                    <i class="bi bi-chevron-left" aria-hidden="true"></i>
                    <span class="visually-hidden"><?= esc(lang('Pager.previous')) ?></span>
                </span>
            </li>
        <?php endif ?>

        <?php foreach ($pager->links() as $link) : ?>
            <li class="page-item<?= $link['active'] ? ' active' : '' ?>"<?= $link['active'] ? ' aria-current="page"' : '' ?>>
                <a class="page-link" href="<?= $link['uri'] ?>">
                    <?= $link['title'] ?>
                </a>
            </li>
        <?php endforeach ?>

        <?php if ($pager->hasNextPage()) : ?>
            <li class="page-item">
                <a class="page-link" href="<?= $pager->getNextPage() ?>" aria-label="<?= esc(lang('Pager.next'), 'attr') ?>">
                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                </a>
            </li>
            <li class="page-item">
                <a class="page-link" href="<?= $pager->getLast() ?>" aria-label="<?= esc(lang('Pager.last'), 'attr') ?>">
                    <i class="bi bi-chevron-double-right" aria-hidden="true"></i>
                </a>
            </li>
        <?php else : ?>
            <li class="page-item disabled" aria-disabled="true">
                <span class="page-link">
                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                    <span class="visually-hidden"><?= esc(lang('Pager.next')) ?></span>
                </span>
            </li>
            <li class="page-item disabled" aria-disabled="true">
                <span class="page-link">
                    <i class="bi bi-chevron-double-right" aria-hidden="true"></i>
                    <span class="visually-hidden"><?= esc(lang('Pager.last')) ?></span>
                </span>
            </li>
        <?php endif ?>
    </ul>
</nav>
