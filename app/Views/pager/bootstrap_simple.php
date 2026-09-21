<?php

use CodeIgniter\Pager\PagerRenderer;

/**
 * Bootstrap 5.3 simple (prev/next) pagination.
 *
 * @var PagerRenderer $pager
 */
$pager->setSurroundCount(0);
?>

<nav aria-label="<?= esc(lang('Pager.pageNavigation'), 'attr') ?>">
    <ul class="pagination mb-0">
        <?php if ($pager->hasPreviousPage()) : ?>
            <li class="page-item">
                <a class="page-link" href="<?= $pager->getPreviousPage() ?>" aria-label="<?= esc(lang('Pager.previous'), 'attr') ?>">
                    <i class="bi bi-chevron-left" aria-hidden="true"></i>
                    <span class="d-none d-sm-inline"><?= esc(lang('Pager.previous')) ?></span>
                </a>
            </li>
        <?php else : ?>
            <li class="page-item disabled" aria-disabled="true">
                <span class="page-link">
                    <i class="bi bi-chevron-left" aria-hidden="true"></i>
                    <span class="d-none d-sm-inline"><?= esc(lang('Pager.previous')) ?></span>
                </span>
            </li>
        <?php endif ?>

        <?php if ($pager->hasNextPage()) : ?>
            <li class="page-item">
                <a class="page-link" href="<?= $pager->getNextPage() ?>" aria-label="<?= esc(lang('Pager.next'), 'attr') ?>">
                    <span class="d-none d-sm-inline"><?= esc(lang('Pager.next')) ?></span>
                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                </a>
            </li>
        <?php else : ?>
            <li class="page-item disabled" aria-disabled="true">
                <span class="page-link">
                    <span class="d-none d-sm-inline"><?= esc(lang('Pager.next')) ?></span>
                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                </span>
            </li>
        <?php endif ?>
    </ul>
</nav>
