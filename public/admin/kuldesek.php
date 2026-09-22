<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
require __DIR__ . '/../inc/etlap_futar.php';
require_admin();

/**
 * Az etlapkikuldesek allapota. Itt latszik, mi tortent a bekuldott
 * PDF-ekkel, es innen is vissza lehet vonni egy meg varakozo kikuldest.
 */

$uzenet = '';
$hiba   = '';
$sorok  = [];
$utolsoFutas = null;

// Visszavonas az admin feluletrol.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        $uzenet = 'error:Az űrlap érvényessége lejárt, próbálja újra.';
    } else {
        try {
            $frissit = db()->prepare(
                'UPDATE etlap_kuldes SET status = "megszakitva", befejezve = NOW()
                  WHERE id = ? AND status = "elonezet"'
            );
            $kuldesId = (int) ($_POST['kuldes_id'] ?? 0);
            $frissit->execute([$kuldesId]);

            if ($frissit->rowCount() === 1) {
                $visszavont = kuldes_betolt($kuldesId);
                esemeny('figyelem', sprintf(
                    'Visszavonva az admin felületről: „%s". Nem ment ki senkinek.',
                    $visszavont['targy'] ?? '?'
                ), $kuldesId);
                $uzenet = 'success:A kiküldést visszavontuk, ez az étlap nem megy ki.';
            } else {
                $uzenet = 'error:Ezt a kiküldést már nem lehet visszavonni.';
            }
        } catch (Throwable $kivetel) {
            error_log('Kuldes visszavonas hiba: ' . $kivetel->getMessage());
            $uzenet = 'error:Technikai hiba történt.';
        }
    }
}

try {
    $sorok = db()->query(
        'SELECT id, felado, targy, pdf_nev, pdf_meret, status, kuldes_ideje,
                kikuldve, hibas, hiba_uzenet, letrehozva, befejezve
           FROM etlap_kuldes ORDER BY id DESC LIMIT 50'
    )->fetchAll();

    $utolsoFutas = allapot_olvas('futar_utolso_futas');
} catch (Throwable $kivetel) {
    error_log('Kuldesek lista hiba: ' . $kivetel->getMessage());
    $hiba = $kivetel->getMessage();
}

/** Az allapot emberi neve es szine. @return array{0:string,1:string} */
function allapot_cimke(string $status): array
{
    return match ($status) {
        'elonezet'    => ['Előnézet elment, vár', 'badge'],
        'kuldes'      => ['Kiküldés folyamatban', 'badge'],
        'kesz'        => ['Kiküldve', 'badge'],
        'megszakitva' => ['Visszavonva', 'badge'],
        default       => ['Hiba', 'badge badge--danger'],
    };
}

render_header('Étlap kiküldések', true, true);
?>

<div class="admin">
    <p class="eyebrow">Admin</p>
    <h1 class="title">Étlap kiküldések</h1>
    <p class="subtitle">
        Ide kerül minden beküldött étlap. A beküldő előbb előnézetet kap, és
        amíg a kiküldés el nem indul, vissza lehet vonni.
    </p>

    <?php if ($uzenet !== ''): ?>
        <?php [$tipus, $szoveg] = explode(':', $uzenet, 2); ?>
        <div class="alert alert--<?= $tipus === 'success' ? 'success' : 'error' ?>" role="alert">
            <span class="alert__icon" aria-hidden="true"><?= $tipus === 'success' ? '&#10003;' : '!' ?></span>
            <span><?= e($szoveg) ?></span>
        </div>
    <?php endif; ?>

    <?php if ($hiba !== ''): ?>
        <div class="alert alert--error" role="alert">
            <span class="alert__icon" aria-hidden="true">!</span>
            <span>
                <strong>Az adatbázis nem érhető el, vagy hiányzik az etlap_kuldes tábla.</strong>
                Nyissa meg a <a href="/admin/diagnosztika.php">diagnosztika oldalt</a>.
            </span>
        </div>
    <?php endif; ?>

    <div class="alert alert--error alert--note" role="note">
        <span class="alert__icon" aria-hidden="true">!</span>
        <span>
            <strong>Az automata utoljára ekkor futott:</strong>
            <?= $utolsoFutas !== null ? e($utolsoFutas) : 'még soha' ?>.
            Ha ez régebbi néhány percnél, az időzítő (cron) nem fut –
            addig a beküldött étlapok nem mennek ki.
        </span>
    </div>

    <?php if ($hiba !== ''): ?>
        <?php /* Adatbazishiba eseten nincs mit mutatni: a figyelmeztetes mar fent van. */ ?>
    <?php elseif (!$sorok): ?>
        <div class="card empty">Még nem érkezett beküldött étlap.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--wrap">
                <thead>
                    <tr>
                        <th>Beérkezett</th>
                        <th>Beküldő</th>
                        <th>Tárgy</th>
                        <th>Állapot</th>
                        <th class="table__col-narrow">Kiküldve</th>
                        <th class="table__col-narrow"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($sorok as $sor): ?>
                    <?php [$cimke, $osztaly] = allapot_cimke((string) $sor['status']); ?>
                    <tr>
                        <td><?= e(date('m. d. H:i', strtotime((string) $sor['letrehozva']))) ?></td>
                        <td><?= e((string) $sor['felado']) ?></td>
                        <td>
                            <?= e((string) $sor['targy']) ?><br>
                            <span class="hint"><?= e((string) $sor['pdf_nev']) ?></span><br>
                            <a class="hint" href="/admin/naplo.php?kuldes=<?= (int) $sor['id'] ?>">mi történt vele?</a>
                        </td>
                        <td>
                            <span class="<?= $osztaly ?>"><?= e($cimke) ?></span>
                            <?php if ($sor['status'] === 'elonezet'): ?>
                                <br><span class="hint">
                                    indul: <?= e(date('H:i', strtotime((string) $sor['kuldes_ideje']))) ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($sor['hiba_uzenet'] !== null): ?>
                                <br><span class="hint"><?= e((string) $sor['hiba_uzenet']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= (int) $sor['kikuldve'] ?>
                            <?php if ((int) $sor['hibas'] > 0): ?>
                                <br><span class="hint"><?= (int) $sor['hibas'] ?> hibás</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($sor['status'] === 'elonezet'): ?>
                                <form method="post" action="/admin/kuldesek.php" class="inline-form">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="kuldes_id" value="<?= (int) $sor['id'] ?>">
                                    <button class="btn btn--secondary btn--small btn--inline" type="submit">
                                        Visszavonás
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <div class="toolbar mt-6">
        <a class="btn btn--secondary btn--small" href="/admin/">Vissza a feliratkozókhoz</a>
        <a class="btn btn--secondary btn--small" href="/admin/naplo.php">Napló</a>
        <a class="btn btn--secondary btn--small" href="/admin/diagnosztika.php">Diagnosztika</a>
    </div>
</div>

<?php render_footer(); ?>
