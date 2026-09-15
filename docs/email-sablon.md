# Heti étlap – levél kiküldése

## A levelet nem kézzel kell összeállítani

Az adminban van egy **Heti étlap levél** oldal (`/admin/etlap.php`). Kitöltöd a
napokat, és:

- rögtön látod, hogy fog kinézni,
- küldhetsz magadnak egy teszt példányt,
- a kész HTML-t kimásolhatod a körlevélbe.

A levél a Pepper House arculatát követi: logó, piros sáv, verzál napnevek,
lábléc leiratkozó linkkel.

---

## Kiküldés a saját levelezőprogramból

1. Admin → **Címlista másolása**. Innen az aktív címek egy sorban
   kimásolhatók, vesszővel vagy pontosvesszővel elválasztva (az Outlook
   asztali alkalmazása pontosvesszőt vár).
2. Admin → **Heti étlap levél** → töltsd ki a napokat → **HTML másolása**.
3. A levelezőprogramban a címeket **titkos másolatba (BCC)** illeszd be.

> **A címzett mezőbe soha ne kerüljenek a címek.** Onnan minden feliratkozó
> látja a többiek email-címét – ez adatvédelmi incidens, amit be kell jelenteni.

## Kiküldés körlevéllel

Ha körlevelet használsz, az **Aktívak letöltése (CSV)** a jobb kiindulás: abban
ott van az `email` és a `leiratkozo_link` oszlop is. A HTML-ben a
`{{leiratkozo_link}}` helykitöltő helyére ez utóbbit kösd be.

> **Minden feliratkozónak a saját leiratkozó linkje kell.** Ha mindenkinek
> ugyanaz megy ki, bárki leiratkoztathat bárkit.

Javasolt tárgy (az admin oldal is kiírja):

```
Heti étlap – 39. hét (09. 21. – 09. 25.)
```

---

## Amire figyelni kell

- **Címzettek rejtve.** Ha nem körlevelet használsz, a címeket **titkos
  másolatba (BCC)** tedd – különben minden feliratkozó látja a többiek
  email-címét, ami adatvédelmi incidens.
- **Csak az aktív listát** használd. A leiratkozottaknak küldeni jogsértő.
- **A megszólítás magázó** – az arculat ezt írja elő.

---

## Miért néz ki "régimódian" a levél HTML-je?

A levelezőprogramok, különösen az Outlook, nem úgy jelenítik meg a HTML-t,
mint egy böngésző: nincs flexbox, nincs grid, és a külső stíluslapot sokszor
eldobják. Ezért a sablon táblázatos elrendezést és beágyazott stílusokat
használ. Ez nem hanyagság, hanem a működés feltétele.

**A Jost betűt a levelezők többsége nem tölti be.** A sablon ezért olyan
betűkre vált, amik a márkához közel állnak és helyben elérhetők: Apple
eszközökön Futura, Windowson Century Gothic, végül Arial.

**A képeket sok levelezőprogram alapból blokkolja.** A logó ezért nem hordoz
fontos információt, és van hozzá alt szöveg – a levél kép nélkül is olvasható.
