<?php
declare(strict_types=1);

/**
 * Levelkuldes.
 *
 * Miert nem a PHP beepitett mail() fuggvenye?
 * A domain levelezese M365-ben van, vagyis a domain SPF rekordja a Microsoft
 * szervereit jeloli meg felado gyanant. Ha a levelet a Hostinger szervere
 * kuldene el ugyanarrol a cimrol, a fogado oldal SPF/DMARC ellenorzese
 * megbukna, es a level spambe kerulne vagy visszapattanna.
 * Ezert azon a szerveren keresztul kuldunk, amelyik a domain nevben
 * hivatalosan is kuldhet.
 *
 * Ketfele utvonal van, a config.php 'mail_transport' ertekevel valaszthatod:
 *
 *   'graph' (ajanlott) - Microsoft Graph API, app regisztracioval.
 *       A levelet a Graph kuldi el a megadott postafiok neveben.
 *       Nem kell hozza jelszo, sem alkalmazasjelszo, es nem erinti
 *       az egyszeru jelszavas SMTP kivezetese.
 *
 *   'smtp' - klasszikus SMTP a smtp.office365.com-on at, jelszoval.
 *       Egyszerubb beallitani, de a Microsoft kivezeti, es sok tenantban
 *       eleve tiltva van (Security Defaults).
 *
 * Reszletek: docs/levelkuldes.md
 */

require_once __DIR__ . '/lib/phpmailer/Exception.php';
require_once __DIR__ . '/lib/phpmailer/PHPMailer.php';
require_once __DIR__ . '/lib/phpmailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailerException;

/**
 * Egy level elkuldese.
 *
 * @param string $unsubscribeToken Ha meg van adva, bekerul a List-Unsubscribe
 *                                 fejlecbe is, igy a levelezok (Gmail, Outlook)
 *                                 sajat leiratkozo gombot jelenitenek meg.
 * @throws MailerException ha a kuldes nem sikerult
 */
function send_email(
    string $to,
    string $subject,
    string $htmlBody,
    string $textBody,
    string $unsubscribeToken = ''
): void {
    $mail = build_message($to, $subject, $htmlBody, $textBody, $unsubscribeToken);

    if (cfg('mail_transport', 'graph') === 'graph') {
        graph_send($mail);
        return;
    }

    smtp_send($mail);
}

/**
 * Osszeallitja a levelet. Mindket kuldesi utvonal ugyanezt hasznalja,
 * igy a level tartalma es fejlecei pontosan ugyanazok lesznek.
 */
function build_message(
    string $to,
    string $subject,
    string $htmlBody,
    string $textBody,
    string $unsubscribeToken = ''
): PHPMailer {
    $mail = new PHPMailer(true);

    $mail->CharSet = PHPMailer::CHARSET_UTF8;
    $mail->Encoding = PHPMailer::ENCODING_BASE64;

    // A felado cimnek egyeznie kell azzal a postafiokkal, amelyik nevében
    // kuldunk, kulonben az Exchange Online elutasitja a kuldest.
    $mail->setFrom(cfg('mail_from'), cfg('mail_from_name', cfg('site_name')));
    $mail->addReplyTo(cfg('contact_email'), cfg('site_name'));
    $mail->addAddress($to);

    if ($unsubscribeToken !== '') {
        $url = unsubscribe_url($unsubscribeToken);
        $mail->addCustomHeader('List-Unsubscribe', '<' . $url . '>');
        // Enelkul a Gmail nem jeleniti meg a sajat leiratkozo gombjat.
        $mail->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
    }

    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body    = $htmlBody;
    $mail->AltBody = $textBody;

    return $mail;
}

/** Kuldes klasszikus SMTP-n (jelszavas). */
function smtp_send(PHPMailer $mail): void
{
    $mail->isSMTP();
    $mail->Host       = cfg('smtp_host');
    $mail->Port       = (int) cfg('smtp_port', '587');
    $mail->SMTPAuth   = true;
    $mail->Username   = cfg('smtp_user');
    $mail->Password   = cfg('smtp_pass');
    $mail->SMTPSecure = cfg('smtp_secure', PHPMailer::ENCRYPTION_STARTTLS);
    $mail->Timeout    = 15;

    $mail->send();
}

/**
 * Kuldes a Microsoft Graph API-n at, app regisztracioval.
 *
 * A kesz levelet nyers MIME-kent adjuk at a Graphnak. Igy minden fejlec
 * atmegy - a List-Unsubscribe is, amit a Graph strukturalt (JSON) kuldes
 * eseten nem engedne be.
 */
function graph_send(PHPMailer $mail): void
{
    // A PHPMailer a fejleceket a kuldesi mod szerinti sorhosszhoz igazitja.
    // Alapbeallitasban (mail) a hatar 63 karakter, es az ennel hosszabb
    // fejleceket - igy a leiratkozo linket is - =?us-ascii?Q?...?= formaba
    // kodolja. A levelezoprogramok a List-Unsubscribe fejlecben ezt nem
    // fejtik vissza, vagyis elromlana a leiratkozo gomb. SMTP modban a
    // hatar 998 karakter, ezert a fejlec erintetlen marad.
    // Kapcsolat nem epul ki: azt a send() csinalna, mi csak preSend()-elunk.
    $mail->isSMTP();
    $mail->preSend();
    $mime = $mail->getSentMIMEMessage();

    $mailbox = cfg('mail_from');
    $url = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($mailbox) . '/sendMail';

    graph_request($url, base64_encode($mime), 'text/plain', graph_token());
}

/**
 * Hozzaferesi token kerese app regisztracioval (client credentials).
 * A token ~1 oraig ervenyes, ezert a keresen belul ujra felhasznaljuk.
 */
function graph_token(): string
{
    static $cached = null;
    static $expiresAt = 0;

    if ($cached !== null && time() < $expiresAt - 60) {
        return $cached;
    }

    $tenant = cfg('graph_tenant_id');
    $url = 'https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/token';

    $body = http_build_query([
        'client_id'     => cfg('graph_client_id'),
        'client_secret' => cfg('graph_client_secret'),
        'scope'         => 'https://graph.microsoft.com/.default',
        'grant_type'    => 'client_credentials',
    ]);

    $response = graph_request($url, $body, 'application/x-www-form-urlencoded');
    $data = json_decode($response, true);

    if (!is_array($data) || empty($data['access_token'])) {
        throw new RuntimeException('A Graph nem adott vissza hozzáférési tokent.');
    }

    $cached    = (string) $data['access_token'];
    $expiresAt = time() + (int) ($data['expires_in'] ?? 3600);

    return $cached;
}

/**
 * Egy HTTPS POST keres. Hiba eseten beszedes kivetelt dob,
 * hogy az admin teszt gombja hasznalhato uzenetet tudjon mutatni.
 */
function graph_request(string $url, string $body, string $contentType, string $token = ''): string
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('A PHP cURL bővítmény nem érhető el a tárhelyen.');
    }

    $headers = ['Content-Type: ' . $contentType];
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('Hálózati hiba: ' . $error);
    }

    // A sikeres sendMail 202-vel valaszol, ures torzzsel.
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException(graph_error_message($status, (string) $response));
    }

    return (string) $response;
}

/** A Microsoft hibavalaszabol olvashato uzenetet keszit. */
function graph_error_message(int $status, string $response): string
{
    $data = json_decode($response, true);

    $detail = '';
    if (is_array($data)) {
        $detail = (string) ($data['error_description'] ?? $data['error']['message'] ?? '');
    }
    if ($detail === '') {
        $detail = mb_substr($response, 0, 300);
    }

    // A leggyakoribb hibak magyarul, hogy ne kelljen talalgatni.
    $hint = match (true) {
        str_contains($detail, 'AADSTS7000215') => ' (Hibás vagy lejárt client secret.)',
        str_contains($detail, 'AADSTS700016') => ' (Ismeretlen client ID – ellenőrizd az alkalmazás azonosítóját.)',
        str_contains($detail, 'AADSTS90002')  => ' (Ismeretlen tenant ID.)',
        $status === 403                        => ' (Hiányzik a Mail.Send jogosultság, vagy nincs rá rendszergazdai jóváhagyás.)',
        $status === 404                        => ' (Nincs ilyen postafiók – a mail_from cím nem létezik a tenantban.)',
        default                                => '',
    };

    return 'Microsoft hiba (HTTP ' . $status . '): ' . $detail . $hint;
}

/**
 * Be van-e allitva a levelkuldes? Ha nincs, a rendszer nem kuld levelet,
 * de a feliratkozas tovabbra is mukodik.
 */
function mail_configured(): bool
{
    if (cfg('mail_from') === '') {
        return false;
    }

    return cfg('mail_transport', 'graph') === 'graph'
        ? cfg('graph_tenant_id') !== '' && cfg('graph_client_id') !== '' && cfg('graph_client_secret') !== ''
        : cfg('smtp_host') !== '';
}

/**
 * Feliratkozast visszaigazolo (udvozlo) level.
 *
 * Ez NEM double opt-in: a feliratkozas a level nelkul is ervenyes, itt nincs
 * mit megerositeni. Csak visszajelzes a felhasznalonak, hogy sikerult,
 * es hogy hol tud leiratkozni.
 *
 * @return bool sikerult-e a kuldes
 */
function send_welcome_email(string $email, string $unsubscribeToken): bool
{
    if (cfg('send_welcome_email', '1') !== '1' || !mail_configured()) {
        return false;
    }

    $siteName      = cfg('site_name');
    $unsubscribeUrl = unsubscribe_url($unsubscribeToken);

    $subject = 'Sikeres feliratkozás – ' . $siteName;

    $text = <<<TXT
    Kedves Feliratkozónk!

    Sikeresen feliratkozott a heti étlapra. Mostantól minden héten elküldjük
    emailben, mi lesz az ebéd.

    Ha mégsem kéri, itt tud leiratkozni:
    {$unsubscribeUrl}

    Üdvözlettel,
    {$siteName}
    TXT;

    $safeUrl  = e($unsubscribeUrl);
    $safeName = e($siteName);

    $html = <<<HTML
    <div style="font-family: Arial, Helvetica, sans-serif; font-size: 16px; line-height: 1.6; color: #1c1b19; max-width: 600px;">
      <h1 style="font-size: 22px; margin: 0 0 16px;">Sikeres feliratkozás</h1>
      <p style="margin: 0 0 16px;">
        Kedves Feliratkozónk! Mostantól minden héten elküldjük emailben a menza
        étlapját, így előre tudja, mi lesz az ebéd.
      </p>
      <p style="margin: 24px 0 0; font-size: 13px; color: #6b6862;">
        Ezt a levelet azért kapja, mert feliratkozott a(z) {$safeName} heti étlapjára.<br>
        <a href="{$safeUrl}" style="color: #6b6862;">Leiratkozás</a>
      </p>
    </div>
    HTML;

    try {
        send_email($email, $subject, $html, $text, $unsubscribeToken);
        return true;
    } catch (Throwable $exception) {
        // A feliratkozas mar elmentodott - a level hibaja NEM buktathatja meg.
        error_log('Udvozlo level kuldese sikertelen (' . $email . '): ' . $exception->getMessage());
        return false;
    }
}
