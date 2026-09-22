<?php
declare(strict_types=1);

/**
 * Az etlap futar inditasa. Ezt hivja meg a tarhely idozitoje (cron).
 *
 * Ketfelekeppen lehet inditani:
 *
 *   1. Parancssorbol (ez a jo megoldas a Hostinger cron beallitasaban):
 *        /usr/bin/php /home/<felhasznalo>/public_html/futar.php
 *      Igy a futas nem a webszerveren keresztul megy, nincs idokorlat,
 *      es kivulrol egyaltalan nem erheto el.
 *
 *   2. Webcimmel, ha a tarhely csak azt tudja:
 *        https://ttketlap.pepperhouse.hu/futar.php?kulcs=<cron_kulcs>
 *      A kulcs nelkul a lap 404-et ad, mintha nem is letezne - igy
 *      kivulrol nem lehet raakadni, hogy van itt egyaltalan valami.
 */

require __DIR__ . '/inc/etlap_futar.php';

$parancssor = PHP_SAPI === 'cli';

if ($parancssor) {
    // Parancssorban nincs idokorlat, a kikuldes igy nyugodtan dolgozhat.
    set_time_limit(0);
} else {
    $kulcs = cfg('cron_kulcs');
    $kapott = (string) ($_GET['kulcs'] ?? '');

    // hash_equals: allando ideju osszehasonlitas, hogy a valaszido ne
    // arulja el, hany karakter volt mar jo a tippbol.
    if ($kulcs === '' || !hash_equals($kulcs, $kapott)) {
        http_response_code(404);
        exit('Nincs ilyen oldal.');
    }

    header('Content-Type: text/plain; charset=utf-8');
    // A keresomotorok semmikeppen ne indexeljek.
    header('X-Robots-Tag: noindex, nofollow');
}

// A sajat hibakezeles fontos: enelkul egy adatbazishiba eseten a kozos
// hibakezelo egy teljes HTML oldalt irna ki, ami a cron naploban
// olvashatatlan. Itt egy sor a valasz, es a kilepesi kod is beszedes.
try {
    $naplo = futar_fut();
} catch (Throwable $hiba) {
    error_log('Etlap futar - a futas elszallt: ' . $hiba->getMessage());

    if (!$parancssor) {
        http_response_code(500);
    }

    echo 'A futás nem sikerült: ', $hiba->getMessage(), PHP_EOL;
    exit(1);
}

// Percenkent futo idozitonel a "nem volt teendo" kiiras karos lehet: ha a
// tarhely emailben kuldi a cron kimenetet, az naponta 1440 levél. Ezert
// parancssorban csak akkor szolunk, ha tortent valami - vagy ha kezzel,
// a -v kapcsoloval inditjak.
$beszedes = !$parancssor || in_array('-v', $argv ?? [], true);

foreach ($naplo as $sor) {
    echo $sor, PHP_EOL;
}

if (!$naplo && $beszedes) {
    echo 'Nem volt teendő.', PHP_EOL;
}
