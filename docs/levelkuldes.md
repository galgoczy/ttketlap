# Levélküldés beállítása (M365 tenant mellett)

## A probléma dióhéjban

A domain levelezése Microsoft 365-ben van. Ez azt jelenti, hogy a domain nyilvános
beállításai (SPF rekord) azt hirdetik a világnak: *„erről a domainről csak a
Microsoft szerverei küldhetnek levelet."*

Ha a menzás oldal a Hostinger szerveréről küldene levelet `menza@a-domained.hu`
feladóval, a fogadó levelezőrendszer ezt látná:

> A levél azt állítja, hogy a-domained.hu-ról jött, de nem a Microsoft küldte.

Az eredmény: spam mappa, vagy egyenesen visszapattanó levél. Ezért a rendszer
**nem** a PHP beépített `mail()` függvényét használja, hanem a Microsofton
keresztül küld – vagyis azon a szolgáltatáson át, amelyik a domain nevében
hivatalosan is küldhet.

---

## Két lehetséges út

| | **Graph API** (ajánlott) | **SMTP jelszóval** |
|---|---|---|
| Mi kell hozzá? | Alkalmazás-regisztráció (Entra ID) | Licencelt postafiók + jelszó |
| Hitelesítés | OAuth 2.0, jelszó nélkül | Jelszó vagy alkalmazásjelszó |
| MFA mellett működik? | Igen | Csak alkalmazásjelszóval |
| Security Defaults mellett? | Igen | **Nem** – eleve tiltva van |
| Meddig él? | Ez a jövő | Kivezetés alatt |

**Ha a tenantban be van kapcsolva a Security Defaults** (új tenantoknál ez az
alapértelmezés), akkor az SMTP jelszavas út **nem fog működni**, és az
alkalmazásjelszó sem elérhető. Ilyenkor a Graph az egyetlen járható út
külső szolgáltató nélkül.

A rendszer alapbeállítása a Graph (`'mail_transport' => 'graph'`).

---

## A) Graph API beállítása – ajánlott

### 1. Alkalmazás regisztrálása

[Microsoft Entra admin center](https://entra.microsoft.com) → **Alkalmazások →
Alkalmazásregisztrációk → Új regisztráció**.

- **Név:** pl. `Menza heti étlap`
- **Támogatott fióktípusok:** csak ebben a szervezeti címtárban
- **Átirányítási URI:** hagyd üresen (nincs rá szükség)

A **Áttekintés** lapon ezt a kettőt másold ki:

- `Application (client) ID` → ez lesz a `graph_client_id`
- `Directory (tenant) ID` → ez lesz a `graph_tenant_id`

### 2. Jogosultság

Az alkalmazásnál: **API-engedélyek → Engedély hozzáadása → Microsoft Graph →
Alkalmazásengedélyek** (*nem* delegált!) → keresd a **`Mail.Send`** engedélyt.

Utána kattints a **„Rendszergazdai hozzájárulás megadása"** gombra. Enélkül
nem fog működni – ehhez tenant-rendszergazda kell.

> **Alkalmazásengedély, nem delegált.** A delegált engedély bejelentkezett
> felhasználó nevében működik; itt viszont egy háttérben futó weboldal küld,
> amögött nem ül senki.

### 3. Titkos kulcs (client secret)

**Tanúsítványok és titkos kódok → Új titkos ügyfélkód.** Az értéket **csak
egyszer** látod – rögtön másold ki, ez lesz a `graph_client_secret`.

> ⚠️ A titkos kulcs **lejár** (legfeljebb 24 hónap). Írd be a naptáradba a
> lejárat előtti hetet, különben egyik napról a másikra leáll a levélküldés.

### 4. Korlátozd egyetlen postafiókra (erősen ajánlott)

Alapból a `Mail.Send` alkalmazásengedély a tenant **összes** postafiókjából
engedne küldeni. Ezt érdemes leszűkíteni a menzás fiókra – Exchange Online
PowerShellben:

```powershell
New-DistributionGroup -Name "Menza kuldo" -Type Security -Members menza@a-domained.hu

New-ApplicationAccessPolicy -AppId <graph_client_id> `
  -PolicyScopeGroupId "Menza kuldo" `
  -AccessRight RestrictAccess `
  -Description "A menzas oldal csak a menza postafiokot hasznalhatja"
```

Ha ezt kihagyod, a rendszer működni fog, de a titkos kulcs kiszivárgása esetén
sokkal nagyobb a kár.

### 5. Beállítások a `config.php`-ban

```php
'mail_transport'      => 'graph',
'mail_from'           => 'menza@a-domained.hu',   // a küldő postafiók
'mail_from_name'      => 'Menza heti étlap',
'graph_tenant_id'     => '...',
'graph_client_id'     => '...',
'graph_client_secret' => '...',
```

A `mail_from` egy **létező postafiók** legyen a tenantban. Megosztott postafiók
(shared mailbox) is jó – a Graphnál, licenc nélkül is.

---

## B) SMTP jelszóval – egyszerűbb, de kifutó

Csak akkor válaszd, ha a Graph valamiért nem járható.

1. Licencelt postafiók kell (megosztott postafiókkal **nem** működik).
2. Microsoft 365 admin központ → **Felhasználók → Aktív felhasználók** → a fiók →
   **Levelezés → E-mail-alkalmazások kezelése** → **Authenticated SMTP** bepipálva.
3. Ha tenant szinten tiltva van, PowerShellből:
   ```powershell
   Set-TransportConfig -SmtpClientAuthenticationDisabled $false
   Set-CASMailbox -Identity menza@a-domained.hu -SmtpClientAuthenticationDisabled $false
   ```
4. MFA mellett **alkalmazásjelszó** kell (ha a tenant egyáltalán engedi).
5. `config.php`:
   ```php
   'mail_transport' => 'smtp',
   'smtp_host'   => 'smtp.office365.com',
   'smtp_port'   => '587',
   'smtp_secure' => 'tls',
   'smtp_user'   => 'menza@a-domained.hu',
   'smtp_pass'   => 'AZ_ALKALMAZASJELSZO',
   'mail_from'   => 'menza@a-domained.hu',   // egyezzen az smtp_user-rel!
   ```

---

## Tesztelés

Lépj be az `/admin/` oldalra, és nyomd meg a **Teszt levél küldése** gombot.
A levél a `config.php`-ban megadott `contact_email` címre megy.

Ha hiba van, a gomb kiírja a pontos okot. A leggyakoribbak:

| Üzenet | Mit jelent |
|---|---|
| Hiányzik a Mail.Send jogosultság | Nincs megadva a rendszergazdai hozzájárulás (2. lépés) |
| Hibás vagy lejárt client secret | Elírtad, vagy lejárt – generálj újat |
| Ismeretlen client ID / tenant ID | Elgépelt azonosító |
| Nincs ilyen postafiók | A `mail_from` cím nem létezik a tenantban |

---

## Amit tudni kell a küldésről

**Küldési limitek.** Az Exchange Online percenként kb. 30 üzenetet, naponta
10 000 címzettet enged. Heti étlaphoz néhány száz feliratkozóval ez bőven elég,
de ezres listánál ütközni fogsz vele.

**A Microsoft nem szereti a tömeges levelet.** Az Exchange Online üzleti
levelezésre való, nem hírlevélre. Néhány száz címzettnél ez nem okoz gondot,
de ha sokan spamnek jelölik a leveleidet, az az egész tenant levelezési
hírnevét rontja – tehát a kollégák normál leveleit is.

**Ha a lista nagyra nő:** a tiszta megoldás egy külön aldomain a küldéshez,
pl. `menza.a-domained.hu`, saját SPF/DKIM rekordokkal. Így ha a menzás levelek
hírneve romlik, az nem húzza magával a cég normál levelezését. Ez már nem
MVP-feladat.

---

## Visszaigazoló levél ≠ double opt-in

A rendszer feliratkozáskor küld egy rövid visszaigazoló levelet.
**Ez nem double opt-in:** a feliratkozás a levél nélkül is érvényes, nincs
mit megerősíteni benne. Csak visszajelzés a felhasználónak, és tartalmazza
a leiratkozó linket.

Ez egyben védelem is: mivel nincs megerősítés, bárki beírhatja valaki más
email-címét. Az illető így legalább azonnal értesül róla, és egy kattintással
le tud iratkozni.

**Ha a levélküldés nem működik** (rossz kulcs, leállt szolgáltatás), a
feliratkozás attól még rendben elmentődik – a felhasználó sikeres visszajelzést
lát. A hiba a szerver hibanaplójába kerül. Kikapcsolni a
`'send_welcome_email' => '0'` beállítással lehet.
