<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
require_admin();

/**
 * Az aktiv feliratkozok cimei egy sorban, hogy at lehessen masolni
 * a levelezoprogramba. Ket elvalasztoval, mert nem mindegyik program
 * ugyanazt varja.
 */

$cimek = [];
$hiba  = '';

try {
    $cimek = db()
        ->query('SELECT email FROM subscribers WHERE status = "active" ORDER BY email ASC')
        ->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $exception) {
    error_log('Címlista hiba: ' . $exception->getMessage());
    $hiba = $exception->getMessage();
}

$vesszovel    = implode(', ', $cimek);
$pontosvesszo = implode('; ', $cimek);

render_header('Címlista', true, true);
?>

<div class="admin">
    <p class="eyebrow">Admin</p>
    <h1 class="title">Címlista másolása</h1>
    <p class="subtitle">
        Az aktív feliratkozók címei egy sorban, hogy be lehessen illeszteni a
        levelezőprogramba. A leiratkozottak nincsenek benne.
    </p>

    <?php if ($hiba !== ''): ?>
        <div class="alert alert--error" role="alert">
            <span class="alert__icon" aria-hidden="true">!</span>
            <span>
                <strong>Az adatbázis nem érhető el.</strong>
                Nyissa meg a <a href="/admin/diagnosztika.php">diagnosztika oldalt</a>.
            </span>
        </div>
    <?php endif; ?>

    <div class="alert alert--error alert--note" role="note">
        <span class="alert__icon" aria-hidden="true">!</span>
        <span>
            <strong>A címeket titkos másolatba (BCC) illessze be</strong>, ne a
            címzett mezőbe. Ha a címzett mezőbe kerülnek, minden feliratkozó látja
            a többiek email-címét – ez adatvédelmi incidens, amit be kell jelenteni.
        </span>
    </div>

    <div class="card">
        <p class="eyebrow"><?= count($cimek) ?> aktív cím</p>

        <div class="field">
            <label class="label" for="vesszovel">Vesszővel elválasztva</label>
            <textarea class="input code-area" id="vesszovel" rows="5" readonly data-select-on-click><?= e($vesszovel) ?></textarea>
            <p class="hint">
                Gmail, Apple Mail, webes levelezők
            </p>
        </div>

        <button class="btn btn--primary btn--small btn--inline" type="button" data-copy-target="vesszovel">
            Vesszős lista másolása
        </button>

        <div class="field mt-6">
            <label class="label" for="pontosvesszo">Pontosvesszővel elválasztva</label>
            <textarea class="input code-area" id="pontosvesszo" rows="5" readonly data-select-on-click><?= e($pontosvesszo) ?></textarea>
            <p class="hint">
                Outlook asztali alkalmazás – az alapból pontosvesszőt vár
            </p>
        </div>

        <button class="btn btn--secondary btn--small btn--inline" type="button" data-copy-target="pontosvesszo">
            Pontosvesszős lista másolása
        </button>
    </div>

    <?php if (count($cimek) > 300): ?>
        <div class="alert alert--error mt-5">
            <span class="alert__icon" aria-hidden="true">!</span>
            <span>
                <strong><?= count($cimek) ?> címzett egy levélben sok lehet.</strong>
                Az Exchange Online egy levélben legfeljebb 500 címzettet enged, és a
                fogadó szerverek is gyanakodva néznek a nagy listákat. Érdemes több
                részletben küldeni.
            </span>
        </div>
    <?php endif; ?>

    <div class="toolbar mt-6">
        <a class="btn btn--secondary btn--small" href="/admin/">Vissza a feliratkozókhoz</a>
        <a class="btn btn--secondary btn--small" href="/admin/kuldesek.php">Étlap kiküldések</a>
        <a class="btn btn--secondary btn--small" href="/admin/export.php?tipus=aktiv">Teljes CSV letöltése</a>
    </div>
</div>

<script src="/assets/js/admin.js?v=1" defer></script>
<?php render_footer(); ?>
