<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
require __DIR__ . '/../inc/mailer.php';

$error = '';

// --- Belepes ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        $error = 'Az űrlap érvényessége lejárt, próbálja újra.';
    } elseif (admin_login_valid((string) ($_POST['user'] ?? ''), (string) ($_POST['password'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['admin_logged_in'] = true;
        header('Location: /admin/');
        exit;
    } else {
        // Lassitas, hogy a probalgatas ne legyen olcso.
        usleep(500000);
        $error = 'Hibás felhasználónév vagy jelszó.';
    }
}

// --- Belepteto kepernyo ---
if (!admin_logged_in()) {
    render_header('Admin belépés', true);
    ?>
    <div class="card">
        <h1 class="title">Admin belépés</h1>

        <?php if ($error !== ''): ?>
            <div class="alert alert--error" role="alert">
                <span class="alert__icon" aria-hidden="true">!</span>
                <span><?= e($error) ?></span>
            </div>
        <?php endif; ?>

        <form method="post" action="/admin/">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <div class="field">
                <label class="label" for="user">Felhasználónév</label>
                <input class="input" type="text" id="user" name="user" autocomplete="username" required>
            </div>
            <div class="field">
                <label class="label" for="password">Jelszó</label>
                <input class="input" type="password" id="password" name="password" autocomplete="current-password" required>
            </div>
            <button class="btn btn--primary" type="submit" name="login" value="1">Belépés</button>
        </form>
    </div>
    <?php
    render_footer();
    exit;
}

// --- Innentol csak bejelentkezve ---

// Statuszvaltas (aktivalas / deaktivalas)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_id'])) {
    if (csrf_valid($_POST['csrf_token'] ?? null)) {
        try {
            $id = (int) $_POST['toggle_id'];
            db()->prepare(
                'UPDATE subscribers
                    SET status = IF(status = "active", "inactive", "active"),
                        unsubscribed_at = IF(status = "active", NOW(), NULL)
                  WHERE id = ?'
            )->execute([$id]);
        } catch (Throwable $exception) {
            error_log('Státuszváltás hiba: ' . $exception->getMessage());
        }
    }
    header('Location: /admin/?' . http_build_query(['oldal' => (int) ($_POST['page'] ?? 1)]));
    exit;
}

// Teszt level kuldese, hogy az SMTP beallitast ellenorizni lehessen.
$mailNotice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_test'])) {
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        $mailNotice = 'error:Az űrlap érvényessége lejárt, próbálja újra.';
    } elseif (!mail_configured()) {
        $mailNotice = 'error:A levélküldés nincs beállítva a config.php-ban. Lásd: docs/levelkuldes.md';
    } else {
        $testTo = cfg('contact_email');
        try {
            send_email(
                $testTo,
                'Teszt levél – ' . cfg('site_name'),
                '<p>Ez egy teszt levél. Ha megkaptad, az SMTP beállítás működik.</p>',
                'Ez egy teszt level. Ha megkaptad, az SMTP beallitas mukodik.'
            );
            $mailNotice = 'success:Teszt levél elküldve ide: ' . $testTo;
        } catch (Throwable $exception) {
            error_log('Teszt level hiba: ' . $exception->getMessage());
            $mailNotice = 'error:A küldés nem sikerült: ' . $exception->getMessage();
        }
    }
}

$perPage = 50;
$page    = max(1, (int) ($_GET['oldal'] ?? 1));
$offset  = ($page - 1) * $perPage;

// Az adatbazis-hibat itt elkapjuk, kulonben a PHP leallna es ures oldal
// jelenne meg. Ilyenkor a diagnosztika oldalra iranyitjuk a figyelmet,
// ami pontosan megmondja, mi hianyzik.
$dbHiba = '';
$stats = ['total' => 0, 'active' => 0, 'inactive' => 0, 'last_week' => 0];
$subscribers = [];
$totalPages = 1;

try {
    $stats = db()->query(
        'SELECT
            COUNT(*)                                       AS total,
            SUM(status = "active")                         AS active,
            SUM(status = "inactive")                       AS inactive,
            SUM(created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)) AS last_week
         FROM subscribers'
    )->fetch();

    $totalPages = max(1, (int) ceil(((int) $stats['total']) / $perPage));

    $stmt = db()->prepare(
        'SELECT id, email, status, consent_version, consent_at, created_at, unsubscribed_at
           FROM subscribers
          ORDER BY created_at DESC
          LIMIT :limit OFFSET :offset'
    );
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $subscribers = $stmt->fetchAll();
} catch (Throwable $exception) {
    error_log('Admin lista hiba: ' . $exception->getMessage());
    $dbHiba = $exception->getMessage();
}

render_header('Feliratkozók', true, true);
?>

<div class="admin">
    <h1 class="title">Feliratkozók</h1>

    <?php if ($dbHiba !== ''): ?>
        <div class="alert alert--error" role="alert">
            <span class="alert__icon" aria-hidden="true">!</span>
            <span>
                <strong>Az adatbázis nem érhető el.</strong><br>
                Emiatt a lista és a darabszámok üresek. Nyisd meg a
                <a href="/admin/diagnosztika.php">diagnosztika oldalt</a> – az pontosan
                megmondja, mi hiányzik.<br>
                <span class="text-xs"><?= e($dbHiba) ?></span>
            </span>
        </div>
    <?php endif; ?>

    <?php if ($mailNotice !== ''): ?>
        <?php [$noticeType, $noticeText] = explode(':', $mailNotice, 2); ?>
        <div class="alert alert--<?= $noticeType === 'success' ? 'success' : 'error' ?>" role="alert">
            <span class="alert__icon" aria-hidden="true"><?= $noticeType === 'success' ? '✓' : '!' ?></span>
            <span><?= e($noticeText) ?></span>
        </div>
    <?php endif; ?>

    <div class="stats">
        <div class="stat">
            <div class="stat__value"><?= (int) $stats['active'] ?></div>
            <div class="stat__label">Aktív</div>
        </div>
        <div class="stat">
            <div class="stat__value"><?= (int) $stats['inactive'] ?></div>
            <div class="stat__label">Leiratkozott</div>
        </div>
        <div class="stat">
            <div class="stat__value"><?= (int) $stats['total'] ?></div>
            <div class="stat__label">Összesen</div>
        </div>
        <div class="stat">
            <div class="stat__value"><?= (int) $stats['last_week'] ?></div>
            <div class="stat__label">Új (7 nap)</div>
        </div>
    </div>

    <div class="toolbar">
        <a class="btn btn--primary btn--small" href="/admin/export.php?tipus=aktiv">Aktívak letöltése (CSV)</a>
        <a class="btn btn--secondary btn--small" href="/admin/export.php?tipus=mind">Teljes lista (CSV)</a>
        <form method="post" action="/admin/" class="inline-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <button class="btn btn--secondary btn--small btn--inline" type="submit" name="send_test" value="1">
                Teszt levél küldése
            </button>
        </form>
        <a class="btn btn--primary btn--small" href="/admin/kuldesek.php">Étlap kiküldések</a>
        <a class="btn btn--secondary btn--small" href="/admin/naplo.php">Napló</a>
        <a class="btn btn--primary btn--small" href="/admin/cimlista.php">Címlista másolása</a>
        <a class="btn btn--secondary btn--small" href="/admin/diagnosztika.php">Diagnosztika</a>
        <a class="btn btn--secondary btn--small" href="/admin/logout.php">Kilépés</a>
    </div>

    <?php if (!$subscribers): ?>
        <div class="card empty">Még nincs egyetlen feliratkozó sem.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Email</th>
                        <th>Státusz</th>
                        <th>Feliratkozott</th>
                        <th>Hozzájárulás</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($subscribers as $row): ?>
                    <tr>
                        <td><?= e($row['email']) ?></td>
                        <td>
                            <span class="badge badge--<?= e($row['status']) ?>">
                                <?= $row['status'] === 'active' ? 'aktív' : 'leiratkozott' ?>
                            </span>
                        </td>
                        <td><?= e(date('Y.m.d. H:i', strtotime($row['created_at']))) ?></td>
                        <td><?= e($row['consent_version']) ?></td>
                        <td>
                            <form method="post" action="/admin/">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="toggle_id" value="<?= (int) $row['id'] ?>">
                                <input type="hidden" name="page" value="<?= $page ?>">
                                <button class="btn btn--secondary btn--small btn--inline" type="submit">
                                    <?= $row['status'] === 'active' ? 'Deaktiválás' : 'Aktiválás' ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="pagination">
                <?php if ($page > 1): ?>
                    <a class="btn btn--secondary btn--small btn--inline" href="/admin/?oldal=<?= $page - 1 ?>">Előző</a>
                <?php endif; ?>
                <span class="pagination__state"><?= $page ?> / <?= $totalPages ?></span>
                <?php if ($page < $totalPages): ?>
                    <a class="btn btn--secondary btn--small btn--inline" href="/admin/?oldal=<?= $page + 1 ?>">Következő</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php render_footer(); ?>
