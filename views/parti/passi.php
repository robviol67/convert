<?php
/** I tre passi del wizard. @var int $passoCorrente 1..3 */
$etichette = [1 => 'Carica', 2 => 'Imposta', 3 => 'Scarica'];
?>
<div class="steps">
  <?php foreach ($etichette as $n => $etichetta):
      $stato = $n < $passoCorrente ? 'done' : ($n === $passoCorrente ? 'on' : ''); ?>
    <div class="step <?= $stato ?>"><b><?= $stato === 'done' ? '&#10003;' : $n ?></b><?= $etichetta ?></div>
  <?php endforeach; ?>
</div>
