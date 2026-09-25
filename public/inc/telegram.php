<?php
declare(strict_types=1);

/**
 * Telegram ertesites a naplo esemenyeirol.
 *
 * Nem minden esemenyrol megy kulon uzenet: egy futas (vagy egy oldal-
 * betoltes) alatt tortent esemenyeket osszegyujtjuk, es a vegen EGY
 * uzenetben kuldjuk el. Igy egy kikuldes nem tolti tele a telefont.
 *
 * Beallitas (config.php):
 *   'telegram_bot_token' => '123456:ABC...',  // a @BotFather adja
 *   'telegram_chat_id'   => '-100123...',     // kinek/melyik csoportba
 *   'telegram_thread_id' => '42',             // temas csoportnal: melyik temaba
 *
 * Ha barmelyik ures, az ertesites egyszeruen kimarad.
 * Az ertesites hibaja SOSEM akaszthatja meg a munkat.
 */

require_once __DIR__ . '/bootstrap.php';

/** A Telegram egy uzenetben legfeljebb 4096 karaktert enged. */
const TELEGRAM_MAX_HOSSZ = 3800;

/** Egy uzenetben legfeljebb ennyi esemenyt sorolunk fel. */
const TELEGRAM_MAX_SOR = 15;

function telegram_beallitva(): bool
{
    return cfg('telegram_bot_token') !== '' && cfg('telegram_chat_id') !== '';
}

/**
 * Egy esemeny felvetele a kovetkezo osszefoglaloba. Az elso esemenynel
 * beregisztralja a kuldest a futas vegere.
 */
function telegram_gyujt(string $szint, string $szoveg): void
{
    if (!telegram_beallitva()) {
        return;
    }

    $sorok = &telegram_puffer();

    if (!$sorok) {
        register_shutdown_function('telegram_osszefoglalo_kuldese');
    }

    $sorok[] = ['ido' => date('H:i'), 'szint' => $szint, 'szoveg' => $szoveg];
}

/** @return array<int,array{ido:string,szint:string,szoveg:string}> */
function &telegram_puffer(): array
{
    static $sorok = [];
    return $sorok;
}

/** Az osszegyujtott esemenyek elkuldese egy uzenetben. */
function telegram_osszefoglalo_kuldese(): void
{
    $sorok = &telegram_puffer();
    if (!$sorok) {
        return;
    }

    $szoveg = telegram_osszefoglalo($sorok);
    $sorok = [];

    telegram_kuldes($szoveg);
}

/**
 * Az uzenet szovege. HTML formazast hasznalunk, mert abban csak harom
 * karaktert kell kodolni (& < >) - a Telegram "Markdown" modja minden
 * alahuzasnal es csillagnal hibat dob, egy email-cimben pedig van ilyen.
 *
 * @param array<int,array{ido:string,szint:string,szoveg:string}> $sorok
 */
function telegram_osszefoglalo(array $sorok): string
{
    $jelek = ['siker' => '✅', 'figyelem' => '⚠️', 'hiba' => '❌', 'info' => '▫️'];

    $hibak = count(array_filter($sorok, fn($s) => $s['szint'] === 'hiba'));
    $fejlec = $hibak > 0
        ? '❌ <b>TTK Kantin étlap – ' . $hibak . ' hiba</b>'
        : '🍽 <b>TTK Kantin étlap</b>';

    $sorSzovegek = [];
    foreach (array_slice($sorok, 0, TELEGRAM_MAX_SOR) as $sor) {
        $sorSzovegek[] = ($jelek[$sor['szint']] ?? '▫️') . ' <i>' . $sor['ido'] . '</i> '
                       . telegram_kodol($sor['szoveg']);
    }

    $kimaradt = count($sorok) - TELEGRAM_MAX_SOR;
    if ($kimaradt > 0) {
        $sorSzovegek[] = '… és még ' . $kimaradt . ' esemény.';
    }

    $lab = '';
    $siteUrl = rtrim(cfg('site_url'), '/');
    if ($siteUrl !== '') {
        $lab = "\n\n" . '<a href="' . telegram_kodol($siteUrl . '/admin/naplo.php') . '">Részletek a naplóban</a>';
    }

    $torzs = implode("\n\n", $sorSzovegek);

    // Ha nagyon hosszu lenne, levagjuk - de ugy, hogy a fejlec es a link
    // megmaradjon, es ne egy HTML-jel kozepen vagjunk.
    $keret = mb_strlen($fejlec) + mb_strlen($lab) + 10;
    if (mb_strlen($torzs) + $keret > TELEGRAM_MAX_HOSSZ) {
        $torzs = mb_substr($torzs, 0, TELEGRAM_MAX_HOSSZ - $keret);
        $torzs = (string) preg_replace('/<[^>]*$/u', '', $torzs) . '…';
        // Ha egy <i> nyitva maradt, lezarjuk, kulonben a Telegram elutasitja.
        if (substr_count($torzs, '<i>') > substr_count($torzs, '</i>')) {
            $torzs .= '</i>';
        }
    }

    return $fejlec . "\n\n" . $torzs . $lab;
}

/** A Telegram HTML modjanak kodolasa. */
function telegram_kodol(string $szoveg): string
{
    return htmlspecialchars($szoveg, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Egy uzenet elkuldese. Igazzal ter vissza, ha a Telegram elfogadta.
 * Soha nem dob hibat. A $hiba parameterbe beirja, mi volt a gond - ezt
 * az admin "teszt" gombja mutatja meg.
 */
function telegram_kuldes(string $szoveg, ?string &$hiba = null): bool
{
    $hiba = null;

    // A cim csak teszteleshez irhato felul (al-Telegram szerverrel).
    $alapUrl = cfg('telegram_api_url', 'https://api.telegram.org');

    if (!telegram_beallitva()) {
        $hiba = 'Nincs beállítva a telegram_bot_token vagy a telegram_chat_id.';
        return false;
    }
    if (!function_exists('curl_init')) {
        $hiba = 'A PHP cURL bővítmény nem érhető el.';
        return false;
    }

    $mezok = [
        'chat_id'                  => cfg('telegram_chat_id'),
        'text'                     => $szoveg,
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => 'true',
    ];

    // Temakra (topic/thread) bontott csoportnal enelkul az uzenet az
    // "Altalanos" temaba menne, nem oda, ahova szanjuk.
    if (cfg('telegram_thread_id') !== '') {
        $mezok['message_thread_id'] = cfg('telegram_thread_id');
    }

    try {
        $ch = curl_init(rtrim($alapUrl, '/') . '/bot' . cfg('telegram_bot_token') . '/sendMessage');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($mezok),
            CURLOPT_RETURNTRANSFER => true,
            // Rovid idokorlat: ha a Telegram lassu, ne tartsa fel a futast.
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $valasz = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $halo   = curl_error($ch);
        curl_close($ch);

        if ($valasz === false) {
            $hiba = 'Hálózati hiba: ' . $halo;
        } else {
            $adat = json_decode((string) $valasz, true);
            if ($status === 200 && is_array($adat) && ($adat['ok'] ?? false) === true) {
                return true;
            }
            $hiba = telegram_hiba_magyarul($status, (string) ($adat['description'] ?? $valasz));

            // Ha a csoportot kozben szupercsoportta alakitottak (pl. a temak
            // bekapcsolasakor), a Telegram megadja az uj azonositot is.
            $uj = $adat['parameters']['migrate_to_chat_id'] ?? null;
            if ($uj !== null) {
                $hiba .= ' Az új azonosító: ' . $uj . ' – ezt írja a telegram_chat_id helyére.';
            }
        }
    } catch (Throwable $kivetel) {
        $hiba = $kivetel->getMessage();
    }

    // A tokent sosem irjuk a naploba - a hibauzenetben nincs benne.
    error_log('Telegram ertesites nem ment el: ' . $hiba);

    return false;
}

/** A gyakori Telegram-hibak magyarul. */
function telegram_hiba_magyarul(int $status, string $leiras): string
{
    $tipp = match (true) {
        $status === 401 => ' – hibás a bot token (telegram_bot_token).',
        str_contains($leiras, 'chat not found') => ' – ismeretlen chat. Ellenőrizze a telegram_chat_id '
            . 'értékét, és hogy írt-e már a botnak (vagy hozzáadta-e a csoporthoz).',
        str_contains($leiras, 'thread not found') => ' – nincs ilyen téma a csoportban. Ellenőrizze '
            . 'a telegram_thread_id értékét (a téma linkjében a csoport azonosítója utáni szám).',
        str_contains($leiras, 'TOPIC_CLOSED') => ' – ez a téma le van zárva, a bot nem írhat bele.',
        str_contains($leiras, 'upgraded to a supergroup') => ' – ez a csoport régi azonosítója. '
            . 'A csoportot közben szupercsoporttá alakították, és új azonosítót kapott.',
        str_contains($leiras, 'bot was blocked') => ' – a botot letiltották ebben a chatben.',
        str_contains($leiras, 'not enough rights') => ' – a botnak nincs joga írni ebbe a csoportba.',
        default => '',
    };

    return 'Telegram hiba (HTTP ' . $status . '): ' . $leiras . $tipp;
}
