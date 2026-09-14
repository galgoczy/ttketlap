<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';

$error = '';

// --- Belepes ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        $error = 'Az űrlap érvényessége lejárt, próbáld újra.';
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
        $id = (int) $_POST['toggle_id'];
        db()->prepare(
            'UPDATE subscribers
                SET status = IF(status = "active", "inactive", "active"),
                    unsubscribed_at = IF(status = "active", NOW(), NULL)
              WHERE id = ?'
        )->execute([$id]);
    }
    header('Location: /admin/?' . http_build_query(['oldal' => (int) ($_POST['page'] ?? 1)]));
    exit;
}

$perPage = 50;
$page    = max(1, (int) ($_GET['oldal'] ?? 1));
$offset  = ($page - 1) * $perPage;

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

render_header('Feliratkozók', true, true);
?>

<div class="admin">
    <h1 class="title">Feliratkozók</h1>

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
                <span class="btn btn--small btn--inline"><?= $page ?> / <?= $totalPages ?></span>
                <?php if ($page < $totalPages): ?>
                    <a class="btn btn--secondary btn--small btn--inline" href="/admin/?oldal=<?= $page + 1 ?>">Következő</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php render_footer(); ?>
