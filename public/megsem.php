<?php
declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/naplo.php';

/**
 * A kikuldes visszavonasa. A linket az elonezet levele tartalmazza.
 *
 * Ugyanaz az elv, mint a leiratkozasnal: a visszavonas csak POST-ra
 * tortenik meg. Sok levelezorendszer eloretolti a levelekben levo
 * linkeket - ha a puszta megnyitas visszavonna a kikuldest, egy
 * spamszuro ellenorzese akaratlanul is leallitana az etlapot.
 */

$token   = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$kesz    = false;
$hiba    = '';
$kuldes  = null;

if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    $hiba = 'Érvénytelen vagy hiányzó link.';
} else {
    try {
        $stmt = db()->prepare(
            'SELECT id, targy, status, kuldes_ideje, kikuldve FROM etlap_kuldes WHERE token = ?'
        );
        $stmt->execute([$token]);
        $kuldes = $stmt->fetch();

        if ($kuldes === false) {
            $hiba = 'Ez a link nem érvényes.';
            $kuldes = null;
        } elseif ($kuldes['status'] === 'megszakitva') {
            $kesz = true;
        } elseif ($kuldes['status'] !== 'elonezet') {
            // A kikuldes mar elindult vagy befejezodott - nincs mit visszavonni.
            $hiba = 'A kiküldés már elindult, ezért nem lehet visszavonni.';
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // A feltetel a WHERE-ben is ott van: ha ugyanebben a pillanatban
            // indult volna el a kikuldes, akkor ez nem valtoztat semmit,
            // es nem allitjuk vissza a mar futo kuldest.
            $frissit = db()->prepare(
                'UPDATE etlap_kuldes SET status = "megszakitva", befejezve = NOW()
                  WHERE id = ? AND status = "elonezet"'
            );
            $frissit->execute([(int) $kuldes['id']]);

            if ($frissit->rowCount() === 1) {
                $kesz = true;
                esemeny('figyelem', sprintf(
                    'Visszavonva az előnézet „Mégsem" gombjával: „%s". Nem ment ki senkinek.',
                    $kuldes['targy']
                ), (int) $kuldes['id']);
            } else {
                $hiba = 'A kiküldés épp most indult el, ezért már nem lehet visszavonni.';
            }
        }
    } catch (Throwable $kivetel) {
        error_log('Megsem hiba: ' . $kivetel->getMessage());
        $hiba = 'Technikai hiba történt. Kérjük, próbálja újra néhány perc múlva.';
    }
}

render_header('Kiküldés visszavonása', true);
?>

<div class="card result">
<?php if ($hiba !== ''): ?>

    <h1 class="title">Kiküldés visszavonása</h1>
    <div class="alert alert--error" role="alert">
        <span class="alert__icon" aria-hidden="true">!</span>
        <span><?= e($hiba) ?></span>
    </div>
    <p class="subtitle">
        Ha segítségre van szüksége, írjon nekünk:
        <a href="mailto:<?= e(cfg('contact_email')) ?>"><?= e(cfg('contact_email')) ?></a>
    </p>

<?php elseif ($kesz): ?>

    <div class="result__icon" aria-hidden="true">&#10003;</div>
    <h1 class="title">Visszavontuk</h1>
    <p class="subtitle">
        Ez az étlap nem megy ki senkinek. Ha javított változatot szeretne
        küldeni, egyszerűen küldje be újra az új PDF-et.
    </p>

<?php else: ?>

    <h1 class="title">Biztosan visszavonja?</h1>
    <p class="subtitle">
        <strong><?= e((string) $kuldes['targy']) ?></strong><br>
        A kiküldés <?= e(date('H:i', strtotime((string) $kuldes['kuldes_ideje']))) ?>-kor indulna.
        Ha visszavonja, ez az étlap nem megy ki senkinek.
    </p>

    <form method="post" action="/megsem.php">
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <button class="btn btn--primary" type="submit">Igen, ne küldjük ki</button>
    </form>

<?php endif; ?>
</div>

<?php render_footer(); ?>
