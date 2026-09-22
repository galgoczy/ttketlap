<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
require __DIR__ . '/../inc/etlap_futar.php';
require_once __DIR__ . '/../inc/naplo.php';
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
        str_contains($uzenet, '[1045]')  => 'A MySQL elutasította a belépést. Három dolgot érdemes megnézni: '
            . '(1) a db_host értéke a Hostingeren "localhost" legyen – ha IP-cím vagy külső név van ott, '
            . 'a MySQL nem ismeri fel a jogosultságot; '
            . '(2) a db_user és db_pass pontosan egyezzen a hPanelben látottal; '
            . '(3) a config.php-ban a jelszó APOSZTRÓFOK között legyen, ne idézőjelben – '
            . 'idézőjelben a $ jel után álló részt a PHP változónak veszi és eltünteti.',
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

ellenoriz('Adatbázis beállítások', function () {
    global $config;
    $host = (string) ($config['db_host'] ?? '');
    $nev  = (string) ($config['db_name'] ?? '');
    $user = (string) ($config['db_user'] ?? '');
    $pass = (string) ($config['db_pass'] ?? '');

    $gondok = [];

    // A leggyakoribb elgepeles: felesleges szokoz a masolaskor.
    foreach (['db_host' => $host, 'db_name' => $nev, 'db_user' => $user, 'db_pass' => $pass] as $kulcs => $ertek) {
        if ($ertek !== trim($ertek)) {
            $gondok[] = $kulcs . ' elején vagy végén szóköz van';
        }
    }

    if ($host !== '' && $host !== 'localhost' && $host !== '127.0.0.1') {
        $gondok[] = 'a db_host nem "localhost" – a Hostingeren szinte mindig az a helyes';
    }
    if ($pass === '' || str_contains($pass, 'IDE_JON')) {
        $gondok[] = 'a db_pass nincs kitöltve';
    }

    $leiras = sprintf(
        'gép: %s · adatbázis: %s · felhasználó: %s · jelszó: %s',
        $host !== '' ? $host : '(üres)',
        $nev  !== '' ? $nev  : '(üres)',
        $user !== '' ? $user : '(üres)',
        $pass !== '' ? 'be van állítva (' . strlen($pass) . ' karakter)' : '(üres)'
    );

    return $gondok
        ? ['hiba', $leiras . ' — ' . implode('; ', $gondok)]
        : ['ok', $leiras];
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

// ---------- Etlap futar ----------

ellenoriz('`etlap_kuldes` tábla', function () {
    $van = db()->query("SHOW TABLES LIKE 'etlap_kuldes'")->fetch();
    return $van
        ? ['ok', 'megvan']
        : ['hiba', 'Nincs meg. Enélkül a beküldött étlapok nem mennek ki. '
                 . 'Futtasd le a sql/etlap-kuldes.sql fájlt a phpMyAdminban.'];
});

ellenoriz('Napló', function () {
    // A tablat a rendszer magatol letrehozza, ha hianyzik.
    naplo_tabla_biztositasa();
    $n = (int) db()->query('SELECT COUNT(*) FROM futar_naplo')->fetchColumn();
    $hibaStmt = db()->prepare(
        'SELECT COUNT(*) FROM futar_naplo WHERE szint = ? AND ido >= ?'
    );
    $hibaStmt->execute(['hiba', date('Y-m-d H:i:s', time() - 86400)]);
    $hibak = (int) $hibaStmt->fetchColumn();

    if ($hibak > 0) {
        return ['figyelem', sprintf('%d bejegyzés, ebből %d hiba az elmúlt napban. '
                                  . 'Nézd meg a Napló oldalt.', $n, $hibak)];
    }

    return ['ok', $n . ' bejegyzés, az elmúlt napban hiba nem volt'];
});

ellenoriz('`rendszer_allapot` tábla', function () {
    $van = db()->query("SHOW TABLES LIKE 'rendszer_allapot'")->fetch();
    return $van
        ? ['ok', 'megvan']
        : ['hiba', 'Nincs meg. Futtasd le a sql/etlap-kuldes.sql fájlt a phpMyAdminban.'];
});

ellenoriz('Étlap postafiók', function () {
    $mailbox = cfg('etlap_mailbox');
    if ($mailbox === '') {
        return ['figyelem', 'Nincs beállítva (etlap_mailbox). Az automatikus étlapküldés ki van kapcsolva.'];
    }
    if ($mailbox === cfg('mail_from')) {
        return ['figyelem', $mailbox . ' – ez ugyanaz, mint a feladó cím. Működik, de ide '
                          . 'érkeznek a vendégek válaszai és a visszapattanó levelek is.'];
    }
    return ['ok', $mailbox];
});

// Eles proba: tenyleg el tudja-e erni a rendszer a postafiokot? Ez tobbet
// er minden beallitas-ellenorzesnel, mert a valodi valaszt mutatja.
ellenoriz('Étlap postafiók elérése', function () {
    $mailbox = cfg('etlap_mailbox');
    if ($mailbox === '') {
        return ['figyelem', 'Nincs beállítva étlap-postafiók, nincs mit ellenőrizni.'];
    }

    // Olvasas: ehhez Mail.Read is eleg.
    $url = graph_mailbox_url($mailbox, 'mailFolders/inbox/messages') . '?$top=1&$select=id';
    $valasz = graph_get($url, [], 'a postafiók olvasása');

    // Iras: a feldolgozott levelet olvasottra kell allitani. Ez a lepes
    // bukik el Mail.Read eseten, ezert kulon is kiprobaljuk - ugy, hogy
    // nem valtoztatunk semmit (a level sajat isRead erteket irjuk vissza).
    $elso = $valasz['value'][0] ?? null;
    if ($elso === null) {
        return ['ok', 'Az olvasás működik. A postafiók üres, ezért az írást most '
                    . 'nem tudtuk kipróbálni.'];
    }

    $reszletes = graph_get(
        graph_mailbox_url($mailbox, 'messages/' . rawurlencode((string) $elso['id']))
            . '?$select=id,isRead',
        [],
        'a postafiók olvasása'
    );

    graph_patch(
        graph_mailbox_url($mailbox, 'messages/' . rawurlencode((string) $elso['id'])),
        ['isRead' => (bool) ($reszletes['isRead'] ?? false)],
        'a levél olvasottra állítása'
    );

    return ['ok', 'Az olvasás és az írás is működik.'];
});

ellenoriz('Beküldésre jogosultak', function () {
    $cimek = etlap_bekuldok();
    if (!$cimek) {
        return ['hiba', 'Egy cím sincs megadva (etlap_bekuldok). Amíg üres, a rendszer '
                      . 'MINDEN beküldött étlapot figyelmen kívül hagy.'];
    }
    return ['ok', implode(', ', $cimek)];
});

ellenoriz('Képek kicsinyítése', function () {
    if (!function_exists('imagecreatefromstring') || !function_exists('imagescale')) {
        return ['figyelem', 'Nincs GD bővítmény a tárhelyen. A képes étlapok így is '
                          . 'kimennek, de a rendszer nem tudja kicsinyíteni őket – '
                          . 'egy telefonnal készült fénykép könnyen túllépi a 3 MB-os '
                          . 'határt, és akkor visszautasítja.'];
    }

    return ['ok', 'működik (a nagy fényképeket 1600 pixel szélesre kicsinyíti)'];
});

ellenoriz('Visszavonási idő', function () {
    $perc = (int) cfg('etlap_varakozas_perc', '15');
    if ($perc <= 0) {
        return ['figyelem', 'Nulla perc: a kiküldés azonnal indul, nincs mód visszavonni.'];
    }
    return ['ok', $perc . ' perc'];
});

ellenoriz('Az időzítő (cron) parancsa', function () {
    // A tarhelyen a weboldal mappaja nem feltetlenul "public_html" -
    // aldomainnel jellemzoen nem az. Ezert nem talalgatunk: kiirjuk a
    // valodi utvonalat, ahogy a szerver latja.
    $teljes = dirname(__DIR__) . '/futar.php';

    if (!is_file($teljes)) {
        return ['hiba', 'A futar.php nincs a helyén: ' . $teljes
                      . '. Úgy tűnik, a feltöltés nem fejeződött be.'];
    }

    $uzenet = 'Teljes parancs: /usr/bin/php ' . $teljes;

    // A Hostinger urlapja a "/usr/bin/php /home/<felhasznalo>/" reszt
    // elore beirja, es csak a maradekot kell begepelni.
    if (preg_match('#^/home/[^/]+/(.+)$#', $teljes, $talalat)) {
        $uzenet .= ' — a Hostinger mezőjébe ez kerül: ' . $talalat[1];
    }

    return ['ok', $uzenet];
});

ellenoriz('Időzítő (cron)', function () {
    $utolso = allapot_olvas('futar_utolso_futas');
    if ($utolso === null) {
        return ['hiba', 'A futár még soha nem futott le. Amíg az időzítő nincs beállítva '
                      . 'a tárhelyen, a beküldött étlapok nem mennek ki. Lásd: docs/etlap-kuldes.md'];
    }

    $eltelt = time() - (int) strtotime($utolso);
    if ($eltelt > 900) {
        return ['hiba', sprintf('Utoljára %s (%d perce) futott – úgy tűnik, az időzítő megállt.',
                                $utolso, (int) round($eltelt / 60))];
    }

    return ['ok', 'utoljára: ' . $utolso];
});

ellenoriz('Elakadt kiküldés', function () {
    $n = (int) db()->query(
        'SELECT COUNT(*) FROM etlap_kuldes WHERE status = "hiba"'
    )->fetchColumn();

    return $n === 0
        ? ['ok', 'nincs']
        : ['figyelem', $n . ' kiküldés hibával állt meg. A részletek az '
                     . 'Étlap kiküldések oldalon láthatók.'];
});

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
        <table class="table table--wrap">
            <thead>
                <tr><th class="table__col-narrow">Állapot</th><th>Mit néz</th><th>Eredmény</th></tr>
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
                            <span class="badge badge--danger">probléma</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($e['cim']) ?></td>
                    <td><?= e($e['uzenet']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <h2 class="title section-title">Hibanapló</h2>
    <?php if ($naploSorok): ?>
        <p class="subtitle">Az utolsó <?= count($naploSorok) ?> bejegyzés. A legfrissebb van alul.</p>
        <div class="table-wrap table-wrap--padded">
            <pre class="log"><?= e(implode("\n", $naploSorok)) ?></pre>
        </div>
    <?php else: ?>
        <div class="card empty">
            A hibanapló nem olvasható innen<?= $naploUt !== '' ? '' : ' (nincs beállítva útvonal)' ?>.
            A Hostinger hPanelben: <strong>Speciális → PHP-konfiguráció</strong>, illetve a
            Fájlkezelőben keresd az <code>error_log</code> fájlt a weboldal mappájában.
        </div>
    <?php endif; ?>

    <div class="toolbar mt-6">
        <a class="btn btn--secondary btn--small" href="/admin/">Vissza a feliratkozókhoz</a>
    </div>
</div>

<?php render_footer(); ?>
