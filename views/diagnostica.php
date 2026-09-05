<?php
/**
 * Stato dell'hosting, leggibile da chi è dentro: senza SSH è l'unico modo per
 * sapere perché una conversione non riesce.
 * @var list<array{nome:string,valore:string,ok:bool,bloccante:bool}> $controlli
 */
use Vblite\Convert\Config;
use Vblite\Convert\Vista;

$rotti = array_filter($controlli, static fn(array $c): bool => !$c['ok'] && $c['bloccante']);
?>
<div class="body" style="padding-top:var(--space-6)">
  <div class="tra">
    <div>
      <p class="kick" style="margin:0 0 8px<?= $rotti !== [] ? ';color:var(--color-accent-2-700)' : '' ?>">
        <?= $rotti === [] ? 'Tutto a posto' : count($rotti) . ' punti da sistemare' ?>
      </p>
      <h2 class="h2">Stato dell'hosting</h2>
      <p class="lede" style="font-size:16px">Cosa serve a questa applicazione e cosa
        offre il server. Se una conversione non riesce, la risposta è quasi sempre qui.</p>
    </div>
    <a class="btn btn-ghost" href="?p=home">Torna alle tipologie</a>
  </div>

  <div style="display:grid;grid-template-columns:1fr 420px;gap:var(--space-8);margin-top:var(--space-6)">
    <div class="foglio">
      <table class="table" style="width:100%;font-size:13.5px">
        <thead><tr><th>Requisito</th><th style="width:220px;text-align:right">Sul server</th></tr></thead>
        <tbody>
        <?php foreach ($controlli as $c): ?>
          <tr>
            <td>
              <span style="color:<?= $c['ok'] ? 'var(--color-accent-700)' : ($c['bloccante'] ? 'var(--color-accent-2-700)' : 'rgba(32,30,29,.5)') ?>">
                <?= $c['ok'] ? '&#10003;' : ($c['bloccante'] ? '&#10007;' : '!') ?>
              </span>
              <?= Vista::e($c['nome']) ?>
            </td>
            <td class="mono" style="text-align:right"><?= Vista::e($c['valore']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div>
      <p class="kick" style="margin:0 0 var(--space-3)">Dove stanno i dati</p>
      <p class="mono" style="font-size:12px;line-height:1.7;color:rgba(32,30,29,.55);margin:0;word-break:break-all">
        <?= Vista::e(Config::percorsoDb()) ?><br>
        <?= Vista::e(Config::cartellaIngresso()) ?><br>
        <?= Vista::e(Config::cartellaUscita()) ?>
      </p>

      <p class="kick" style="margin:var(--space-6) 0 var(--space-3)">Se i limiti sono bassi</p>
      <p style="font-size:13.5px;line-height:1.6;color:rgba(32,30,29,.7);margin:0">
        <span class="mono">.htaccess</span> e <span class="mono">.user.ini</span> provano
        già ad alzarli. Se restano bassi, l'hosting li ha bloccati: vanno alzati dal
        pannello di controllo, sezione PHP.
      </p>

      <p class="kick" style="margin:var(--space-6) 0 var(--space-3)">Versione</p>
      <div class="spec">
        <div><span class="et">PHP</span><span class="va mono"><?= PHP_VERSION ?></span></div>
        <div><span class="et">SQLite</span><span class="va mono"><?= Vista::e(\SQLite3::version()['versionString'] ?? '—') ?></span></div>
      </div>
    </div>
  </div>

  <div class="foot">
    <span class="mono muted">vblite /convert · diagnostica</span>
    <a href="?p=storico" style="font-size:14px">Storico</a>
  </div>
</div>
