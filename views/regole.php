<?php
/**
 * 2d — Step 2, le regole del tracciato. Tabella e regole opzionali vengono dal
 * manifest; i conteggi sono quelli veri, letti dal PDF appena caricato.
 * @var array<string,mixed> $manifest
 * @var array<string,mixed> $analisi
 * @var array<string,mixed> $bozza
 */
use Vblite\Convert\Auth;
use Vblite\Convert\Conversioni\OctoScidoo\Raggruppatore;
use Vblite\Convert\Vista;

$passoCorrente = 2;
require __DIR__ . '/parti/passi.php';

$intestazione = $analisi['intestazione'];
$periodo = ($intestazione['dal'] ?? null) !== null
    ? $intestazione['dal'] . ' – ' . $intestazione['al']
    : 'periodo non dichiarato';
$default = Raggruppatore::REGOLE_DEFAULT;
?>
<form method="post" action="?p=converti" style="display:contents">
<input type="hidden" name="csrf" value="<?= Vista::e(Auth::gettone()) ?>">

<div class="body">
  <div class="tra">
    <div>
      <h2 class="h2"><?= Vista::e($bozza['nome_originale']) ?></h2>
      <p class="mono" style="color:rgba(32,30,29,.55);margin:8px 0 0">
        <?= Vista::numero($analisi['pagine']) ?> pagine ·
        <?= Vista::numero($analisi['righe_lette']) ?> righe cliente ·
        <?= Vista::numero($analisi['prenotazioni']) ?> prenotazioni ·
        <?= Vista::e($periodo) ?> · testo nativo
      </p>
    </div>
    <a class="btn btn-ghost" href="?p=carica&amp;t=<?= Vista::e($bozza['tipologia']) ?>">Cambia file</a>
  </div>

  <div style="display:grid;grid-template-columns:1fr 400px;gap:var(--space-8);margin-top:var(--space-6)">

    <div style="display:flex;flex-direction:column;gap:var(--space-4)">
      <div>
        <p class="kick" style="margin:0 0 var(--space-3)">Regole di conversione · 23 colonne Octorate → 32 colonne Scidoo</p>
        <div class="mrow hd"><span class="kick">Octorate</span><span></span><span class="kick">Scidoo</span><span class="kick">Regola</span></div>
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

      <?php if ($analisi['anteprima'] !== []): ?>
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
        <p class="kick" style="margin:0 0 var(--space-3)">Tracciato in uscita</p>
        <div class="seg">
          <label class="seg-opt"><input type="radio" name="formato" value="xlsx" checked><span>XLSX</span></label>
          <label class="seg-opt"><input type="radio" name="formato" value="csv"><span>CSV</span></label>
        </div>
        <p style="font-size:13px;color:rgba(32,30,29,.55);margin:var(--space-2) 0 0">
          Intestazioni e ordine colonne dal tuo <span class="mono">File Import Prenotazioni.xlsx</span>.
        </p>
      </div>

      <div>
        <p class="kick" style="margin:0 0 var(--space-3)">Periodo da convertire</p>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-2)">
          <div class="field"><label for="dal">Dal</label><input class="input" id="dal" name="periodo_dal" value="<?= Vista::e($intestazione['dal'] ?? '') ?>"></div>
          <div class="field"><label for="al">Al</label><input class="input" id="al" name="periodo_al" value="<?= Vista::e($intestazione['al'] ?? '') ?>"></div>
        </div>
        <p style="font-size:13px;color:rgba(32,30,29,.55);margin:var(--space-2) 0 0">
          Tutto il PDF: <?= Vista::numero($analisi['prenotazioni']) ?> prenotazioni.
        </p>
      </div>

      <div>
        <p class="kick" style="margin:0 0 var(--space-3)">Regole opzionali</p>
        <?php foreach ($manifest['regole_opzionali'] as $i => $regola): ?>
          <label style="display:flex;gap:10px;font-size:14.5px;align-items:flex-start<?= $i > 0 ? ';margin-top:var(--space-3)' : '' ?>">
            <input type="checkbox" name="regole[<?= Vista::e($regola['chiave']) ?>]" value="1" style="margin-top:4px"
                   <?= $regola['default'] ? 'checked' : '' ?>>
            <span><?= Vista::e($regola['titolo']) ?>
              <?php if ($regola['nota'] !== null): ?>
                <span style="display:block;font-size:13px;color:rgba(32,30,29,.6)"><?= Vista::e($regola['nota']) ?></span>
              <?php endif; ?>
            </span>
          </label>
        <?php endforeach; ?>
      </div>

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
        <?= Vista::numero($analisi['prenotazioni']) ?> righe in uscita · <?= Vista::numero($analisi['anomalie']) ?> da rivedere
      </span>
      <button class="btn btn-secondary" type="submit" name="azione" value="salva_preset">Salva queste regole</button>
      <button class="btn btn-primary" type="submit" name="azione" value="converti">Converti</button>
    </div>
  </div>
</div>
</form>
