<?php
declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

/**
 * Fontos: a leiratkozas csak POST-ra tortenik meg.
 * Sok levelezo rendszer eloretolti (prefetch) az emailben levo linkeket -
 * ha a GET azonnal leiratkoztatna, veletlenul is kikerulnenek emberek a listarol.
 */

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$done  = false;
$error = '';
$subscriber = null;

if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    $error = 'Érvénytelen vagy hiányzó leiratkozási link.';
} else {
    try {
        $stmt = db()->prepare('SELECT id, email, status FROM subscribers WHERE unsubscribe_token = ?');
        $stmt->execute([$token]);
        $subscriber = $stmt->fetch();

        if ($subscriber === false) {
            $error = 'Ez a leiratkozási link nem érvényes.';
            $subscriber = null;
        } elseif ($subscriber['status'] === 'inactive') {
            $done = true;
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            db()->prepare(
                'UPDATE subscribers
                    SET status = "inactive", unsubscribed_at = NOW()
                  WHERE id = ?'
            )->execute([$subscriber['id']]);
            $done = true;
        }
    } catch (Throwable $exception) {
        error_log('Leiratkozasi hiba: ' . $exception->getMessage());
        $error = 'Technikai hiba történt. Kérjük, próbáld újra néhány perc múlva.';
    }
}

render_header('Leiratkozás', true);
?>

<div class="card result">
<?php if ($error !== ''): ?>

    <h1 class="title">Leiratkozás</h1>
    <div class="alert alert--error" role="alert">
        <span class="alert__icon" aria-hidden="true">!</span>
        <span><?= e($error) ?></span>
    </div>
    <p class="subtitle">
        Ha segítségre van szükséged, írj nekünk:
        <a href="mailto:<?= e(cfg('contact_email')) ?>"><?= e(cfg('contact_email')) ?></a>
    </p>

<?php elseif ($done): ?>

    <div class="result__icon" aria-hidden="true">✓</div>
    <h1 class="title">Leiratkoztál</h1>
    <p class="subtitle">
        Erre a címre többé nem küldünk heti étlapot.
        Ha meggondolnád magad, bármikor újra feliratkozhatsz.
    </p>
    <a class="btn btn--secondary" href="/">Vissza a feliratkozáshoz</a>

<?php else: ?>

    <h1 class="title">Biztosan leiratkozol?</h1>
    <p class="subtitle">
        A(z) <strong><?= e($subscriber['email']) ?></strong> címre ezután
        nem küldjük tovább a heti étlapot.
    </p>
    <form method="post" action="/leiratkozas.php">
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <button class="btn btn--danger" type="submit">Igen, leiratkozom</button>
    </form>

<?php endif; ?>
</div>

<?php render_footer(); ?>
