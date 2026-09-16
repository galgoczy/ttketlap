# Nyomtatványok

A4 plakát az étterembe, QR kóddal a feliratkozó oldalra.

- `plakat.pdf` – **ez megy a nyomdába.** Nyomtatáshoz A4, álló, 100% méret
  (nem „oldalhoz igazítva"), lehetőleg 160–250 g-os papírra.
- `plakat.html` – a plakát forrása. Ha szöveget kell módosítani, ezt írd át.
- `qr.svg` – a QR kód, `https://ttketlap.pepperhouse.hu` címmel, H szintű
  hibajavítással (a kód akkor is beolvasható, ha a nyomat kissé megsérül).

Ez a mappa **nem kerül fel a tárhelyre** – a deploy csak a `public/` mappát
tölti fel.

## Új PDF készítése módosítás után

A `plakat.html` betűkészlete és logója a `public/assets` mappából tölt be,
ezért a fájlt a helyén kell hagyni. Böngészőben nyisd meg, majd Ctrl+P:

- papírméret: A4, álló
- margó: **Nincs** (a margót maga a lap adja)
- **Háttérgrafikák: bekapcsolva** – enélkül a piros csík nem nyomtatódik ki

## Ha megváltozik a cím

A QR kódot újra kell generálni, mert a régi a régi címre vinne:

```
npm install qrcode
npx qrcode -o qr.svg -t svg -e H "https://uj-cim.hu"
```
