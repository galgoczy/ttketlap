<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
require __DIR__ . '/../inc/mailer.php';
require_admin();

/**
 * Rendszerallapot. Vegigmegy azon, amitol a rendszer mukodese fugg, es
 * magyarul megmondja, mi hianyzik. Titkos ertekeket SOHA nem ir ki -
 * csak azt, hogy be van-e allitva.
 */

/** @var array<int, array{cim:string, allapot:string, uzenet:string}> $eredmenyek */
$eredmenyek = [];

/**
 * A gyakori adatbazis-hibakat magyarul irja le. A nyers uzenetet is
 * meghagyjuk a vegen, hogy szukseg eseten tovabb lehessen keresni.
 */
function erthetobb_hiba(string $uzenet): string
{
    $magyarazat = match (true) {
        str_contains($uzenet, '[2002]')  => 'Nem érhető el az adatbázis szerver. Ellenőrizd a db_host értékét a config.php-ban (a Hostingeren általában "localhost").',
        str_contains($uzenet, '[1045]')  => 'Hibás adatbázis felhasználónév vagy jelszó (db_user / db_pass).',
        str_contains($uzenet, '[1049]')  => 'Nincs ilyen nevű adatbázis (db_name). Ellenőrizd a hPanelben a pontos nevet.',
        str_contains($uzenet, '[1146]')  => 'Hiányzik egy tábla. Futtasd le a sql/schema.sql fájlt phpMyAdminban.',
        str_contains($uzenet, '[1044]')  => 'A felhasználónak nincs joga ehhez az adatbázishoz.',
        default => '',
    };

    return $magyarazat !== ''
        ? $magyarazat . ' (' . $uzenet . ')'
        : $uzenet;
}

function ellenoriz(string $cim, callable $teszt): void
{
    global $eredmenyek;
    try {
        [$allapot, $uzenet] = $teszt();
    } catch (Throwable $e) {
        $allapot = 'hiba';
        $uzenet  = erthetobb_hiba($e->getMessage());
    }
    $eredmenyek[] = ['cim' => $cim, 'allapot' => $allapot, 'uzenet' => $uzenet];
}

// ---------- Kornyezet ----------
ellenoriz('PHP verzió', fn() => [
    version_compare(PHP_VERSION, '8.1', '>=') ? 'ok' : 'hiba',
    PHP_VERSION . (version_compare(PHP_VERSION, '8.1', '>=') ? '' : ' – legalább 8.1 kell'),
]);

ellenoriz('MySQL bővítmény (PDO)', fn() => extension_loaded('pdo_mysql')
    ? ['ok', 'elérhető']
    : ['hiba', 'A pdo_mysql bővítmény hiányzik. A hPanelben állítsd be a PHP verziót.']);

ellenoriz('cURL bővítmény', fn() => extension_loaded('curl')
    ? ['ok', 'elérhető (a Graph API-s levélküldéshez kell)']
    : ['hiba', 'A cURL bővítmény hiányzik – enélkül a Graph API-s levélküldés nem működik.']);

// ---------- Adatbazis ----------
ellenoriz('Adatbázis kapcsolat', function () {
    db()->query('SELECT 1');
    return ['ok', 'sikeres'];
});

ellenoriz('`subscribers` tábla', function () {
    $db = db();  // ha a kapcsolat nem el, a fenti sor mar jelezte
    $van = $db->query("SHOW TABLES LIKE 'subscribers'")->fetch();
    if (!$van) {
        return ['hiba', 'Nincs meg. Futtasd le a sql/schema.sql fájlt phpMyAdminban.'];
    }
    $n = (int) $db->query('SELECT COUNT(*) FROM subscribers')->fetchColumn();
    return ['ok', $n . ' sor'];
});

ellenoriz('`signup_attempts` tábla', function () {
    $van = db()->query("SHOW TABLES LIKE 'signup_attempts'")->fetch();
    return $van
        ? ['ok', 'megvan']
        : ['hiba', 'Nincs meg. Enélkül a feliratkozás "Technikai hiba" üzenettel elszáll. '
                 . 'Futtasd le a sql/schema.sql fájl MÁSODIK táblájának létrehozását is.'];
});

// ---------- Beallitasok ----------
ellenoriz('app_secret', function () {
    $v = cfg('app_secret');
    if ($v === '' || str_contains($v, 'IDE_JON')) {
        return ['hiba', 'Nincs beállítva. A leiratkozó linkek ettől függenek.'];
    }
    return strlen($v) >= 32
        ? ['ok', 'be van állítva (' . strlen($v) . ' karakter)']
        : ['figyelem', 'Rövid (' . strlen($v) . ' karakter). Legalább 32 ajánlott.'];
});

ellenoriz('site_url', function () {
    $v = cfg('site_url');
    if ($v === '' || str_contains($v, 'pelda.hu')) {
        return ['hiba', 'Nincs kitöltve – a leiratkozó linkek rossz címre mutatnának.'];
    }
    if (!str_starts_with($v, 'https://')) {
        return ['figyelem', $v . ' – https:// ajánlott'];
    }
    if (str_ends_with($v, '/')) {
        return ['figyelem', $v . ' – a végén ne legyen per jel'];
    }
    return ['ok', $v];
});

ellenoriz('Admin jelszó', function () {
    $v = cfg('admin_password_hash');
    return str_starts_with($v, '$2y$') || str_starts_with($v, '$2b$') || str_starts_with($v, '$2a$')
        ? ['ok', 'érvényes bcrypt hash']
        : ['hiba', 'Nem bcrypt hash. Generálj újat a tools/jelszo-hash.html fájllal.'];
});

ellenoriz('Adatkezelési verzió', fn() => cfg('consent_version') !== ''
    ? ['ok', cfg('consent_version')]
    : ['hiba', 'Nincs kitöltve.']);

// ---------- Levelkuldes ----------
ellenoriz('Levélküldés módja', function () {
    $t = cfg('mail_transport', 'graph');
    return in_array($t, ['graph', 'smtp'], true)
        ? ['ok', $t === 'graph' ? 'Microsoft Graph API' : 'SMTP (jelszavas)']
        : ['hiba', 'Ismeretlen érték: ' . $t . ' – "graph" vagy "smtp" lehet.'];
});

ellenoriz('Feladó postafiók', fn() => cfg('mail_from') !== '' && !str_contains(cfg('mail_from'), 'pelda.hu')
    ? ['ok', cfg('mail_from')]
    : ['hiba', 'Nincs kitöltve a mail_from.']);

ellenoriz('Levélküldés beállítva', function () {
    if (mail_configured()) {
        return ['ok', 'minden szükséges mező ki van töltve'];
    }
    $t = cfg('mail_transport', 'graph');
    $hiany = [];
    if ($t === 'graph') {
        foreach (['graph_tenant_id', 'graph_client_id', 'graph_client_secret'] as $k) {
            if (cfg($k) === '') { $hiany[] = $k; }
        }
    } elseif (cfg('smtp_host') === '') {
        $hiany[] = 'smtp_host';
    }
    if (cfg('mail_from') === '') { $hiany[] = 'mail_from'; }

    return ['hiba', 'Hiányzó mezők: ' . ($hiany ? implode(', ', $hiany) : 'ismeretlen')
                  . '. Amíg nincs kitöltve, a feliratkozás működik, de levél nem megy ki.'];
});

ellenoriz('Visszaigazoló levél', fn() => cfg('send_welcome_email', '1') === '1'
    ? ['ok', 'bekapcsolva']
    : ['figyelem', 'kikapcsolva (send_welcome_email = 0)']);

// ---------- Hibanaplo ----------
$naploSorok = [];
$naploUt = (string) ini_get('error_log');
if ($naploUt !== '' && is_readable($naploUt)) {
    $sorok = @file($naploUt, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $naploSorok = array_slice($sorok, -15);
}

$szamlalo = ['ok' => 0, 'figyelem' => 0, 'hiba' => 0];
foreach ($eredmenyek as $e) { $szamlalo[$e['allapot']]++; }

render_header('Diagnosztika', true, true);
?>

<div class="admin">
    <p class="eyebrow">Admin</p>
    <h1 class="title">Diagnosztika</h1>
    <p class="subtitle">
        Ez az oldal végigméri, amitől a rendszer működése függ. Titkos értékeket
        (jelszó, kulcs) nem ír ki – csak azt, hogy be vannak-e állítva.
    </p>

    <?php if ($szamlalo['hiba'] > 0): ?>
        <div class="alert alert--error" role="alert">
            <span class="alert__icon" aria-hidden="true">!</span>
            <span><strong><?= $szamlalo['hiba'] ?> probléma</strong> – ezek okozzák a hibát. A részletek lentebb.</span>
        </div>
    <?php else: ?>
        <div class="alert alert--success" role="status">
            <span class="alert__icon" aria-hidden="true">&#10003;</span>
            <span>Minden ellenőrzés rendben.</span>
        </div>
    <?php endif; ?>

    <div class="table-wrap">
        <table class="table" style="white-space: normal">
            <thead>
                <tr><th style="width: 1%">Állapot</th><th>Mit néz</th><th>Eredmény</th></tr>
            </thead>
            <tbody>
            <?php foreach ($eredmenyek as $e): ?>
                <tr>
                    <td>
                        <?php if ($e['allapot'] === 'ok'): ?>
                            <span class="badge badge--active">rendben</span>
                        <?php elseif ($e['allapot'] === 'figyelem'): ?>
                            <span class="badge badge--inactive">figyelem</span>
                        <?php else: ?>
                            <span class="badge" style="background: var(--color-danger-soft); border-color: var(--color-danger-line); color: var(--color-danger-text)">probléma</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($e['cim']) ?></td>
                    <td><?= e($e['uzenet']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <h2 class="title" style="font-size: var(--text-h3); margin-top: var(--space-6)">Hibanapló</h2>
    <?php if ($naploSorok): ?>
        <p class="subtitle">Az utolsó <?= count($naploSorok) ?> bejegyzés. A legfrissebb van alul.</p>
        <div class="table-wrap" style="padding: var(--space-4)">
            <pre style="margin: 0; font-family: var(--font-mono); font-size: var(--text-xs); white-space: pre-wrap; word-break: break-word"><?= e(implode("\n", $naploSorok)) ?></pre>
        </div>
    <?php else: ?>
        <div class="card empty">
            A hibanapló nem olvasható innen<?= $naploUt !== '' ? '' : ' (nincs beállítva útvonal)' ?>.
            A Hostinger hPanelben: <strong>Speciális → PHP-konfiguráció</strong>, illetve a
            Fájlkezelőben keresd az <code>error_log</code> fájlt a weboldal mappájában.
        </div>
    <?php endif; ?>

    <div class="toolbar" style="margin-top: var(--space-6)">
        <a class="btn btn--secondary btn--small" href="/admin/">Vissza a feliratkozókhoz</a>
    </div>
</div>

<?php render_footer(); ?>
