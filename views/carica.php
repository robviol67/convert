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
      <h2 class="h2"><?= Vista::e($manifest['titolo_upload'] ?? 'Carica il file') ?></h2>
      <p class="lede" style="font-size:16px"><?= $manifest['lede_upload'] ?? '' ?></p>

      <form id="form-upload" method="post" action="?p=upload" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= Vista::e(Auth::gettone()) ?>">
        <input type="hidden" name="tipologia" value="<?= Vista::e($manifest['chiave']) ?>">
        <?php
          $ammesse = $manifest['estensioni_ingresso'] ?? ['pdf'];
          $accetta = implode(',', array_map(static fn(string $e): string => '.' . $e, $ammesse));
        ?>
        <input type="file" name="file" id="file" accept="<?= Vista::e($accetta) ?>" class="nascosto">

        <div class="drop" id="drop" style="margin-top:var(--space-6);min-height:300px">
          <div>
            <div class="dsheet halftone" style="width:82px;margin:0 auto var(--space-4)">
              <i style="width:60%"></i><i></i><i></i><i style="width:45%"></i><i></i><i style="width:75%"></i><i></i>
            </div>
            <p style="font:600 24px/1.2 var(--font-heading);margin:0" id="drop-titolo"><?= Vista::e($manifest['invito_upload'] ?? "Trascina il PDF in quest'area") ?></p>
            <p style="font-size:15px;color:rgba(32,30,29,.55);margin:10px 0 var(--space-4)" id="drop-nota">o seleziona dal computer</p>
            <button class="btn btn-primary" type="button" id="scegli">Scegli un file</button>
          </div>
        </div>

        <?php // Chi ha copiato la trascrizione dal pannello di un video non ha
              // nessun file da trascinare: il testo e' gia' negli appunti. ?>
        <?php if (!empty($manifest['accetta_incolla'])): ?>
          <div style="margin-top:var(--space-4)">
            <p class="kick" style="margin:0 0 var(--space-2)">Oppure incolla il testo</p>
            <textarea class="input" name="testo" id="testo" rows="6" spellcheck="false"
                      style="width:100%;font-size:13.5px;line-height:1.5;resize:vertical"
                      placeholder="Incolla qui la trascrizione copiata dal video, coi tempi o senza."></textarea>
            <button class="btn btn-secondary" type="submit" id="converti-incollato"
                    style="margin-top:var(--space-2)" disabled>Usa il testo incollato</button>
          </div>
        <?php endif; ?>
      </form>

      <?php if (!empty($manifest['accetta_incolla'])): ?>
        <script>
        /* Il pulsante resta spento finche' non c'e' qualcosa da mandare: un
           modulo vuoto tornerebbe indietro con un errore, e l'errore si evita
           prima di prenderlo. */
        (function () {
          var testo = document.getElementById('testo');
          var invia = document.getElementById('converti-incollato');
          if (!testo || !invia) { return; }
          testo.addEventListener('input', function () {
            invia.disabled = testo.value.trim() === '';
          });
        })();
        </script>
      <?php endif; ?>

      <div style="margin-top:var(--space-6)">
        <p class="kick" style="margin:0 0 var(--space-3)"><?= Vista::e($manifest['titolo_eccezioni'] ?? 'Cosa sapere') ?></p>
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

      <?php
        // L'elenco lo dichiara la tipologia: per un tracciato sono le colonne,
        // per un documento i tratti che sopravvivono alla conversione.
        $colonne = $manifest['colonne_uscita'] ?? [];
      ?>
      <?php if ($colonne !== []): ?>
        <p class="kick" style="margin:var(--space-6) 0 var(--space-2)"><?= Vista::e($manifest['titolo_colonne'] ?? 'In uscita') ?></p>
        <p class="mono" style="font-size:12px;line-height:1.7;color:rgba(32,30,29,.55);margin:0">
          <?= Vista::e(implode(' · ', array_map('trim', $colonne))) ?>
        </p>
      <?php endif; ?>
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
    var ammesse = <?= json_encode($manifest['estensioni_ingresso'] ?? ['pdf']) ?>;
    var suo = (file.name.split('.').pop() || '').toLowerCase();
    if (ammesse.indexOf(suo) === -1) {
      nota.textContent = 'Formati accettati: ' + ammesse.join(', ') + '.';
      return;
    }
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
