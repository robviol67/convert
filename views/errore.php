<?php
/** Schermata di errore generica. */
use Vblite\Convert\Vista;
?>
<div class="body" style="padding-top:var(--space-8)">
  <h2 class="h2"><?= Vista::e($messaggio) ?></h2>
  <p class="lede"><a href="?p=home">Torna alle tipologie</a></p>
</div>
