<?php
/**
 * Stato dell'hosting, leggibile da chi è dentro: senza SSH è l'unico modo per
 * sapere perché una conversione non riesce.
 * @var list<array{nome:string,valore:string,ok:bool,bloccante:bool}> $controlli
 * @var list<array<string,string>> $errori
 */
use Vblite\Convert\Auth;
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

  <?php if ($errori !== []): ?>
    <div style="margin-top:var(--space-8)">
      <div class="tra" style="margin-bottom:var(--space-3)">
        <p class="kick" style="margin:0;color:var(--color-accent-2-700)">
          Ultimi errori · <?= count($errori) ?>
        </p>
        <form method="post" action="?p=svuota_errori" style="margin:0">
          <input type="hidden" name="csrf" value="<?= Vista::e(Auth::gettone()) ?>">
          <button class="btn btn-ghost" style="padding:4px 10px;font-size:13px">Svuota il registro</button>
        </form>
      </div>
      <div class="foglio">
        <table class="table" style="width:100%;font-size:13px">
          <thead><tr>
            <th style="width:80px">Codice</th>
            <th style="width:150px">Quando</th>
            <th>Che cosa</th>
            <th style="width:170px">Dove</th>
            <th style="width:90px">Pagina</th>
          </tr></thead>
          <tbody>
          <?php foreach ($errori as $errore): ?>
            <tr>
              <td class="mono" style="color:var(--color-accent-2-700)"><?= Vista::e($errore['codice'] ?? '—') ?></td>
              <td class="mono"><?= Vista::e($errore['quando'] ?? '—') ?></td>
              <td><?= Vista::e($errore['messaggio'] ?? '—') ?></td>
              <td class="mono"><?= Vista::e($errore['dove'] ?? '—') ?></td>
              <td class="mono"><?= Vista::e($errore['pagina'] ?? '—') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p style="font-size:13px;color:rgba(32,30,29,.55);margin:var(--space-3) 0 0">
        Il registro sta in <span class="mono">data/errori.log</span>, non raggiungibile dal web,
        e tiene le ultime 40 voci.
      </p>
    </div>
  <?php endif; ?>

  <div class="foot">
    <span class="mono muted">vblite /convert · diagnostica</span>
    <a href="?p=storico" style="font-size:14px">Storico</a>
  </div>
</div>
