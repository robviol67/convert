<?php
/**
 * 2b — Home. Le tessere vengono dal registro delle tipologie: aggiungerne una
 * non richiede di toccare questa schermata.
 * @var list<\Vblite\Convert\Conversioni\Conversione> $tipologie
 */
use Vblite\Convert\Vista;
?>
<div class="body" style="padding-top:var(--space-6)">
  <h2 class="h2">Che conversione ti serve?</h2>
  <p class="lede" style="font-size:16px">Ogni tipologia ha le sue regole di lettura e il suo tracciato in uscita.</p>

  <div class="tiles" style="margin-top:var(--space-6)">
    <?php foreach ($tipologie as $tipologia):
        $m = $tipologia->manifest();
        $c = $conteggi[$tipologia::chiave()] ?? null; ?>
      <a class="tile" href="?p=carica&amp;t=<?= Vista::e($tipologia::chiave()) ?>">
        <span class="tag tag-accent" style="align-self:flex-start">Attiva</span>
        <span style="font:600 24px/1.15 var(--font-heading)"><?= Vista::e($m['titolo']) ?></span>
        <span style="font-size:14px;color:rgba(32,30,29,.65);line-height:1.5"><?= Vista::e($m['sottotitolo']) ?> Raggruppa le righe ospite per numero di prenotazione.</span>
        <span class="mono" style="color:rgba(32,30,29,.45);margin-top:auto;padding-top:var(--space-3)">
          <?php if ($c !== null): ?>
            <?= Vista::numero($c['n']) ?> conversion<?= $c['n'] === 1 ? 'e' : 'i' ?> · ultima <?= Vista::e(Vista::quando($c['ultima'])) ?>
          <?php else: ?>
            nessuna conversione ancora
          <?php endif; ?>
        </span>
      </a>
    <?php endforeach; ?>

    <?php foreach ($in_arrivo as $segnaposto): ?>
      <div class="tile soon">
        <span class="tag tag-outline" style="align-self:flex-start">In arrivo</span>
        <span style="font:600 24px/1.15 var(--font-heading);color:rgba(32,30,29,.5)"><?= Vista::e($segnaposto['titolo']) ?></span>
        <span style="font-size:14px;color:rgba(32,30,29,.5);line-height:1.5"><?= Vista::e($segnaposto['sottotitolo']) ?></span>
      </div>
    <?php endforeach; ?>
  </div>

  <div style="margin-top:var(--space-8)">
    <p class="kick" style="margin:0 0 var(--space-3)">Le tue ultime conversioni</p>
    <?php if ($ultime === []): ?>
      <p style="font-size:14px;color:rgba(32,30,29,.55);margin:0">Nessuna conversione ancora. Scegli una tipologia qui sopra.</p>
    <?php else: ?>
      <table class="table" style="width:100%;font-size:13.5px">
        <thead><tr>
          <th>File</th>
          <th style="width:170px">Tipologia</th>
          <th style="width:110px;text-align:right">Prenotazioni</th>
          <th style="width:150px">Esito</th>
          <th style="width:130px">Quando</th>
          <th style="width:110px"></th>
        </tr></thead>
        <tbody>
        <?php foreach ($ultime as $i => $job): ?>
          <tr>
            <td><?= $i === 0 ? '<strong>' : '' ?><?= Vista::e($job['nome_originale']) ?><?= $i === 0 ? '</strong>' : '' ?></td>
            <td>Octo → Scidoo</td>
            <td class="mono" style="text-align:right"><?= Vista::numero($job['righe_scritte']) ?></td>
            <td><?php require __DIR__ . '/parti/esito.php'; ?></td>
            <td class="mono"><?= Vista::e(Vista::quando($job['creato_il'])) ?></td>
            <td style="text-align:right">
              <?php if ($job['file_out'] !== null): ?>
                <a class="btn btn-secondary" style="padding:4px 10px;font-size:13px" href="?p=scarica&amp;job=<?= Vista::e($job['riferimento']) ?>">Scarica</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="foot">
    <span class="mono" style="color:rgba(32,30,29,.5)">Accesso come <?= Vista::e($utente['nome']) ?></span>
    <a href="?p=storico" style="font-size:14px">Tutto lo storico</a>
  </div>
</div>
