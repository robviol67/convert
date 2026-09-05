<?php
/**
 * Guscio di tutte le schermate.
 * @var string $vistaCorpo
 * @var array<string,mixed>|null $utente
 * @var bool|null $nudo  schermata senza barra applicativa (accesso)
 */
use Vblite\Convert\Vista;

$nudo ??= false;
$titolo = $titolo ?? 'vblite /convert';
?><!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=1240">
<title><?= Vista::e($titolo) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Source+Serif+4:ital,opsz,wght@0,8..60,300..700;1,8..60,300..700&display=swap">
<link rel="stylesheet" href="public/css/broadsheet.css">
<link rel="stylesheet" href="public/css/convert.css">
</head>
<body<?= $nudo ? '' : '' ?>>
<div class="sh">
<?php if (!$nudo && $utente !== null): ?>
  <div class="appbar">
    <a class="brand" href="?p=home">vblite<em>&#8202;/convert</em></a>
    <?php if (!empty($tipologiaCorrente)): ?>
      <span class="appbar-sep">/</span>
      <span class="appbar-tipologia"><?= Vista::e($tipologiaCorrente) ?></span>
    <?php endif; ?>
    <div class="rail" style="margin-left:auto">
      <a href="?p=storico"<?= ($paginaCorrente ?? '') === 'storico' ? ' aria-current="page"' : '' ?>>Storico</a>
      <a href="?p=utenti"<?= ($paginaCorrente ?? '') === 'utenti' ? ' aria-current="page"' : '' ?>>Utenti</a>
      <a href="?p=esci">Esci</a>
    </div>
    <a class="avatar" href="?p=utenti" title="<?= Vista::e($utente['nome']) ?>"><?= Vista::e(Vista::iniziali($utente['nome'])) ?></a>
  </div>
<?php endif; ?>

<?php if (!$nudo && $utente !== null && (int) $utente['deve_cambiare'] === 1 && ($paginaCorrente ?? '') !== 'utenti'): ?>
  <p class="avviso" style="margin:var(--space-4) var(--space-8) 0">
    La tua password è ancora quella provvisoria.
    <a href="?p=utenti">Cambiala adesso</a>.
  </p>
<?php endif; ?>

<?php require $vistaCorpo; ?>
</div>
</body>
</html>
