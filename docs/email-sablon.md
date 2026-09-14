# Heti étlap – email sablon (kézi kiküldéshez)

Az admin felületen az **„Aktívak letöltése (CSV)”** gombbal kapott fájl tartalmazza
az `email` és a `leiratkozo_link` oszlopot. A körlevél (mail merge) funkcióval
ezt a két mezőt kell összefűzni a levélbe.

> **Fontos:** minden feliratkozónak a *saját* leiratkozó linkjét kell megkapnia.
> Ha mindenkinek ugyanazt a linket küldöd ki, akkor bárki le tud iratkoztatni bárkit.

---

## Tárgy

```
Heti étlap – {{hét}}. hét ({{tól}} – {{ig}})
```

## Szöveges változat

```
Szia!

Itt a menza étlapja erre a hétre:

Hétfő:     ...
Kedd:      ...
Szerda:    ...
Csütörtök: ...
Péntek:    ...

Jó étvágyat!

--
Ezt a levelet azért kapod, mert feliratkoztál a menza heti étlapjára.
Leiratkozás: {{leiratkozo_link}}
```

## HTML változat

```html
<div style="font-family: Arial, sans-serif; font-size: 16px; line-height: 1.6; color: #1c1b19; max-width: 600px;">
  <h1 style="font-size: 22px; margin: 0 0 16px;">Heti étlap – {{hét}}. hét</h1>

  <table role="presentation" style="width: 100%; border-collapse: collapse;">
    <tr><td style="padding: 8px 0; border-bottom: 1px solid #e0ddd6;"><strong>Hétfő</strong><br>...</td></tr>
    <tr><td style="padding: 8px 0; border-bottom: 1px solid #e0ddd6;"><strong>Kedd</strong><br>...</td></tr>
    <tr><td style="padding: 8px 0; border-bottom: 1px solid #e0ddd6;"><strong>Szerda</strong><br>...</td></tr>
    <tr><td style="padding: 8px 0; border-bottom: 1px solid #e0ddd6;"><strong>Csütörtök</strong><br>...</td></tr>
    <tr><td style="padding: 8px 0;"><strong>Péntek</strong><br>...</td></tr>
  </table>

  <p style="margin: 24px 0 0; font-size: 13px; color: #6b6862;">
    Ezt a levelet azért kapod, mert feliratkoztál a menza heti étlapjára.<br>
    <a href="{{leiratkozo_link}}" style="color: #6b6862;">Leiratkozás</a>
  </p>
</div>
```

---

## Kiküldési tippek

- **Címzettek rejtve:** ha nem körlevelet használsz, a címeket **titkos másolatba (BCC)**
  tedd – különben minden feliratkozó látja a többiek email-címét, ami adatvédelmi
  incidens.
- **Csak az aktív listát** használd. A leiratkozottaknak küldeni jogsértő.
- Ha hetente 100-nál több levelet küldesz, érdemes SMTP-szolgáltatót
  (pl. a Hostinger saját email-szolgáltatása) használni, hogy ne kerüljön spambe.
