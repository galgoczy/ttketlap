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
    // Ha az automatikus etlapkuldest is hasznalod, kell melle
    // Mail.ReadWrite is (a Mail.Read keves: a feldolgozott levelet a
    // rendszer olvasottra allitja, az pedig iras). Lasd: docs/etlap-kuldes.md
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

    // --- Etlap futar: a bekuldott PDF automatikus kikuldese ---
    // Reszletes beallitasi utmutato: docs/etlap-kuldes.md
    //
    // Az a postafiok, ahova az uzletvezeto a napi etlapot kuldi.
    // M365-ben erre MEGOSZTOTT POSTAFIOK (shared mailbox) valo: az
    // ingyenes, nem fogyaszt licencet. Ha ures, a figyeles kikapcsol.
    'etlap_mailbox' => '',

    // Kik kuldhetnek be etlapot. Vesszovel elvalasztott cimek.
    // Ami nem ezekrol a cimekrol erkezik, azt a rendszer figyelmen kivul
    // hagyja - ez a fo vedelem az ellen, hogy idegen kuldessen ki barmit.
    // A felado cim (mail_from) is szerepelhet itt: a vegtelen kor ellen
    // nem a cim vedi a rendszert, hanem a kimeno leveleken levo rejtett
    // jelolo fejlec. Lasd: docs/etlap-kuldes.md
    'etlap_bekuldok' => 'marketing@pelda.hu, kantin@pelda.hu',

    // Ennyi percig var a rendszer a kikuldes elott. Ez alatt a bekuldo a
    // kapott elonezetben levo "Megsem" linkkel meg leallithatja.
    // 0 = azonnali kikuldes (nem ajanlott).
    'etlap_varakozas_perc' => '15',

    // Egy futas legfeljebb ennyi masodpercig kuld leveleket, aztan a
    // kovetkezo futas folytatja. Parancssori (cron) futasnal nyugodtan
    // lehet hosszabb; webcimrol inditva maradjon a tarhely idokorlatja alatt.
    'etlap_futasi_ido' => '240',

    // Ennyi masodpercet var ket level kozott. Az Exchange Online percenkent
    // korlatozott szamu levelet enged; ez tartja a rendszert a hatar alatt.
    'etlap_kuldes_tempo' => '2.2',

    // Csak akkor kell, ha a futart webcimmel inditod (cron_kulcs nelkul a
    // futar.php webrol egyaltalan nem erheto el). Generalas:
    //   openssl rand -hex 24
    'cron_kulcs' => '',
];
