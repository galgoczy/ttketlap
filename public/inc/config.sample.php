<?php
/**
 * Masold at ezt a fajlt `config.php` neven ugyanebbe a mappaba,
 * es toltsd ki a sajat adataiddal. A config.php NEM kerul be a gitbe.
 */

return [
    // --- Adatbazis (Hostinger hPanel -> Adatbazisok -> MySQL) ---
    'db_host' => 'localhost',
    'db_name' => 'u123456789_kantin',
    'db_user' => 'u123456789_kantin',
    'db_pass' => 'IDE_JON_AZ_ADATBAZIS_JELSZO',

    // --- Admin belepes ---
    // A hash generalasat lasd a README-ben ("Az admin jelszo hash generalasa").
    // Roviden:  php -r 'echo password_hash("JELSZO", PASSWORD_BCRYPT, ["cost" => 12]);'
    'admin_user'          => 'admin',
    'admin_password_hash' => '$2y$12$CSERELD_LE_EZT_EGY_VALODI_BCRYPT_HASHRE',

    // --- Altalanos ---
    'site_name'        => 'TTK Kantin heti etlap',
    'site_url'         => 'https://pelda.hu',   // vegen NE legyen per jel
    'contact_email'    => 'kantin@pelda.hu',
    'operator_name'    => 'Uzemelteto Kft.',
    'operator_address' => '1111 Budapest, Pelda utca 1.',

    // Az adatkezelesi tajekoztato aktualis verzioja. Ha modositod a tajekoztatot,
    // emeld meg (pl. 2026-01-01), igy nyomon kovetheto, ki mire adott hozzajarulast.
    'consent_version' => '2026-09-14',

    // Az unsubscribe token es az IP-hash sozasahoz. Egyszer allitsd be, utana NE valtoztasd.
    // Generalas:  openssl rand -base64 48
    'app_secret' => 'IDE_JON_EGY_HOSSZU_VELETLEN_KARAKTERLANC',

    // --- Levelkuldes ---
    // Reszletes beallitasi utmutato: docs/levelkuldes.md
    //
    // 'graph' (ajanlott): Microsoft Graph API, app regisztracioval.
    //          Nem kell hozza jelszo, es nem erinti az egyszeru jelszavas
    //          SMTP kivezetese. Ehhez a graph_* mezoket toltsd ki.
    // 'smtp':  klasszikus jelszavas SMTP. Egyszerubb, de kifuto megoldas,
    //          es sok tenantban eleve tiltva van.
    'mail_transport' => 'graph',

    // A felado postafiok. Mindket utvonalnal ez a felado cim.
    'mail_from'      => 'kantin@pelda.hu',
    'mail_from_name' => 'TTK Kantin heti etlap',

    // --- 'graph' utvonal: az Entra ID app regisztracio adatai ---
    // Entra admin center -> App registrations -> az alkalmazasod:
    //   graph_tenant_id  = Directory (tenant) ID
    //   graph_client_id  = Application (client) ID
    //   graph_client_secret = a Certificates & secrets alatt letrehozott ertek
    // Az alkalmazasnak Mail.Send APPLICATION jogosultsag kell,
    // rendszergazdai jovahagyassal. Lasd: docs/levelkuldes.md
    'graph_tenant_id'     => '',
    'graph_client_id'     => '',
    'graph_client_secret' => '',

    // --- 'smtp' utvonal (csak ha mail_transport = 'smtp') ---
    'smtp_host'   => 'smtp.office365.com',
    'smtp_port'   => '587',
    'smtp_secure' => 'tls',
    'smtp_user'   => 'kantin@pelda.hu',
    'smtp_pass'   => '',

    // Kuldjon-e visszaigazolo levelet feliratkozaskor? '1' = igen, '0' = nem.
    'send_welcome_email' => '1',
];
