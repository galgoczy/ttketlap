# Automatikus étlapküldés

Az üzletvezető elküldi a napi étlapot egy postafiókba – **PDF-ben vagy
képként** –, a rendszer pedig kiküldi minden feliratkozónak. Ez az útmutató
végigvezet a beállításon.

## Hogyan működik

1. Az üzletvezető ráküldi az étlapot az étlap-postafiókra
   (`ttk.etlap@pepperhouse.hu`), **PDF, JPG vagy PNG csatolmányként**.
   A levél **tárgya** lesz a körlevél tárgya. A levél **szövegét a rendszer
   figyelmen kívül hagyja** – mindig ez a sablonszöveg megy ki:

   > Kedves Vendégünk!
   >
   > Mellékelten küldjük friss étlapunkat. Reméljük, hogy hamarosan ismét
   > vendégül láthatjuk!
   >
   > *[az étlap]*
   >
   > a TTK Kantin csapata

   Ez szándékos: a beküldött levelekben ott az aláírás, telefonszám és egyéb
   belső szöveg, ami így véletlenül sem kerülhet ki a vendégekhez. A szöveg
   módosításához a `public/inc/etlap_futar.php` fájlban az `ETLAP_SABLON`-t
   kell átírni.
2. A tárhelyen percenként lefut a `futar.php`. Megnézi a postafiókot, és ha
   talál jogosult feladótól érkezett, csatolmányos levelet, előkészíti
   a kiküldést.
3. **A beküldő visszakap egy előnézetet**, pontosan azzal a levéllel, ami
   ki fog menni – benne egy „Mégsem" linkkel.
4. Ha 15 percig senki nem nyúl hozzá, a kiküldés elindul. A leveleket a
   rendszer **egyesével** küldi, mindenkinek a saját leiratkozó linkjével.
5. A végén a beküldő kap egy összegzést: hány címre ment ki.

Az állapot bármikor megnézhető az admin felületen: **Étlap kiküldések**.
Hogy pontosan mi történt és mikor, azt a **Napló** oldal mutatja.

### PDF vagy kép?

| | Mi történik |
|---|---|
| **PDF** | Csatolmányként megy. A vendégnek meg kell nyitnia, viszont nyomtatható és éles marad nagyításnál is. |
| **JPG / PNG** | **A levél törzsébe ágyazva** megy: a vendég rögtön látja, nem kell megnyitnia semmit. Ez a kényelmesebb napi étlaphoz. |

Képnél a rendszer a nagy fényképeket automatikusan 1600 pixel szélesre
kicsinyíti – egy telefonnal készült fotó így is belefér a méretkorlátba.

Fontos: a képet **csatolmányként** adja hozzá, ne a levél szövegébe
illessze be. A szövegbe ágyazott képet sok levelezőprogram máshogy kezeli,
és előfordulhat, hogy a rendszer nem találja meg.

## Miért nem kerül licencbe

Az étlap-postafiók legyen **megosztott postafiók** (shared mailbox). Az M365-ben
ez ingyenes, 50 GB-ig nem kell hozzá licenc. Nem is kell bele soha belépni –
a rendszer a Graph API-n keresztül olvassa.

## 1. A postafiók létrehozása

Microsoft 365 admin központ → **Csapatok és csoportok** → **Megosztott postafiókok**
→ **Megosztott postafiók hozzáadása**. Név: **`ttk.etlap@pepperhouse.hu`**.

### Ezt a lépést ne hagyd ki

Állítsd be, hogy erre a címre **csak a szervezeten belülről** lehessen írni.
Enélkül bárki az internetről beküldhetne ide egy PDF-et.

Exchange admin központ → **Címzettek** → **Postafiókok** → az étlap-postafiók →
**Levélfolyam beállításai** → **Kézbesítési korlátozások** → kapcsold be, hogy
*csak hitelesített feladóktól* fogad levelet.

Ez a védelem első fele. A második a `etlap_bekuldok` lista a beállításokban:
a rendszer csak az ott felsorolt címekről érkező leveleket dolgozza fel.

## 2. Jogosultság az alkalmazásnak

Az app registration eddig csak küldeni tudott. Most olvasnia is kell:

Entra admin központ → **App registrations** → az alkalmazás → **API permissions**
→ **Add a permission** → Microsoft Graph → **Application permissions** →
**`Mail.ReadWrite`** → majd **Grant admin consent**.

> **Miért `Mail.ReadWrite`, és miért nem elég a `Mail.Read`?**
> A rendszer a feldolgozott levelet olvasottra állítja – ebből tudja, hogy
> azzal már végzett. Ez írási művelet, amit a `Mail.Read` nem enged; azzal
> a futár 403-as hibával áll meg. A `Mail.ReadWrite` magában foglalja az
> olvasást is, tehát a `Mail.Read`-et nem kell külön felvenni.

### Érdemes szűkíteni

Az alkalmazás-szintű `Mail.Send` és `Mail.ReadWrite` a tenant **összes** postafiókját
eléri. Érdemes ezt a két címre korlátozni egy hozzáférési szabállyal
(Exchange Online PowerShell):

```powershell
New-ApplicationAccessPolicy -AppId <az alkalmazás azonosítója> `
  -PolicyScopeGroupId <egy biztonsági csoport, amiben a két postafiók van> `
  -AccessRight RestrictAccess `
  -Description "TTK Kantin etlaprendszer"
```

Ezt az M365-öt kezelő kolléga tudja megcsinálni. Nem kötelező a működéshez,
de enélkül egy kiszivárgott jelszó (client secret) a teljes levelezéshez
adna hozzáférést.

## 3. Adatbázis

Futtasd le a `sql/etlap-kuldes.sql` fájlt a Hostinger phpMyAdmin felületén.
Két táblát hoz létre: `etlap_kuldes` és `rendszer_allapot`.

## 4. Beállítások a config.php-ban

```php
'etlap_mailbox'        => 'ttk.etlap@pepperhouse.hu',
'etlap_bekuldok'       => 'marketing@pepperhouse.hu, ttk@pepperhouse.hu',
'etlap_varakozas_perc' => '15',
'cron_kulcs'           => '',   // csak webcímes indításhoz kell
```

Több beküldőt vesszővel válassz el. A kis- és nagybetű nem számít, a
felesleges szóközöket a rendszer levágja.

### A beküldő lehet ugyanaz, mint a feladó

A fenti beállításban a `ttk@pepperhouse.hu` egyszerre **feladó cím**
(`mail_from`) és **jogosult beküldő**. Ez így rendben van.

Hogy ebből ne legyen végtelen kör (a rendszer kiküldi a saját levelét,
az visszaérkezik, és újra kiküldi), két védelem van beépítve:

1. Minden kimenő levélre rákerül egy rejtett jelölő fejléc
   (`X-TTK-Kantin`). Ha egy ilyen levél bármilyen úton visszakerülne az
   étlap-postafiókba, a rendszer felismeri és nem dolgozza fel.
2. Az étlap-postafiók és a feladó cím **soha nem kap körlevelet**, akkor
   sem, ha véletlenül feliratkozna.

## 5. Az időzítő (cron) beállítása

Hostinger hPanel → **Speciális** → **Cron-feladatok**.

- Gyakoriság: **percenként** (vagy 5 percenként, ha a tárhely ennél ritkábbat enged)
- Parancs:

```
/usr/bin/php <a weboldal mappája>/futar.php
```

**A pontos útvonalat ne találgasd:** a weboldal mappája nem feltétlenül
`public_html`. Aldomainnél a Hostingeren jellemzően
`domains/<a domain>/public_html`, és a Fájlkezelő ezt is csak
„public_html" néven mutatja.

Nyisd meg az **admin → Diagnosztika** oldalt: az **„Az időzítő (cron)
parancsa"** sor kiírja a teljes parancsot, és azt is, mi kerül pontosan
a Hostinger mezőjébe (az űrlap a `/usr/bin/php /home/<felhasználó>/`
részt magától beírja).

**Így a futár kívülről egyáltalán nem érhető el** – ez a biztonságos megoldás.

Ha a tárhely csak webcímes időzítést enged, akkor adj meg egy `cron_kulcs`
értéket (`openssl rand -hex 24`), és ezt a címet időzítsd:

```
https://ttketlap.pepperhouse.hu/futar.php?kulcs=<a kulcs>
```

Kulcs nélkül a `futar.php` 404-et ad, mintha nem is létezne.

## 6. Próba

1. Nyisd meg az **admin → Diagnosztika** oldalt. Az étlap-futár sorainak
   zöldnek kell lenniük, köztük az „Időzítő (cron)" sornak.
2. Iratkozz fel egy saját címmel a főoldalon.
3. Küldj egy levelet PDF-fel vagy képpel az étlap-postafiókra egy jogosult
   címről.
4. Pár percen belül meg kell érkeznie az előnézetnek. Ebben kattints a
   **Mégsem** linkre – így ellenőrzöd a visszavonást anélkül, hogy bárkinek
   kimenne levél.
5. Küldd be újra, és most hagyd lefutni.

## Ha valami nem stimmel

| Tünet | Mit nézz meg |
|---|---|
| Nem jön előnézet | Diagnosztika → „Időzítő (cron)" sor. Ha „még soha", a cron nem fut. |
| „Nem jogosult feladó" a naplóban | A beküldő címe nincs benne az `etlap_bekuldok` listában. |
| HTTP 403 „Access is denied" | A hibaüzenet szögletes zárójelben megmondja, melyik művelet bukott el, és melyik jogosultság kell hozzá. Olvasottra állításnál ez `Mail.ReadWrite`. |
| „Nem találtunk étlapot" válasz | A csatolmány nem PDF/JPG/PNG, vagy a kép a levél szövegébe lett beillesztve csatolmány helyett. |
| A kép nem látszik a levélben | A levelezőprogram alapból blokkolja a képeket. A címzettnek engedélyeznie kell a megjelenítést. |
| A kiküldés félbemaradt | Nem baj: a következő futás onnan folytatja, ahol abbahagyta. Senki nem kap két példányt. |
| Sok a hibás cím | Ezek jellemzően megszűnt postafiókok. A Napló oldalon címenként látszik, mi volt a gond. |
| Nem tudod, mi történt | **Admin → Napló.** Szűrhető „Csak a problémák"-ra, és egy-egy kiküldés eseményeire. |
| Nem jön Telegram-üzenet | **Admin → Napló → Teszt üzenet küldése.** Megmondja, mi a baj. A Diagnosztika is ellenőrzi a token formátumát. |

## A napló

Az **admin → Napló** oldal időrendben mutatja, mi történt: beérkezett
étlap, előnézet, a kiküldés indulása és haladása, visszavonás, és minden
hiba – emberi nyelven, címzett szintig.

- Csak az érdemi események kerülnek bele. A percenkénti „nem volt teendő"
  nem, különben napi 1440 üres sor fullasztaná el a lényeget. Hogy a
  rendszer él-e, azt az oldal tetején az „utoljára ekkor nézett be" sor
  mutatja.
- A bejegyzések 60 napig maradnak meg, utána maguktól törlődnek.
- A naplótáblát a rendszer magától létrehozza, nem kell hozzá phpMyAdmin.
- Ha a napló írása valamiért nem sikerül, a kiküldés attól még megy tovább.

## Telegram értesítés

Ha történik valami, a rendszer egy összefoglaló üzenetet küld Telegramra.

### Beállítás

A `config.php`-ba (a szerveren – **soha ne a GitHubra, és ne chatbe**):

```php
'telegram_bot_token' => '123456789:AAH...',   // a @BotFather adja
'telegram_chat_id'   => '123456789',          // csoportnál negatív szám
```

Ha még nincs meg a chat azonosító: írj egy üzenetet a botnak (csoportnál:
add hozzá a botot, és írj a csoportba), majd nyisd meg böngészőben:

```
https://api.telegram.org/bot<A TOKEN>/getUpdates
```

A válaszban a `"chat":{"id": ...}` szám kell.

### Témákra bontott csoport (thread / topic)

Ha a csoport témákra van bontva, meg kell adni, melyik témába menjenek az
üzenetek – különben az „Általános" témába kerülnek:

```php
'telegram_chat_id'   => '-1001234567890',
'telegram_thread_id' => '42',
```

Mindkettő kiolvasható egy linkből: a témában egy üzenetre **jobb gomb →
Hivatkozás másolása**. A link így néz ki:

```
https://t.me/c/1234567890/42/100
               ──────────  ──
               csoport     téma
```

- **chat_id:** a csoport száma elé írjon `-100`-at → `-1001234567890`
- **thread_id:** a középső szám → `42`

A botot adja hozzá a csoporthoz. Ha a csoportban csak adminok írhatnak,
a botot is adminná kell tenni (elég az üzenetküldési jog).

Kipróbálni: **admin → Napló → Teszt üzenet küldése.** Ha nem megy, a gomb
magyarul megmondja, mi a baj (rossz token, ismeretlen chat stb.).

### Miről szól, és miről nem

| Szól | Nem szól |
|---|---|
| Új étlap érkezett, előnézet elment | A kiküldés percenkénti haladása |
| Indul a kiküldés | Címzettenkénti hibák (a záró összegzés megszámolja őket) |
| Befejeződött (hány címre ment) | A Microsoft „lassíts" kérése (az nem hiba) |
| Visszavonás | Saját, visszapattant levél |
| Bármilyen hiba, és ha megjavult | |

- **Egy futás = egy üzenet.** Ami egy percben történik, egy üzenetbe kerül.
- **Ugyanaz a hiba csak egyszer szól.** Ha például hiányzik egy jogosultság,
  a hiba percenként megismétlődne – ez naponta 1440 üzenet lenne. Ehelyett
  egyszer szól, 6 óra múlva emlékeztet, ha még fennáll, és külön szól,
  amikor megjavult.
- **Leállt adatbázisnál is szól.** Az ismétlődés-szűrő fájlban tárolja az
  állapotát, nem az adatbázisban – így pont a legsúlyosabb esetben is működik.
- Ha a Telegram nem elérhető, a kiküldés attól még megy tovább.

## Korlátok

- **A csatolmány legfeljebb 3 MB** lehet. A Microsoft a levelet kb. 4 MB-ig
  fogadja, és a kódolás kb. harmadával növeli a méretet. Nagyobb fájl esetén
  a beküldő kap egy értesítést, és nem megy ki semmi.
  Képnél ez ritkán gond: a rendszer előbb kicsinyít (ehhez a GD bővítmény kell
  a tárhelyen – a Diagnosztika „Képek kicsinyítése" sora megmondja, megvan-e).
  PDF-nél nincs kicsinyítés, ott a mentésnél kell kisebb méretet választani.
- A kiküldés tempója szándékosan lassú (kb. másodpercenként egy fél levél),
  mert az Exchange Online percenként korlátozott számú levelet enged.
  Néhány száz címnél ez pár perc.
- Két futás soha nem fedi át egymást: erre egy adatbázis-zár vigyáz.

## Ami itt nem volt kipróbálható

A fejlesztői gépen nem volt MySQL, ezért az adatbázissal dolgozó részek
(a kiküldés nyilvántartása, a folytatás, a visszavonás) **csak az éles
rendszeren ellenőrizhetők** – emiatt fontos a 6. pont szerinti próba,
különösen a „Mégsem" gomb kipróbálása még azelőtt, hogy bárkinek levél menne.
