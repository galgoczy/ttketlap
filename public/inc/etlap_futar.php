<?php
declare(strict_types=1);

/**
 * Etlap futar: a bekuldott PDF-bol kikuldott korlevel.
 *
 * A folyamat:
 *   1. Az uzletvezeto elkuldi a napi etlapot PDF-ben egy postafiokba
 *      (pl. etlap@pepperhouse.hu).
 *   2. Ez a szkript percenkent megnezi a postafiokot. Ha talal ott egy
 *      jogosult feladotol erkezett, PDF-et tartalmazo levelet, letrehoz
 *      belole egy kikuldest, es visszakuld egy elonezetet a feladonak.
 *   3. Az elonezetben van egy "Megsem" link. Ha a megadott ido alatt
 *      (alapbol 15 perc) senki nem nyul hozza, a kikuldes elindul.
 *   4. A leveleket egyesevel kuldi ki, mindenkinek a sajat leiratkozo
 *      linkjevel. Ha a futas megszakad, a kovetkezo onnan folytatja,
 *      ahol abbahagyta - igy senki nem kap ket peldanyt.
 *
 * Miert egyesevel es nem egy BCC-s levelben?
 * Igy minden levelbe belekerul a cimzett sajat leiratkozo linkje, es a
 * levelezorendszerek (Gmail, Outlook) is szivesebben fogadjak.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/email_template.php';

/** A PDF legnagyobb merete. A Graph a nyers levelet ~4 MB-ig fogadja,
 *  es a base64 kodolas ~33%-kal novel, ezert marad 3 MB. */
const ETLAP_MAX_PDF = 3 * 1024 * 1024;

/** Egyszerre ennyi uj levelet dolgoz fel egy futas. */
const ETLAP_MAX_UZENET = 10;

/**
 * A futar egy korenek lefuttatasa. A visszaadott sorok a naploba
 * es az admin felulet "utolso futas" sorahoz kellenek.
 *
 * @return array<int,string>
 */
function futar_fut(): array
{
    $naplo = [];

    // Ket futas ne fedje at egymast: az ketszer kuldene ugyanazt.
    // A MySQL nevesitett zarja erre valo, es a kapcsolat bontasakor
    // magatol feloldodik - igy egy osszeomlott futas sem ragad be.
    $zar = db()->prepare('SELECT GET_LOCK(?, 0)');
    $zar->execute(['etlap_futar_' . cfg('db_name')]);
    if ((int) $zar->fetchColumn() !== 1) {
        return ['Egy másik futás még dolgozik, ez a kör kimarad.'];
    }

    // Lepesenkent kulon kezeljuk a hibat: ha a postafiok olvasasa elbukik
    // (pl. meg nincs meg a jogosultsag), a mar folyamatban levo kikuldes
    // attol meg fusson tovabb.
    try {
        foreach (['beerkezett_feldolgozas', 'kuldesek_inditasa', 'kuldes_folytatasa'] as $lepes) {
            try {
                $naplo = array_merge($naplo, $lepes());
            } catch (Throwable $hiba) {
                error_log(sprintf('Etlap futar - %s: %s', $lepes, $hiba->getMessage()));
                $naplo[] = 'Hiba (' . $lepes . '): ' . $hiba->getMessage();
            }
        }
    } finally {
        db()->prepare('SELECT RELEASE_LOCK(?)')
            ->execute(['etlap_futar_' . cfg('db_name')]);
    }

    allapot_ment('futar_utolso_futas', date('Y-m-d H:i:s'));

    return $naplo;
}

/** Egy allapotertek eltarolasa (a diagnosztika oldal ezeket mutatja). */
function allapot_ment(string $kulcs, string $ertek): void
{
    // A VALUES(...) alak a MySQL 8-ban elavult, ezert az erteket inkabb
    // ketszer adjuk at.
    db()->prepare(
        'INSERT INTO rendszer_allapot (kulcs, ertek) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE ertek = ?'
    )->execute([$kulcs, $ertek, $ertek]);
}

/** Egy tarolt allapotertek kiolvasasa. */
function allapot_olvas(string $kulcs): ?string
{
    $stmt = db()->prepare('SELECT ertek FROM rendszer_allapot WHERE kulcs = ?');
    $stmt->execute([$kulcs]);
    $ertek = $stmt->fetchColumn();

    return $ertek === false ? null : (string) $ertek;
}

/** A bekuldesre jogosult cimek, kisbetusen. @return array<int,string> */
function etlap_bekuldok(): array
{
    $nyers = cfg('etlap_bekuldok');
    $cimek = array_map(
        fn($cim) => mb_strtolower(trim($cim)),
        explode(',', $nyers)
    );

    return array_values(array_filter($cimek, fn($cim) => $cim !== ''));
}

// ---------------------------------------------------------------------
// 1. lepes: beerkezett levelek feldolgozasa
// ---------------------------------------------------------------------

/** @return array<int,string> */
function beerkezett_feldolgozas(): array
{
    $naplo   = [];
    $mailbox = cfg('etlap_mailbox');

    if ($mailbox === '') {
        return ['Nincs beállítva etlap_mailbox, a beérkező levelek figyelése kimarad.'];
    }

    $url = graph_mailbox_url($mailbox, 'mailFolders/inbox/messages')
         . '?$filter=' . rawurlencode('isRead eq false')
         . '&$top=' . ETLAP_MAX_UZENET
         . '&$select=' . rawurlencode('id,subject,from,receivedDateTime,hasAttachments,body,internetMessageHeaders');

    // A Prefer fejleccel a level torzset sima szovegkent kerjuk, igy nem
    // kell HTML-t bontogatnunk.
    $valasz = graph_get(
        $url,
        ['Prefer: outlook.body-content-type="text"'],
        'a postafiók olvasása'
    );
    $uzenetek = $valasz['value'] ?? [];

    if (!$uzenetek) {
        return $naplo;
    }

    foreach ($uzenetek as $uzenet) {
        try {
            $naplo[] = uzenet_feldolgozas($mailbox, $uzenet);
        } catch (Throwable $hiba) {
            error_log('Etlap futar - uzenet hiba: ' . $hiba->getMessage());
            $naplo[] = 'Hiba egy levél feldolgozásakor: ' . $hiba->getMessage();
        }
    }

    return $naplo;
}

/**
 * Egy beerkezett level feldolgozasa.
 *
 * @param array<string,mixed> $uzenet
 */
function uzenet_feldolgozas(string $mailbox, array $uzenet): string
{
    $id     = (string) ($uzenet['id'] ?? '');
    $felado = mb_strtolower((string) ($uzenet['from']['emailAddress']['address'] ?? ''));
    $targy  = trim((string) ($uzenet['subject'] ?? ''));

    if ($id === '') {
        return 'Azonosító nélküli levél, kihagyva.';
    }

    // Hurokvedelem. NEM a felado cime alapjan dontunk: a felado cim
    // (mail_from) egyben jogosult bekuldo is lehet - nalunk pont az.
    // Ehelyett a sajat leveleinken levo rejtett jelolot keressuk, es
    // kizarjuk azt az esetet, amikor a postafiok onmagatol kap levelet.
    if ($felado === mb_strtolower($mailbox) || sajat_levelunk($uzenet)) {
        uzenet_olvasott($mailbox, $id);
        return 'Saját magunktól érkezett levél, kihagyva.';
    }

    if (!in_array($felado, etlap_bekuldok(), true)) {
        uzenet_olvasott($mailbox, $id);
        // Ismeretlen feladonak szandekosan NEM valaszolunk: a felado cime
        // hamisithato, es a valasz egy artatlan emberhez jutna el.
        return 'Nem jogosult feladó (' . $felado . '), kihagyva.';
    }

    $pdf = etlap_csatolmany($mailbox, $id);

    if ($pdf === null) {
        uzenet_olvasott($mailbox, $id);
        ertesito_bekuldonek(
            $felado,
            'Nem találtunk étlapot a levélben',
            'A beküldött levélben nem volt olyan csatolmány, amit étlapként fel tudnánk '
            . 'használni, ezért nem küldtünk ki semmit. Kérjük, csatolja az étlapot '
            . 'PDF, JPG vagy PNG formátumban, és küldje el újra. (Ha a képet a levél '
            . 'szövegébe illesztette be, próbálja inkább csatolmányként hozzáadni.)'
        );
        return 'Nincs használható csatolmány a levélben, értesítettük a beküldőt.';
    }

    if (strlen($pdf['tartalom']) > ETLAP_MAX_PDF) {
        uzenet_olvasott($mailbox, $id);
        ertesito_bekuldonek(
            $felado,
            'Túl nagy a PDF',
            sprintf(
                'A beküldött fájl %s, a megengedett legnagyobb méret pedig %s. '
                . 'Ezért nem küldtünk ki semmit. Kérjük, mentse kisebb méretben '
                . '(a legtöbb PDF-készítőben van "kis méret" vagy "web" beállítás), és küldje el újra.',
                meret_szoveg(strlen($pdf['tartalom'])),
                meret_szoveg(ETLAP_MAX_PDF)
            )
        );
        return 'Túl nagy csatolmány, értesítettük a beküldőt.';
    }

    $bevezeto = tiszta_bevezeto((string) ($uzenet['body']['content'] ?? ''));
    $targy    = etlap_targy($targy);
    $token    = bin2hex(random_bytes(32));
    $varakozas = max(0, (int) cfg('etlap_varakozas_perc', '15'));

    // INSERT IGNORE: ha ugyanezt a levelet mar feldolgoztuk (pl. az elozo
    // futas a jelolés elott szakadt meg), az egyedi kulcs megfogja, es nem
    // indul masodszor is kikuldes.
    // Az indulas idejet PHP-ben szamoljuk ki, nem SQL-ben: igy a lekerdezes
    // egyszeru marad, es a beallitott idozona (Europe/Budapest) szerint megy.
    $kuldesIdeje = date('Y-m-d H:i:s', time() + $varakozas * 60);

    $stmt = db()->prepare(
        'INSERT IGNORE INTO etlap_kuldes
            (uzenet_id, felado, targy, bevezeto, pdf_nev, pdf_meret, pdf_tartalom,
             token, status, kuldes_ideje)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, "elonezet", ?)'
    );
    $stmt->execute([
        $id, $felado, $targy, $bevezeto,
        $pdf['nev'], strlen($pdf['tartalom']), $pdf['tartalom'],
        $token, $kuldesIdeje,
    ]);

    if ($stmt->rowCount() === 0) {
        uzenet_olvasott($mailbox, $id);
        return 'Ezt a levelet már feldolgoztuk korábban.';
    }

    $kuldesId = (int) db()->lastInsertId();
    uzenet_olvasott($mailbox, $id);

    // Az elonezet elkuldese. Ha ez nem sikerul, NEM indul a kikuldes:
    // nem megy ki semmi ugy, hogy a bekuldo nem latta elotte.
    try {
        elonezet_kuldes($kuldesId);
    } catch (Throwable $hiba) {
        db()->prepare(
            'UPDATE etlap_kuldes SET status = "hiba", hiba_uzenet = ? WHERE id = ?'
        )->execute([mb_substr('Az előnézet nem ment el: ' . $hiba->getMessage(), 0, 500), $kuldesId]);

        error_log('Etlap futar - elonezet hiba: ' . $hiba->getMessage());
        return 'Az előnézetet nem sikerült elküldeni, a kiküldés nem indul el.';
    }

    return sprintf('Új étlap "%s" (%s), előnézet elküldve: %s', $targy, $pdf['nev'], $felado);
}

/**
 * Sajat rendszerunk kuldte-e a levelet? A build_message minden kimeno
 * levelre rarakja a jelolo fejlecet, igy ezt biztosan felismerjuk -
 * akkor is, ha valamilyen atiranyitas miatt kerult vissza hozzank.
 *
 * @param array<string,mixed> $uzenet
 */
function sajat_levelunk(array $uzenet): bool
{
    foreach ($uzenet['internetMessageHeaders'] ?? [] as $fejlec) {
        if (strcasecmp((string) ($fejlec['name'] ?? ''), ETLAP_JELOLO_FEJLEC) === 0) {
            return true;
        }
    }

    return false;
}

/** A level olvasottra allitasa - igy a kovetkezo futas nem talalja meg ujra. */
function uzenet_olvasott(string $mailbox, string $id): void
{
    graph_patch(
        graph_mailbox_url($mailbox, 'messages/' . rawurlencode($id)),
        ['isRead' => true],
        'a levél olvasottra állítása'
    );
}

/** Amit etlapkent elfogadunk: kiterjesztes => MIME tipus. */
const ETLAP_TIPUSOK = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
];

/**
 * Az elso hasznalhato csatolmany kikeresese (PDF vagy kep).
 *
 * @return array{nev:string,tartalom:string,tipus:string}|null
 */
function etlap_csatolmany(string $mailbox, string $uzenetId): ?array
{
    $url = graph_mailbox_url($mailbox, 'messages/' . rawurlencode($uzenetId) . '/attachments');
    $valasz = graph_get($url, [], 'a csatolmány letöltése');

    foreach ($valasz['value'] ?? [] as $csatolmany) {
        $nev = (string) ($csatolmany['name'] ?? '');

        // A fajlnev kiterjesztese alapjan dontunk, mert a levelezoprogramok
        // sokfele contentType-ot irnak ugyanarra a fajtara.
        $kiterjesztes = mb_strtolower((string) pathinfo($nev, PATHINFO_EXTENSION));

        if (!isset(ETLAP_TIPUSOK[$kiterjesztes]) || empty($csatolmany['contentBytes'])) {
            continue;
        }

        $tartalom = base64_decode((string) $csatolmany['contentBytes'], true);
        if ($tartalom === false) {
            continue;
        }

        $tipus = ETLAP_TIPUSOK[$kiterjesztes];

        // Telefonnal keszult fenykep konnyen 5-6 MB. Kicsinyitjuk, hogy
        // beleferjen a korlatba - es hogy a cimzettek postafiokjat se
        // terheljuk feleslegesen.
        if (kep_e($tipus)) {
            $tartalom = kep_kicsinyites($tartalom, $tipus);
        }

        return [
            'nev'      => $nev !== '' ? $nev : 'etlap.' . $kiterjesztes,
            'tartalom' => $tartalom,
            'tipus'    => $tipus,
        ];
    }

    return null;
}

/** Kep-e a csatolmany? (A PDF-et csatolmanykent kuldjuk, a kepet beagyazzuk.) */
function kep_e(string $tipus): bool
{
    return str_starts_with($tipus, 'image/');
}

/**
 * Nagy kep atmeretezese. Ha nincs GD bovitmeny a tarhelyen, vagy a kep
 * mar eleg kicsi, valtozatlanul adja vissza - inkabb menjen ki az eredeti,
 * mint hogy elszalljon a feldolgozas.
 */
function kep_kicsinyites(string $tartalom, string $tipus, int $maxSzelesseg = 1600): string
{
    if (!function_exists('imagecreatefromstring') || !function_exists('imagescale')) {
        return $tartalom;
    }

    try {
        $kep = @imagecreatefromstring($tartalom);
        if ($kep === false) {
            return $tartalom;
        }

        $szelesseg = imagesx($kep);
        if ($szelesseg <= $maxSzelesseg && strlen($tartalom) <= ETLAP_MAX_PDF) {
            imagedestroy($kep);
            return $tartalom;
        }

        $kicsi = imagescale($kep, min($szelesseg, $maxSzelesseg));
        imagedestroy($kep);

        if ($kicsi === false) {
            return $tartalom;
        }

        ob_start();
        if ($tipus === 'image/png') {
            imagepng($kicsi, null, 6);
        } else {
            imagejpeg($kicsi, null, 82);
        }
        $uj = (string) ob_get_clean();
        imagedestroy($kicsi);

        // Csak akkor cserelunk, ha tenyleg nyertunk vele.
        return ($uj !== '' && strlen($uj) < strlen($tartalom)) ? $uj : $tartalom;
    } catch (Throwable $hiba) {
        error_log('Etlap futar - kep kicsinyites nem sikerult: ' . $hiba->getMessage());
        return $tartalom;
    }
}

/**
 * A bekuldott level szovegebol hasznalhato bevezetot keszit.
 * Levagja az alairast es az idezett valaszreszt, mert azok nem a
 * feliratkozoknak szolnak.
 */
function tiszta_bevezeto(string $nyers): string
{
    $nyers = str_replace(["\r\n", "\r"], "\n", $nyers);
    $sorok = [];

    foreach (explode("\n", $nyers) as $sor) {
        $vagott = trim($sor);

        // Alairas hatarolo, idezet, vagy a valasz fejlece: innentol vagunk.
        if ($vagott === '--' || $vagott === '__' || str_starts_with($vagott, '-----Original')
            || str_starts_with($vagott, '________')) {
            break;
        }
        if (str_starts_with($vagott, '>')) {
            continue;
        }

        $sorok[] = $vagott;
    }

    $szoveg = trim(implode("\n", $sorok));
    // A tobbszoros ures sorokat egyre huzzuk ossze.
    $szoveg = (string) preg_replace("/\n{3,}/", "\n\n", $szoveg);

    return mb_substr($szoveg, 0, 1500);
}

/** A level targya. Ha a bekuldo nem irt targyat, adunk egy ertelmeset. */
function etlap_targy(string $nyers): string
{
    // A levelezok altal rakott elotagok nem valok a korlevelbe.
    $nyers = (string) preg_replace('/^\s*((re|fw|fwd|vá|vál|tov)\s*:\s*)+/iu', '', $nyers);
    $nyers = trim($nyers);

    if ($nyers === '') {
        return 'TTK Kantin – napi étlap (' . date('Y. m. d.') . ')';
    }

    return mb_substr($nyers, 0, 200);
}

/** Emberi meretkiiras. */
function meret_szoveg(int $bajt): string
{
    if ($bajt >= 1024 * 1024) {
        return number_format($bajt / (1024 * 1024), 1, ',', ' ') . ' MB';
    }

    return number_format($bajt / 1024, 0, ',', ' ') . ' KB';
}

// ---------------------------------------------------------------------
// 2. lepes: a level osszeallitasa
// ---------------------------------------------------------------------

/**
 * A feliratkozoknak kimeno level HTML valtozata.
 *
 * @param array<string,mixed> $kuldes
 */
function etlap_level_html(array $kuldes, string $leiratkozoUrl): string
{
    $torzs = '';

    foreach (bevezeto_bekezdesek((string) $kuldes['bevezeto']) as $bekezdes) {
        $torzs .= email_bekezdes($bekezdes);
    }

    if (kuldes_kep_e($kuldes)) {
        // Kepnel a levél torzsebe agyazzuk: a cimzett rogton latja,
        // nem kell megnyitnia semmit.
        $torzs .= email_kep(ETLAP_KEP_CID);
    } else {
        $torzs .= email_bekezdes('Az étlapot a levél csatolmányában, PDF-ben találja.', true);
    }

    return email_keret(
        (string) $kuldes['targy'],
        $torzs,
        kuldes_kep_e($kuldes) ? 'Itt a mai étlap.' : 'A mai étlap a csatolmányban.',
        $leiratkozoUrl
    );
}

/** A beagyazott kep azonositoja a levelben. */
const ETLAP_KEP_CID = 'etlap';

/**
 * Kep-e a kikuldeshez tartozo fajl? A tarolt fajlnev kiterjesztesebol
 * dontjuk el, igy nem kellett uj oszlop az adatbazisba.
 *
 * @param array<string,mixed> $kuldes
 */
function kuldes_kep_e(array $kuldes): bool
{
    return kep_e(kuldes_fajl_tipusa($kuldes));
}

/**
 * A kikuldeshez tartozo fajl MIME tipusa a fajlnev alapjan.
 *
 * @param array<string,mixed> $kuldes
 */
function kuldes_fajl_tipusa(array $kuldes): string
{
    $kiterjesztes = mb_strtolower(
        (string) pathinfo((string) ($kuldes['pdf_nev'] ?? ''), PATHINFO_EXTENSION)
    );

    return ETLAP_TIPUSOK[$kiterjesztes] ?? 'application/octet-stream';
}

/**
 * A kikuldeshez tartozo csatolmany a levelkuldonek atadhato formaban.
 *
 * @param array<string,mixed> $kuldes
 * @return array<int,array<string,string>>
 */
function kuldes_csatolmanya(array $kuldes): array
{
    $csatolmany = [
        'nev'      => (string) $kuldes['pdf_nev'],
        'tartalom' => (string) $kuldes['pdf_tartalom'],
        'tipus'    => kuldes_fajl_tipusa($kuldes),
    ];

    if (kuldes_kep_e($kuldes)) {
        $csatolmany['cid'] = ETLAP_KEP_CID;
    }

    return [$csatolmany];
}

/**
 * A feliratkozoknak kimeno level szoveges valtozata (azoknak, akik nem
 * HTML-ben olvasnak, es a spamszuroknek is jobb, ha van).
 *
 * @param array<string,mixed> $kuldes
 */
function etlap_level_szoveg(array $kuldes, string $leiratkozoUrl): string
{
    $sorok = [(string) $kuldes['targy'], ''];

    $bevezeto = trim((string) $kuldes['bevezeto']);
    if ($bevezeto !== '') {
        $sorok[] = $bevezeto;
        $sorok[] = '';
    }

    $sorok[] = kuldes_kep_e($kuldes)
        ? 'Az étlapot a levélben képként küldtük. Ha nem látja, engedélyezze a képek megjelenítését.'
        : 'Az étlapot a levél csatolmányában, PDF-ben találja.';
    $sorok[] = '';
    $sorok[] = '--';
    $sorok[] = 'Ezt a levelet azért kapja, mert feliratkozott a TTK Kantin étlapjára.';

    if ($leiratkozoUrl !== '') {
        $sorok[] = 'Leiratkozás: ' . $leiratkozoUrl;
    }

    return implode("\n", $sorok);
}

/**
 * A bevezeto szoveget bekezdesekre bontja, es HTML-biztossa teszi.
 *
 * @return array<int,string>
 */
function bevezeto_bekezdesek(string $bevezeto): array
{
    $bevezeto = trim($bevezeto);

    if ($bevezeto === '') {
        return ['Kedves Feliratkozónk! Küldjük a mai étlapunkat.'];
    }

    $bekezdesek = [];
    foreach (preg_split("/\n\s*\n/", $bevezeto) ?: [] as $resz) {
        $resz = trim($resz);
        if ($resz !== '') {
            // Fontos: eloszor e()-vel biztonsagossa tesszuk, es csak utana
            // teszunk bele <br>-t. Forditva a bekuldo HTML-t csempeszhetne be.
            $bekezdesek[] = nl2br(e($resz));
        }
    }

    return $bekezdesek ?: ['Kedves Feliratkozónk! Küldjük a mai étlapunkat.'];
}

// ---------------------------------------------------------------------
// 3. lepes: elonezet a bekuldonek
// ---------------------------------------------------------------------

/** Az elonezet elkuldese a bekuldonek, "Megsem" linkkel. */
function elonezet_kuldes(int $kuldesId): void
{
    $kuldes = kuldes_betolt($kuldesId);
    if ($kuldes === null) {
        throw new RuntimeException('A kiküldés nem található.');
    }

    $cimzettek = aktiv_cimzett_szam();
    $megsemUrl = rtrim(cfg('site_url'), '/') . '/megsem.php?token=' . $kuldes['token'];

    // A figyelmezteto doboz a level tetejen. Inline stilus kell,
    // mert a levelezok a kulso stiluslapot nem toltik be.
    $doboz = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"'
        . ' style="margin:0 0 24px 0;border:2px solid ' . EMAIL_PIROS . ';border-radius:6px">'
        . '<tr><td style="padding:16px">'
        . email_bekezdes('<strong>Ez csak előnézet – még nem ment ki senkinek.</strong>')
        . email_bekezdes(sprintf(
            'A kiküldés <strong>%s-kor</strong> indul, és <strong>%d címre</strong> megy majd ki. '
            . 'Az alábbi levelet fogják megkapni, ezzel a fájllal: %s.',
            date('H:i', strtotime((string) $kuldes['kuldes_ideje'])),
            $cimzettek,
            e((string) $kuldes['pdf_nev'])
        ))
        . email_bekezdes('Ha valami nem stimmel, állítsa le:')
        . email_gomb('Mégsem küldjük ki', $megsemUrl)
        . email_bekezdes('Ha minden rendben, nincs teendője.', true)
        . '</td></tr></table>';

    $torzs = $doboz;
    foreach (bevezeto_bekezdesek((string) $kuldes['bevezeto']) as $bekezdes) {
        $torzs .= email_bekezdes($bekezdes);
    }

    // Az elonezet pontosan azt mutassa, amit a cimzettek kapnak: kepnel
    // a beagyazott kepet, PDF-nel a csatolmanyra utalo sort.
    $torzs .= kuldes_kep_e($kuldes)
        ? email_kep(ETLAP_KEP_CID)
        : email_bekezdes('Az étlapot a levél csatolmányában, PDF-ben találja.', true);

    $html = email_keret(
        (string) $kuldes['targy'],
        $torzs,
        'Előnézet – még nem ment ki senkinek.',
        ''
    );

    $szoveg = "ELŐNÉZET – ez a levél még nem ment ki senkinek.\n"
        . sprintf(
            "A kiküldés %s-kor indul, %d címre.\nMégsem: %s\n\n",
            date('H:i', strtotime((string) $kuldes['kuldes_ideje'])),
            $cimzettek,
            $megsemUrl
        )
        . etlap_level_szoveg($kuldes, '');

    send_email(
        (string) $kuldes['felado'],
        'Előnézet – ' . $kuldes['targy'],
        $html,
        $szoveg,
        '',
        kuldes_csatolmanya($kuldes)
    );
}

/** Rovid ertesito a bekuldonek (hibas bekuldes, osszegzes). */
function ertesito_bekuldonek(string $cim, string $targy, string $szoveg): void
{
    try {
        send_email(
            $cim,
            $targy,
            email_keret($targy, email_bekezdes(e($szoveg)), $targy, ''),
            $szoveg
        );
    } catch (Throwable $hiba) {
        error_log('Etlap futar - ertesito nem ment el: ' . $hiba->getMessage());
    }
}

// ---------------------------------------------------------------------
// 4. lepes: kikuldes
// ---------------------------------------------------------------------

/**
 * A letelt varakozasu kikuldesek elinditasa.
 *
 * @return array<int,string>
 */
function kuldesek_inditasa(): array
{
    $stmt = db()->prepare(
        'UPDATE etlap_kuldes SET status = "kuldes"
          WHERE status = "elonezet" AND kuldes_ideje <= NOW()'
    );
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        return [];
    }

    return [sprintf('%d kiküldés indul.', $stmt->rowCount())];
}

/**
 * A folyamatban levo kikuldes folytatasa, ameddig az ido engedi.
 *
 * @return array<int,string>
 */
function kuldes_folytatasa(): array
{
    $stmt = db()->query(
        'SELECT * FROM etlap_kuldes WHERE status = "kuldes" ORDER BY id LIMIT 1'
    );
    $kuldes = $stmt->fetch();

    if ($kuldes === false) {
        return [];
    }

    $hatarido = time() + max(30, (int) cfg('etlap_futasi_ido', '240'));
    $tempo    = max(0.0, (float) cfg('etlap_kuldes_tempo', '2.2'));
    $kuldesId = (int) $kuldes['id'];

    // A sajat cimeink soha ne kapjanak korlevelet. Ha az etlap postafiok
    // valahogy feliratkozna, a neki kikuldott etlap ujra bejonne a
    // postafiokba, es a rendszer korbe-korbe kuldozgetne magat.
    $cimzettStmt = db()->prepare(
        'SELECT id, email, unsubscribe_token FROM subscribers
          WHERE status = "active" AND id > ? AND email NOT IN (?, ?)
          ORDER BY id LIMIT 50'
    );
    $sajatCimek = [mb_strtolower(cfg('etlap_mailbox')), mb_strtolower(cfg('mail_from'))];
    $haladasStmt = db()->prepare(
        'UPDATE etlap_kuldes SET utolso_cimzett_id = ?, kikuldve = ?, hibas = ? WHERE id = ?'
    );

    while (time() < $hatarido) {
        $cimzettStmt->execute([(int) $kuldes['utolso_cimzett_id'], $sajatCimek[0], $sajatCimek[1]]);
        $cimzettek = $cimzettStmt->fetchAll();

        if (!$cimzettek) {
            return kuldes_lezaras($kuldes);
        }

        foreach ($cimzettek as $cimzett) {
            if (time() >= $hatarido) {
                break 2;
            }

            $sikeres = egy_cimzettnek($kuldes, $cimzett);

            // A mutato akkor is lep, ha a kuldes elbukott: kulonben a
            // rendszer orokre ugyanazon a rossz cimen probalkozna.
            $kuldes['utolso_cimzett_id'] = (int) $cimzett['id'];
            $kuldes['kikuldve'] = (int) $kuldes['kikuldve'] + ($sikeres ? 1 : 0);
            $kuldes['hibas']    = (int) $kuldes['hibas'] + ($sikeres ? 0 : 1);

            $haladasStmt->execute([
                $kuldes['utolso_cimzett_id'], $kuldes['kikuldve'], $kuldes['hibas'], $kuldesId,
            ]);

            if ($tempo > 0) {
                usleep((int) ($tempo * 1000000));
            }
        }
    }

    return [sprintf(
        'Kiküldés folyamatban (#%d): eddig %d levél ment el, %d hibás. A következő futás folytatja.',
        $kuldesId,
        (int) $kuldes['kikuldve'],
        (int) $kuldes['hibas']
    )];
}

/**
 * Egy cimzettnek valo kuldes. Igazzal ter vissza, ha sikerult.
 *
 * @param array<string,mixed> $kuldes
 * @param array<string,mixed> $cimzett
 */
function egy_cimzettnek(array $kuldes, array $cimzett): bool
{
    $token = (string) $cimzett['unsubscribe_token'];
    $url   = unsubscribe_url($token);

    $csatolmany = kuldes_csatolmanya($kuldes);

    // A "lassits" valaszra varunk es ujraprobalunk - az nem hiba.
    for ($proba = 1; $proba <= 3; $proba++) {
        try {
            send_email(
                (string) $cimzett['email'],
                (string) $kuldes['targy'],
                etlap_level_html($kuldes, $url),
                etlap_level_szoveg($kuldes, $url),
                $token,
                $csatolmany
            );

            return true;
        } catch (GraphLassitsException $lassits) {
            sleep($lassits->varakozas);
        } catch (Throwable $hiba) {
            error_log(sprintf(
                'Etlap futar - nem ment el (%s): %s',
                $cimzett['email'],
                $hiba->getMessage()
            ));

            return false;
        }
    }

    error_log('Etlap futar - tulterhelés miatt kimaradt: ' . $cimzett['email']);

    return false;
}

/**
 * A kikuldes lezarasa es osszegzes a bekuldonek.
 *
 * @param array<string,mixed> $kuldes
 * @return array<int,string>
 */
function kuldes_lezaras(array $kuldes): array
{
    db()->prepare(
        'UPDATE etlap_kuldes SET status = "kesz", befejezve = NOW() WHERE id = ?'
    )->execute([(int) $kuldes['id']]);

    $osszegzes = sprintf(
        'Az étlap kiküldése befejeződött. Elküldve: %d címre.',
        (int) $kuldes['kikuldve']
    );

    if ((int) $kuldes['hibas'] > 0) {
        $osszegzes .= sprintf(
            ' %d címre nem sikerült elküldeni – ezek jellemzően megszűnt '
            . 'vagy elgépelt címek. A részletek a hibanaplóban vannak.',
            (int) $kuldes['hibas']
        );
    }

    ertesito_bekuldonek((string) $kuldes['felado'], 'Kiküldve: ' . $kuldes['targy'], $osszegzes);

    return [$osszegzes];
}

// ---------------------------------------------------------------------
// Segedek
// ---------------------------------------------------------------------

/** @return array<string,mixed>|null */
function kuldes_betolt(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM etlap_kuldes WHERE id = ?');
    $stmt->execute([$id]);
    $kuldes = $stmt->fetch();

    return $kuldes === false ? null : $kuldes;
}

function aktiv_cimzett_szam(): int
{
    return (int) db()->query(
        'SELECT COUNT(*) FROM subscribers WHERE status = "active"'
    )->fetchColumn();
}
