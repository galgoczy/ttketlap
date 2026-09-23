<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
require __DIR__ . '/../inc/naplo.php';
require __DIR__ . '/../inc/etlap_futar.php';
require_admin();

/**
 * Esemenynaplo: mi tortent a rendszerben, idorendben, a legfrissebb felul.
 * Szurni lehet a problemakra, es egy adott kikuldes esemenyeire.
 */

// Telegram teszt uzenet.
$tgUzenet = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['telegram_teszt'])) {
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        $tgUzenet = 'error:Az űrlap érvényessége lejárt, próbálja újra.';
    } else {
        $tgHiba = null;
        $siker = telegram_kuldes(
            "✅ <b>TTK Kantin étlap</b>\n\nEz egy teszt üzenet az admin felületről. "
            . 'Ha ezt látja, az értesítés működik.',
            $tgHiba
        );
        $tgUzenet = $siker
            ? 'success:A teszt üzenet elment. Nézze meg a Telegramot.'
            : 'error:' . ($tgHiba ?? 'Ismeretlen hiba.');
    }
}

$csakGond = ($_GET['szuro'] ?? '') === 'gond';
$kuldesId = (int) ($_GET['kuldes'] ?? 0);

$sorok       = [];
$hiba        = '';
$utolsoFutas = null;
$kuldesCim   = '';

try {
    naplo_tabla_biztositasa();

    $feltetelek = [];
    $parameterek = [];

    if ($csakGond) {
        $feltetelek[] = "szint IN ('figyelem', 'hiba')";
    }
    if ($kuldesId > 0) {
        $feltetelek[] = 'kuldes_id = ?';
        $parameterek[] = $kuldesId;

        $kuldes = kuldes_betolt($kuldesId);
        $kuldesCim = $kuldes !== null ? (string) $kuldes['targy'] : '';
    }

    $sql = 'SELECT ido, szint, uzenet, kuldes_id FROM futar_naplo'
         . ($feltetelek ? ' WHERE ' . implode(' AND ', $feltetelek) : '')
         . ' ORDER BY id DESC LIMIT 300';

    $stmt = db()->prepare($sql);
    $stmt->execute($parameterek);
    $sorok = $stmt->fetchAll();

    $utolsoFutas = allapot_olvas('futar_utolso_futas');
} catch (Throwable $kivetel) {
    error_log('Naplo oldal hiba: ' . $kivetel->getMessage());
    $hiba = $kivetel->getMessage();
}

/** A szint emberi neve es a hozza illo cimke. @return array{0:string,1:string} */
function szint_cimke(string $szint): array
{
    return match ($szint) {
        'siker'    => ['Rendben', 'badge badge--active'],
        'figyelem' => ['Figyelem', 'badge badge--inactive'],
        'hiba'     => ['Hiba', 'badge badge--danger'],
        default    => ['Esemény', 'badge'],
    };
}

render_header('Napló', true, true);
?>

<div class="admin">
    <p class="eyebrow">Admin</p>
    <h1 class="title">Napló</h1>
    <p class="subtitle">
        Mi történt a rendszerben, a legfrissebb felül. Csak az érdemi események
        kerülnek ide: beérkezett étlap, előnézet, kiküldés, visszavonás és hiba.
        A bejegyzések <?= NAPLO_MEGORZES_NAP ?> napig maradnak meg.
    </p>

    <?php if ($tgUzenet !== ''): ?>
        <?php [$tgTipus, $tgSzoveg] = explode(':', $tgUzenet, 2); ?>
        <div class="alert alert--<?= $tgTipus === 'success' ? 'success' : 'error' ?>" role="alert">
            <span class="alert__icon" aria-hidden="true"><?= $tgTipus === 'success' ? '&#10003;' : '!' ?></span>
            <span><?= e($tgSzoveg) ?></span>
        </div>
    <?php endif; ?>

    <?php if ($hiba !== ''): ?>
        <div class="alert alert--error" role="alert">
            <span class="alert__icon" aria-hidden="true">!</span>
            <span>
                <strong>A napló nem olvasható.</strong>
                Nyissa meg a <a href="/admin/diagnosztika.php">diagnosztika oldalt</a>.
            </span>
        </div>
    <?php endif; ?>

    <div class="alert alert--error alert--note" role="note">
        <span class="alert__icon" aria-hidden="true">!</span>
        <span>
            <strong>Az automata utoljára ekkor nézett be:</strong>
            <?= $utolsoFutas !== null ? e($utolsoFutas) : 'még soha' ?>.
            Ha ez friss, a rendszer él – akkor is, ha a napló csendes.
        </span>
    </div>

    <div class="toolbar">
        <a class="btn btn--small <?= !$csakGond && $kuldesId === 0 ? 'btn--primary' : 'btn--secondary' ?>"
           href="/admin/naplo.php">Minden esemény</a>
        <a class="btn btn--small <?= $csakGond ? 'btn--primary' : 'btn--secondary' ?>"
           href="/admin/naplo.php?szuro=gond">Csak a problémák</a>
    </div>

    <?php if ($kuldesId > 0): ?>
        <p class="subtitle">
            Csak ennek a kiküldésnek az eseményei:
            <strong><?= e($kuldesCim !== '' ? $kuldesCim : '#' . $kuldesId) ?></strong>
            · <a href="/admin/naplo.php">mind mutatása</a>
        </p>
    <?php endif; ?>

    <?php if ($hiba !== ''): ?>
        <?php /* A figyelmeztetes mar fent van. */ ?>
    <?php elseif (!$sorok): ?>
        <div class="card empty">
            <?= $csakGond
                ? 'Nincs rögzített probléma.'
                : 'Még nincs bejegyzés. Amint étlap érkezik, itt látszik, mi történt vele.' ?>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--wrap">
                <thead>
                    <tr>
                        <th class="table__col-narrow">Időpont</th>
                        <th class="table__col-narrow">Típus</th>
                        <th>Esemény</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($sorok as $sor): ?>
                    <?php [$cimke, $osztaly] = szint_cimke((string) $sor['szint']); ?>
                    <tr>
                        <td class="naplo__ido"><?= e(date('m. d. H:i', strtotime((string) $sor['ido']))) ?></td>
                        <td><span class="<?= $osztaly ?>"><?= e($cimke) ?></span></td>
                        <td>
                            <?= e((string) $sor['uzenet']) ?>
                            <?php if ($sor['kuldes_id'] !== null && $kuldesId === 0): ?>
                                <br><a class="hint" href="/admin/naplo.php?kuldes=<?= (int) $sor['kuldes_id'] ?>">
                                    ennek a kiküldésnek az eseményei
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <div class="card mt-6">
        <p class="eyebrow">Telegram értesítés</p>
        <?php if (telegram_beallitva()): ?>
            <p class="subtitle">
                Be van kapcsolva. Ha történik valami – új étlap, kiküldés, visszavonás
                vagy hiba –, a rendszer egy összefoglaló üzenetet küld. A rutinszerű
                eseményekről (pl. a kiküldés percenkénti haladása) nem szól.
            </p>
            <form method="post" action="/admin/naplo.php" class="inline-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <button class="btn btn--secondary btn--small btn--inline" type="submit"
                        name="telegram_teszt" value="1">Teszt üzenet küldése</button>
            </form>
        <?php else: ?>
            <p class="subtitle m-0">
                Nincs beállítva. A config.php-ban a <code>telegram_bot_token</code> és a
                <code>telegram_chat_id</code> kitöltésével kapcsolható be.
            </p>
        <?php endif; ?>
    </div>

    <div class="toolbar mt-6">
        <a class="btn btn--secondary btn--small" href="/admin/">Vissza a feliratkozókhoz</a>
        <a class="btn btn--secondary btn--small" href="/admin/kuldesek.php">Étlap kiküldések</a>
        <a class="btn btn--secondary btn--small" href="/admin/diagnosztika.php">Diagnosztika</a>
    </div>
</div>

<?php render_footer(); ?>
