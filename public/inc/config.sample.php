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
    // A hash generalasat lasd a README-ben ("Az admin jelszo hash generalasa").
    // Roviden:  php -r 'echo password_hash("JELSZO", PASSWORD_BCRYPT, ["cost" => 12]);'
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
    // Generalas:  openssl rand -base64 48
    'app_secret' => 'IDE_JON_EGY_HOSSZU_VELETLEN_KARAKTERLANC',

    // --- Levelkuldes (SMTP) ---
    // Reszletes beallitasi utmutato: docs/levelkuldes.md
    // Ha uresen hagyod az smtp_host-ot, a rendszer nem kuld levelet,
    // de a feliratkozas tovabbra is mukodik.
    'smtp_host'   => 'smtp.office365.com',
    'smtp_port'   => '587',
    'smtp_secure' => 'tls',
    'smtp_user'   => 'menza@pelda.hu',
    'smtp_pass'   => 'IDE_JON_AZ_SMTP_JELSZO',

    // A felado cim. M365 eseten ennek egyeznie KELL az smtp_user postafiokkal
    // (vagy annak egy engedelyezett alias-aval), kulonben az Exchange elutasitja.
    'mail_from'      => 'menza@pelda.hu',
    'mail_from_name' => 'Menza heti etlap',

    // Kuldjon-e visszaigazolo levelet feliratkozaskor? '1' = igen, '0' = nem.
    'send_welcome_email' => '1',
];
