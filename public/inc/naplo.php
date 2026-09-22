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

/** Ennyi napig oriz bejegyzest a naplo. */
const NAPLO_MEGORZES_NAP = 60;

/** Az esemeny sulyossaga. */
const NAPLO_SZINTEK = ['info', 'siker', 'figyelem', 'hiba'];

/**
 * Egy esemeny rogzitese. A szoveget visszaadja, igy egy sorban lehet
 * naplozni es visszaterni:  return esemeny('hiba', 'Valami elromlott');
 */
function esemeny(string $szint, string $szoveg, ?int $kuldesId = null): string
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
