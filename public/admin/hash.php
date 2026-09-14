<?php
declare(strict_types=1);

/**
 * EGYSZER HASZNALATOS SEGEDESZKOZ.
 * Ezzel tudod legeneralni az admin jelszo hash-t a config.php-be.
 * HASZNALAT UTAN TOROLD EZT A FAJLT a tarhelyrol!
 */

$hash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $hash = password_hash((string) ($_POST['password'] ?? ''), PASSWORD_BCRYPT, ['cost' => 12]);
}
?><!DOCTYPE html>
<html lang="hu">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Jelszó hash generálás</title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<div class="page"><main class="page__main">
<div class="card">
    <h1 class="title">Admin jelszó hash</h1>
    <p class="subtitle">
        Írd be a kívánt admin jelszót. A kapott hash-t másold a <code>config.php</code>
        <code>admin_password_hash</code> mezőjébe, majd <strong>töröld ezt a fájlt</strong> a tárhelyről.
    </p>
    <form method="post">
        <div class="field">
            <label class="label" for="password">Jelszó</label>
            <input class="input" type="text" id="password" name="password" required>
        </div>
        <button class="btn btn--primary" type="submit">Hash generálása</button>
    </form>
    <?php if ($hash !== ''): ?>
        <div class="field" style="margin-top: var(--space-5)">
            <label class="label" for="hash">Másold ezt:</label>
            <input class="input" id="hash" value="<?= htmlspecialchars($hash, ENT_QUOTES) ?>" readonly onclick="this.select()">
        </div>
    <?php endif; ?>
</div>
</main></div>
</body>
</html>
