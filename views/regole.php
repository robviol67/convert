<?php
/**
 * 2d — Step 2, le regole del tracciato. Tabella e regole opzionali vengono dal
 * manifest; i conteggi sono quelli veri, letti dal file appena caricato.
 * @var array<string,mixed> $manifest
 * @var array<string,mixed> $analisi
 * @var array<string,mixed> $bozza
 */
use Vblite\Convert\Auth;
use Vblite\Convert\Vista;

$passoCorrente = 2;
require __DIR__ . '/parti/passi.php';

$intestazione = $analisi['intestazione'];
$periodo = ($intestazione['dal'] ?? null) !== null
    ? $intestazione['dal'] . ' – ' . $intestazione['al']
    : 'periodo non dichiarato';
// I valori di partenza delle regole li dichiara il manifest.
$lessico = Vista::lessico((string) $bozza['tipologia']);

$default = [];
foreach ($manifest['regole_opzionali'] ?? [] as $regola) {
    $default[$regola['chiave']] = $regola['default'];
}
?>
<form method="post" action="?p=converti" style="display:contents" id="regole">
<input type="hidden" name="csrf" value="<?= Vista::e(Auth::gettone()) ?>">

<div class="body">
  <div class="tra">
    <div>
      <h2 class="h2"><?= Vista::e($bozza['nome_originale']) ?></h2>
      <p class="mono" style="color:rgba(32,30,29,.55);margin:8px 0 0">
        <?= Vista::e($analisi['sommario'] ?? '') ?>
      </p>
    </div>
    <a class="btn btn-ghost" href="?p=carica&amp;t=<?= Vista::e($bozza['tipologia']) ?>">Cambia file</a>
  </div>

  <div style="display:grid;grid-template-columns:1fr 400px;gap:var(--space-8);margin-top:var(--space-6)">

    <div style="display:flex;flex-direction:column;gap:var(--space-4)">
      <div>
        <p class="kick" style="margin:0 0 var(--space-3)"><?= Vista::e($manifest['titolo_regole'] ?? 'Regole di conversione') ?></p>
        <div class="mrow hd"><span class="kick"><?= Vista::e($manifest['colonna_da'] ?? 'Da') ?></span><span></span><span class="kick"><?= Vista::e($manifest['colonna_a'] ?? 'A') ?></span><span class="kick">Regola</span></div>
        <?php foreach ($manifest['regole_conversione'] as $regola): ?>
          <div class="mrow">
            <span class="mono"><?= Vista::e($regola['da']) ?></span>
            <?php if ($regola['a'] === null): ?>
              <span class="arw scarto">&#8600;</span>
              <span style="color:var(--color-accent-2-700)">nessuna colonna</span>
            <?php else: ?>
              <span class="arw">&#8594;</span>
              <span class="mono"><?= Vista::e($regola['a']) ?></span>
            <?php endif; ?>
            <span style="color:<?= !empty($regola['accento']) ? 'var(--color-accent-800)' : 'rgba(32,30,29,.65)' ?>"><?= $regola['regola'] ?></span>
          </div>
        <?php endforeach; ?>
      </div>

      <?php // L'unico blocco che sa di prenotazioni: compare solo se la tipologia
            // produce un'anteprima del raggruppamento, cioè oggi solo Octo → Scidoo.
            // Quando servirà a una seconda tipologia, le colonne verranno dal manifest. ?>
      <?php if (!empty($analisi['anteprima'])): ?>
        <div style="flex:none;display:flex;flex-direction:column">
          <p class="kick" style="margin:0 0 var(--space-3)">
            Anteprima del raggruppamento — prenotazione <?= Vista::e($analisi['anteprima'][0]['npren']) ?>,
            <?= (int) $analisi['anteprima'][0]['_ospiti'] ?> righe → 1
          </p>
          <div class="foglio">
            <table class="table" style="width:100%;font-size:12.5px">
              <thead><tr>
                <th>ID</th><th>Cognome</th><th>Nome</th><th>Arrivo</th><th>Partenza</th>
                <th style="text-align:right">Ad.</th><th style="text-align:right">Ba.</th>
                <th>Agenzia</th><th style="text-align:right">Prezzo Retta</th>
              </tr></thead>
              <tbody>
              <?php foreach ($analisi['anteprima'] as $p): ?>
                <tr>
                  <td class="mono"><?= Vista::e((string) $p['id']) ?></td>
                  <td><?= Vista::e($p['cognome']) ?></td>
                  <td><?= Vista::e($p['nome']) ?></td>
                  <td class="mono"><?= Vista::e(Vista::data($p['arrivo'])) ?></td>
                  <td class="mono"><?= Vista::e(Vista::data($p['partenza'])) ?></td>
                  <td class="mono" style="text-align:right"><?= (int) $p['adulti'] ?></td>
                  <td class="mono" style="text-align:right"><?= (int) $p['bambini'] ?></td>
                  <td><?= $p['agenzia'] !== '' ? Vista::e($p['agenzia']) : '&#8212;' ?></td>
                  <td class="mono" style="text-align:right"><?= Vista::e(Vista::valuta($p['prezzo_retta'])) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
        <?php endif; ?>
      
    </div>

    <div style="display:flex;flex-direction:column;gap:var(--space-6)">
      <div>
        <p class="kick" style="margin:0 0 var(--space-3)">Formato in uscita</p>
        <div class="seg">
          <?php foreach (($manifest['formati_uscita'] ?? ['xlsx' => 'XLSX']) as $chiave => $etichetta): ?>
            <label class="seg-opt">
              <input type="radio" name="formato" value="<?= Vista::e((string) $chiave) ?>"
                     <?= $chiave === array_key_first($manifest['formati_uscita'] ?? ['xlsx' => '']) ? 'checked' : '' ?>>
              <span><?= Vista::e($etichetta) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <?php if (!empty($manifest['nota_uscita'])): ?>
          <p style="font-size:13px;color:rgba(32,30,29,.55);margin:var(--space-2) 0 0">
            <?= $manifest['nota_uscita'] ?>
          </p>
        <?php endif; ?>
      </div>

      <div<?= empty($manifest['ha_periodo']) ? ' hidden' : '' ?>>
        <p class="kick" style="margin:0 0 var(--space-3)">Periodo da convertire</p>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-2)">
          <div class="field"><label for="dal">Dal</label><input class="input" id="dal" name="periodo_dal" value="<?= Vista::e($intestazione['dal'] ?? '') ?>"></div>
          <div class="field"><label for="al">Al</label><input class="input" id="al" name="periodo_al" value="<?= Vista::e($intestazione['al'] ?? '') ?>"></div>
        </div>
        <p style="font-size:13px;color:rgba(32,30,29,.55);margin:var(--space-2) 0 0">
          Tutto il file: <?= Vista::numero($analisi['prenotazioni']) ?> <?= Vista::e($lessico['unita_plurale']) ?>.
        </p>
      </div>

      <div>
        <p class="kick" style="margin:0 0 var(--space-3)">Regole opzionali</p>
        <?php foreach ($manifest['regole_opzionali'] as $i => $regola): ?>
          <label class="opzione" data-formati="<?= Vista::e(implode(' ', $regola['solo_se_formato'] ?? [])) ?>"
                 style="display:flex;gap:10px;font-size:14.5px;align-items:flex-start<?= $i > 0 ? ';margin-top:var(--space-3)' : '' ?>">
            <input type="checkbox" name="regole[<?= Vista::e($regola['chiave']) ?>]" value="1" style="margin-top:4px"
                   <?= $regola['default'] ? 'checked' : '' ?>>
            <span><?= Vista::e($regola['titolo']) ?>
              <?php if (($regola['nota'] ?? null) !== null): ?>
                <span style="display:block;font-size:13px;color:rgba(32,30,29,.6)"><?= Vista::e($regola['nota']) ?></span>
              <?php endif; ?>
            </span>
          </label>
        <?php endforeach; ?>
      </div>

      <?php // Impostazioni proprie di un formato: compaiono solo quando serve. ?>
      <?php foreach ($manifest['scelte'] ?? [] as $scelta): ?>
        <div class="opzione" data-formati="<?= Vista::e(implode(' ', $scelta['solo_se_formato'] ?? [])) ?>">
          <p class="kick" style="margin:0 0 var(--space-3)"><?= Vista::e($scelta['etichetta']) ?></p>
          <select class="input" style="padding:7px 9px;font-size:14px" name="scelte[<?= Vista::e($scelta['chiave']) ?>]">
            <?php foreach ($scelta['opzioni'] as $valore => $etichetta): ?>
              <option value="<?= Vista::e((string) $valore) ?>"
                      <?= (string) $valore === (string) $scelta['default'] ? 'selected' : '' ?>>
                <?= Vista::e($etichetta) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if (($scelta['nota'] ?? null) !== null): ?>
            <p style="font-size:13px;color:rgba(32,30,29,.55);margin:var(--space-2) 0 0"><?= Vista::e($scelta['nota']) ?></p>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>

      <?php if (($manifest['campi'] ?? []) !== []): ?>
        <div class="opzione" data-formati="<?= Vista::e(implode(' ', $manifest['campi'][0]['solo_se_formato'] ?? [])) ?>">
          <p class="kick" style="margin:0 0 var(--space-3)">Dati del file prodotto</p>
          <?php foreach ($manifest['campi'] as $campo): ?>
            <div class="field" style="margin-bottom:var(--space-3)">
              <label for="c-<?= Vista::e($campo['chiave']) ?>"><?= Vista::e($campo['etichetta']) ?></label>
              <input class="input" id="c-<?= Vista::e($campo['chiave']) ?>"
                     name="campi[<?= Vista::e($campo['chiave']) ?>]"
                     value="<?= Vista::e((string) $campo['default']) ?>" maxlength="200">
              <?php if (($campo['nota'] ?? null) !== null): ?>
                <span style="display:block;font-size:13px;color:rgba(32,30,29,.55);margin-top:4px"><?= Vista::e($campo['nota']) ?></span>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div>
        <p class="kick" style="margin:0 0 var(--space-3)">Numero camera</p>
        <select class="input" name="sorgente_camera" style="padding:7px 9px;font-size:14px">
          <?php foreach ($manifest['sorgenti_camera'] as $valore => $etichetta): ?>
            <option value="<?= Vista::e($valore) ?>" <?= $valore === $default['sorgente_camera'] ? 'selected' : '' ?>>
              <?= Vista::e($etichetta) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
  </div>

  <div class="foot">
    <a class="btn btn-ghost" href="?p=carica&amp;t=<?= Vista::e($bozza['tipologia']) ?>">Indietro</a>
    <div style="display:flex;align-items:center;gap:var(--space-4)">
      <span class="mono" style="color:rgba(32,30,29,.5)">
        <?= Vista::numero($analisi['prenotazioni']) ?> <?= Vista::e($lessico['unita_plurale']) ?> in uscita · <?= Vista::numero($analisi['anomalie']) ?> da rivedere
      </span>
      <button class="btn btn-secondary" type="submit" name="azione" value="salva_preset">Salva queste regole</button>
      <button class="btn btn-primary" type="submit" name="azione" value="converti">Converti</button>
    </div>
  </div>
</div>
</form>

<script>
/* Le impostazioni proprie di un formato compaiono solo quando quel formato è
   scelto: chiedere l'autore a chi converte in .txt sarebbe rumore. Senza
   JavaScript restano tutte visibili, e funzionano lo stesso. */
(function () {
  var modulo = document.getElementById('regole');
  if (!modulo) { return; }

  var opzioni = Array.prototype.slice.call(modulo.querySelectorAll('.opzione[data-formati]'));
  var formati = Array.prototype.slice.call(modulo.querySelectorAll('input[name="formato"]'));
  if (!formati.length) { return; }

  function aggiorna() {
    var scelto = (formati.filter(function (r) { return r.checked; })[0] || {}).value;
    opzioni.forEach(function (o) {
      var soloPer = (o.getAttribute('data-formati') || '').split(' ').filter(Boolean);
      o.hidden = soloPer.length > 0 && soloPer.indexOf(scelto) === -1;
    });
  }

  formati.forEach(function (r) { r.addEventListener('change', aggiorna); });
  aggiorna();
})();
</script>
