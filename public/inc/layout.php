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
<!-- Jost: a Pepper House design system betukeszlete (a markabeli Futura
     legkozelebbi szabadon hasznalhato megfeleloje). A preconnect azert kell,
     hogy a betu ne keslekedjen a megjelenessel. -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Jost:wght@300;400;500;600;700&display=swap">
<link rel="stylesheet" href="/assets/css/app.css?v=2">
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
