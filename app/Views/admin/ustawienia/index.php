<?= $this->extend('layouts/mcs') ?>
<?= $this->section('content') ?>

<main class="panel">
    <h2>Ustawienia</h2>
    <p class="podpis">
        Konfiguracja matchowania adresów IP do przestrzeni AS<?= (int) $asAsn ?>
        (Alfa-System — „nasze IP”). Prefiksy CIDR porównujemy z podmiotami w przeglądzie i na stronie IP.
    </p>

    <?php if (! empty($blad)): ?>
        <p class="blad"><?= esc($blad) ?></p>
    <?php endif; ?>
    <?php if (! empty($komunikat)): ?>
        <p class="ok"><?= esc($komunikat) ?></p>
    <?php endif; ?>

    <h3>AS<?= (int) $asAsn ?> — nasze adresy</h3>
    <p class="podpis" style="margin-top:0">
        Gdy matchowanie jest <strong>włączone</strong>, MCS oznacza IP leżące w poniższych sieciach
        jako <span class="badge-nasze">nasze IP</span> oraz pozwala filtrować przegląd
        (tylko nasze / tylko obce). Wyłączenie nie usuwa listy prefiksów — tylko przestaje oznaczać i filtrować.
    </p>

    <form method="post" action="<?= site_url('admin/ustawienia') ?>" class="ustawienia-form">
        <?= csrf_field() ?>

        <div class="przełączniki" style="margin-bottom:1rem">
            <label>
                <input type="checkbox" name="as198050_wlaczone" value="1"
                    <?= ! empty($asWlaczone) ? 'checked' : '' ?>>
                Włącz matchowanie do AS<?= (int) $asAsn ?>
            </label>
        </div>

        <label for="as198050_cidry" style="display:block;font-size:0.8rem;color:var(--meta);margin-bottom:0.25rem">
            Prefiksy CIDR (jeden na linię; dozwolone też przecinki/spacje)
        </label>
        <textarea id="as198050_cidry" name="as198050_cidry" rows="12" class="ustawienia-textarea"
                  spellcheck="false"
                  placeholder="np.&#10;91.231.80.0/22&#10;176.97.24.0/21&#10;2a0f:1700::/29"><?= esc($asCidryTekst) ?></textarea>

        <p class="podpis">
            ASN: <code>AS<?= (int) $asAsn ?></code>.
            Lista startowa pochodzi z ogłoszeń RIPE (announced-prefixes) — aktualizuj ręcznie,
            gdy operator zmieni routing. Pusta lista + włączone matchowanie = żadne IP nie jest „nasze”.
        </p>

        <div class="ustawienia-akcje">
            <button type="submit">Zapisz ustawienia</button>
        </div>
    </form>

    <form method="post" action="<?= site_url('admin/ustawienia/przywroc-domyslne') ?>"
          style="margin-top:0.75rem"
          onsubmit="return confirm('Przywrócić domyślną listę prefiksów AS<?= (int) $asAsn ?>? Obecna lista w bazie zostanie nadpisana.');">
        <?= csrf_field() ?>
        <button type="submit" class="reset" style="cursor:pointer">Przywróć domyślne prefiksy RIPE</button>
    </form>

    <dl class="legenda">
        <dt>Co robi matchowanie?</dt>
        <dd>
            Porównuje adres IP incydentu z listą CIDR. Trafienie = „nasze IP” (AS<?= (int) $asAsn ?>).
            Nie pobiera live BGP — tylko lokalna lista z tych ustawień.
        </dd>
        <dt>Gdzie widać efekt?</dt>
        <dd>
            Przegląd: kolumna/etykieta przy IP oraz filtr „Sieć”. Strona IP: komunikat, czy adres jest w AS<?= (int) $asAsn ?>.
        </dd>
        <dt>Domyślne prefiksy (seed)</dt>
        <dd>
            <code><?= esc(implode(', ', $asDomyslneCidry)) ?></code>
        </dd>
    </dl>
</main>

<style>
    .ustawienia-textarea {
        width: 100%;
        max-width: 36rem;
        font-family: ui-monospace, "Cascadia Code", "Consolas", monospace;
        font-size: 0.85rem;
        line-height: 1.45;
        padding: 0.55rem 0.65rem;
        border: 1px solid var(--kreska);
        background: #fff;
        color: var(--tekst);
        resize: vertical;
    }
    .ustawienia-akcje {
        margin-top: 0.75rem;
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
        align-items: center;
    }
    .badge-nasze {
        display: inline-block;
        margin-left: 0.35rem;
        padding: 0.05rem 0.4rem;
        font-size: 0.72rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        border: 1px solid #7a9ab8;
        background: #dce8f4;
        color: var(--akcent);
        vertical-align: middle;
    }
</style>

<?= $this->endSection() ?>
