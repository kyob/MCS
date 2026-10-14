<!DOCTYPE html>
<html lang="pl" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Logowanie — MCS</title>
    <script>
        (function () {
            try {
                var t = localStorage.getItem('mcs-theme');
                if (t === 'light' || t === 'dark') {
                    document.documentElement.setAttribute('data-bs-theme', t);
                } else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                    document.documentElement.setAttribute('data-bs-theme', 'dark');
                }
            } catch (e) { /* ignore */ }
        })();
    </script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
          crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet"
          integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+"
          crossorigin="anonymous">
</head>
<body class="bg-body-tertiary d-flex align-items-center min-vh-100 py-4">
<main class="container" style="max-width: 26rem">
    <div class="text-center mb-4">
        <div class="d-inline-flex align-items-center justify-content-center rounded-3 bg-primary-subtle text-primary p-3 mb-3">
            <i class="bi bi-shield-lock-fill fs-2" aria-hidden="true"></i>
        </div>
        <h1 class="h3 fw-semibold mb-1">MCS</h1>
        <p class="text-body-secondary mb-0">Moja Czysta Sieć</p>
    </div>

    <div class="card border-0 shadow-sm rounded-3">
        <div class="card-body p-4">
            <p class="text-body-secondary mb-4">
                Zaloguj się, aby otworzyć przegląd zagrożeń, raporty IP i panel wtyczek.
            </p>

            <?php if (! empty($blad)): ?>
                <div class="alert alert-danger border-0 rounded-3" role="alert"><?= esc($blad) ?></div>
            <?php endif; ?>

            <form method="post" action="<?= site_url('login') ?>" autocomplete="on">
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label" for="email">E-mail</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-envelope" aria-hidden="true"></i></span>
                        <input class="form-control" id="email" type="text" name="email" inputmode="email"
                               autocomplete="username" required autofocus>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label" for="haslo">Hasło</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-key" aria-hidden="true"></i></span>
                        <input class="form-control" id="haslo" type="password" name="haslo"
                               autocomplete="current-password" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-box-arrow-in-right me-1" aria-hidden="true"></i>Zaloguj
                </button>
            </form>
        </div>
    </div>

    <p class="text-center small text-body-secondary mt-4 mb-0">
        Monitoring incydentów i watchlista IP
    </p>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
        crossorigin="anonymous"></script>
</body>
</html>
