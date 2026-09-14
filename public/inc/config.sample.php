<?php
/**
 * Masold at ezt a fajlt `config.php` neven ugyanebbe a mappaba,
 * es toltsd ki a sajat adataiddal. A config.php NEM kerul be a gitbe.
 */

return [
    // --- Adatbazis (Hostinger hPanel -> Adatbazisok -> MySQL) ---
    'db_host' => 'localhost',
    'db_name' => 'u123456789_menza',
    'db_user' => 'u123456789_menza',
    'db_pass' => 'IDE_JON_AZ_ADATBAZIS_JELSZO',

    // --- Admin belepes ---
    // A jelszo hash-t a /admin/hash.php oldalon tudod legeneralni (utana torold a fajlt).
    'admin_user'          => 'admin',
    'admin_password_hash' => '$2y$12$CSERELD_LE_EZT_EGY_VALODI_BCRYPT_HASHRE',

    // --- Altalanos ---
    'site_name'        => 'Menza heti etlap',
    'site_url'         => 'https://pelda.hu',   // vegen NE legyen per jel
    'contact_email'    => 'menza@pelda.hu',
    'operator_name'    => 'Uzemelteto Kft.',
    'operator_address' => '1111 Budapest, Pelda utca 1.',

    // Az adatkezelesi tajekoztato aktualis verzioja. Ha modositod a tajekoztatot,
    // emeld meg (pl. 2026-01-01), igy nyomon kovetheto, ki mire adott hozzajarulast.
    'consent_version' => '2026-09-14',

    // Az unsubscribe token es az IP-hash sozasahoz. Egyszer allitsd be, utana NE valtoztasd.
    'app_secret' => 'IDE_JON_EGY_HOSSZU_VELETLEN_KARAKTERLANC',
];
