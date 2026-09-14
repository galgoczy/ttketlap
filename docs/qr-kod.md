# QR-kód a menzába

## Milyen linket kódolj be?

```
https://a-sajat-domained.hu/
```

Ha később szeretnéd tudni, melyik plakát hozta a feliratkozókat, használj
forrásjelölést – ez az adatbázis `source` mezőjébe kerül:

```
https://a-sajat-domained.hu/?forras=menza-bejarat
https://a-sajat-domained.hu/?forras=talalcado
```

## QR-kód készítése

Bármelyik ingyenes generátor jó (pl. qr-code-generator.com). Beállítások:

- **Típus:** URL
- **Hibajavítási szint:** H (magas) – így egy kis kosz vagy karcolás sem zavarja be
- **Formátum:** SVG vagy PDF (nyomtatáshoz, ne PNG – az elmosódik nagyításkor)

## Nyomtatási méret

| Olvasási távolság | Minimális QR-méret |
|---|---|
| 30 cm (asztali kártya) | 3 × 3 cm |
| 1 m (faliplakát)       | 10 × 10 cm |
| 2-3 m (nagy tábla)     | 25 × 25 cm |

Ökölszabály: a QR-kód oldalhossza legyen a leolvasási távolság **tizede**.

## Amit a plakátra írj

Egy QR-kód önmagában kevés – emberek nem szkennelnek be ismeretlen kódot.
Írd mellé:

> **Kérd a heti étlapot emailben**
> Szkenneld be, add meg az email-címed, és minden héten elküldjük.

## Tesztelés nyomtatás előtt

1. Nyomtasd ki a tényleges méretben egy A4-es lapra.
2. Próbáld beolvasni a valódi távolságból, gyenge fényben is.
3. Ellenőrizd, hogy a megnyíló oldal mobilon jól néz ki.
