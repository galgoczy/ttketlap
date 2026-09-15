/**
 * Admin segedfunkciok: kijeloles es vagolapra masolas.
 *
 * Miert kulon fajlban van, es miert nincs onclick="..." a HTML-ben?
 * A .htaccess biztonsagi szabalya (Content-Security-Policy) csak a sajat
 * tarhelyrol szarmazo szkriptet engedi futni. A HTML-be irt onclick
 * ugyanezen szabaly miatt NEM fut le - nemán, hibauzenet nelkul.
 */
(function () {
  'use strict';

  /** Kattintasra kijeloli a mezo teljes tartalmat. */
  document.querySelectorAll('[data-select-on-click]').forEach(function (mezo) {
    mezo.addEventListener('click', function () {
      mezo.select();
    });
  });

  /** Masolas vagolapra, visszajelzessel a gombon. */
  document.querySelectorAll('[data-copy-target]').forEach(function (gomb) {
    var eredetiFelirat = gomb.textContent;

    gomb.addEventListener('click', function () {
      var forras = document.getElementById(gomb.getAttribute('data-copy-target'));
      if (!forras) { return; }

      var szoveg = forras.value !== undefined ? forras.value : forras.textContent;

      function visszajelzes(sikerult) {
        gomb.textContent = sikerult ? 'Kimásolva' : 'Nem sikerült';
        setTimeout(function () { gomb.textContent = eredetiFelirat; }, 2000);
      }

      // A modern vagolap-API csak https-en (vagy localhoston) mukodik,
      // ezert van alatta a regi modszer tartaleknak.
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(szoveg).then(
          function () { visszajelzes(true); },
          function () { regiMasolas(forras, visszajelzes); }
        );
      } else {
        regiMasolas(forras, visszajelzes);
      }
    });
  });

  function regiMasolas(forras, visszajelzes) {
    try {
      forras.select();
      forras.setSelectionRange(0, 999999); // mobil Safari miatt
      visszajelzes(document.execCommand('copy'));
    } catch (hiba) {
      visszajelzes(false);
    }
  }
})();
