<?php
/**
 * 2h — Storico. I file non scadono: restano finche' non si cancellano.
 * @var list<array<string,mixed>> $jobs
 */
use Vblite\Convert\Auth;
use Vblite\Convert\Vista;

$filtri = ['tutte' => 'Tutte', 'mie' => 'Mie', 'da_rivedere' => 'Da rivedere'];
$cerca  = trim((string) ($_GET['q'] ?? ''));
if ($cerca !== '') {
    $jobs = array_values(array_filter(
        $jobs,
        static fn(array $j): bool => mb_stripos($j['nome_originale'], $cerca) !== false
    ));
}
?>
<div class="body" style="padding-top:var(--space-6)">
  <div class="tra">
    <div>
      <h2 class="h2">Storico conversioni</h2>
      <p class="lede" style="font-size:16px">Tutto quello che è stato convertito, da chiunque. I file non scadono.</p>
    </div>
    <form method="get" style="display:flex;gap:var(--space-2);align-items:center">
      <input type="hidden" name="p" value="storico">
      <input type="hidden" name="f" value="<?= Vista::e($filtro) ?>">
      <input class="input" style="width:200px" name="q" placeholder="Cerca un file" value="<?= Vista::e($cerca) ?>">
      <a class="btn btn-primary" href="?p=home">Nuova conversione</a>
    </form>
  </div>

  <div style="display:flex;gap:var(--space-4);margin-top:var(--space-6);align-items:baseline">
    <div class="seg">
      <?php foreach ($filtri as $chiave => $etichetta): ?>
        <label class="seg-opt">
          <input type="radio" name="hf" <?= $filtro === $chiave ? 'checked' : '' ?>
                 onchange="window.location='?p=storico&amp;f=<?= Vista::e($chiave) ?>'">
          <span><?= Vista::e($etichetta) ?></span>
        </label>
      <?php endforeach; ?>
    </div>
    <span class="mono" style="color:rgba(32,30,29,.45);margin-left:auto">
      <?= Vista::numero($totali['conversioni']) ?> conversioni · <?= Vista::e(Vista::byte($totali['byte'])) ?> archiviati
    </span>
  </div>

  <div style="margin-top:var(--space-4)">
    <?php if ($jobs === []): ?>
      <p style="font-size:15px;color:rgba(32,30,29,.6)">Nessuna conversione con questo filtro.</p>
    <?php else: ?>
      <table class="table" style="width:100%;font-size:13.5px">
        <thead><tr>
          <th>File</th>
          <th style="width:140px">Tipologia</th>
          <th style="width:130px">Utente</th>
          <th style="width:100px;text-align:right">Pren.</th>
          <th style="width:140px">Esito</th>
          <th style="width:120px">Quando</th>
          <th style="width:190px"></th>
        </tr></thead>
        <tbody>
        <?php foreach ($jobs as $i => $job): ?>
          <tr>
            <td><?= $i === 0 ? '<strong>' : '' ?><?= Vista::e($job['nome_originale']) ?><?= $i === 0 ? '</strong>' : '' ?></td>
            <td><?= Vista::e(Vista::tipologia((string) $job['tipologia'])) ?></td>
            <td><?= Vista::e($job['utente_nome']) ?></td>
            <td class="mono" style="text-align:right"><?= Vista::numero((int) ($job['righe_scritte'] ?? 0)) ?></td>
            <td><?php require __DIR__ . '/parti/esito.php'; ?></td>
            <td class="mono"><?= Vista::e(Vista::quando($job['creato_il'])) ?></td>
            <td style="text-align:right;white-space:nowrap">
              <?php if ($job['file_out'] !== null && is_file($job['file_out'])): ?>
                <a class="btn btn-secondary" style="padding:4px 10px;font-size:13px" href="?p=scarica&amp;job=<?= Vista::e($job['riferimento']) ?>">Scarica</a>
              <?php endif; ?>
              <?php if (is_file($job['file_in'])): ?>
                <form method="post" action="?p=rifai&amp;job=<?= Vista::e($job['riferimento']) ?>" style="display:inline">
                  <input type="hidden" name="csrf" value="<?= Vista::e(Auth::gettone()) ?>">
                  <button class="btn btn-ghost" style="padding:4px 8px;font-size:13px" type="submit">
                    <?= $job['esito'] === 'errore' ? 'Riprova' : 'Rifai' ?>
                  </button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="foot">
    <span class="mono" style="color:rgba(32,30,29,.5)">Nessuna scadenza: i file restano finché non li cancelli</span>
    <a href="?p=home" style="font-size:14px">Torna alle tipologie</a>
  </div>
</div>
