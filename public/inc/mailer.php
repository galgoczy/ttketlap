<?php
declare(strict_types=1);

/**
 * Levelkuldes SMTP-n keresztul (PHPMailer).
 *
 * Miert SMTP es nem a PHP beepitett mail() fuggvenye?
 * A domain levelezese M365-ben van, vagyis a domain SPF rekordja a Microsoft
 * szervereit jeloli meg felado gyanant. Ha a levelet a Hostinger szervere
 * kuldene el ugyanarrol a cimrol, a fogado oldal SPF/DMARC ellenorzese
 * megbukna, es a level spambe kerulne vagy visszapattanna.
 * Ezert azon a szerveren keresztul kuldunk, amelyik a domain nevben
 * hivatalosan is kuldhet.
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
    global $config;

    $mail = new PHPMailer(true);

    $mail->isSMTP();
    $mail->Host       = cfg('smtp_host');
    $mail->Port       = (int) cfg('smtp_port', '587');
    $mail->SMTPAuth   = true;
    $mail->Username   = cfg('smtp_user');
    $mail->Password   = cfg('smtp_pass');
    $mail->SMTPSecure = cfg('smtp_secure', PHPMailer::ENCRYPTION_STARTTLS);
    $mail->CharSet    = PHPMailer::CHARSET_UTF8;
    $mail->Timeout    = 15;

    // A felado cimnek egyeznie kell azzal a postafiokkal, amivel bejelentkezunk,
    // kulonben az Exchange Online elutasitja a kuldest.
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

    $mail->send();
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
    if (cfg('send_welcome_email', '1') !== '1' || cfg('smtp_host') === '') {
        return false;
    }

    $siteName      = cfg('site_name');
    $unsubscribeUrl = unsubscribe_url($unsubscribeToken);

    $subject = 'Sikeres feliratkozás – ' . $siteName;

    $text = <<<TXT
    Szia!

    Sikeresen feliratkoztál a heti étlapra. Mostantól minden héten elküldjük
    emailben, mi lesz az ebéd.

    Ha mégsem kéred, itt tudsz leiratkozni:
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
        Szia! Mostantól minden héten elküldjük emailben a menza étlapját,
        így előre tudod, mi lesz az ebéd.
      </p>
      <p style="margin: 24px 0 0; font-size: 13px; color: #6b6862;">
        Ezt a levelet azért kapod, mert feliratkoztál a(z) {$safeName} heti étlapjára.<br>
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
