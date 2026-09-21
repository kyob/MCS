<?= $this->extend('layouts/mcs') ?>
<?= $this->section('content') ?>

<div class="card border-0 shadow-sm rounded-3">
    <div class="card-body py-5 text-center">
        <h1 class="h3 mb-2">Brak uprawnień</h1>
        <p class="text-body-secondary mb-4">
            Ta strona wymaga innej roli niż Twoje konto.
        </p>
        <a class="btn btn-primary" href="<?= site_url('/') ?>">Wróć do przeglądu</a>
    </div>
</div>

<?= $this->endSection() ?>
