<?php
declare(strict_types=1);

/**
 * A Telegram ertesites ellenorzese egy al-Telegram szerverrel.
 *
 * Futtatas a projekt gyokerebol:
 *   1. php -S 127.0.0.1:8098 tools/al-telegram.php   (kulon ablakban)
 *   2. php tools/teszt-telegram.php
 *
 * A valodi Telegramhoz nem nyul. Az al-szerver rogziti, pontosan milyen
 * kerest kapott volna a Telegram, es ugy valaszol, ahogy az valaszolna.
 */

$GLOBALS['hibak'] = 0;
$GLOBALS['okk']   = 0;

function allit(string $mit, bool $igaz, string $reszlet = ''): void
{
    if ($igaz) {
        $GLOBALS['okk']++;
        echo "  ok    $mit\n";
        return;
    }
    $GLOBALS['hibak']++;
    echo "  HIBA  $mit" . ($reszlet !== '' ? "\n        $reszlet" : '') . "\n";
}

function fejezet(string $cim): void
{
    echo "\n$cim\n";
}

const AL_TELEGRAM = 'http://127.0.0.1:8098';
const KERES_NAPLO = __DIR__ . '/al-telegram-keresek.jsonl';

/** @return array<int,array<string,mixed>> */
function keresek(): array
{
    if (!is_file(KERES_NAPLO)) {
        return [];
    }
    return array_map(
        fn($sor) => json_decode($sor, true),
        array_filter(explode("\n", (string) file_get_contents(KERES_NAPLO)))
    );
}

require __DIR__ . '/../public/inc/naplo.php';

$GLOBALS['config']['telegram_bot_token'] = '123456:TESZT_TOKEN_abcdefghijklmnopqrstuvwxyz';
$GLOBALS['config']['telegram_chat_id']   = '42';
$GLOBALS['config']['telegram_api_url']   = AL_TELEGRAM;
$GLOBALS['config']['site_url']           = 'https://ttketlap.pepperhouse.hu';

// ---------------------------------------------------------------
fejezet('Az üzenet szövege');

$uzenet = telegram_osszefoglalo([
    ['ido' => '10:25', 'szint' => 'info',  'szoveg' => 'Új étlap érkezett ttk@pepperhouse.hu címről'],
    ['ido' => '10:40', 'szint' => 'siker', 'szoveg' => 'Kiküldve 142 címre.'],
]);
allit('van fejléc', str_contains($uzenet, 'TTK Kantin étlap'));
allit('hiba nélkül nem ijesztget', !str_contains($uzenet, 'hiba</b>'));
allit('minden esemény benne van', str_contains($uzenet, 'Új étlap') && str_contains($uzenet, '142 címre'));
allit('az időpont látszik', str_contains($uzenet, '10:25'));
allit('link a naplóra', str_contains($uzenet, 'https://ttketlap.pepperhouse.hu/admin/naplo.php'));

$hibas = telegram_osszefoglalo([
    ['ido' => '10:25', 'szint' => 'hiba', 'szoveg' => 'Valami elromlott'],
    ['ido' => '10:26', 'szint' => 'hiba', 'szoveg' => 'Megint'],
]);
allit('hibánál a fejléc is szól, és megszámolja', str_contains($hibas, '2 hiba'), strtok($hibas, "\n"));

// Az emailben es a hibauzenetekben lehet < > & - ezek eltornek a Telegram
// HTML-jet, ha nincsenek kodolva, es akkor az uzenet el sem menne.
$veszelyes = telegram_osszefoglalo([
    ['ido' => '10:00', 'szint' => 'hiba', 'szoveg' => 'Hiba <script> & "idézet" a_b*c'],
]);
allit('a < > & kódolva van', str_contains($veszelyes, '&lt;script&gt; &amp;'), $veszelyes);
allit('nyers <script> nem marad', !str_contains($veszelyes, '<script>'));

$sok = [];
for ($i = 1; $i <= 40; $i++) {
    $sok[] = ['ido' => '10:00', 'szint' => 'info', 'szoveg' => "Esemény $i"];
}
$sokUzenet = telegram_osszefoglalo($sok);
allit('sok eseménynél csak az elsőket sorolja', !str_contains($sokUzenet, 'Esemény 16'));
allit('és jelzi, hány maradt ki', str_contains($sokUzenet, 'még 25 esemény'), '');

$hosszu = [];
for ($i = 1; $i <= 15; $i++) {
    $hosszu[] = ['ido' => '10:00', 'szint' => 'hiba', 'szoveg' => str_repeat('nagyon hosszú hibaüzenet ', 30)];
}
$hosszuUzenet = telegram_osszefoglalo($hosszu);
allit('belefér a Telegram 4096 karakteres korlátjába', mb_strlen($hosszuUzenet) <= 4096,
    (string) mb_strlen($hosszuUzenet));
allit('levágás után sem marad nyitott <i>',
    substr_count($hosszuUzenet, '<i>') === substr_count($hosszuUzenet, '</i>'));
allit('levágás után is megmarad a link', str_contains($hosszuUzenet, 'Részletek a naplóban'));

// ---------------------------------------------------------------
fejezet('Küldés az ál-Telegramnak');

@unlink(KERES_NAPLO);
$hiba = null;
$ok = telegram_kuldes('Próba <b>üzenet</b>', $hiba);
$k = keresek();

allit('sikeresnek jelzi', $ok, (string) $hiba);
allit('pontosan egy kérés ment', count($k) === 1, (string) count($k));
allit('a jó címre ment (/bot<token>/sendMessage)',
    ($k[0]['ut'] ?? '') === '/bot123456:TESZT_TOKEN_abcdefghijklmnopqrstuvwxyz/sendMessage',
    (string) ($k[0]['ut'] ?? ''));
allit('a jó chatbe', ($k[0]['post']['chat_id'] ?? '') === '42');
allit('HTML formázással', ($k[0]['post']['parse_mode'] ?? '') === 'HTML');
allit('a szöveg épen megérkezett', ($k[0]['post']['text'] ?? '') === 'Próba <b>üzenet</b>');

// Hibas token
$GLOBALS['config']['telegram_bot_token'] = '1:ROSSZTOKEN';
$hiba = null;
$ok = telegram_kuldes('x', $hiba);
allit('rossz tokennél nem dob hibát, csak hamisat ad', $ok === false);
allit('magyarul megmondja, mi a baj', str_contains((string) $hiba, 'hibás a bot token'), (string) $hiba);
allit('a hibaüzenetben nincs benne a token', !str_contains((string) $hiba, 'ROSSZTOKEN'));

// Ismeretlen chat
$GLOBALS['config']['telegram_bot_token'] = '123456:TESZT_TOKEN_abcdefghijklmnopqrstuvwxyz';
$GLOBALS['config']['telegram_chat_id']   = '999';
$hiba = null;
telegram_kuldes('x', $hiba);
allit('ismeretlen chatnél tanácsot ad', str_contains((string) $hiba, 'telegram_chat_id'), (string) $hiba);
$GLOBALS['config']['telegram_chat_id'] = '42';

// Elerhetetlen szerver
$GLOBALS['config']['telegram_api_url'] = 'http://127.0.0.1:1';
$hiba = null;
$dobott = false;
try { $ok = telegram_kuldes('x', $hiba); } catch (Throwable $e) { $dobott = true; }
allit('elérhetetlen Telegramnál sem dob hibát', !$dobott && $ok === false, (string) $hiba);
$GLOBALS['config']['telegram_api_url'] = AL_TELEGRAM;

// Nincs beallitva
$GLOBALS['config']['telegram_chat_id'] = '';
allit('beállítás nélkül nem küld', telegram_kuldes('x') === false);
$GLOBALS['config']['telegram_chat_id'] = '42';

// ---------------------------------------------------------------
fejezet('Egy futás = egy üzenet');

// Kulon folyamatban futtatjuk, mert a kuldes a folyamat VEGEN tortenik.
// Adatbazis nincs - a naplo irasa elbukik, az ertesitesnek ettol meg
// mennie kell.
@unlink(KERES_NAPLO);
$szkript = <<<'PHP'
<?php
require $argv[1] . '/public/inc/naplo.php';
$GLOBALS['config']['telegram_bot_token'] = '123456:TESZT_TOKEN_abcdefghijklmnopqrstuvwxyz';
$GLOBALS['config']['telegram_chat_id']   = '42';
$GLOBALS['config']['telegram_api_url']   = 'http://127.0.0.1:8098';
esemeny('info',  'Új étlap érkezett');
esemeny('info',  'Kiküldés folyamatban – ez rutin', null, false);
esemeny('siker', 'Kiküldve 142 címre');
echo "kész\n";
PHP;
$ideiglenes = sys_get_temp_dir() . '/tg-futas-' . getmypid() . '.php';
file_put_contents($ideiglenes, $szkript);
$kimenet = shell_exec('php ' . escapeshellarg($ideiglenes) . ' ' . escapeshellarg(dirname(__DIR__)) . ' 2>/dev/null');
unlink($ideiglenes);

$k = keresek();
allit('a futás rendben lefutott', trim((string) $kimenet) === 'kész', (string) $kimenet);
allit('három esemény EGY üzenetben ment', count($k) === 1, count($k) . ' üzenet');
$szoveg = (string) ($k[0]['post']['text'] ?? '');
allit('benne van az új étlap', str_contains($szoveg, 'Új étlap érkezett'));
allit('benne van a befejezés', str_contains($szoveg, 'Kiküldve 142 címre'));
allit('a rutin esemény NEM ment ki', !str_contains($szoveg, 'rutin'));
allit('adatbázis nélkül is elment (a napló hibája nem állítja meg)', $k !== []);

// Ha csak rutin esemeny volt, ne menjen semmi.
@unlink(KERES_NAPLO);
$szkript2 = <<<'PHP'
<?php
require $argv[1] . '/public/inc/naplo.php';
$GLOBALS['config']['telegram_bot_token'] = '123456:TESZT_TOKEN_abcdefghijklmnopqrstuvwxyz';
$GLOBALS['config']['telegram_chat_id']   = '42';
$GLOBALS['config']['telegram_api_url']   = 'http://127.0.0.1:8098';
esemeny('info', 'Kiküldés folyamatban', null, false);
PHP;
file_put_contents($ideiglenes, $szkript2);
shell_exec('php ' . escapeshellarg($ideiglenes) . ' ' . escapeshellarg(dirname(__DIR__)) . ' 2>/dev/null');
unlink($ideiglenes);
allit('csak rutin eseménynél nem jön üzenet', keresek() === []);

@unlink(KERES_NAPLO);

// ---------------------------------------------------------------
fejezet('Ismétlődő hiba: egyszer szól, és akkor is, ha megjavult');

// Minden lepes kulon folyamat, ahogy elesben a percenkenti futasok.
$futtat = function (string $kod): void {
    $szkript = "<?php\nrequire \$argv[1] . '/public/inc/naplo.php';\n"
        . "\$GLOBALS['config']['telegram_bot_token'] = '123456:TESZT_TOKEN_abcdefghijklmnopqrstuvwxyz';\n"
        . "\$GLOBALS['config']['telegram_chat_id'] = '42';\n"
        . "\$GLOBALS['config']['telegram_api_url'] = 'http://127.0.0.1:8098';\n"
        . "\$GLOBALS['config']['db_name'] = 'teszt_ismetlodes';\n"
        . $kod;
    $f = sys_get_temp_dir() . '/tg-ism-' . getmypid() . '.php';
    file_put_contents($f, $szkript);
    shell_exec('php ' . escapeshellarg($f) . ' ' . escapeshellarg(dirname(__DIR__)) . ' 2>/dev/null');
    unlink($f);
};

$GLOBALS['config']['db_name'] = 'teszt_ismetlodes';
@unlink(hibaallapot_fajl('proba'));
@unlink(KERES_NAPLO);

$futtat("ismetlodo_hiba('proba', 'Hiányzik a jogosultság');");
$futtat("ismetlodo_hiba('proba', 'Hiányzik a jogosultság');");
$futtat("ismetlodo_hiba('proba', 'Hiányzik a jogosultság');");
allit('ugyanaz a hiba háromszor → egy értesítés', count(keresek()) === 1, count(keresek()) . ' db');

$futtat("ismetlodo_hiba('proba', 'Egy MÁSIK hiba');");
allit('egy új, más hibáról viszont szól', count(keresek()) === 2, count(keresek()) . ' db');

$futtat("hiba_megszunt('proba', 'Újra működik.');");
$k = keresek();
allit('amikor megjavul, arról is szól', count($k) === 3, count($k) . ' db');
allit('„rendben" jelzéssel', str_contains((string) ($k[2]['post']['text'] ?? ''), '✅'),
    (string) ($k[2]['post']['text'] ?? ''));

$futtat("hiba_megszunt('proba', 'Újra működik.');");
allit('a „megjavult" üzenet sem ismétlődik', count(keresek()) === 3, count(keresek()) . ' db');

$futtat("ismetlodo_hiba('proba', 'Hiányzik a jogosultság');");
allit('ha megjavulás után újra elromlik, újra szól', count(keresek()) === 4, count(keresek()) . ' db');

// A 6 oras csend lejarta utan ujra szol, ha meg mindig fennall.
file_put_contents(hibaallapot_fajl('proba'), (time() - 7 * 3600) . '|' . md5('Hiányzik a jogosultság'));
$futtat("ismetlodo_hiba('proba', 'Hiányzik a jogosultság');");
allit('6 óra után emlékeztet, ha még mindig fennáll', count(keresek()) === 5, count(keresek()) . ' db');

@unlink(hibaallapot_fajl('proba'));
@unlink(KERES_NAPLO);

// ---------------------------------------------------------------
echo "\n";
printf("Összesen: %d rendben, %d hiba\n", $GLOBALS['okk'], $GLOBALS['hibak']);
exit($GLOBALS['hibak'] === 0 ? 0 : 1);
