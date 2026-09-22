<?php
declare(strict_types=1);

/**
 * Az etlap futar adatbazis nelkuli reszeinek ellenorzese.
 *
 * Futtatas a projekt gyokerebol:  php tools/teszt-futar.php
 *
 * Amit ellenoriz: a bekuldott level szovegenek tisztitasa, a targy
 * kezelese, a HTML-injekcio elleni vedelem, es a kesz levél (MIME)
 * felepitese csatolmannyal egyutt. Adatbazis NEM kell hozza.
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

require __DIR__ . '/../public/inc/etlap_futar.php';

// ---------------------------------------------------------------
fejezet('A beküldött levél szövegének tisztítása');

$alairassal = "Kedves Mindenki!\n\nItt a mai étlap.\n\n--\nKovács Béla\nüzletvezető\n+36 1 234 5678";
allit(
    'az aláírás levágódik',
    !str_contains(tiszta_bevezeto($alairassal), 'Kovács Béla'),
    tiszta_bevezeto($alairassal)
);
allit(
    'a valódi szöveg megmarad',
    str_contains(tiszta_bevezeto($alairassal), 'Itt a mai étlap.')
);

$idezettel = "Új étlap.\n\n> Előző levél sora\n> Másik sor";
allit(
    'az idézett válasz kimarad',
    !str_contains(tiszta_bevezeto($idezettel), 'Előző levél')
);

$outlookos = "Szöveg\r\n\r\n________________________________\r\nFeladó: valaki";
allit(
    'az Outlook válasz-elválasztója levágódik',
    !str_contains(tiszta_bevezeto($outlookos), 'Feladó:')
);

allit(
    'a hosszú szöveg korlátozva van',
    mb_strlen(tiszta_bevezeto(str_repeat('a', 5000))) <= 1500
);

// ---------------------------------------------------------------
fejezet('A levél tárgya');

allit('a Re: előtag lekerül', etlap_targy('Re: Napi étlap') === 'Napi étlap', etlap_targy('Re: Napi étlap'));
allit('a magyar Vá: előtag is lekerül', etlap_targy('Vá: Étlap') === 'Étlap', etlap_targy('Vá: Étlap'));
allit('a többszörös előtag is', etlap_targy('Re: Fwd: Étlap') === 'Étlap', etlap_targy('Re: Fwd: Étlap'));
allit('üres tárgy helyett dátumos', str_contains(etlap_targy('   '), 'TTK Kantin'), etlap_targy('   '));
allit('a rendes tárgy változatlan', etlap_targy('Hétfői menü') === 'Hétfői menü');

// ---------------------------------------------------------------
fejezet('HTML-injekció a beküldött szövegből');

$rosszindulat = 'Szia <script>alert(1)</script> és <b>vastag</b>';
$bekezdesek = bevezeto_bekezdesek($rosszindulat);
$egyben = implode('', $bekezdesek);

allit('a <script> nem marad HTML-ként', !str_contains($egyben, '<script>'), $egyben);
allit('a <b> sem marad HTML-ként', !str_contains($egyben, '<b>'));
allit('a szöveg viszont olvasható marad', str_contains($egyben, 'alert(1)'));
allit('a sortörésből <br> lesz', str_contains(implode('', bevezeto_bekezdesek("egy\nkettő")), '<br'));
allit('üres szöveg helyett van alapértelmezett', str_contains(implode('', bevezeto_bekezdesek('')), 'Kedves'));

// ---------------------------------------------------------------
fejezet('Méret kiírása');

allit('kilobájt', meret_szoveg(150 * 1024) === '150 KB', meret_szoveg(150 * 1024));
allit('megabájt', meret_szoveg(3 * 1024 * 1024) === '3,0 MB', meret_szoveg(3 * 1024 * 1024));

// ---------------------------------------------------------------
fejezet('A kimenő levél tartalma');

$kuldes = [
    'targy'    => 'TTK Kantin – hétfői étlap',
    'bevezeto' => "Kedves Vendégeink!\n\nMa is friss alapanyagokkal dolgoztunk.",
    'pdf_nev'  => 'etlap.pdf',
];
$leiratkozo = 'https://ttketlap.pepperhouse.hu/leiratkozas.php?token=' . str_repeat('a', 64);

$html = etlap_level_html($kuldes, $leiratkozo);
allit('a tárgy megjelenik a levélben', str_contains($html, 'hétfői étlap'));
allit('a bevezető megjelenik', str_contains($html, 'friss alapanyagokkal'));
allit('a leiratkozó link benne van', str_contains($html, $leiratkozo));
allit('utal a csatolmányra', str_contains($html, 'csatolmány'));
allit('a logó is benne van', str_contains($html, 'pepperhouse-logo'));

$szoveg = etlap_level_szoveg($kuldes, $leiratkozo);
allit('a szöveges változatban is ott a leiratkozás', str_contains($szoveg, $leiratkozo));
allit('a szöveges változatban nincs HTML', !str_contains($szoveg, '<'));

// ---------------------------------------------------------------
fejezet('A kész levél (MIME) csatolmánnyal');

// Valodi, apro PDF - eleg hozza a fejlec es a lezaras.
$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF";
$token = str_repeat('b', 64);

$mail = build_message(
    'vendeg@pelda.hu',
    $kuldes['targy'],
    $html,
    $szoveg,
    $token,
    [['nev' => 'napi-etlap.pdf', 'tartalom' => $pdf]]
);
$mail->isSMTP();
$mail->preSend();
$mime = $mail->getSentMIMEMessage();

allit('a csatolmány neve benne van a levélben', str_contains($mime, 'napi-etlap.pdf'), '');
allit('a csatolmány típusa PDF', str_contains($mime, 'application/pdf'));
allit(
    'a PDF tartalma épen átment',
    str_contains(str_replace(["\r\n", "\n"], '', $mime), rtrim(base64_encode($pdf), '='))
);
allit('a levél többrészes (szöveg + HTML + csatolmány)', str_contains($mime, 'multipart/mixed'));

// A leiratkozo fejlec a korabbi javitas miatt kulon figyelmet erdemel:
// ha kodolva menne ki, a Gmail leiratkozo gombja elromlana.
allit(
    'a List-Unsubscribe fejléc nincs elkódolva',
    str_contains($mime, 'List-Unsubscribe: <http') && !str_contains($mime, 'List-Unsubscribe: =?'),
    'részlet: ' . substr($mime, (int) strpos($mime, 'List-Unsubscribe'), 90)
);
allit('az egykattintásos leiratkozás fejléce is megvan', str_contains($mime, 'List-Unsubscribe-Post'));

// ---------------------------------------------------------------
fejezet('Beküldésre jogosultak');

$GLOBALS['config']['etlap_bekuldok'] = 'Egy@Pelda.hu ,  ketto@pelda.hu,';
allit('kisbetűsít és tisztít', etlap_bekuldok() === ['egy@pelda.hu', 'ketto@pelda.hu'],
    implode('|', etlap_bekuldok()));

$GLOBALS['config']['etlap_bekuldok'] = '';
allit('üres beállításból üres lista lesz', etlap_bekuldok() === []);

// ---------------------------------------------------------------
fejezet('Végtelen kör elleni védelem (az éles címekkel)');

// Eles beallitas: a ttk@ egyszerre felado cim ES jogosult bekuldo.
$GLOBALS['config']['mail_from']      = 'ttk@pepperhouse.hu';
$GLOBALS['config']['etlap_mailbox']  = 'ttk.etlap@pepperhouse.hu';
$GLOBALS['config']['etlap_bekuldok'] = 'marketing@pepperhouse.hu, ttk@pepperhouse.hu';

allit(
    'a ttk@ benne van a jogosult beküldőkben',
    in_array('ttk@pepperhouse.hu', etlap_bekuldok(), true),
    implode('|', etlap_bekuldok())
);

$jelolo = ETLAP_JELOLO_FEJLEC;

$bekuldottLevel = ['internetMessageHeaders' => [
    ['name' => 'Received', 'value' => 'valami'],
    ['name' => 'Subject',  'value' => 'Napi étlap'],
]];
allit('a kézzel beküldött levelet NEM tartja sajátnak', !sajat_levelunk($bekuldottLevel));

$sajatLevel = ['internetMessageHeaders' => [
    ['name' => 'Received', 'value' => 'valami'],
    ['name' => $jelolo,    'value' => '1'],
]];
allit('a saját levelünket felismeri', sajat_levelunk($sajatLevel));

$maskepp = ['internetMessageHeaders' => [['name' => strtolower($jelolo), 'value' => '1']]];
allit('kis-nagybetűtől függetlenül is', sajat_levelunk($maskepp));

allit('fejléc nélküli levél nem saját', !sajat_levelunk([]));

// A jelolo valoban rakerul minden kimeno levelre:
$ellenorzo = build_message('x@pelda.hu', 'Tárgy', '<p>szöveg</p>', 'szöveg');
$ellenorzo->isSMTP();
$ellenorzo->preSend();
allit(
    'a jelölő fejléc rajta van a kimenő levélen',
    str_contains($ellenorzo->getSentMIMEMessage(), $jelolo . ': 1')
);

// ---------------------------------------------------------------
fejezet('Kép (JPG) étlapként');

allit('a JPG képnek számít', kep_e('image/jpeg'));
allit('a PNG is', kep_e('image/png'));
allit('a PDF nem', !kep_e('application/pdf'));

$kepKuldes = ['targy' => 'Mai étlap', 'bevezeto' => 'Jó étvágyat!', 'pdf_nev' => 'etlap.JPG'];
$pdfKuldes = ['targy' => 'Mai étlap', 'bevezeto' => 'Jó étvágyat!', 'pdf_nev' => 'etlap.pdf'];

allit('nagybetűs kiterjesztést is felismer', kuldes_kep_e($kepKuldes));
allit('a típust a fájlnévből állapítja meg', kuldes_fajl_tipusa($kepKuldes) === 'image/jpeg',
    kuldes_fajl_tipusa($kepKuldes));
allit('PDF-nél nem kép', !kuldes_kep_e($pdfKuldes));

$kepHtml = etlap_level_html($kepKuldes + ['pdf_tartalom' => 'x'], '#');
allit('a képes levélben beágyazott kép van', str_contains($kepHtml, 'src="cid:etlap"'), '');
allit('a képes levél NEM csatolmányra utal', !str_contains($kepHtml, 'csatolmányában'));

$pdfHtml = etlap_level_html($pdfKuldes + ['pdf_tartalom' => 'x'], '#');
allit('a PDF-es levél a csatolmányra utal', str_contains($pdfHtml, 'csatolmányában'));
allit('a PDF-es levélben nincs beágyazott kép', !str_contains($pdfHtml, 'cid:etlap'));

// ---------------------------------------------------------------
fejezet('Nagy fénykép kicsinyítése');

if (!function_exists('imagecreatetruecolor')) {
    echo "  (kihagyva: nincs GD bővítmény)\n";
} else {
    // "Fenykep": 3000 pixel szeles, zajjal - hogy ne tomorodjon a semmibe.
    $nagy = imagecreatetruecolor(3000, 2000);
    for ($i = 0; $i < 4000; $i++) {
        imagefilledrectangle($nagy, random_int(0, 2900), random_int(0, 1900),
            random_int(0, 2999), random_int(0, 1999),
            imagecolorallocate($nagy, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
    }
    ob_start(); imagejpeg($nagy, null, 92); $nagyJpg = (string) ob_get_clean();
    imagedestroy($nagy);

    $kicsi = kep_kicsinyites($nagyJpg, 'image/jpeg');
    $meret = imagecreatefromstring($kicsi);

    allit('a kicsinyített kép még érvényes JPG', $meret !== false);
    allit('a szélessége 1600 pixel', imagesx($meret) === 1600, (string) imagesx($meret));
    allit('a fájl kisebb lett', strlen($kicsi) < strlen($nagyJpg),
        meret_szoveg(strlen($nagyJpg)) . ' -> ' . meret_szoveg(strlen($kicsi)));
    allit('belefér a levélbe (3 MB alatt)', strlen($kicsi) <= ETLAP_MAX_PDF,
        meret_szoveg(strlen($kicsi)));

    // Kis kepet ne bantsunk.
    $kis = imagecreatetruecolor(800, 600);
    ob_start(); imagejpeg($kis, null, 85); $kisJpg = (string) ob_get_clean();
    imagedestroy($kis);
    allit('a kis képhez nem nyúl', kep_kicsinyites($kisJpg, 'image/jpeg') === $kisJpg);

    // Rossz adat ne dontse le a rendszert.
    allit('sérült képnél az eredetit adja vissza',
        kep_kicsinyites('ez nem kép', 'image/jpeg') === 'ez nem kép');

    // ---------------------------------------------------------------
    fejezet('A kész levél beágyazott képpel');

    $kuldesTeljes = $kepKuldes + ['pdf_tartalom' => $kicsi];
    $mailKep = build_message(
        'vendeg@pelda.hu', 'Mai étlap',
        etlap_level_html($kuldesTeljes, '#'),
        etlap_level_szoveg($kuldesTeljes, '#'),
        str_repeat('c', 64),
        kuldes_csatolmanya($kuldesTeljes)
    );
    $mailKep->isSMTP();
    $mailKep->preSend();
    $mimeKep = $mailKep->getSentMIMEMessage();

    allit('a kép beágyazva megy (Content-ID)', str_contains($mimeKep, 'Content-ID: <etlap>'), '');
    allit('inline megjelenítéssel', str_contains($mimeKep, 'Content-Disposition: inline'));
    allit('JPEG típussal', str_contains($mimeKep, 'image/jpeg'));
    allit('a levél related szerkezetű', str_contains($mimeKep, 'multipart/related'));
    allit('a szöveges változat is szól a képről',
        str_contains(etlap_level_szoveg($kuldesTeljes, '#'), 'képként'));
}

// ---------------------------------------------------------------
fejezet('Alapszöveg üres levélnél');

$uresKep = ['targy' => 'Mai étlap', 'bevezeto' => '', 'pdf_nev' => 'etlap.jpg', 'pdf_tartalom' => 'x'];
$uresPdf = ['targy' => 'Mai étlap', 'bevezeto' => '', 'pdf_nev' => 'etlap.pdf', 'pdf_tartalom' => 'x'];

$uresHtml = etlap_level_html($uresKep, '#');
allit('a megszólítás benne van', str_contains($uresHtml, 'Kedves Vendégünk!'));
allit('a törzsszöveg benne van',
    str_contains($uresHtml, 'Mellékelten küldjük friss étlapunkat'));
allit('az aláírás benne van', str_contains($uresHtml, 'a TTK Kantin csapata'));

// A sorrend a lenyeg: a kep az alairas ELE kerul.
$kepHelye     = strpos($uresHtml, 'cid:etlap');
$alairasHelye = strpos($uresHtml, 'a TTK Kantin csapata');
allit('a kép az aláírás elé kerül', $kepHelye !== false && $kepHelye < $alairasHelye,
    "kép: $kepHelye, aláírás: $alairasHelye");

$uresPdfHtml = etlap_level_html($uresPdf, '#');
allit('PDF-nél nincs fölösleges ismétlés',
    !str_contains($uresPdfHtml, 'csatolmányában, PDF-ben'));
allit('PDF-nél is ott az aláírás', str_contains($uresPdfHtml, 'a TTK Kantin csapata'));

// Ha a bekuldo irt sajat szoveget, az alapszoveg NEM jelenik meg.
$sajat = ['targy' => 'Mai étlap', 'bevezeto' => 'Ma rendezvény miatt rövidebb a választék.',
          'pdf_nev' => 'etlap.pdf', 'pdf_tartalom' => 'x'];
$sajatHtml = etlap_level_html($sajat, '#');
allit('saját szövegnél nincs alapszöveg', !str_contains($sajatHtml, 'Kedves Vendégünk!'));
allit('saját szövegnél a csatolmány-sor megmarad',
    str_contains($sajatHtml, 'csatolmányában, PDF-ben'));
allit('saját szöveg megjelenik', str_contains($sajatHtml, 'rendezvény miatt'));

// A szoveges valtozat ugyanazt mondja.
$uresSzoveg = etlap_level_szoveg($uresKep, '#');
allit('a szöveges változatban is ott a megszólítás',
    str_contains($uresSzoveg, 'Kedves Vendégünk!'));
allit('a szöveges változatban is ott az aláírás',
    str_contains($uresSzoveg, 'a TTK Kantin csapata'));
allit('a szöveges változatban is az aláírás van hátul',
    strpos($uresSzoveg, 'a TTK Kantin csapata') > strpos($uresSzoveg, 'képként'));
allit('a szöveges változatban nincs HTML', !str_contains($uresSzoveg, '<'));

// Csak szokozokbol allo torzs is uresnek szamit.
$csakSzokoz = $uresKep;
$csakSzokoz['bevezeto'] = "   \n\n  ";
allit('a csak szóközből álló levél is alapszöveget kap',
    str_contains(etlap_level_html($csakSzokoz, '#'), 'Kedves Vendégünk!'));

// ---------------------------------------------------------------
fejezet('Napló');

// A tesztgepen nincs adatbazis - pont jo: ezzel azt ellenorizzuk, hogy
// a naplo hibaja SOSEM allitja meg a munkat.
$eredmeny = null;
$dobott   = false;
try {
    $eredmeny = esemeny('hiba', 'Próba bejegyzés');
} catch (Throwable $e) {
    $dobott = true;
}
allit('adatbázis nélkül sem dob hibát', !$dobott);
allit('a szöveget visszaadja, hogy egy sorban lehessen naplózni és visszatérni',
    $eredmeny === 'Próba bejegyzés', (string) $eredmeny);
allit('ismeretlen szintnél sem dob hibát', esemeny('valami', 'x') === 'x');

$dobottTakaritas = false;
try { naplo_takaritas(); } catch (Throwable $e) { $dobottTakaritas = true; }
allit('a takarítás sem dob hibát adatbázis nélkül', !$dobottTakaritas);

allit('a lépések emberi nevet kapnak',
    lepes_neve('beerkezett_feldolgozas') === 'A postafiók ellenőrzése nem sikerült');

// ---------------------------------------------------------------
echo "\n";
printf("Összesen: %d rendben, %d hiba\n", $GLOBALS['okk'], $GLOBALS['hibak']);
exit($GLOBALS['hibak'] === 0 ? 0 : 1);
