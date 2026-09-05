<?php
/**
 * 2e — Conversione in corso. Il job gira in un processo distaccato: questa
 * pagina interroga ?p=stato una volta al secondo e si puo' lasciare.
 * @var array<string,mixed> $job
 */
use Vblite\Convert\Auth;
use Vblite\Convert\Vista;

$lessico = Vista::lessico((string) $job['tipologia']);

$passoCorrente = 3;
require __DIR__ . '/parti/passi.php';
?>
<div class="body">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-8);align-items:start">

    <div>
      <p class="kick" style="margin:0 0 var(--space-4)">Conversione in corso</p>
      <div class="cmyk-num" id="percentuale" style="font:600 150px/0.85 var(--font-heading);letter-spacing:-.04em">
        <span class="paper">0%</span><span class="plate plate-c" aria-hidden="true">0%</span>
      </div>
      <h2 class="h2" id="posizione" style="font-size:26px;margin-top:var(--space-6)">In coda…</h2>
      <p class="lede" id="dettaglio" style="font-size:16px">
        Puoi lasciare questa pagina: la trovi nello storico quando è pronta.
      </p>
      <div class="barra" style="margin-top:var(--space-6);max-width:420px"><i id="barra" style="width:0"></i></div>
      <form method="post" action="?p=annulla&amp;job=<?= Vista::e($job['riferimento']) ?>" style="margin-top:var(--space-6)">
        <input type="hidden" name="csrf" value="<?= Vista::e(Auth::gettone()) ?>">
        <button class="btn btn-ghost" type="submit">Annulla</button>
      </form>
    </div>

    <div>
      <p class="kick" style="margin:0 0 var(--space-4)">Registro</p>
      <div class="registro" id="registro" style="max-width:460px"></div>
    </div>
  </div>

  <div class="foot">
    <span class="mono" style="color:rgba(32,30,29,.5)" id="stima">Lettura del PDF…</span>
    <span class="mono" style="color:rgba(32,30,29,.5)">job #<?= Vista::e($job['riferimento']) ?> · <?= Vista::e($utente['nome']) ?></span>
  </div>
</div>

<script>
/* Avanzamento via polling: un giro al secondo, e a fine lavoro si va al passo 3. */
(function () {
  var riferimento = <?= json_encode($job['riferimento']) ?>;
  var avvio = Date.now();

  var ordine = { in_coda: 0, lettura: 1, raggruppamento: 2, scrittura: 3, fatto: 4 };

  /* Ogni passo dice da se' quando e' concluso: la testata si riconosce alla prima
     pagina, le righe si contano a lettura finita, e cosi' via. Cosi' due passi
     della stessa fase non risultano entrambi «in corso». */
  var etichette = <?= json_encode($lessico['passi'], JSON_UNESCAPED_UNICODE) ?>;

  /* Ogni passo dice da se' quando e' concluso: la testata si riconosce alla
     prima pagina, le righe si contano a lettura finita, e cosi' via. Cosi' due
     passi della stessa fase non risultano entrambi «in corso». I nomi li porta
     la tipologia: la schermata e' una sola, il vocabolario no. */
  var passi = [
    { fatto: function (s) { return s.pagine > 0; },
      misura: function (s) { return s.pagine > 0 ? 'pag. 1' : '—'; } },
    { fatto: function (s) { return s.righe_lette > 0; },
      misura: function (s) { return s.righe_lette > 0 ? gruppi(s.righe_lette) : 'pag. ' + s.pagina_corrente; } },
    { fatto: function (s) { return s.righe_scritte > 0; },
      misura: function (s) { return s.righe_scritte > 0 ? gruppi(s.righe_scritte) : '—'; } },
    { fatto: function (s) { return s.righe_scritte > 0; },
      misura: function (s) { return s.righe_scritte > 0 ? '&#10003;' : '—'; } },
    { fatto: function (s) { return s.righe_scritte > 0; },
      misura: function (s) { return s.righe_scritte > 0 ? '&#10003;' : '—'; } },
    { fatto: function (s) { return ordine[s.passo] >= 4; },
      misura: function (s) { return ordine[s.passo] >= 3 ? gruppi(s.righe_scritte) : '—'; } }
  ];

  function gruppi(n) { return String(n || 0).replace(/\B(?=(\d{3})+(?!\d))/g, '.'); }

  function disegna(s) {
    var html = '';
    var correnteTrovato = false;
    passi.forEach(function (passo, indice) {
      passo.testo = etichette[indice] || '';
      var stato;
      if (passo.fatto(s)) {
        stato = 'fatto';
      } else if (!correnteTrovato) {
        stato = 'adesso';
        correnteTrovato = true;
      } else {
        stato = 'futuro';
      }
      var segno = stato === 'fatto' ? '&#10003;' : (stato === 'adesso' ? '&#9633;' : '&#8212;');
      html += '<div class="' + stato + '"><span>' + segno + '</span>'
            + '<span>' + (stato === 'adesso' ? '<strong>' + passo.testo + '</strong>' : passo.testo) + '</span>'
            + '<span class="misura">' + passo.misura(s) + '</span></div>';
    });
    if (s.da_rivedere > 0) {
      html += '<div class="scarto"><span>!</span><span><a href="?p=rivedere&job=' + riferimento + '" style="color:var(--color-accent-2-700)">'
            + gruppi(s.da_rivedere) + ' <?= Vista::e($lessico['unita_plurale']) ?> da rivedere</a></span>'
            + '<span class="misura">finora</span></div>';
    }
    document.getElementById('registro').innerHTML = html;

    var perc = s.pagine > 0 ? Math.round(s.pagina_corrente / s.pagine * 100) : 0;
    if (s.passo === 'scrittura') perc = Math.max(perc, 95);
    document.getElementById('percentuale').innerHTML =
      '<span class="paper">' + perc + '%</span><span class="plate plate-c" aria-hidden="true">' + perc + '%</span>';
    document.getElementById('barra').style.width = perc + '%';
    document.getElementById('posizione').textContent = s.pagine > 0
      ? 'Pagina ' + s.pagina_corrente + ' di ' + s.pagine
      : 'Lettura del PDF…';
    if (s.righe_lette > 0) {
      document.getElementById('dettaglio').textContent =
        (s.righe_scritte || 0) + ' <?= Vista::e($lessico['unita_plurale']) ?> da ' + s.righe_lette + ' <?= Vista::e($lessico['origine']) ?>. '
        + 'Puoi lasciare questa pagina: la trovi nello storico quando è pronta.';
    }

    var trascorsi = Math.round((Date.now() - avvio) / 1000);
    document.getElementById('stima').textContent = perc > 3
      ? 'Tempo stimato rimanente ' + secondi(Math.max(0, Math.round(trascorsi * (100 - perc) / perc)))
      : 'Lettura del PDF…';
  }

  function secondi(n) {
    return String(Math.floor(n / 60)).padStart(2, '0') + ':' + String(n % 60).padStart(2, '0');
  }

  function giro() {
    fetch('?p=stato&job=' + riferimento, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (s) {
        disegna(s);
        if (s.esito === 'in_corso') { setTimeout(giro, 1000); return; }
        window.location = s.esito === 'errore'
          ? '?p=storico'
          : '?p=pronto&job=' + riferimento;
      })
      .catch(function () { setTimeout(giro, 2000); });
  }

  giro();
})();
</script>
