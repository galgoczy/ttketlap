<?php
declare(strict_types=1);

/** Kozos HTML fejlec. */
function render_header(string $title, bool $noindex = false, bool $wide = false): void
{
    ?><!DOCTYPE html>
<html lang="hu">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> &middot; <?= e(cfg('site_name')) ?></title>
<?php if ($noindex): ?>
<meta name="robots" content="noindex, nofollow">
<?php endif; ?>
<meta name="theme-color" content="#e52721">
<!-- A Jost betukeszlet sajat tarhelyrol tolt be (assets/fonts). A torzsszoveg
     betujet eloretoltjuk, hogy ne legyen villanas az elso megjelenesnel. -->
<link rel="preload" href="/assets/fonts/jost-latin-400.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/assets/fonts/jost-latin-ext-400.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="/assets/css/app.css?v=3">
</head>
<body>
<div class="page">
<main class="page__main<?= $wide ? ' page__main--wide' : '' ?>">
<?php
}

/** Kozos HTML lablec. */
function render_footer(): void
{
    ?>
</main>
<footer class="page__footer">
    <?= e(cfg('operator_name')) ?> &middot;
    <a href="/adatkezeles.php">Adatkezelési tájékoztató</a>
</footer>
</div>
</body>
</html>
<?php
}
