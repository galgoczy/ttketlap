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

require_once __DIR__ . '/email_template.php';
require_once __DIR__ . '/lib/phpmailer/Exception.php';
require_once __DIR__ . '/lib/phpmailer/PHPMailer.php';
require_once __DIR__ . '/lib/phpmailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailerException;

/** Ezzel a fejleccel jeloljuk meg a sajat leveleinket (lasd build_message). */
const ETLAP_JELOLO_FEJLEC = 'X-TTK-Kantin';

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
    string $unsubscribeToken = '',
    array $csatolmanyok = []
): void {
    $mail = build_message($to, $subject, $htmlBody, $textBody, $unsubscribeToken, $csatolmanyok);

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
    string $unsubscribeToken = '',
    array $csatolmanyok = []
): PHPMailer {
    $mail = new PHPMailer(true);

    $mail->CharSet = PHPMailer::CHARSET_UTF8;
    $mail->Encoding = PHPMailer::ENCODING_BASE64;

    // A felado cimnek egyeznie kell azzal a postafiokkal, amelyik nevében
    // kuldunk, kulonben az Exchange Online elutasitja a kuldest.
    $mail->setFrom(cfg('mail_from'), cfg('mail_from_name', cfg('site_name')));
    $mail->addReplyTo(cfg('contact_email'), cfg('site_name'));
    $mail->addAddress($to);

    // Sajat jelolo minden kimeno levelen. Errol ismeri fel a futar, ha
    // egy sajat levelunk valahogy visszakerulne az etlap postafiokba -
    // enelkul vegtelen korbe kerulhetne a rendszer.
    $mail->addCustomHeader(ETLAP_JELOLO_FEJLEC, '1');

    if ($unsubscribeToken !== '') {
        $url = unsubscribe_url($unsubscribeToken);
        $mail->addCustomHeader('List-Unsubscribe', '<' . $url . '>');
        // Enelkul a Gmail nem jeleniti meg a sajat leiratkozo gombjat.
        $mail->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
    }

    // Csatolmanyok. A tartalmat memoriabol adjuk at (addStringAttachment),
    // mert a PDF az adatbazisban van, nem fajlkent a lemezen.
    foreach ($csatolmanyok as $csatolmany) {
        $mail->addStringAttachment(
            $csatolmany['tartalom'],
            $csatolmany['nev'],
            PHPMailer::ENCODING_BASE64,
            $csatolmany['tipus'] ?? 'application/pdf'
        );
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

/** A Graph felhasznaloi (postafiok) vegpontjanak kezdete. */
function graph_mailbox_url(string $mailbox, string $ut): string
{
    return 'https://graph.microsoft.com/v1.0/users/'
        . rawurlencode($mailbox) . '/' . ltrim($ut, '/');
}

/**
 * Olvasas a Graphbol, JSON valasszal.
 *
 * @param array<int,string> $extraHeaders
 * @return array<string,mixed>
 */
function graph_get(string $url, array $extraHeaders = []): array
{
    $valasz = graph_http('GET', $url, graph_token(), '', '', $extraHeaders);
    $adat   = json_decode($valasz, true);

    if (!is_array($adat)) {
        throw new RuntimeException('A Graph válasza nem értelmezhető.');
    }

    return $adat;
}

/** Egy level modositasa (nalunk: olvasottra allitas). */
function graph_patch(string $url, array $adat): void
{
    graph_http(
        'PATCH',
        $url,
        graph_token(),
        (string) json_encode($adat, JSON_UNESCAPED_UNICODE),
        'application/json'
    );
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
 * A Graph "lassits" valasza (HTTP 429). Kulon kivetel, mert ez nem hiba:
 * a hivo fel megvarja a kert idot es ujraprobalja.
 */
class GraphLassitsException extends RuntimeException
{
    public int $varakozas;

    public function __construct(int $varakozas)
    {
        $this->varakozas = $varakozas;
        parent::__construct('A Microsoft átmenetileg lassításra kért.');
    }
}

/**
 * Egy HTTPS POST keres. Hiba eseten beszedes kivetelt dob,
 * hogy az admin teszt gombja hasznalhato uzenetet tudjon mutatni.
 */
function graph_request(string $url, string $body, string $contentType, string $token = ''): string
{
    return graph_http('POST', $url, $token, $body, $contentType);
}

/**
 * Egy HTTPS keres tetszoleges modszerrel. A GET-et az etlap postafiok
 * olvasasahoz hasznaljuk, a PATCH-et a level olvasottra allitasahoz.
 *
 * @param array<int,string> $extraHeaders
 */
function graph_http(
    string $method,
    string $url,
    string $token = '',
    string $body = '',
    string $contentType = '',
    array $extraHeaders = []
): string {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('A PHP cURL bővítmény nem érhető el a tárhelyen.');
    }

    $headers = $extraHeaders;
    if ($contentType !== '') {
        $headers[] = 'Content-Type: ' . $contentType;
    }
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => true,
        // A 429-es valasz Retry-After fejlecet kuld: az mondja meg,
        // mennyit kell varni. Ezert a fejleceket is el kell kapnunk.
        CURLOPT_HEADER         => true,
    ]);
    if ($body !== '') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $response  = curl_exec($ch);
    $status    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $fejlecHossz = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $error     = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('Hálózati hiba: ' . $error);
    }

    $response = (string) $response;
    $fejlecek = substr($response, 0, $fejlecHossz);
    $torzs    = substr($response, $fejlecHossz);

    // Tul sok keres egyszerre: a Microsoft megmondja, mennyit varjunk.
    if ($status === 429) {
        $varakozas = 30;
        if (preg_match('/^Retry-After:\s*(\d+)/mi', $fejlecek, $talalat)) {
            $varakozas = max(1, min(300, (int) $talalat[1]));
        }
        throw new GraphLassitsException($varakozas);
    }

    // A sikeres sendMail 202-vel valaszol, ures torzzsel.
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException(graph_error_message($status, $torzs));
    }

    return $torzs;
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
    Kedves Feliratkozónk! Mostantól minden héten elküldjük emailben étlapunkat.

    Az első levél a következő étlapküldéskor érkezik. Ha bármikor meggondolná
    magát, természetesen a levél alján talál leiratkozó linket.

    Leiratkozás: {$unsubscribeUrl}

    Üdvözlettel,
    {$siteName}
    TXT;

    $torzs = email_bekezdes(
            'Kedves Feliratkozónk! Mostantól minden héten elküldjük emailben étlapunkat.'
        )
        . email_bekezdes(
            'Az első levél a következő étlapküldéskor érkezik. Ha bármikor meggondolná '
            . 'magát, természetesen a levél alján talál leiratkozó linket.'
        );

    $html = email_keret(
        'Sikeres feliratkozás',
        $torzs,
        'Mostantól minden héten elküldjük étlapunkat.',
        $unsubscribeUrl
    );

    try {
        send_email($email, $subject, $html, $text, $unsubscribeToken);
        return true;
    } catch (Throwable $exception) {
        // A feliratkozas mar elmentodott - a level hibaja NEM buktathatja meg.
        error_log('Udvozlo level kuldese sikertelen (' . $email . '): ' . $exception->getMessage());
        return false;
    }
}
