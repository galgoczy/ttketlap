# Heti menza étlap – feliratkozási rendszer

QR-kód → mobilbarát feliratkozó oldal → saját email-adatbázis → admin + CSV export → leiratkozás.

Nincs WordPress, nincs külső hírlevél-szolgáltató. Sima PHP + MySQL, ami elfut
bármelyik Hostinger tárhelyen.

---

## Mi van a dobozban?

| Oldal | Mit csinál |
|---|---|
| `/` | Feliratkozó oldal (ide mutat a QR-kód) |
| `/adatkezeles.php` | Adatkezelési tájékoztató |
| `/leiratkozas.php?token=...` | Leiratkozás (egyedi link minden feliratkozónak) |
| `/admin/` | Jelszavas admin: lista, darabszámok, CSV export, deaktiválás |

Mappaszerkezet:

```
public/            ← ez kerül ki a tárhely public_html mappájába
  index.php          feliratkozó oldal
  leiratkozas.php    leiratkozás
  adatkezeles.php    tájékoztató
  admin/             adminfelület
  inc/               közös PHP kód, levélküldés + a konfiguráció (kívülről nem elérhető)
  assets/css/app.css MINDEN vizuális beállítás egy helyen
sql/schema.sql     adatbázis tábla
docs/              email sablon, levélküldés, QR-kód útmutató
tools/             jelszó-hash generáló (csak helyben, nem kerül fel a tárhelyre)
.github/workflows/ automatikus feltöltés Hostingerre
```

---

## Beüzemelés – lépésről lépésre

### 1. Adatbázis létrehozása

1. Lépj be a Hostinger **hPanel**-be → **Adatbázisok** → **MySQL adatbázisok**.
2. Hozz létre egy új adatbázist (pl. `menza`) és egy felhasználót. **Mentsd el a jelszót.**
3. Kattints a **phpMyAdmin** gombra az adatbázis mellett.
4. Válaszd az **SQL** fület, másold be a `sql/schema.sql` fájl teljes tartalmát, és futtasd le.

### 2. Fájlok feltöltése

Az automatikus feltöltés beállítása a 4. lépésben van. Első alkalommal a leggyorsabb
kézzel: hPanel → **Fájlkezelő** → töltsd fel a `public/` mappa **tartalmát**
(nem magát a mappát!) a `public_html` mappába.

### 3. Konfiguráció

1. A `public_html/inc/` mappában másold le a `config.sample.php` fájlt `config.php` néven.
2. Nyisd meg szerkesztésre, és töltsd ki:
   - `db_host`, `db_name`, `db_user`, `db_pass` – az 1. lépésben kapott adatok
   - `site_url` – a domained, `https://`-sel, a végén **per jel nélkül**
   - `operator_name`, `operator_address`, `contact_email` – ezek jelennek meg a
     tájékoztatóban
   - `app_secret` – egy hosszú véletlen karakterlánc. **Egyszer állítsd be, utána
     soha ne változtasd**, mert a leiratkozó linkek erre épülnek. Generálás:

     ```bash
     openssl rand -base64 48
     ```

   - `mail_from`, `graph_*` (vagy `smtp_*`) – a levélküldéshez, lásd
     [`docs/levelkuldes.md`](docs/levelkuldes.md). Ha ezeket egyelőre üresen
     hagyod, a feliratkozás működik, csak visszaigazoló levél nem megy ki.
3. Admin jelszó: generálj hozzá egy hash-t (lásd lentebb), és másold a
   `config.php` `admin_password_hash` mezőjébe.
4. Nyisd meg a `https://a-domained.hu/` oldalt, és iratkozz fel egy teszt címmel.

#### Az admin jelszó hash generálása

A jelszót soha nem tároljuk nyersen, csak egy bcrypt hash-t.

**A legegyszerűbb:** nyisd meg a [`tools/jelszo-hash.html`](tools/jelszo-hash.html)
fájlt a böngésződben (elég rákattintani), írd be a jelszót, és másold a kapott
hash-t. A fájl internet nélkül is működik, a jelszó nem hagyja el a gépedet,
és nem kerül fel a tárhelyre sem.

**Terminálból**, ha úgy kényelmesebb – **a parancs bekéri a jelszót, és nem
írja ki a képernyőre, így a shell előzményekbe sem kerül bele**:

```bash
read -rs -p "Jelszó: " PW && echo &&
php -r 'echo password_hash($argv[1], PASSWORD_BCRYPT, ["cost" => 12]), PHP_EOL;' "$PW" &&
unset PW
```

A kapott `$2y$12$...` kezdetű sor az, amit a `config.php`-ba kell másolni.

**Ha nincs PHP a gépeden** (a macOS-en alapból nincs), két lehetőség:

```bash
# a) Apache htpasswd – macOS-en és a legtöbb Linuxon alapból elérhető
read -rs -p "Jelszó: " PW && echo &&
htpasswd -nbBC 12 "" "$PW" | cut -d: -f2 &&
unset PW
```

```bash
# b) Docker, ha az van kéznél
read -rs -p "Jelszó: " PW && echo &&
docker run --rm php:8.3-cli php -r 'echo password_hash($argv[1], PASSWORD_BCRYPT, ["cost" => 12]), PHP_EOL;' "$PW" &&
unset PW
```

Mindhárom ugyanolyan formátumú bcrypt hash-t ad, amit a rendszer elfogad.

> A hash-t nyugodtan másolhatod emailben vagy chatben – nem lehet belőle
> visszafejteni a jelszót. A **jelszót** magát viszont ne küldd sehova.

### 4. Automatikus feltöltés GitHubról (opcionális, de ajánlott)

Ezután elég a GitHubra pusholni, és a tárhely magától frissül.

#### 4.1. Hozz létre egy külön FTP-fiókot ehhez az oldalhoz

**Ne a fő FTP-fiókot add oda a GitHubnak.** A Hostinger fő FTP-fiókja a *teljes
tárhelycsomaghoz* tartozik, nem egy weboldalhoz – vagyis a csomagod **összes**
oldalának fájljait eléri. Ha ez a jelszó kerül be a GitHub secretbe, akkor egy
esetleges szivárgás nem csak a menzás oldalt érinti.

Ehelyett:

1. hPanel → **Fájlok** → **FTP-fiókok** → **További FTP-fiók létrehozása**.
2. A **könyvtár** mezőbe írd be *kizárólag* ennek az oldalnak az útvonalát, pl.:
   `/home/u123456789/domains/a-domained.hu/public_html`
3. Adj neki saját, hosszú, véletlen jelszót (nem ugyanazt, amit máshol használsz).

Így ez a fiók csak ehhez az egy oldalhoz fér hozzá.

#### 4.2. GitHub secretek

A repo → **Settings** → **Secrets and variables** → **Actions** →
**New repository secret**. Vedd fel ezt a hármat:

| Név | Érték |
|---|---|
| `FTP_SERVER` | az FTP szerver címe (hPanel → FTP-fiókok) |
| `FTP_USERNAME` | a 4.1-ben létrehozott fiók felhasználóneve |
| `FTP_PASSWORD` | a 4.1-ben megadott jelszó |

#### 4.3. Ellenőrizd a célmappát

Ez **egyetlen sor** a `.github/workflows/deploy.yml` fájlban:

```yaml
server-dir: /
```

Ez mondja meg, hogy a tárhelyen hova másolja a fájlokat. Az alapértelmezett `/`
azt jelenti: *oda, ahova az FTP-fiók belép*. Ha a 4.1 szerinti külön fiókot
használod, ez már helyes, nincs teendőd.

Csak akkor kell hozzányúlni, ha mégis a **fő** FTP-fiókkal csatlakozol – az
ugyanis a tárhely gyökerébe lép be, nem az oldal mappájába, így oda kell
navigálni. Válaszd ki azt az **egy** sort, ami rád igaz:

| Ha ezzel csatlakozol… | …akkor `server-dir:` |
|---|---|
| Külön, a `public_html`-re korlátozott fiók (ajánlott) | `/` |
| Fő FTP-fiók, fő domain | `/public_html/` |
| Fő FTP-fiók, további domain | `/domains/a-domained.hu/public_html/` |

Kész. Minden `main` ágra pusholás után automatikusan felmegy a `public/` tartalma.
A folyamatot a repo **Actions** fülén tudod követni.

> A `config.php` szándékosan **nincs** a gitben és a feltöltésből is ki van zárva –
> így az adatbázis-jelszó soha nem kerül nyilvánosságra, és a feltöltés sem írja felül.

### 5. Levélküldés

A domain levelezése M365-ben van, ezért a leveleket a Microsofton keresztül
küldjük – különben a leveleink spambe kerülnének.

Két út közül lehet választani, a `config.php` `mail_transport` mezőjével:

- **`graph`** (alapértelmezés, ajánlott) – Microsoft Graph API,
  alkalmazás-regisztrációval. Nem kell hozzá jelszó, és nem érinti az
  egyszerű jelszavas SMTP kivezetése.
- **`smtp`** – klasszikus jelszavas SMTP. Egyszerűbb, de kifutó megoldás,
  és ha a tenantban be van kapcsolva a Security Defaults, eleve nem működik.

A beállítás lépésről lépésre: [`docs/levelkuldes.md`](docs/levelkuldes.md).

Feliratkozáskor a rendszer küld egy rövid visszaigazoló levelet, benne a
leiratkozó linkkel. Ez **nem** double opt-in: a feliratkozás a levél nélkül is
érvényes. Ha a küldés hibázik, a feliratkozás akkor is elmentődik.

Az `/admin/` oldalon a **Teszt levél küldése** gombbal tudod ellenőrizni,
hogy a beállítás jó-e.

### 6. QR-kód

Lásd: [`docs/qr-kod.md`](docs/qr-kod.md).

---

## A heti étlap kiküldése (kézzel)

1. Lépj be az `/admin/` oldalra.
2. **Aktívak letöltése (CSV)** – a fájl tartalmazza minden feliratkozó
   egyedi leiratkozó linkjét is.
3. Küldd ki körlevélként. A sablon és a fontos tudnivalók:
   [`docs/email-sablon.md`](docs/email-sablon.md).

---

## Design testreszabása

Minden szín, betűméret, térköz és lekerekítés a
[`public/assets/css/app.css`](public/assets/css/app.css) tetején lévő `:root` blokkban van.
A design system megérkezésekor **csak ezeket az értékeket kell átírni** –
a HTML és a PHP változatlan marad.

Az oldal mobile first: egy oszlop, 48px-es érintési felületek, a betűméret sehol
nem kisebb 16px-nél (különben az iPhone ránagyít a mezőkre). Sötét témát is támogat.

---

## Ami be van építve a biztonság érdekében

- Minden adatbázis-lekérdezés előkészített (prepared) utasítás – nincs SQL injection.
- CSRF-token minden űrlapon.
- Rejtett „honeypot” mező + IP-alapú sebességkorlát (10 próbálkozás / óra) a botok ellen.
- Az admin jelszó bcrypt hash-ként tárolva, nem nyersen.
- A leiratkozás csak megerősítés (POST) után történik – így a levelezőprogramok
  link-előnézete nem iratkoztat le senkit véletlenül.
- Az IP-cím csak sózott lenyomatként tárolódik, nem nyersen.
- CSV export kepletinjekció (`=`, `+`, `@`) ellen védve.
- A kiküldött levelek `List-Unsubscribe` fejlécet kapnak, így a Gmail és az
  Outlook saját leiratkozó gombot jelenít meg – ez javítja a kézbesíthetőséget.
- Biztonsági HTTP-fejlécek és HTTPS-kényszerítés a `.htaccess`-ben.

---

## Későbbi automatizálás

Az adatbázis már készen áll rá. A következő lépés egy `cron` feladat lenne, ami
egy feltöltött étlapból összeállítja a levelet, és kiküldi az aktív feliratkozóknak
(`SELECT email, unsubscribe_token FROM subscribers WHERE status = 'active'`).
Ez szándékosan nem része az első verziónak.

## Adatvédelmi megjegyzés

Az `adatkezeles.php` egy **általános sablon**, nem jogi tanács. Mielőtt élesben
kimegy, nézesd át valakivel, aki ért hozzá, és írd bele a valós céges adatokat.
Ha módosítod a tájékoztatót, emeld meg a `consent_version` értéket a
`config.php`-ban – így nyomon követhető, ki melyik verzióra adott hozzájárulást.
