<?php
/**
 * 2i — Utenti. Pochi, nominali, creati a mano: nessuna registrazione pubblica.
 * @var list<array<string,mixed>> $utenti
 */
use Vblite\Convert\Auth;
use Vblite\Convert\Config;
use Vblite\Convert\Vista;

?>
<div class="body" style="padding-top:var(--space-6)">
  <div class="tra">
    <div>
      <h2 class="h2">Utenti</h2>
      <p class="lede" style="font-size:16px">Pochi, nominali, creati a mano. Nessuna registrazione pubblica.</p>
    </div>
    <button class="btn btn-primary" type="button" onclick="document.getElementById('nuovo').hidden = !document.getElementById('nuovo').hidden">Nuovo utente</button>
  </div>

  <?php if ($avviso !== null): ?>
    <p class="avviso buono" style="margin:var(--space-4) 0 0"><?= Vista::e($avviso) ?></p>
  <?php endif; ?>

  <?php if ((int) $utente['deve_cambiare'] === 1): ?>
    <form class="avviso" method="post" action="?p=cambia_password" style="margin:var(--space-4) 0 0;display:flex;gap:var(--space-3);align-items:center">
      <input type="hidden" name="csrf" value="<?= Vista::e(Auth::gettone()) ?>">
      <span>La tua password è ancora quella provvisoria: cambiala adesso.</span>
      <input class="input" type="password" name="password" placeholder="Nuova password" minlength="10" required style="width:220px">
      <button class="btn btn-primary" type="submit">Cambia</button>
    </form>
  <?php endif; ?>

  <form id="nuovo" hidden method="post" action="?p=utente_nuovo" class="foglio" style="margin-top:var(--space-4);display:grid;grid-template-columns:1.2fr 1fr 1fr auto;gap:var(--space-3);align-items:end">
    <input type="hidden" name="csrf" value="<?= Vista::e(Auth::gettone()) ?>">
    <div class="field"><label for="n-email">Email</label><input class="input" id="n-email" name="email" type="email" required></div>
    <div class="field"><label for="n-nome">Nome</label><input class="input" id="n-nome" name="nome" required></div>
    <div class="field"><label for="n-pass">Password provvisoria</label><input class="input" id="n-pass" name="password" minlength="10" required></div>
    <button class="btn btn-primary" type="submit">Crea</button>
  </form>

  <div style="margin-top:var(--space-6)">
    <table class="table" style="width:100%;font-size:13.5px">
      <thead><tr>
        <th>Email</th>
        <th style="width:170px">Nome</th>
        <th style="width:210px">Ruolo</th>
        <th style="width:110px;text-align:right">Conversioni</th>
        <th style="width:140px">Ultimo accesso</th>
        <th style="width:260px"></th>
      </tr></thead>
      <tbody>
      <?php foreach ($utenti as $u): ?>
        <tr>
          <td class="mono"><?= Vista::e($u['email']) ?></td>
          <td><?= Vista::e($u['nome']) ?></td>
          <td>
            <div class="tags">
              <span class="tag tag-accent"><?= $u['ruolo'] === 'admin' ? 'Amministratore' : Vista::e($u['ruolo']) ?></span>
              <?php if ((int) $u['deve_cambiare'] === 1): ?>
                <span class="tag tag-outline">password provvisoria</span>
              <?php endif; ?>
            </div>
          </td>
          <td class="mono" style="text-align:right"><?= Vista::numero((int) $u['conversioni']) ?></td>
          <td class="mono"><?= Vista::e(Vista::quando($u['ultimo_accesso'])) ?></td>
          <td style="text-align:right">
            <form method="post" action="?p=utente_reimposta" style="display:flex;gap:6px;justify-content:flex-end">
              <input type="hidden" name="csrf" value="<?= Vista::e(Auth::gettone()) ?>">
              <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
              <input class="input" name="password" placeholder="Nuova provvisoria" minlength="10" required
                     style="padding:4px 8px;font-size:13px;width:150px">
              <button class="btn btn-ghost" style="padding:4px 8px;font-size:13px" type="submit">Reimposta</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div style="margin-top:var(--space-8);display:grid;grid-template-columns:1fr 1fr;gap:var(--space-8);max-width:900px">
    <div>
      <p class="kick" style="margin:0 0 var(--space-3)">Dove stanno i dati</p>
      <p class="mono" style="font-size:12.5px;line-height:1.8;color:rgba(32,30,29,.6);margin:0">
        SQLite · <?= Vista::e(Config::percorsoDb()) ?><br>
        tabella users (id, email, password_hash, nome, ruolo, creato_il)<br>
        tabella jobs (id, user_id, tipologia, file_in, file_out, esito)
      </p>
    </div>
    <div>
      <p class="kick" style="margin:0 0 var(--space-3)">Stato del server</p>
      <p style="font-size:14px;line-height:1.6;color:rgba(32,30,29,.75);margin:0 0 var(--space-6)">
        Se una conversione non riesce, la causa è quasi sempre un limite dell'hosting:
        <a href="?p=diagnostica">vedi la diagnostica</a>.
      </p>

      <p class="kick" style="margin:0 0 var(--space-3)">Nota per lo sviluppo</p>
      <p style="font-size:14px;line-height:1.6;color:rgba(32,30,29,.7);margin:0">
        Le password sono salvate come hash Argon2id, mai in chiaro. Al primo accesso
        l'utente deve cambiare la password provvisoria: finché non lo fa, l'avviso
        resta in cima a questa pagina.
      </p>
    </div>
  </div>

  <div class="foot">
    <span class="mono" style="color:rgba(32,30,29,.5)"><?= count($utenti) ?> utenti attivi</span>
    <a href="?p=home" style="font-size:14px">Torna alle tipologie</a>
  </div>
</div>
