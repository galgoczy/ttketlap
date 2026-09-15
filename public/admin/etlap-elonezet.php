<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
require_admin();

/**
 * A heti etlap level elonezete, kulon oldalkent.
 *
 * Miert nem az etlap.php-ba van beagyazva?
 * A beagyazott (srcdoc) keret orokli a szulo oldal biztonsagi szabalyat,
 * az pedig tiltja a HTML-be irt stilusokat. A level viszont eppen azokra
 * epul. Kulon oldalkent sajat - engedekenyebb, de mindenre mast tilto -
 * szabalyt kap, lasd a .htaccess vonatkozo reszet.
 */

$html = (string) ($_SESSION['etlap_elonezet'] ?? '');

if ($html === '') {
    $html = '<!DOCTYPE html><html lang="hu"><head><meta charset="utf-8"></head>'
          . '<body style="margin:0;padding:24px;font-family:Arial,sans-serif;color:#5e5e5e">'
          . 'Töltse ki a napokat, majd nyomja meg az „Előnézet frissítése” gombot.'
          . '</body></html>';
}

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex');
echo $html;
