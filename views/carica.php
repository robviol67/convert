<?php
/**
 * 2c — Step 1, carica. La scheda a destra e le eccezioni a sinistra vengono dal
 * manifest della tipologia: nessun testo e' scritto in questa pagina.
 * @var array<string,mixed> $manifest
 */
use Vblite\Convert\Auth;
use Vblite\Convert\Config;
use Vblite\Convert\Vista;

$passoCorrente = 1;
require __DIR__ . '/parti/passi.php';
?>
<div class="body">
  <?php if ($errore !== null): ?>
    <p class="avviso" role="alert" style="margin:0 0 var(--space-4)"><?= Vista::e($errore) ?></p>
  <?php endif; ?>

  <div class="wizard" style="display:grid;grid-template-columns:1fr 460px;gap:var(--space-8)">

    <div style="display:flex;flex-direction:column">
      <h2 class="h2">Carica la stampa Octorate</h2>
      <p class="lede" style="font-size:16px">Serve l'export <em>Stampa clienti presenti</em>, in PDF, esattamente come lo produce Octorate — niente ritagli né stampe parziali.</p>

      <form id="form-upload" method="post" action="?p=upload" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= Vista::e(Auth::gettone()) ?>">
        <input type="hidden" name="tipologia" value="<?= Vista::e($manifest['chiave']) ?>">
        <input type="file" name="file" id="file" accept="application/pdf" class="nascosto">

        <div class="drop" id="drop" style="margin-top:var(--space-6);min-height:300px">
          <div>
            <div class="dsheet halftone" style="width:82px;margin:0 auto var(--space-4)">
              <i style="width:60%"></i><i></i><i></i><i style="width:45%"></i><i></i><i style="width:75%"></i><i></i>
            </div>
            <p style="font:600 24px/1.2 var(--font-heading);margin:0" id="drop-titolo">Trascina il PDF in quest'area</p>
            <p style="font-size:15px;color:rgba(32,30,29,.55);margin:10px 0 var(--space-4)" id="drop-nota">o seleziona dal computer</p>
            <button class="btn btn-primary" type="button" id="scegli">Scegli un file</button>
          </div>
        </div>
      </form>

      <div style="margin-top:var(--space-6)">
        <p class="kick" style="margin:0 0 var(--space-3)">Le sette eccezioni note di questo tracciato</p>
        <div class="numerato">
          <?php foreach ($manifest['eccezioni'] as $i => $eccezione): ?>
            <div>
              <span class="n"><?= $i + 1 ?></span>
              <span><strong><?= $eccezione['titolo'] ?></strong> <?= $eccezione['testo'] ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div>
      <p class="kick" style="margin:0 0 var(--space-3)"><?= Vista::e($manifest['titolo']) ?> · scheda della tipologia</p>
      <p style="font-size:14.5px;line-height:1.6;color:rgba(32,30,29,.75);margin:0"><?= $manifest['descrizione'] ?></p>

      <p class="kick" style="margin:var(--space-6) 0 var(--space-2)">Si può convertire</p>
      <div class="check">
        <?php foreach ($manifest['si_puo'] as $voce): ?>
          <div><span class="si">&#10003;</span><span><?= $voce ?></span></div>
        <?php endforeach; ?>
      </div>

      <p class="kick" style="margin:var(--space-6) 0 var(--space-2);color:var(--color-accent-2-700)">Non si può convertire</p>
      <div class="check">
        <?php foreach ($manifest['non_si_puo'] as $voce): ?>
          <div><span class="no">&#10007;</span><span><?= $voce ?></span></div>
        <?php endforeach; ?>
      </div>

      <p class="kick" style="margin:var(--space-6) 0 var(--space-2)">Specifiche</p>
      <div class="spec">
        <?php foreach ($manifest['specifiche'] as $etichetta => $valore): ?>
          <div><span class="et"><?= Vista::e((string) $etichetta) ?></span><span class="mono va"><?= Vista::e($valore) ?></span></div>
        <?php endforeach; ?>
      </div>

      <p class="kick" style="margin:var(--space-6) 0 var(--space-2)">Colonne in uscita</p>
      <p class="mono" style="font-size:12px;line-height:1.7;color:rgba(32,30,29,.55);margin:0">
        <?php $testate = (new \Vblite\Convert\Conversioni\OctoScidoo\ScrittoreScidoo())->testate();
              $visibili = array_values(array_filter($testate, static fn(string $t): bool => !str_starts_with($t, '*'))); ?>
        <?= Vista::e(implode(' · ', array_map('trim', $visibili))) ?>
      </p>
    </div>
  </div>

  <div class="foot">
    <a class="btn btn-ghost" href="?p=home">Cambia tipologia</a>
    <button class="btn btn-secondary" id="continua" type="submit" form="form-upload" disabled>Continua</button>
  </div>
</div>

<script>
/* Trascinamento del PDF: mentre il file e' sopra l'area, il resto della pagina
   sbiadisce e il riquadro si accende (stato «hot»). */
(function () {
  var input = document.getElementById('file');
  var drop = document.getElementById('drop');
  var continua = document.getElementById('continua');
  var titolo = document.getElementById('drop-titolo');
  var nota = document.getElementById('drop-nota');
  var maxByte = <?= Config::MAX_BYTE ?>;

  document.getElementById('scegli').addEventListener('click', function () { input.click(); });
  drop.addEventListener('click', function (e) { if (e.target === drop) input.click(); });

  function accetta(file) {
    if (!file) return;
    if (file.type !== 'application/pdf') { nota.textContent = 'Serve un PDF.'; return; }
    if (file.size > maxByte) { nota.textContent = 'Il file supera i 50 MB.'; return; }
    titolo.textContent = file.name;
    nota.textContent = Math.round(file.size / 1024 / 1024 * 10) / 10 + ' MB · pronto da leggere';
    continua.removeAttribute('disabled');
    continua.classList.remove('btn-secondary');
    continua.classList.add('btn-primary');
  }

  input.addEventListener('change', function () { accetta(input.files[0]); });

  ['dragenter', 'dragover'].forEach(function (evento) {
    drop.addEventListener(evento, function (e) {
      e.preventDefault();
      drop.classList.add('hot');
      document.body.classList.add('trascina');
    });
  });
  ['dragleave', 'drop'].forEach(function (evento) {
    drop.addEventListener(evento, function (e) {
      e.preventDefault();
      if (evento === 'dragleave' && drop.contains(e.relatedTarget)) return;
      drop.classList.remove('hot');
      document.body.classList.remove('trascina');
    });
  });
  drop.addEventListener('drop', function (e) {
    var file = e.dataTransfer.files[0];
    if (!file) return;
    input.files = e.dataTransfer.files;
    accetta(file);
  });
})();
</script>
