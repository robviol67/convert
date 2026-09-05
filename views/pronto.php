<?php
/**
 * 2f — Pronto. Nessuna scadenza: il file resta finche' non lo si cancella.
 * @var array<string,mixed> $job
 * @var array{testate:list<string>,righe:list<list<string>>} $anteprima
 */
use Vblite\Convert\Config;
use Vblite\Convert\Vista;

$passoCorrente = 3;
require __DIR__ . '/parti/passi.php';

$durata = null;
if ($job['concluso_il'] !== null) {
    $durata = max(0, strtotime($job['concluso_il'] . ' UTC') - strtotime($job['creato_il'] . ' UTC'));
}
$linkDiretto = Config::baseUrl() . '/?p=scarica&job=' . $job['riferimento'];
?>
<div class="body">
  <div style="display:grid;grid-template-columns:1.05fr 1fr;gap:var(--space-8);align-items:start">

    <div>
      <?php if ($job['esito'] === 'errore'): ?>
        <p class="kick" style="margin:0 0 var(--space-3);color:var(--color-accent-2-700)">Conversione non riuscita</p>
        <h2 class="h1" style="font-size:50px;max-width:20ch">Il file non è stato convertito.</h2>
        <p class="lede"><?= Vista::e($job['errore']) ?></p>
        <div style="margin-top:var(--space-6)"><a class="btn btn-primary" href="?p=home">Riprova</a></div>
      <?php else: ?>
        <p class="kick" style="margin:0 0 var(--space-3);color:var(--color-accent-700)">Conversione completata</p>
        <h2 class="h1" style="font-size:50px;max-width:20ch"><?= Vista::numero($job['righe_scritte']) ?> prenotazioni, pronte per Scidoo.</h2>
        <p class="lede">
          <?= Vista::numero($job['righe_lette']) ?> righe cliente raggruppate in <?= Vista::numero($job['righe_scritte']) ?> prenotazioni,
          32 colonne, date come date e importi come valuta.
        </p>

        <div style="display:flex;gap:var(--space-3);align-items:center;margin-top:var(--space-6)">
          <a class="btn btn-primary" style="font-size:17px;padding:12px 22px" href="?p=scarica&amp;job=<?= Vista::e($job['riferimento']) ?>">
            Scarica <?= Vista::e($job['nome_uscita']) ?>
          </a>
          <button class="btn btn-secondary" type="button" id="copia" data-link="<?= Vista::e($linkDiretto) ?>">Copia link</button>
        </div>
        <p class="mono" style="color:rgba(32,30,29,.5);margin-top:var(--space-4)">
          <?= Vista::e(Vista::byte($job['byte_out'] === null ? null : (int) $job['byte_out'])) ?> · resta disponibile nello storico
        </p>

        <div style="display:flex;gap:var(--space-6);margin-top:var(--space-8);padding-top:var(--space-4);border-top:1px solid var(--color-divider)">
          <a class="btn btn-ghost" href="?p=carica&amp;t=<?= Vista::e($job['tipologia']) ?>">Converti un altro PDF</a>
          <form method="post" action="?p=rifai&amp;job=<?= Vista::e($job['riferimento']) ?>" style="display:inline">
            <input type="hidden" name="csrf" value="<?= Vista::e(\Vblite\Convert\Auth::gettone()) ?>">
            <button class="btn btn-ghost" type="submit">Rifai con le stesse regole</button>
          </form>
        </div>
      <?php endif; ?>
    </div>

    <div>
      <p class="kick" style="margin:0 0 var(--space-4)">Riepilogo</p>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-6) var(--space-4);max-width:430px">
        <?php
        $lastre = [
            [Vista::numero($job['righe_scritte']), 'prenotazioni scritte'],
            [Vista::numero($job['righe_lette']), 'righe cliente lette'],
            [$durata === null ? '—' : sprintf('%02d:%02d', intdiv($durata, 60), $durata % 60), 'tempo'],
        ];
        foreach ($lastre as [$numero, $etichetta]): ?>
          <div>
            <div class="cmyk-num" style="font:600 42px/0.9 var(--font-heading)">
              <span class="paper"><?= Vista::e($numero) ?></span>
              <span class="plate plate-c" aria-hidden="true"><?= Vista::e($numero) ?></span>
            </div>
            <div style="font-size:14px;color:rgba(32,30,29,.6);margin-top:8px"><?= Vista::e($etichetta) ?></div>
          </div>
        <?php endforeach; ?>
        <div>
          <div style="font:600 42px/0.9 var(--font-heading);color:var(--color-accent-2-700)"><?= Vista::numero(count($anomalie)) ?></div>
          <div style="font-size:14px;color:rgba(32,30,29,.6);margin-top:8px">
            <?php if ($anomalie !== []): ?>
              <a href="?p=rivedere&amp;job=<?= Vista::e($job['riferimento']) ?>" style="color:var(--color-accent-2-700)">da rivedere</a>
            <?php else: ?>
              da rivedere
            <?php endif; ?>
          </div>
        </div>
      </div>

      <?php if ($anteprima['righe'] !== []): ?>
        <div class="foglio" style="margin-top:var(--space-6);max-width:430px">
          <p class="kick" style="margin:0 0 var(--space-3)">Prime righe del tracciato</p>
          <?php
            // Le colonne dell'anteprima le decide il motore, non questa pagina:
            // una tipologia diversa ne mostrera' altre senza che qui cambi nulla.
            $aDestra = static fn(int $i): bool => $i >= count($anteprima['testate']) - 2;
          ?>
          <table class="table" style="width:100%;font-size:12px">
            <thead><tr>
              <?php foreach ($anteprima['testate'] as $i => $testata): ?>
                <th<?= $aDestra($i) ? ' style="text-align:right"' : '' ?>><?= Vista::e($testata) ?></th>
              <?php endforeach; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($anteprima['righe'] as $riga): ?>
              <tr>
                <?php foreach ($riga as $i => $valore): ?>
                  <td class="mono"<?= $aDestra($i) ? ' style="text-align:right"' : '' ?>><?= Vista::e($valore) ?></td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="foot">
    <span class="mono" style="color:rgba(32,30,29,.5)">job #<?= Vista::e($job['riferimento']) ?> · Octo → Scidoo · <?= Vista::e($utente['nome']) ?></span>
    <a href="?p=storico" style="font-size:14px">Vedi nello storico</a>
  </div>
</div>

<script>
(function () {
  var bottone = document.getElementById('copia');
  if (!bottone) return;
  bottone.addEventListener('click', function () {
    navigator.clipboard.writeText(bottone.dataset.link).then(function () {
      var testo = bottone.textContent;
      bottone.textContent = 'Link copiato';
      setTimeout(function () { bottone.textContent = testo; }, 2000);
    });
  });
})();
</script>
