<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Logowanie — MCS</title>
    <style>
        :root {
            --tlo: #e8eef4;
            --panel: #f7fafc;
            --akcent: #1f4e79;
            --blad: #8b1e1e;
            --tekst: #1a2332;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Segoe UI", "Liberation Sans", sans-serif;
            color: var(--tekst);
            background:
                radial-gradient(ellipse at 20% 0%, #c5d8ea 0%, transparent 55%),
                linear-gradient(160deg, var(--tlo), #d5dde6);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }
        main {
            width: min(24rem, 100%);
            background: var(--panel);
            border: 1px solid #b8c5d4;
            padding: 1.75rem 1.5rem;
        }
        h1 {
            margin: 0 0 0.35rem;
            font-size: 1.35rem;
            color: var(--akcent);
            letter-spacing: 0.02em;
        }
        .podpis {
            margin: 0 0 1.25rem;
            font-size: 0.9rem;
            color: #4a5a6a;
        }
        label {
            display: block;
            margin: 0.75rem 0 0.25rem;
            font-size: 0.85rem;
            font-weight: 600;
        }
        input[type="text"],
        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 0.55rem 0.65rem;
            border: 1px solid #9aabbc;
            background: #fff;
            font: inherit;
        }
        button {
            margin-top: 1.25rem;
            width: 100%;
            padding: 0.65rem;
            border: 0;
            background: var(--akcent);
            color: #fff;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
        }
        button:hover { filter: brightness(1.08); }
        .blad {
            margin: 0 0 1rem;
            padding: 0.55rem 0.7rem;
            background: #f8e8e8;
            border: 1px solid #d9a0a0;
            color: var(--blad);
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
<main>
    <h1>Moja Czysta Sieć</h1>
    <p class="podpis">Zaloguj się, aby otworzyć przegląd zagrożeń, raporty IP i panel wtyczek.</p>

    <?php if (! empty($blad)): ?>
        <p class="blad"><?= esc($blad) ?></p>
    <?php endif; ?>

    <form method="post" action="<?= site_url('login') ?>" autocomplete="on">
        <?= csrf_field() ?>
        <label for="email">E-mail</label>
        <input id="email" type="text" name="email" inputmode="email" autocomplete="username" required autofocus>

        <label for="haslo">Hasło</label>
        <input id="haslo" type="password" name="haslo" autocomplete="current-password" required>

        <button type="submit">Zaloguj</button>
    </form>
</main>
</body>
</html>
