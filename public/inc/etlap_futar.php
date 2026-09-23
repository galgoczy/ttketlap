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
require_once __DIR__ . '/naplo.php';

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
                hiba_megszunt($lepes, lepes_helyreallt($lepes));
            } catch (Throwable $hiba) {
                error_log(sprintf('Etlap futar - %s: %s', $lepes, $hiba->getMessage()));
                $naplo[] = ismetlodo_hiba($lepes, lepes_neve($lepes) . ': ' . $hiba->getMessage());
            }
        }
    } finally {
        db()->prepare('SELECT RELEASE_LOCK(?)')
            ->execute(['etlap_futar_' . cfg('db_name')]);
    }

    allapot_ment('futar_utolso_futas', date('Y-m-d H:i:s'));
    naplo_takaritas();

    return $naplo;
}

/** A helyreallas emberi szovege. */
function lepes_helyreallt(string $kulcs): string
{
    return match ($kulcs) {
        'beerkezett_feldolgozas' => 'A postafiók ellenőrzése újra működik.',
        'kuldesek_inditasa'      => 'A kiküldések indítása újra működik.',
        'kuldes_folytatasa'      => 'A kiküldés újra halad.',
        'uzenet'                 => 'A beérkezett levelek feldolgozása újra működik.',
        default                  => 'A korábbi hiba megszűnt.',
    };
}

/** A lepesek emberi neve a naplohoz. */
function lepes_neve(string $lepes): string
{
    return match ($lepes) {
        'beerkezett_feldolgozas' => 'A postafiók ellenőrzése nem sikerült',
        'kuldesek_inditasa'      => 'A kiküldések indítása nem sikerült',
        'kuldes_folytatasa'      => 'A kiküldés megakadt',
        default                  => 'Hiba',
    };
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

    $uzenetHiba = false;

    foreach ($uzenetek as $uzenet) {
        try {
            $naplo[] = uzenet_feldolgozas($mailbox, $uzenet);
        } catch (Throwable $hiba) {
            error_log('Etlap futar - uzenet hiba: ' . $hiba->getMessage());
            // Ha egy levelet nem sikerul feldolgozni, az olvasatlan marad,
            // es a kovetkezo futas ujra probalja - vagyis a hiba percenkent
            // ismetlodne. Ezert ugyanugy szurjuk, mint a lepeshibakat.
            $naplo[] = ismetlodo_hiba('uzenet', 'Hiba egy levél feldolgozásakor: ' . $hiba->getMessage());
            $uzenetHiba = true;
        }
    }

    // Ha most minden level rendben ment, egy korabbi hiba megszunt.
    if (!$uzenetHiba) {
        hiba_megszunt('uzenet', lepes_helyreallt('uzenet'));
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
        return esemeny('figyelem', 'Azonosító nélküli levél érkezett, kihagyva.');
    }

    // Hurokvedelem. NEM a felado cime alapjan dontunk: a felado cim
    // (mail_from) egyben jogosult bekuldo is lehet - nalunk pont az.
    // Ehelyett a sajat leveleinken levo rejtett jelolot keressuk, es
    // kizarjuk azt az esetet, amikor a postafiok onmagatol kap levelet.
    if ($felado === mb_strtolower($mailbox) || sajat_levelunk($uzenet)) {
        uzenet_olvasott($mailbox, $id);
        return esemeny('info', 'Saját levelünk került vissza a postafiókba, kihagyva.', null, false);
    }

    if (!in_array($felado, etlap_bekuldok(), true)) {
        uzenet_olvasott($mailbox, $id);
        // Ismeretlen feladonak szandekosan NEM valaszolunk: a felado cime
        // hamisithato, es a valasz egy artatlan emberhez jutna el.
        return esemeny('figyelem', sprintf(
            'Levél érkezett nem jogosult feladótól (%s) – nem dolgoztuk fel. '
            . 'Ha ez a cím is küldhet étlapot, vegye fel az etlap_bekuldok közé.',
            $felado !== '' ? $felado : 'ismeretlen'
        ));
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
        return esemeny('figyelem', sprintf(
            '%s levelében nem volt PDF, JPG vagy PNG csatolmány. Értesítettük.',
            $felado
        ));
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
        return esemeny('figyelem', sprintf(
            '%s túl nagy fájlt küldött (%s). Értesítettük, nem ment ki semmi.',
            $felado,
            meret_szoveg(strlen($pdf['tartalom']))
        ));
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
        return esemeny('info', 'Egy korábban már feldolgozott levél újra előkerült, kihagyva.', null, false);
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
        return esemeny('hiba', sprintf(
            'Az előnézetet nem sikerült elküldeni %s címre, ezért a kiküldés NEM indul el. Ok: %s',
            $felado,
            $hiba->getMessage()
        ), $kuldesId);
    }

    return esemeny('info', sprintf(
        'Új étlap érkezett %s címről: „%s" (%s, %s). Előnézet elküldve, a kiküldés %s-kor indul.',
        $felado,
        $targy,
        $pdf['nev'],
        meret_szoveg(strlen($pdf['tartalom'])),
        date('H:i', strtotime($kuldesIdeje))
    ), $kuldesId);
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
    return email_keret(
        (string) $kuldes['targy'],
        etlap_torzs($kuldes),
        kuldes_kep_e($kuldes) ? 'Itt a mai étlap.' : 'A mai étlap a csatolmányban.',
        $leiratkozoUrl
    );
}

/**
 * A level torzse: bevezeto szoveg + az etlap (beagyazott kep vagy a
 * csatolmanyra utalo sor). Az elonezet is ezt hasznalja, igy a bekuldo
 * pontosan azt latja, amit a cimzettek kapnak.
 *
 * @param array<string,mixed> $kuldes
 */
function etlap_torzs(array $kuldes): string
{
    $bekezdesek = bevezeto_bekezdesek((string) $kuldes['bevezeto']);
    $kep = kuldes_kep_e($kuldes);

    // Sajat alapszovegnel tudjuk, hol az alairas: az mindig az utolso
    // bekezdes. Az etlapot ele tesszuk, kulonben a kep az alairas ala
    // csusszan. A bekuldo sajat szovegenel ezt nem talalgatjuk.
    $alairas = '';
    if (alap_bevezeto_e($kuldes) && count($bekezdesek) > 1) {
        $alairas = (string) array_pop($bekezdesek);
    }

    $torzs = '';
    foreach ($bekezdesek as $bekezdes) {
        $torzs .= email_bekezdes($bekezdes);
    }

    if ($kep) {
        // Kepnel a level torzsebe agyazzuk: a cimzett rogton latja,
        // nem kell megnyitnia semmit.
        $torzs .= email_kep(ETLAP_KEP_CID);
    } elseif (!alap_bevezeto_e($kuldes)) {
        // Az alapszoveg maga mondja, hogy mellekelten kuldjuk - ott ez
        // a sor csak ismetles lenne.
        $torzs .= email_bekezdes('Az étlapot a levél csatolmányában, PDF-ben találja.', true);
    }

    if ($alairas !== '') {
        $torzs .= email_bekezdes($alairas);
    }

    return $torzs;
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
    $alairas  = '';

    if ($bevezeto === '') {
        // Ugyanaz a felepites, mint a HTML valtozatban: az alairas
        // az etlap utan jon.
        $bekezdesek = ETLAP_ALAP_BEVEZETO;
        $alairas    = (string) array_pop($bekezdesek);
        $bevezeto   = implode("\n\n", $bekezdesek);
    }

    $sorok[] = $bevezeto;
    $sorok[] = '';

    $sorok[] = kuldes_kep_e($kuldes)
        ? 'Az étlapot a levélben képként küldtük. Ha nem látja, engedélyezze a képek megjelenítését.'
        : 'Az étlapot a levél csatolmányában, PDF-ben találja.';
    $sorok[] = '';

    if ($alairas !== '') {
        $sorok[] = $alairas;
        $sorok[] = '';
    }
    $sorok[] = '--';
    $sorok[] = 'Ezt a levelet azért kapja, mert feliratkozott a TTK Kantin étlapjára.';

    if ($leiratkozoUrl !== '') {
        $sorok[] = 'Leiratkozás: ' . $leiratkozoUrl;
    }

    return implode("\n", $sorok);
}

/**
 * Ez a szoveg megy ki, ha a bekuldo ures levelet kuldott (csak csatolmanyt).
 * Az utolso bekezdes az alairas - a kep/csatolmany ele kerul, hogy az
 * alairas maradjon a levél vegen.
 */
const ETLAP_ALAP_BEVEZETO = [
    'Kedves Vendégünk!',
    'Mellékelten küldjük friss étlapunkat. Reméljük, hogy hamarosan ismét '
    . 'vendégül láthatjuk!',
    'a TTK Kantin csapata',
];

/** Sajat alapszoveggel megy-e ki a level, vagy a bekuldo irt sajatot? */
function alap_bevezeto_e(array $kuldes): bool
{
    return trim((string) ($kuldes['bevezeto'] ?? '')) === '';
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
        return array_map('e', ETLAP_ALAP_BEVEZETO);
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

    return $bekezdesek ?: array_map('e', ETLAP_ALAP_BEVEZETO);
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

    // Az elonezet pontosan azt mutassa, amit a cimzettek kapnak - ezert
    // ugyanaz a fuggveny epiti a torzset, mint a kimeno levelnel.
    $torzs = $doboz . etlap_torzs($kuldes);

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
    $esedekes = db()->prepare(
        'SELECT id, targy FROM etlap_kuldes
          WHERE status = "elonezet" AND kuldes_ideje <= ?
          ORDER BY id'
    );
    $esedekes->execute([date('Y-m-d H:i:s')]);

    // A feltetelben ujra ott a status: ha kozben valaki visszavonta,
    // ez nem indítja el.
    $indit = db()->prepare(
        'UPDATE etlap_kuldes SET status = "kuldes" WHERE id = ? AND status = "elonezet"'
    );

    $naplo = [];
    foreach ($esedekes->fetchAll() as $sor) {
        $indit->execute([(int) $sor['id']]);
        if ($indit->rowCount() === 1) {
            $naplo[] = esemeny('info', sprintf(
                'Letelt a visszavonási idő, indul a kiküldés: „%s" – %d címre.',
                $sor['targy'],
                aktiv_cimzett_szam()
            ), (int) $sor['id']);
        }
    }

    return $naplo;
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

    // A haladasrol percenkent szolna - Telegramra ezert nem megy, csak
    // az indulas es a befejezes.
    return [esemeny('info', sprintf(
        'Kiküldés folyamatban: „%s" – eddig %d levél ment el%s. A következő futás folytatja.',
        $kuldes['targy'],
        (int) $kuldes['kikuldve'],
        (int) $kuldes['hibas'] > 0 ? ', ' . (int) $kuldes['hibas'] . ' nem sikerült' : ''
    ), $kuldesId, false)];
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
            esemeny('figyelem', sprintf(
                'A Microsoft lassításra kért, %d másodpercet várunk (ez nem hiba).',
                $lassits->varakozas
            ), (int) $kuldes['id'], false);
            sleep($lassits->varakozas);
        } catch (Throwable $hiba) {
            // Telegramra nem kuldjuk egyenkent: sok rossz cimnel elarasztana,
            // es a zaro osszegzes ugyis megmondja, hany nem ment el.
            esemeny('hiba', sprintf(
                'Nem ment el %s címre: %s',
                $cimzett['email'],
                $hiba->getMessage()
            ), (int) $kuldes['id'], false);

            return false;
        }
    }

    esemeny('hiba', sprintf(
        'Nem ment el %s címre: a Microsoft háromszor is lassításra kért, kihagytuk.',
        $cimzett['email']
    ), (int) $kuldes['id'], false);

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
        'UPDATE etlap_kuldes SET status = "kesz", befejezve = ? WHERE id = ?'
    )->execute([date('Y-m-d H:i:s'), (int) $kuldes['id']]);

    $osszegzes = sprintf(
        'Az étlap kiküldése befejeződött. Elküldve: %d címre.',
        (int) $kuldes['kikuldve']
    );

    if ((int) $kuldes['hibas'] > 0) {
        $osszegzes .= sprintf(
            ' %d címre nem sikerült elküldeni – ezek jellemzően megszűnt '
            . 'vagy elgépelt címek. A részletek az admin felület Napló oldalán láthatók.',
            (int) $kuldes['hibas']
        );
    }

    ertesito_bekuldonek((string) $kuldes['felado'], 'Kiküldve: ' . $kuldes['targy'], $osszegzes);

    return [esemeny(
        (int) $kuldes['hibas'] > 0 ? 'figyelem' : 'siker',
        '„' . $kuldes['targy'] . '": ' . $osszegzes,
        (int) $kuldes['id']
    )];
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
