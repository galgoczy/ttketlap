<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
require __DIR__ . '/../inc/email_template.php';
require_admin();

/**
 * Heti etlap level osszeallitasa.
 *
 * Kitoltod a napokat, es keszen kapod a levelet: elonezetben megnezheted,
 * kaphatsz belole egy teszt peldanyt, es a kesz HTML-t ki tudod masolni a
 * korlevelbe. A kikuldes tovabbra is kezzel tortenik.
 */

const NAPOK = ['Hétfő', 'Kedd', 'Szerda', 'Csütörtök', 'Péntek'];

$napok    = [];
$hetCim   = '';
$bevezeto = '';
$uzenet   = '';

// Alapertelmezes: a kovetkezo het.
$kovetkezoHetfo = new DateTimeImmutable('next monday');
$kovetkezoPentek = $kovetkezoHetfo->modify('+4 days');
$alapCim = sprintf(
    '%s. hét (%s – %s)',
    $kovetkezoHetfo->format('W'),
    $kovetkezoHetfo->format('m. d.'),
    $kovetkezoPentek->format('m. d.')
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        $uzenet = 'error:Az űrlap érvényessége lejárt, próbálja újra.';
    } else {
        $hetCim   = trim((string) ($_POST['het_cim'] ?? ''));
        $bevezeto = trim((string) ($_POST['bevezeto'] ?? ''));
        foreach (NAPOK as $i => $nap) {
            $napok[$nap] = trim((string) ($_POST['nap'][$i] ?? ''));
        }

    }
}

if ($hetCim === '') {
    $hetCim = $alapCim;
}
if (!$napok) {
    $napok = array_fill_keys(NAPOK, '');
}

function etlap_targy(string $hetCim): string
{
    return 'Heti étlap – ' . ($hetCim !== '' ? $hetCim : 'aktuális hét');
}

function etlap_level_html(string $hetCim, string $bevezeto, array $napok, string $leiratkozoUrl): string
{
    $torzs = '';
    if ($bevezeto !== '') {
        $torzs .= email_bekezdes(nl2br(e($bevezeto)));
    }
    $torzs .= email_etlap($napok);
    $torzs .= email_bekezdes('Jó étvágyat kívánunk!', true);

    return email_keret(
        $hetCim !== '' ? 'Heti étlap – ' . e($hetCim) : 'Heti étlap',
        $torzs,
        'Ezen a héten ez lesz az ebéd.',
        $leiratkozoUrl
    );
}

/** Egyszeru szoveges valtozat azoknak, akik nem HTML-ben olvasnak. */
function etlap_level_szoveg(string $hetCim, string $bevezeto, array $napok, string $leiratkozoUrl): string
{
    $sorok = ['Heti étlap' . ($hetCim !== '' ? ' – ' . $hetCim : ''), ''];
    if ($bevezeto !== '') {
        $sorok[] = $bevezeto;
        $sorok[] = '';
    }
    foreach ($napok as $nap => $fogas) {
        if (trim($fogas) !== '') {
            $sorok[] = mb_strtoupper($nap);
            $sorok[] = $fogas;
            $sorok[] = '';
        }
    }
    $sorok[] = 'Jó étvágyat kívánunk!';
    $sorok[] = '';
    $sorok[] = '--';
    $sorok[] = 'Ezt a levelet azért kapja, mert feliratkozott a heti étlapra.';
    $sorok[] = 'Leiratkozás: ' . $leiratkozoUrl;

    return implode("\n", $sorok);
}

// A kimasolhato HTML-ben a leiratkozo linket helykitoltovel adjuk meg,
// hogy a korlevel minden cimzettnek a sajatjat helyettesitse be.
$masolhatoHtml = etlap_level_html($hetCim, $bevezeto, $napok, '{{leiratkozo_link}}');

// Az elonezetet kulon oldal jeleniti meg (lasd etlap-elonezet.php), ezert
// a kesz HTML-t a munkamenetbe tesszuk - igy nem kell URL-ben atadni.
$_SESSION['etlap_elonezet'] = etlap_level_html($hetCim, $bevezeto, $napok, '#');
$vanTartalom   = array_filter($napok, fn($v) => trim($v) !== '') !== [];

render_header('Heti étlap levél', true, true);
?>

<div class="admin">
    <p class="eyebrow">Admin</p>
    <h1 class="title">Heti étlap levél</h1>
    <p class="subtitle">
        Töltse ki a napokat, és a kész levelet alul rögtön látja. A HTML-t
        kimásolhatja a levelezőprogramjába. A kiküldés nem innen történik.
    </p>

    <?php if ($uzenet !== ''): ?>
        <?php [$tipus, $szoveg] = explode(':', $uzenet, 2); ?>
        <div class="alert alert--<?= $tipus === 'success' ? 'success' : 'error' ?>" role="alert">
            <span class="alert__icon" aria-hidden="true"><?= $tipus === 'success' ? '&#10003;' : '!' ?></span>
            <span><?= e($szoveg) ?></span>
        </div>
    <?php endif; ?>

    <form method="post" action="/admin/etlap.php">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

        <div class="card">
            <div class="field">
                <label class="label" for="het_cim">A hét megjelölése</label>
                <input class="input" type="text" id="het_cim" name="het_cim" value="<?= e($hetCim) ?>">
            </div>

            <div class="field">
                <label class="label" for="bevezeto">Bevezető (nem kötelező)</label>
                <input class="input" type="text" id="bevezeto" name="bevezeto"
                       value="<?= e($bevezeto) ?>" placeholder="pl. Ezen a héten szezonális alapanyagokkal dolgozunk.">
            </div>

            <?php foreach (NAPOK as $i => $nap): ?>
                <div class="field">
                    <label class="label" for="nap<?= $i ?>"><?= e($nap) ?></label>
                    <textarea class="input" id="nap<?= $i ?>" name="nap[<?= $i ?>]" rows="2"
                              placeholder="Leves&#10;Főétel"><?= e($napok[$nap] ?? '') ?></textarea>
                </div>
            <?php endforeach; ?>

            <div class="toolbar m-0">
                <button class="btn btn--primary btn--small btn--inline" type="submit">Előnézet frissítése</button>
            </div>
        </div>
    </form>

    <?php if ($vanTartalom): ?>
        <h2 class="title section-title">Így fog kinézni</h2>
        <p class="subtitle">
            A levelezőprogramok kissé eltérően jelenítik meg – ezért érdemes a
            teszt levelet is megnézni a saját postafiókjában.
        </p>
        <div class="table-wrap table-wrap--flush">
            <iframe title="A levél előnézete" class="preview-frame"
                    src="/admin/etlap-elonezet.php"></iframe>
        </div>

        <h2 class="title section-title">A kész HTML</h2>
        <p class="subtitle">
            Ezt másolja a körlevélbe. A <code>{{leiratkozo_link}}</code> helyére a
            körlevél minden címzettnél a saját linkjét teszi be – ez a CSV
            <code>leiratkozo_link</code> oszlopa.
        </p>
        <div class="field">
            <textarea class="input code-area" id="html" rows="10" readonly data-select-on-click><?= e($masolhatoHtml) ?></textarea>
        </div>
        <button class="btn btn--primary btn--small btn--inline" type="button" data-copy-target="html">
            HTML másolása
        </button>
        <p class="subtitle text-xs">
            Tárgynak ezt javasoljuk: <strong><?= e(etlap_targy($hetCim)) ?></strong>
        </p>
    <?php endif; ?>

    <div class="toolbar mt-6">
        <a class="btn btn--secondary btn--small" href="/admin/">Vissza a feliratkozókhoz</a>
        <a class="btn btn--secondary btn--small" href="/admin/cimlista.php">Címlista másolása</a>
        <a class="btn btn--secondary btn--small" href="/admin/export.php?tipus=aktiv">Aktívak letöltése (CSV)</a>
    </div>
</div>

<script src="/assets/js/admin.js?v=1" defer></script>
<?php render_footer(); ?>
