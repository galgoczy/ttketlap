# Levélküldés beállítása (M365 tenant mellett)

## A probléma dióhéjban

A domain levelezése Microsoft 365-ben van. Ez azt jelenti, hogy a domain nyilvános
beállításai (SPF rekord) azt hirdetik a világnak: *„erről a domainről csak a
Microsoft szerverei küldhetnek levelet."*

Ha a menzás oldal a Hostinger szerveréről küldene levelet `menza@a-domained.hu`
feladóval, a fogadó levelezőrendszer ezt látná:

> A levél azt állítja, hogy a-domained.hu-ról jött, de nem a Microsoft küldte.

Az eredmény: spam mappa, vagy egyenesen visszapattanó levél. Ezért a rendszer
**nem** a PHP beépített `mail()` függvényét használja, hanem SMTP-n keresztül
azon a szerveren át küld, amelyik a domain nevében hivatalosan is küldhet.

---

## Ajánlott megoldás: küldés az M365-ön keresztül

### 1. Postafiók a küldéshez

Kell egy postafiók a tenantban, pl. `menza@a-domained.hu`. Ez lehet meglévő is,
de tisztább egy dedikált fiók. **Licenc kell hozzá** – megosztott postafiókkal
(shared mailbox) az SMTP küldés nem működik.

### 2. SMTP AUTH engedélyezése

A Microsoft 365 admin központban: **Felhasználók → Aktív felhasználók →**
az adott fiók → **Levelezés → E-mail-alkalmazások kezelése →**
pipáld be az **Authenticated SMTP** opciót.

Ha az egész tenantra le van tiltva, a Exchange admin központban kell
engedélyezni (`Beállítások → Levelezési folyamat`), vagy PowerShellből:

```powershell
Set-TransportConfig -SmtpClientAuthenticationDisabled $false
Set-CASMailbox -Identity menza@a-domained.hu -SmtpClientAuthenticationDisabled $false
```

### 3. Jelszó

Ha a fiókon van többlépcsős azonosítás (MFA) – és lennie kellene –, akkor a
sima jelszó nem működik: **alkalmazásjelszót** (app password) kell generálni.

### 4. Beállítások a `config.php`-ban

```php
'smtp_host'   => 'smtp.office365.com',
'smtp_port'   => '587',
'smtp_secure' => 'tls',
'smtp_user'   => 'menza@a-domained.hu',
'smtp_pass'   => 'AZ_ALKALMAZASJELSZO',
'mail_from'      => 'menza@a-domained.hu',   // egyeznie kell az smtp_user-rel!
'mail_from_name' => 'Menza heti étlap',
```

> A `mail_from` és az `smtp_user` **egyezzen meg**. Az Exchange Online elutasítja,
> ha olyan címről próbálsz küldeni, amire a bejelentkezett fióknak nincs joga.

### 5. Tesztelés

Lépj be az `/admin/` oldalra, és nyomd meg a **Teszt levél küldése** gombot.
A levél a `config.php`-ban megadott `contact_email` címre megy.
Ha hiba van, a gomb megmutatja a pontos hibaüzenetet.

---

## Amit tudni kell az M365-ös küldésről

**Küldési limitek.** Az Exchange Online percenként kb. 30 üzenetet, naponta
10 000 címzettet enged egy fióknak. Heti étlaphoz néhány száz feliratkozóval
ez bőven elég, de ha a lista ezres nagyságrendbe nő, ütközni fogsz vele.

**A Microsoft nem szereti a tömeges levelet.** Az Exchange Online üzleti
levelezésre való, nem hírlevélre. Néhány száz címzettnél ez nem okoz gondot,
de ha sokan spamnek jelölik a leveleidet, az az egész tenant levelezési
hírnevét rontja – tehát a kollégák normál leveleit is.

**A jelszavas SMTP kifutó.** A Microsoft kivezeti az egyszerű jelszavas
SMTP hitelesítést: 2026 végétől a meglévő tenantoknál alapból kikapcsol
(admin még visszakapcsolhatja), az azután létrehozott tenantoknál pedig
nem is lesz elérhető. A jövő az OAuth. A mostani beállítás tehát működik,
de néhány éven belül át kell állni – a `mailer.php` fájlban ez egy
körülhatárolt változtatás.

---

## Ha a lista nagyra nő: külön aldomain

Ha egyszer több ezer címzettről lesz szó, a tiszta megoldás egy **külön
aldomain** a küldéshez, pl. `menza.a-domained.hu`, saját SPF/DKIM rekordokkal.
Előnye: ha a menzás levelek hírneve romlik, az nem húzza magával a cég
normál levelezését. Ez viszont már nem MVP-feladat.

---

## Visszaigazoló levél ≠ double opt-in

A rendszer feliratkozáskor küld egy rövid visszaigazoló levelet.
**Ez nem double opt-in:** a feliratkozás a levél nélkül is érvényes, nincs
mit megerősíteni benne. Csak visszajelzés a felhasználónak, és tartalmazza
a leiratkozó linket.

Ez egyben védelem is: mivel nincs megerősítés, bárki beírhatja valaki más
email-címét. Az illető így legalább azonnal értesül róla, és egy kattintással
le tud iratkozni.

**Ha a levélküldés nem működik** (rossz jelszó, leállt szerver), a feliratkozás
attól még rendben elmentődik – a felhasználó sikeres visszajelzést lát.
A hiba a szerver hibanaplójába kerül. Kikapcsolni a
`'send_welcome_email' => '0'` beállítással lehet.
