<?php
declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

render_header('Adatkezelési tájékoztató');
?>

<div class="card prose">
    <h1 class="title">Adatkezelési tájékoztató</h1>
    <p class="subtitle">
        Hatályos verzió: <?= e(cfg('consent_version')) ?>
    </p>

    <h2>Ki kezeli az adataidat?</h2>
    <p>
        <?= e(cfg('operator_name')) ?> (<?= e(cfg('operator_address')) ?>),
        elérhetőség: <a href="mailto:<?= e(cfg('contact_email')) ?>"><?= e(cfg('contact_email')) ?></a>.
    </p>

    <h2>Milyen adatot kezelünk?</h2>
    <ul>
        <li>az email-címedet,</li>
        <li>a feliratkozás időpontját és az elfogadott tájékoztató verziószámát,</li>
        <li>a feliratkozás státuszát (aktív vagy leiratkozott),</li>
        <li>a feliratkozáskori IP-címed titkosított lenyomatát (visszaélések megelőzésére).</li>
    </ul>

    <h2>Miért kezeljük?</h2>
    <p>
        Kizárólag azért, hogy elküldjük neked a menza heti étlapját.
        Az adatkezelés jogalapja a te hozzájárulásod (GDPR 6. cikk (1) a) pont).
        Az email-címedet reklám céljára nem használjuk, és harmadik félnek nem adjuk át.
    </p>

    <h2>Meddig kezeljük?</h2>
    <p>
        A hozzájárulásod visszavonásáig. Leiratkozás után a címed inaktív státuszba kerül,
        és többé nem küldünk rá levelet. A leiratkozás tényét a jogszerű működés igazolása
        érdekében megőrizzük, de kérésre az adatot véglegesen töröljük.
    </p>

    <h2>Hogyan iratkozhatsz le?</h2>
    <p>
        Minden kiküldött levél alján találsz egy egyedi leiratkozási linket.
        Leiratkozni bármikor, indokolás nélkül, díjmentesen tudsz.
        Írhatsz nekünk a <a href="mailto:<?= e(cfg('contact_email')) ?>"><?= e(cfg('contact_email')) ?></a>
        címre is.
    </p>

    <h2>Milyen jogaid vannak?</h2>
    <ul>
        <li>tájékoztatást kérhetsz a rólad kezelt adatokról,</li>
        <li>kérheted az adataid helyesbítését vagy törlését,</li>
        <li>bármikor visszavonhatod a hozzájárulásodat,</li>
        <li>panasszal fordulhatsz a Nemzeti Adatvédelmi és Információszabadság Hatósághoz
            (NAIH, 1055 Budapest, Falk Miksa utca 9-11., <a href="https://naih.hu" target="_blank" rel="noopener">naih.hu</a>).</li>
    </ul>

    <h2>Adatbiztonság</h2>
    <p>
        Az adatokat saját tárhelyünkön, jelszóval védett adatbázisban tároljuk.
        Külső hírlevél-szolgáltatót nem használunk.
    </p>

    <p style="margin-top: var(--space-6)">
        <a class="btn btn--secondary" href="/">Vissza a feliratkozáshoz</a>
    </p>
</div>

<?php render_footer(); ?>
