<?php
// Al-Telegram szerver a tools/teszt-telegram.php-hoz.
// Inditas:  php -S 127.0.0.1:8098 tools/al-telegram.php
// Rogziti a kerest, es ugy valaszol, ahogy a valodi Telegram tenne.
$ut = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
file_put_contents(
    __DIR__ . '/al-telegram-keresek.jsonl',
    json_encode(['ut' => $ut, 'post' => $_POST], JSON_UNESCAPED_UNICODE) . "\n",
    FILE_APPEND
);
header('Content-Type: application/json');
if (str_contains($ut, 'ROSSZTOKEN')) {
    http_response_code(401);
    echo '{"ok":false,"error_code":401,"description":"Unauthorized"}';
    return;
}
if (($_POST['chat_id'] ?? '') === '999') {
    http_response_code(400);
    echo '{"ok":false,"error_code":400,"description":"Bad Request: chat not found"}';
    return;
}
echo '{"ok":true,"result":{"message_id":1}}';
