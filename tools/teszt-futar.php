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
echo "\n";
printf("Összesen: %d rendben, %d hiba\n", $GLOBALS['okk'], $GLOBALS['hibak']);
exit($GLOBALS['hibak'] === 0 ? 0 : 1);
