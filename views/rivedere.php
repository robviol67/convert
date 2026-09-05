<?php
/**
 * 2g — Da rivedere. Le righe sono gia' nel file: qui si correggono i valori
 * incerti e il foglio si riscrive, stesso job, nuova versione.
 * @var array<string,mixed> $job
 * @var list<array<string,mixed>> $anomalie
 */
use Vblite\Convert\Auth;
use Vblite\Convert\Vista;

$daCorreggere = array_values(array_filter($anomalie, static fn(array $a): bool => $a['gravita'] === 'correggi'));
$informative  = array_values(array_filter($anomalie, static fn(array $a): bool => $a['gravita'] === 'informativa'));
$pagina  = max(1, (int) ($_GET['pag'] ?? 1));
$perPagina = 40;
$totalePagine = max(1, (int) ceil(count($daCorreggere) / $perPagina));
$visibili = array_slice($daCorreggere, ($pagina - 1) * $perPagina, $perPagina);
?>
<div class="body" style="padding-top:var(--space-6)">
  <div class="tra">
    <div>
      <p class="kick" style="margin:0 0 8px;color:var(--color-accent-2-700)">
        <?= Vista::numero(count($daCorreggere)) ?> prenotazioni su <?= Vista::numero($job['righe_scritte']) ?>
      </p>
      <h2 class="h2">Da rivedere prima dell'import</h2>
      <p class="lede" style="font-size:16px">
        Sono già nel file, ma con un valore incerto: Scidoo le accetterebbe sbagliate.
        Correggi qui e il foglio si riscrive.
      </p>
    </div>
    <div style="display:flex;gap:var(--space-2)">
      <a class="btn btn-secondary" href="?p=scarica&amp;job=<?= Vista::e($job['riferimento']) ?>">Scarica il file</a>
      <button class="btn btn-primary" type="submit" form="form-correzioni">Applica e riscarica</button>
    </div>
  </div>

  <form id="form-correzioni" method="post" action="?p=correggi&amp;job=<?= Vista::e($job['riferimento']) ?>" style="margin-top:var(--space-6)">
    <input type="hidden" name="csrf" value="<?= Vista::e(Auth::gettone()) ?>">

    <?php if ($visibili === []): ?>
      <p style="font-size:15px;color:rgba(32,30,29,.6)">Nessuna prenotazione da rivedere: il tracciato è completo.</p>
    <?php else: ?>
      <table class="table" style="width:100%;font-size:13.5px">
        <thead><tr>
          <th style="width:70px">N°pren.</th>
          <th style="width:150px">Cliente</th>
          <th>Cosa non torna</th>
          <th style="width:150px">Colonna Scidoo</th>
          <th style="width:210px">Correzione</th>
          <th style="width:80px"></th>
        </tr></thead>
        <tbody>
        <?php foreach ($visibili as $a): ?>
          <tr<?= $a['risolta'] ? ' style="opacity:.45"' : '' ?>>
            <td class="mono"><?= Vista::e($a['chiave']) ?></td>
            <td><?= Vista::e($a['cliente']) ?></td>
            <td><?= Vista::e($a['motivo']) ?></td>
            <td class="mono"><?= Vista::e($a['colonna']) ?></td>
            <td>
              <input class="input" style="padding:5px 8px;font-size:13px;width:180px"
                     name="correzione[<?= (int) $a['id'] ?>]"
                     value="<?= Vista::e($a['valore_corretto'] ?? '') ?>"
                     placeholder="<?= Vista::e($a['valore_proposto'] ?? '') ?>">
            </td>
            <td>
              <label class="btn btn-ghost" style="padding:4px 8px;font-size:13px;cursor:pointer">
                <input type="checkbox" name="salta[<?= (int) $a['id'] ?>]" value="1" style="margin-right:5px"
                       <?= $a['risolta'] ? 'checked' : '' ?>>Salta
              </label>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <?php if ($totalePagine > 1): ?>
        <div class="riga" style="margin-top:var(--space-4);font-size:13.5px">
          <span class="mono muted">Pagina <?= $pagina ?> di <?= $totalePagine ?></span>
          <?php if ($pagina > 1): ?>
            <a href="?p=rivedere&amp;job=<?= Vista::e($job['riferimento']) ?>&amp;pag=<?= $pagina - 1 ?>">Precedenti</a>
          <?php endif; ?>
          <?php if ($pagina < $totalePagine): ?>
            <a href="?p=rivedere&amp;job=<?= Vista::e($job['riferimento']) ?>&amp;pag=<?= $pagina + 1 ?>">Successive</a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($informative !== []): ?>
      <div style="margin-top:var(--space-6);display:flex;gap:var(--space-4);align-items:flex-start;max-width:820px">
        <div class="dsheet halftone" style="width:96px;flex:none">
          <i style="width:55%"></i><i></i><i></i>
          <i style="width:70%;background:var(--color-accent-2-400)"></i><i></i><i></i><i style="width:40%"></i>
        </div>
        <div>
          <p class="kick" style="margin:0 0 8px">Fatto senza chiedere · <?= Vista::numero(count($informative)) ?> casi</p>
          <p style="font-size:15px;line-height:1.6;color:rgba(32,30,29,.7);margin:0;max-width:60ch">
            Voci risolte dalle regole del tracciato e riportate qui solo per trasparenza:
            <?php
            $perColonna = [];
            foreach ($informative as $i) {
                $perColonna[$i['colonna']] = ($perColonna[$i['colonna']] ?? 0) + 1;
            }
            $pezzi = [];
            foreach ($perColonna as $colonna => $n) {
                $pezzi[] = Vista::numero($n) . ' su ' . Vista::e((string) $colonna);
            }
            echo implode(' · ', $pezzi);
            ?>.
          </p>
        </div>
      </div>
    <?php endif; ?>
  </form>

  <div class="foot">
    <span class="mono" style="color:rgba(32,30,29,.5)"><?= Vista::e($job['nome_originale']) ?> · job #<?= Vista::e($job['riferimento']) ?></span>
    <a href="?p=pronto&amp;job=<?= Vista::e($job['riferimento']) ?>" style="font-size:14px">Torna al download</a>
  </div>
</div>
