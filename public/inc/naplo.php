<?php
declare(strict_types=1);

/**
 * Esemenynaplo: mi tortent a rendszerben, emberi nyelven.
 *
 * Az admin "Napló" oldala ebbol dolgozik. Szandekosan csak az ERDEMI
 * esemenyek kerulnek bele (beerkezett etlap, elonezet, kikuldes, hiba,
 * visszavonas) - a percenkenti "nem volt teendo" nem, kulonben napi
 * 1440 ures sor fullasztana el a lenyeget.
 *
 * A naplo irasa SOSEM dobhat hibat: ha a naplo nem mukodik, attol meg a
 * kikuldesnek mennie kell.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/telegram.php';

/** Ennyi napig oriz bejegyzest a naplo. */
const NAPLO_MEGORZES_NAP = 60;

/** Az esemeny sulyossaga. */
const NAPLO_SZINTEK = ['info', 'siker', 'figyelem', 'hiba'];

/**
 * Egy esemeny rogzitese. A szoveget visszaadja, igy egy sorban lehet
 * naplozni es visszaterni:  return esemeny('hiba', 'Valami elromlott');
 *
 * @param bool $ertesit Menjen-e rola Telegram ertesites. A rutinszeru,
 *                      gyakori esemenyeknel (pl. "kikuldes folyamatban")
 *                      hamis, hogy ne teljen meg a telefon.
 */
function esemeny(string $szint, string $szoveg, ?int $kuldesId = null, bool $ertesit = true): string
{
    if (!in_array($szint, NAPLO_SZINTEK, true)) {
        $szint = 'info';
    }

    try {
        naplo_tabla_biztositasa();

        db()->prepare(
            'INSERT INTO futar_naplo (ido, szint, uzenet, kuldes_id) VALUES (?, ?, ?, ?)'
        )->execute([
            // Az idot PHP-bol adjuk, igy biztosan a magyar ido szerint
            // latszik, fuggetlenul attol, milyen idozonaban fut az adatbazis.
            date('Y-m-d H:i:s'),
            $szint,
            mb_substr($szoveg, 0, 1000),
            $kuldesId,
        ]);
    } catch (Throwable $hiba) {
        // A naplo hibaja nem allithatja meg a munkat. A szerver sajat
        // hibanaplojaba azert beirjuk, hogy nyoma maradjon.
        error_log('Naplo irasa nem sikerult: ' . $hiba->getMessage() . ' | ' . $szoveg);
    }

    // Az ertesites akkor is menjen, ha a naplo irasa elbukott - sot, pont
    // akkor van ra a legnagyobb szukseg.
    if ($ertesit) {
        try {
            telegram_gyujt($szint, $szoveg);
        } catch (Throwable $hiba) {
            error_log('Telegram gyujtes nem sikerult: ' . $hiba->getMessage());
        }
    }

    return $szoveg;
}

/**
 * Letrehozza a naplotablat, ha meg nincs meg. Igy a naplohoz nem kell
 * kulon phpMyAdmin lepes. Keresenkent csak egyszer probalja.
 */
function naplo_tabla_biztositasa(): void
{
    static $kesz = false;
    if ($kesz) {
        return;
    }

    // Szabvanyos (egyszeres) idezojelek: a dupla idezojelet egyes MySQL
    // beallitasok (ANSI_QUOTES) oszlopnevnek ertik, es akkor a tabla nem
    // jonne letre - a naplo pedig nemán nem mukodne.
    db()->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS futar_naplo (
          id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
          ido       DATETIME     NOT NULL,
          szint     ENUM('info','siker','figyelem','hiba') NOT NULL DEFAULT 'info',
          uzenet    VARCHAR(1000) NOT NULL,
          kuldes_id INT UNSIGNED DEFAULT NULL,
          PRIMARY KEY (id),
          KEY idx_ido (ido),
          KEY idx_szint (szint, ido)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

    $kesz = true;
}

/** A regi bejegyzesek torlese, hogy a tabla ne hizzon a vegtelensegig. */
function naplo_takaritas(): void
{
    try {
        naplo_tabla_biztositasa();
        db()->prepare('DELETE FROM futar_naplo WHERE ido < ?')->execute([
            date('Y-m-d H:i:s', time() - NAPLO_MEGORZES_NAP * 86400),
        ]);
    } catch (Throwable $hiba) {
        error_log('Naplo takaritas nem sikerult: ' . $hiba->getMessage());
    }
}

/** Ennyi ido utan jelezzuk ujra ugyanazt a hibat, ha meg mindig fennall. */
const ISMETLODO_HIBA_CSEND_ORA = 6;

/**
 * Egy tartos hiba (pl. hianyzo jogosultsag, leallt adatbazis) percenkent
 * ujra elojonne, es naponta 1440 ugyanolyan bejegyzes + Telegram-uzenet
 * lenne belole. Ezert ugyanazt a hibat csak egyszer rogzitjuk, es csak
 * 6 ora mulva jelezzuk ujra, ha meg mindig fennall. A szerver sajat
 * hibanaplojaba ettol fuggetlenul minden alkalommal bekerul.
 *
 * Az allapotot FAJLBAN tartjuk, nem az adatbazisban: igy akkor is
 * mukodik, ha eppen az adatbazis all - pont akkor van ra a legnagyobb
 * szukseg.
 */
function ismetlodo_hiba(string $kulcs, string $szoveg): string
{
    $fajl = hibaallapot_fajl($kulcs);
    $lenyomat = md5($szoveg);

    $elozo = @file_get_contents($fajl);
    if (is_string($elozo) && str_contains($elozo, '|')) {
        [$mikor, $regiLenyomat] = explode('|', trim($elozo), 2);
        if ($regiLenyomat === $lenyomat
            && time() - (int) $mikor < ISMETLODO_HIBA_CSEND_ORA * 3600) {
            return $szoveg;
        }
    }

    // Ha a fajl nem irhato, inkabb szoljunk minden alkalommal, mint hogy
    // elnyeljuk a hibat.
    @file_put_contents($fajl, time() . '|' . $lenyomat, LOCK_EX);

    return esemeny('hiba', $szoveg);
}

/** Ha egy korabban jelzett hiba megszunt, azt is jelezzuk - egyszer. */
function hiba_megszunt(string $kulcs, string $helyreallt): void
{
    $fajl = hibaallapot_fajl($kulcs);
    if (!is_file($fajl)) {
        return;
    }

    @unlink($fajl);
    esemeny('siker', $helyreallt);
}

/**
 * A hibaallapot fajl helye. A nev tartalmazza az adatbazis nevet, hogy
 * ha tobb oldal osztozik a tarhely ideiglenes mappajan, ne keveredjenek.
 */
function hibaallapot_fajl(string $kulcs): string
{
    $azonosito = substr(md5(cfg('db_name') . '|' . __DIR__), 0, 12);

    return rtrim(sys_get_temp_dir(), '/')
         . '/ttk-kantin-' . $azonosito . '-' . preg_replace('/[^a-z0-9_]/', '', $kulcs) . '.hiba';
}
