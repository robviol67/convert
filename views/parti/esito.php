<?php
/** Etichetta di esito di un job. @var array<string,mixed> $job */
use Vblite\Convert\Vista;

$daRivedere = (int) ($job['da_rivedere'] ?? 0);
?>
<?php if ($job['esito'] === 'errore'): ?>
  <span class="tag tag-accent-2" title="<?= Vista::e($job['errore']) ?>"><?= Vista::e($job['errore'] ?? 'Errore') ?></span>
<?php elseif ($job['esito'] === 'in_corso'): ?>
  <?php // Un lavoro a tappe interrotto riprende da qui. ?>
  <a class="tag tag-neutral" href="?p=avanzamento&amp;job=<?= Vista::e($job['riferimento']) ?>">In corso — apri</a>
<?php elseif ($daRivedere > 0): ?>
  <a class="tag tag-accent-2" href="?p=rivedere&amp;job=<?= Vista::e($job['riferimento']) ?>"><?= Vista::numero($daRivedere) ?> da rivedere</a>
<?php else: ?>
  <span class="tag tag-accent">Completata</span>
<?php endif; ?>
