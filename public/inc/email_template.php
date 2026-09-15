<?php
declare(strict_types=1);

/**
 * Pepper House leveltemplate.
 *
 * Miert nez ki a kodja "regimodian"?
 * A levelezoprogramok (kulonosen az Outlook) nem ugy jelenitik meg a HTML-t,
 * mint egy bongeszo: nincs flexbox, nincs grid, a kulso stiluslapot pedig
 * sokszor eldobjak. Ezert tablazatos elrendezes es beagyazott (inline)
 * stilusok kellenek. Ez nem elavultsag, hanem a mukodes feltetele.
 *
 * Betukeszlet: a Jost webfontot a levelezok tulnyomo resze NEM tolti be,
 * ezert a lista a markahoz kozel allo, helyben elerheto betukkel folytatodik
 * (Futura Apple-eszkozokon, Century Gothic Windowson), vegul Arial.
 */

/** A marka szinei - ugyanazok, mint az oldalon. */
const EMAIL_PIROS      = '#e52721';
const EMAIL_FEKETE     = '#141414';
const EMAIL_SZOVEG     = '#2e2e2e';
const EMAIL_HALVANY    = '#5e5e5e';
const EMAIL_HATTER     = '#f8f7f5';
const EMAIL_FEHER      = '#ffffff';
const EMAIL_VONAL      = '#e4e4e4';
const EMAIL_BETU       = "'Jost','Futura','Century Gothic',Helvetica,Arial,sans-serif";

/**
 * Egy kesz levelet epit a markas keretbe.
 *
 * @param string $cim          A levél nagy címsora.
 * @param string $torzs        Kesz HTML a torzsbe (tablazat-sorok vagy <p>-k).
 * @param string $elonezet     A postafiokban a targy mellett megjeleno szoveg.
 * @param string $leiratkozoUrl Ha ures, a lablecben csak a cegnev szerepel.
 */
function email_keret(string $cim, string $torzs, string $elonezet = '', string $leiratkozoUrl = ''): string
{
    $siteName = e(cfg('site_name'));
    $cegNev   = e(cfg('operator_name'));
    $logoUrl  = e(rtrim(cfg('site_url'), '/') . '/assets/img/pepperhouse-logo.png');
    $oldalUrl = e(rtrim(cfg('site_url'), '/'));

    $elonezetBlokk = '';
    if ($elonezet !== '') {
        // A postafiok listajaban a targy utan ez a szoveg latszik, maga a
        // level teteje viszont nem mutatja. A sok nem-toro szokoz azert kell,
        // hogy a level elso mondata ne csorogjon utana az elonezetbe.
        $elonezetBlokk = '<div style="display:none;max-height:0;overflow:hidden;opacity:0;'
            . 'mso-hide:all;font-size:1px;line-height:1px;color:' . EMAIL_HATTER . '">'
            . e($elonezet) . str_repeat('&#8203;&nbsp;', 60) . '</div>';
    }

    $lablecLeiratkozo = '';
    if ($leiratkozoUrl !== '') {
        $lablecLeiratkozo = '<br><a href="' . e($leiratkozoUrl) . '" style="color:'
            . EMAIL_HALVANY . ';text-decoration:underline">Leiratkozás</a>';
    }

    $betu = EMAIL_BETU;

    return <<<HTML
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="hu">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="x-apple-disable-message-reformatting" />
<meta name="color-scheme" content="light" />
<meta name="supported-color-schemes" content="light" />
<title>{$cim}</title>
</head>
<body style="margin:0;padding:0;background-color:#f8f7f5;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%">
{$elonezetBlokk}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f8f7f5">
  <tr>
    <td align="center" style="padding:24px 12px">

      <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px">

        <!-- Fejlec: logo -->
        <tr>
          <td align="center" style="padding:8px 0 24px 0">
            <a href="{$oldalUrl}" style="text-decoration:none">
              <img src="{$logoUrl}" width="200" alt="{$cegNev}"
                   style="display:block;width:200px;max-width:200px;height:auto;border:0" />
            </a>
          </td>
        </tr>

        <!-- Kartya -->
        <tr>
          <td style="background-color:#ffffff;border:1px solid #e4e4e4;border-radius:8px">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">

              <!-- Piros sav a kartya tetejen -->
              <tr><td style="background-color:#e52721;height:4px;line-height:4px;font-size:0;border-radius:8px 8px 0 0">&nbsp;</td></tr>

              <tr>
                <td style="padding:32px 28px 28px 28px;font-family:{$betu}">
                  <h1 style="margin:0 0 20px 0;font-family:{$betu};font-size:26px;line-height:1.2;
                             font-weight:700;color:#000000;letter-spacing:-0.01em">{$cim}</h1>
                  {$torzs}
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- Lablec -->
        <tr>
          <td align="center" style="padding:24px 16px 8px 16px;font-family:{$betu};
                     font-size:13px;line-height:1.6;color:#5e5e5e">
            {$cegNev}{$lablecLeiratkozo}
          </td>
        </tr>

      </table>

    </td>
  </tr>
</table>
</body>
</html>
HTML;
}

/** Bekezdes a level torzsebe. */
function email_bekezdes(string $szoveg, bool $halvany = false): string
{
    $szin = $halvany ? EMAIL_HALVANY : EMAIL_SZOVEG;
    $betu = EMAIL_BETU;
    $meret = $halvany ? '14px' : '16px';

    return '<p style="margin:0 0 16px 0;font-family:' . $betu . ';font-size:' . $meret
         . ';line-height:1.65;color:' . $szin . '">' . $szoveg . '</p>';
}

/** Piros, pill formaju gomb - a markahoz igazodva. */
function email_gomb(string $felirat, string $url): string
{
    $betu = EMAIL_BETU;

    return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 20px 0">'
         . '<tr><td align="center" bgcolor="' . EMAIL_PIROS . '" style="border-radius:999px">'
         . '<a href="' . e($url) . '" style="display:inline-block;padding:14px 32px;font-family:' . $betu
         . ';font-size:14px;font-weight:600;letter-spacing:0.12em;text-transform:uppercase;'
         . 'color:#ffffff;text-decoration:none;border-radius:999px">' . e($felirat) . '</a>'
         . '</td></tr></table>';
}

/**
 * A heti etlap napjai. Az ures napokat kihagyja, igy negy- vagy
 * haromnapos hetre is jo.
 *
 * @param array<string,string> $napok  nap => fogasok (soronkent uj sor)
 */
function email_etlap(array $napok): string
{
    $betu = EMAIL_BETU;
    $sorok = '';
    $elso = true;

    foreach ($napok as $nap => $fogas) {
        $fogas = trim($fogas);
        if ($fogas === '') {
            continue;
        }

        $keret = $elso ? '' : 'border-top:1px solid ' . EMAIL_VONAL . ';';
        $elso = false;

        $fogasHtml = nl2br(e($fogas));

        $sorok .= '<tr><td style="padding:14px 0;' . $keret . '">'
            . '<div style="font-family:' . $betu . ';font-size:12px;font-weight:600;'
            . 'letter-spacing:0.12em;text-transform:uppercase;color:' . EMAIL_PIROS . ';'
            . 'margin-bottom:4px">' . e($nap) . '</div>'
            . '<div style="font-family:' . $betu . ';font-size:16px;line-height:1.5;color:'
            . EMAIL_SZOVEG . '">' . $fogasHtml . '</div>'
            . '</td></tr>';
    }

    if ($sorok === '') {
        return '';
    }

    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
         . 'style="margin:4px 0 8px 0">' . $sorok . '</table>';
}
