<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
require_admin();

$onlyActive = ($_GET['tipus'] ?? 'aktiv') !== 'mind';

$sql = 'SELECT email, status, consent_version, consent_at, created_at, unsubscribed_at, unsubscribe_token
          FROM subscribers'
     . ($onlyActive ? ' WHERE status = "active"' : '')
     . ' ORDER BY created_at ASC';

$rows = db()->query($sql)->fetchAll();

$filename = sprintf(
    'feliratkozok-%s-%s.csv',
    $onlyActive ? 'aktiv' : 'mind',
    date('Y-m-d')
);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'wb');

// BOM, hogy az Excel felismerje az UTF-8 kodolast (ekezetes szoveg).
fwrite($out, "\xEF\xBB\xBF");

$header = ['email', 'statusz', 'feliratkozas', 'hozzajarulas_verzio', 'hozzajarulas_ideje', 'leiratkozas', 'leiratkozo_link'];
fputcsv($out, $header, ';', '"', '\\');

foreach ($rows as $row) {
    fputcsv($out, [
        csv_safe($row['email']),
        $row['status'] === 'active' ? 'aktív' : 'leiratkozott',
        $row['created_at'],
        $row['consent_version'],
        $row['consent_at'],
        $row['unsubscribed_at'] ?? '',
        unsubscribe_url($row['unsubscribe_token']),
    ], ';', '"', '\\');
}

fclose($out);

/**
 * Vedelem a tablazatkezelo keplet-injekcio ellen:
 * a =, +, -, @ karakterrel kezdodo cellat az Excel kepletkent futtatna.
 */
function csv_safe(string $value): string
{
    return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'" . $value : $value;
}
