<?php
/**
 * La tabella di mappatura delle colonne.
 *
 * Ogni riga è una colonna del file che verrà prodotto: come si chiama, da dove
 * prende il valore, di che tipo è. Si parte dall'identità — ogni colonna del
 * file d'origine diventa una colonna in uscita con lo stesso nome — perché è
 * quasi sempre il punto di partenza giusto, e chi deve solo aggiungere un
 * codice non deve rimappare venti colonne a mano.
 *
 * Sta tutto in un riquadro che scorre, con un filtro sopra: un CSV di
 * gestionale porta quaranta colonne, e quaranta blocchi di controlli in fila
 * sono una pagina in cui non si trova più niente.
 *
 * @var array<string,mixed> $analisi
 */
use Vblite\Convert\Conversioni\Tabelle\ConversioneTabelle;
use Vblite\Convert\Vista;

$colonne  = $analisi['colonne'] ?? [];
$assaggio = $analisi['assaggio'] ?? [];

$tipi = [
    'testo'  => 'Testo',
    'numero' => 'Numero',
    'intero' => 'Intero',
    'data'   => 'Data',
    'valuta' => 'Valuta',
];
?>
<div id="mappatura" data-colonne="<?= Vista::e(json_encode($colonne, JSON_UNESCAPED_UNICODE)) ?>"
     data-tipi="<?= Vista::e(json_encode($tipi, JSON_UNESCAPED_UNICODE)) ?>"
     data-fisso="<?= Vista::e(ConversioneTabelle::FISSO) ?>"
     data-vuoto="<?= Vista::e(ConversioneTabelle::VUOTO) ?>">

  <div class="tra" style="margin-bottom:var(--space-2);gap:var(--space-3);align-items:center">
    <p class="kick" style="margin:0">Colonne in uscita</p>
    <span style="display:flex;gap:var(--space-3);align-items:center">
      <input class="input" id="filtro-mappatura" type="search" placeholder="Filtra…"
             style="padding:3px 8px;font-size:12.5px;width:150px" autocomplete="off">
      <span class="mono muted" style="font-size:12.5px" id="conta-colonne"></span>
    </span>
  </div>

  <div class="mappa" id="riquadro-mappatura">
    <div class="mrow hd">
      <span class="kick">Come si chiamerà</span>
      <span class="kick">Da dove viene</span>
      <span class="kick cella-valore" hidden>Valore</span>
      <span class="kick">Tipo</span>
      <span></span>
    </div>
    <div id="righe-mappatura"></div>
  </div>

  <div style="display:flex;gap:var(--space-2);margin-top:var(--space-2);flex-wrap:wrap">
    <button type="button" class="btn btn-secondary" id="aggiungi-fissa">+ Colonna a valore fisso</button>
    <button type="button" class="btn btn-ghost" id="ripristina">Ricomincia dalle colonne del file</button>
  </div>

  <?php if ($assaggio !== []): ?>
    <div style="margin-top:var(--space-4)">
      <p class="kick" style="margin:0 0 var(--space-2)">Le prime righe del file caricato</p>
      <div class="foglio" style="overflow-x:auto">
        <table class="table" style="width:100%;font-size:12.5px;white-space:nowrap">
          <thead><tr>
            <?php foreach ($colonne as $nome): ?>
              <th><?= Vista::e($nome) ?></th>
            <?php endforeach; ?>
          </tr></thead>
          <tbody>
          <?php foreach ($assaggio as $riga): ?>
            <tr>
              <?php foreach ($colonne as $i => $nome): ?>
                <td class="mono"><?= Vista::e(mb_substr((string) ($riga[$i] ?? ''), 0, 24)) ?></td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
</div>

<script>
/* La mappatura si costruisce qui: le righe sono dinamiche perché il numero di
   colonne lo decide il file, non la pagina. Senza JavaScript la mappatura non
   si può modificare, ma il modulo parte lo stesso con l'identità — cioè il
   file esce con le stesse colonne che aveva. */
(function () {
  var radice = document.getElementById('mappatura');
  if (!radice) { return; }

  var colonne = JSON.parse(radice.getAttribute('data-colonne') || '[]');
  var tipi    = JSON.parse(radice.getAttribute('data-tipi') || '{}');
  var FISSO   = radice.getAttribute('data-fisso');
  var VUOTO   = radice.getAttribute('data-vuoto');
  var riquadro    = document.getElementById('riquadro-mappatura');
  var contenitore = document.getElementById('righe-mappatura');
  var conta       = document.getElementById('conta-colonne');
  var filtro      = document.getElementById('filtro-mappatura');

  function identita() {
    return colonne.map(function (nome) {
      return { nome: nome, da: nome, valore: '', tipo: 'testo' };
    });
  }

  var mappa = identita();

  function opzioni(selezionata) {
    var html = '';
    colonne.forEach(function (nome) {
      html += '<option value="' + esc(nome) + '"' + (nome === selezionata ? ' selected' : '') + '>'
            + esc(nome) + '</option>';
    });
    html += '<option value="' + FISSO + '"' + (selezionata === FISSO ? ' selected' : '') + '>— valore fisso —</option>';
    html += '<option value="' + VUOTO + '"' + (selezionata === VUOTO ? ' selected' : '') + '>— lascia vuota —</option>';
    return html;
  }

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function disegna() {
    /* La colonna del valore fisso compare solo se serve davvero: senza, sarebbe
       una striscia vuota per tutta la lunghezza dell'elenco. */
    var conValore = mappa.some(function (c) { return c.da === FISSO; });
    riquadro.classList.toggle('con-valore', conValore);
    Array.prototype.forEach.call(riquadro.querySelectorAll('.cella-valore'), function (c) {
      c.hidden = !conValore;
    });

    var html = '';
    mappa.forEach(function (c, i) {
      var tipiHtml = '';
      Object.keys(tipi).forEach(function (k) {
        tipiHtml += '<option value="' + k + '"' + (k === c.tipo ? ' selected' : '') + '>' + esc(tipi[k]) + '</option>';
      });

      var nascosto = '<input type="hidden" name="colonne[' + i + '][valore]" value="' + esc(c.valore) + '">';

      html += '<div class="mrow" data-i="' + i + '" data-cerca="'
        +   esc((c.nome + ' ' + c.da).toLowerCase()) + '">'
        + '<span><input class="input" name="colonne[' + i + '][nome]" value="' + esc(c.nome) + '" '
        +   'maxlength="120">' + (conValore ? '' : nascosto) + '</span>'
        + '<span><select class="input" name="colonne[' + i + '][da]">' + opzioni(c.da) + '</select></span>'
        + (conValore
            ? '<span class="cella-valore">'
              + (c.da === FISSO
                  ? '<input class="input" name="colonne[' + i + '][valore]" value="' + esc(c.valore) + '" '
                    + 'maxlength="200" placeholder="uguale per tutte le righe">'
                  : nascosto)
              + '</span>'
            : '')
        + '<span><select class="input" name="colonne[' + i + '][tipo]">' + tipiHtml + '</select></span>'
        + '<span><button type="button" class="btn btn-ghost togli" title="Togli questa colonna">&times;</button></span>'
        + '</div>';
    });

    contenitore.innerHTML = html || '<p class="niente">Nessuna colonna in uscita: il file sarebbe vuoto.</p>';
    conta.textContent = mappa.length + (mappa.length === 1 ? ' colonna' : ' colonne')
      + ' · ' + colonne.length + ' nel file';
    setaccia();
  }

  /* Il filtro nasconde, non toglie: i campi restano nel modulo, così una riga
     fuori vista continua a essere inviata e a essere riletta da raccogli(). */
  function setaccia() {
    var cerca = (filtro.value || '').trim().toLowerCase();
    var viste = 0;
    Array.prototype.forEach.call(contenitore.querySelectorAll('.mrow'), function (riga) {
      var ok = cerca === '' || riga.getAttribute('data-cerca').indexOf(cerca) !== -1;
      riga.style.display = ok ? '' : 'none';
      if (ok) { viste++; }
    });
    if (cerca !== '') {
      conta.textContent = viste + ' su ' + mappa.length;
    }
  }

  /* Si rilegge lo stato dai campi prima di ridisegnare: altrimenti una modifica
     appena fatta andrebbe persa al primo cambio di menu. */
  function raccogli() {
    Array.prototype.forEach.call(contenitore.querySelectorAll('.mrow'), function (riga) {
      var i = parseInt(riga.getAttribute('data-i'), 10);
      if (!mappa[i]) { return; }
      var nome   = riga.querySelector('input[name$="[nome]"]');
      var da     = riga.querySelector('select[name$="[da]"]');
      var valore = riga.querySelector('[name$="[valore]"]');
      var tipo   = riga.querySelector('select[name$="[tipo]"]');
      if (nome)   { mappa[i].nome = nome.value; }
      if (da)     { mappa[i].da = da.value; }
      if (valore) { mappa[i].valore = valore.value; }
      if (tipo)   { mappa[i].tipo = tipo.value; }
    });
  }

  contenitore.addEventListener('change', function (e) {
    raccogli();
    if (e.target.name && e.target.name.indexOf('[da]') !== -1) { disegna(); }
  });

  contenitore.addEventListener('click', function (e) {
    if (!e.target.classList.contains('togli')) { return; }
    raccogli();
    var riga = e.target.closest('.mrow');
    mappa.splice(parseInt(riga.getAttribute('data-i'), 10), 1);
    disegna();
  });

  filtro.addEventListener('input', setaccia);

  document.getElementById('aggiungi-fissa').addEventListener('click', function () {
    raccogli();
    mappa.push({ nome: 'Nuova colonna', da: FISSO, valore: '', tipo: 'testo' });
    filtro.value = '';
    disegna();
    var ultima = contenitore.querySelector('.mrow:last-child input');
    if (ultima) { ultima.focus(); ultima.select(); ultima.scrollIntoView({ block: 'nearest' }); }
  });

  document.getElementById('ripristina').addEventListener('click', function () {
    mappa = identita();
    filtro.value = '';
    disegna();
  });

  disegna();
})();
</script>
