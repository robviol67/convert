<?php
/**
 * Primo avvio · schermata unica.
 * @var list<string> $errori
 * @var list<array{nome:string,valore:string,ok:bool,bloccante:bool}> $controlli
 */
use Vblite\Convert\Auth;
use Vblite\Convert\Installazione;
use Vblite\Convert\Vista;

$bloccanti = array_filter($controlli, static fn(array $c): bool => !$c['ok'] && $c['bloccante']);
?>
<div class="body" style="padding-top:var(--space-8)">
  <div class="reg" style="left:18px;top:18px"></div>

  <p class="kick" style="margin:0 0 var(--space-2)">Primo avvio</p>
  <h1 class="h1" style="font-size:44px;max-width:18ch">Mettiamo in piedi <em style="font-style:italic;font-weight:400">/convert</em>.</h1>
  <p class="lede">Due account interni, niente registrazione pubblica. Questa pagina
    esiste solo adesso: appena c'è un utente, sparisce da sola.</p>

  <div style="display:grid;grid-template-columns:1fr 420px;gap:var(--space-8);margin-top:var(--space-8)">

    <div>
      <?php if ($errori !== []): ?>
        <div class="avviso" role="alert" style="margin:0 0 var(--space-4)">
          <?php foreach ($errori as $errore): ?>
            <div><?= Vista::e($errore) ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($bloccanti !== []): ?>
        <div class="avviso" role="alert" style="margin:0 0 var(--space-4)">
          L'hosting non soddisfa <?= count($bloccanti) ?> requisiti: vedi la colonna a destra.
          Puoi installare lo stesso, ma la conversione potrebbe non riuscire.
        </div>
      <?php endif; ?>

      <form method="post" action="?p=installa">
        <input type="hidden" name="csrf" value="<?= Vista::e(Auth::gettone()) ?>">

        <?php foreach (Installazione::UTENTI_PREVISTI as $i => $previsto): ?>
          <div class="foglio" style="margin-bottom:var(--space-4)">
            <p class="kick" style="margin:0 0 var(--space-3)">Utente <?= $i + 1 ?></p>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-3)">
              <div class="field">
                <label for="email<?= $i ?>">Email</label>
                <input class="input" id="email<?= $i ?>" type="email" name="email[<?= $i ?>]"
                       value="<?= Vista::e($previsto['email']) ?>" required>
              </div>
              <div class="field">
                <label for="nome<?= $i ?>">Nome</label>
                <input class="input" id="nome<?= $i ?>" name="nome[<?= $i ?>]"
                       value="<?= Vista::e($previsto['nome']) ?>" required>
              </div>
            </div>
            <div class="field" style="margin-top:var(--space-3)">
              <label for="password<?= $i ?>">Password provvisoria — almeno <?= Installazione::LUNGHEZZA_MINIMA ?> caratteri</label>
              <input class="input" id="password<?= $i ?>" type="password" name="password[<?= $i ?>]"
                     minlength="<?= Installazione::LUNGHEZZA_MINIMA ?>" required autocomplete="new-password">
            </div>
          </div>
        <?php endforeach; ?>

        <button class="btn btn-primary btn-block" type="submit">Crea gli account ed entra</button>
        <p style="font-size:13.5px;color:rgba(32,30,29,.55);margin:var(--space-3) 0 0">
          Le password si salvano come hash, mai in chiaro. Al primo accesso
          l'applicazione chiede di cambiarle.
        </p>
      </form>
    </div>

    <div>
      <p class="kick" style="margin:0 0 var(--space-3)">L'hosting regge?</p>
      <div class="spec">
        <?php foreach ($controlli as $controllo): ?>
          <div>
            <span class="et" style="color:<?= $controllo['ok'] ? 'rgba(32,30,29,.5)' : 'var(--color-accent-2-700)' ?>">
              <?= $controllo['ok'] ? '&#10003;' : ($controllo['bloccante'] ? '&#10007;' : '!') ?>
              <?= Vista::e($controllo['nome']) ?>
            </span>
            <span class="va mono"><?= Vista::e($controllo['valore']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
      <p style="font-size:13px;color:rgba(32,30,29,.55);margin:var(--space-4) 0 0">
        I limiti di upload e di tempo li alzano <span class="mono">.htaccess</span> e
        <span class="mono">.user.ini</span>. Se restano bassi, l'hosting li ha bloccati
        e vanno alzati dal pannello.
      </p>
    </div>
  </div>

  <div class="foot">
    <span class="mono muted">vblite /convert · primo avvio</span>
    <span class="mono muted">PHP <?= PHP_VERSION ?></span>
  </div>
</div>
