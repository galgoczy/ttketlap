<?php
declare(strict_types=1);

/**
 * Kozos indulo fajl: konfiguracio, adatbazis, seged fuggvenyek.
 * Minden publikus oldal ezt tolti be eloszor.
 */

mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Budapest');

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Hianyzo config.php. Masold at a config.sample.php fajlt config.php neven.');
}

/** @var array<string,string> $config */
$config = require $configFile;

/**
 * Vegso biztonsagi halo: ha barhol kezeletlen hiba tortenik, a latogato
 * ne egy ures feher oldalt lasson. A reszletek a hibanaploba mennek -
 * a kepernyore SOHA, mert az technikai reszleteket szivarogtatna ki.
 */
function hiba_oldal(): void
{
    // Parancssorban (idozitett futas) nincs ertelme HTML oldalt kiirni:
    // az csak olvashatatlanna tenne a cron naplojat. A nem nulla kilepesi
    // kod viszont fontos - abbol tudja a tarhely, hogy a futas elbukott.
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Technikai hiba történt. A részletek a hibanaplóban vannak.\n");
        exit(1);
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }

    echo '<!DOCTYPE html><html lang="hu"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>Technikai hiba</title>'
       . '<style>body{margin:0;padding:2rem 1rem;background:#f8f7f5;color:#2e2e2e;'
       . 'font-family:system-ui,-apple-system,"Segoe UI",Arial,sans-serif;line-height:1.6}'
       . 'main{max-width:34rem;margin:3rem auto;background:#fff;border:1px solid #e4e4e4;'
       . 'border-radius:8px;padding:1.5rem}h1{font-size:1.5rem;margin:0 0 .5rem;color:#000}'
       . 'p{margin:0 0 1rem}a{color:#e52721}</style></head><body><main>'
       . '<h1>Technikai hiba</h1>'
       . '<p>Az oldal most nem érhető el. Kérjük, próbálja újra néhány perc múlva.</p>'
       . '<p style="font-size:.875rem;color:#5e5e5e">Ha Ön az üzemeltető: a hiba oka a szerver '
       . 'hibanaplójában van, és az <a href="/admin/diagnosztika.php">admin diagnosztika</a> '
       . 'oldal is megmutatja, mi hiányzik.</p>'
       . '</main></body></html>';
}

set_exception_handler(function (Throwable $e): void {
    error_log(sprintf(
        'Kezeletlen hiba: %s @ %s:%d',
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));
    hiba_oldal();
});

// A vegzetes hibakat (pl. hianyzo fajl) nem a kivetelkezelo fogja el.
register_shutdown_function(function (): void {
    $utolso = error_get_last();
    if ($utolso !== null
        && in_array($utolso['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        hiba_oldal();
    }
});

/**
 * Adatbazis kapcsolat (lusta inicializalas).
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    global $config;
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $config['db_host'], $config['db_name']);

    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}

function cfg(string $key, string $default = ''): string
{
    global $config;
    return isset($config[$key]) ? (string) $config[$key] : $default;
}

/** HTML-biztos kiiratas. */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => is_https(),
        'path'     => '/',
    ]);
    session_start();
}

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    return ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

/** CSRF token az aktualis munkamenethez. */
function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_valid(?string $token): bool
{
    start_session();
    return is_string($token)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

/** Az IP-t nem taroljuk nyersen, csak sozott hash-kent. */
function ip_hash(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return hash_hmac('sha256', $ip, cfg('app_secret'));
}

function normalize_email(string $email): string
{
    return mb_strtolower(trim($email));
}

function valid_email(string $email): bool
{
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL) && mb_strlen($email) <= 190;
}

function unsubscribe_url(string $token): string
{
    return rtrim(cfg('site_url'), '/') . '/leiratkozas.php?token=' . $token;
}

/**
 * Egyszeru sebessegkorlat: max $limit probalkozas $minutes percen belul IP-nkent.
 */
function rate_limit_exceeded(int $limit = 10, int $minutes = 60): bool
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM signup_attempts
         WHERE ip_hash = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)'
    );
    $stmt->execute([ip_hash(), $minutes]);

    return (int) $stmt->fetchColumn() >= $limit;
}

function log_signup_attempt(): void
{
    db()->prepare('INSERT INTO signup_attempts (ip_hash) VALUES (?)')->execute([ip_hash()]);
    // Regi bejegyzesek takaritasa, hogy a tabla ne hizzon.
    db()->exec('DELETE FROM signup_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 7 DAY)');
}
