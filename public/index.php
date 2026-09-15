<?php
declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/mailer.php';

start_session();

$errors    = [];
$succeeded = false;
$email     = '';
$consent   = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email   = normalize_email((string) ($_POST['email'] ?? ''));
    $consent = !empty($_POST['consent']);
    $honey   = trim((string) ($_POST['website'] ?? ''));

    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Az űrlap érvényessége lejárt. Kérjük, próbálja újra.';
    } elseif ($honey !== '') {
        // Bot toltotte ki a rejtett mezot - ugy teszunk, mintha sikerult volna.
        $succeeded = true;
    } elseif (!valid_email($email)) {
        $errors[] = 'Kérjük, adjon meg egy érvényes email-címet.';
    } elseif (!$consent) {
        $errors[] = 'A feliratkozáshoz el kell fogadnia az adatkezelési tájékoztatót.';
    } else {
        try {
            if (rate_limit_exceeded()) {
                $errors[] = 'Túl sok próbálkozás történt. Kérjük, próbálja újra később.';
            } else {
                log_signup_attempt();
                $token = subscribe($email);
                $succeeded = true;

                // Visszaigazolo level. Ha nem megy ki (pl. az SMTP nem elerheto),
                // a feliratkozas akkor is ervenyes marad - a felhasznalo nem hibazott.
                send_welcome_email($email, $token);
                // Uj CSRF token, hogy a frissites ne kuldje be ujra az urlapot.
                unset($_SESSION['csrf_token']);
            }
        } catch (Throwable $exception) {
            error_log('Feliratkozasi hiba: ' . $exception->getMessage());
            $errors[] = 'Technikai hiba történt. Kérjük, próbálja újra néhány perc múlva.';
        }
    }
}

/**
 * Feliratkoztatja az email-cimet. Ha mar letezik, ujra aktivalja,
 * es frissiti a hozzajarulas idopontjat/verziojat.
 *
 * @return string a cimhez tartozo leiratkozo token
 */
function subscribe(string $email): string
{
    $stmt = db()->prepare('SELECT id, status, unsubscribe_token FROM subscribers WHERE email = ?');
    $stmt->execute([$email]);
    $existing = $stmt->fetch();

    if ($existing === false) {
        $token = bin2hex(random_bytes(32));

        db()->prepare(
            'INSERT INTO subscribers
                (email, status, consent_version, consent_at, unsubscribe_token, source, ip_hash)
             VALUES (?, "active", ?, NOW(), ?, ?, ?)'
        )->execute([
            $email,
            cfg('consent_version'),
            $token,
            substr((string) ($_GET['forras'] ?? 'qr'), 0, 64),
            ip_hash(),
        ]);

        return $token;
    }

    db()->prepare(
        'UPDATE subscribers
            SET status = "active",
                consent_version = ?,
                consent_at = NOW(),
                unsubscribed_at = NULL
          WHERE id = ?'
    )->execute([cfg('consent_version'), $existing['id']]);

    return $existing['unsubscribe_token'];
}

render_header('Feliratkozás a heti étlapra');
?>

<?php if ($succeeded): ?>

    <div class="card result">
        <div class="result__icon" aria-hidden="true">✓</div>
        <h1 class="title">Sikeres feliratkozás</h1>
        <p class="subtitle">
            Mostantól elküldjük Önnek emailben a menza heti étlapját.
            Leiratkozni bármikor tud a levelek alján található linkkel.
        </p>
    </div>

<?php else: ?>

    <div class="card">
        <p class="eyebrow">Menza</p>
        <h1 class="title">Kérje a heti étlapot emailben</h1>
        <p class="subtitle">
            Iratkozzon fel, és minden héten elküldjük a menza étlapját &ndash;
            így előre tudja, mi lesz az ebéd.
        </p>

        <?php foreach ($errors as $error): ?>
            <div class="alert alert--error" role="alert">
                <span class="alert__icon" aria-hidden="true">!</span>
                <span><?= e($error) ?></span>
            </div>
        <?php endforeach; ?>

        <form method="post" action="" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

            <!-- Honeypot: botok elleni rejtett mezo. -->
            <div class="hp" aria-hidden="true">
                <label for="website">Ezt a mezőt hagyja üresen</label>
                <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
            </div>

            <div class="field">
                <label class="label" for="email">Email-cím</label>
                <input
                    class="input"
                    type="email"
                    id="email"
                    name="email"
                    inputmode="email"
                    autocomplete="email"
                    autocapitalize="off"
                    spellcheck="false"
                    placeholder="pelda@email.hu"
                    required
                    value="<?= e($email) ?>"
                    <?= $errors ? 'aria-invalid="true"' : '' ?>
                >
            </div>

            <label class="checkbox" for="consent">
                <input type="checkbox" id="consent" name="consent" value="1" required <?= $consent ? 'checked' : '' ?>>
                <span>
                    Elolvastam és elfogadom az
                    <a href="/adatkezeles.php" target="_blank" rel="noopener">adatkezelési tájékoztatót</a>,
                    és hozzájárulok, hogy a heti étlapot emailben megkapjam.
                </span>
            </label>

            <button class="btn btn--primary" type="submit">Feliratkozom</button>
        </form>
    </div>

<?php endif; ?>

<?php render_footer(); ?>
