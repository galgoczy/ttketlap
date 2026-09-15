<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';

start_session();

function admin_logged_in(): bool
{
    return !empty($_SESSION['admin_logged_in']);
}

/** Minden admin oldal elejen meghivando. */
function require_admin(): void
{
    if (!admin_logged_in()) {
        header('Location: /admin/');
        exit;
    }
}

/**
 * Ellenorzi az admin belepest. Allando ideju osszehasonlitast hasznal,
 * hogy a felhasznalonev se legyen kitalalhato idomeresbol.
 */
function admin_login_valid(string $user, string $password): bool
{
    $userOk = hash_equals(cfg('admin_user'), $user);
    $passOk = password_verify($password, cfg('admin_password_hash'));

    return $userOk && $passOk;
}
