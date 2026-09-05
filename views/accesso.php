<?php
/** 2a — Accesso. Utenti nominali su SQLite, nessuna registrazione pubblica. */
use Vblite\Convert\Auth;
use Vblite\Convert\Vista;
?>
<div style="display:grid;grid-template-columns:1.15fr 1fr;min-height:100vh">
  <div style="padding:var(--space-8);display:flex;flex-direction:column;position:relative">
    <span class="brand" style="font-size:24px">vblite<em>&#8202;/convert</em></span>
    <div style="margin-top:auto">
      <h1 class="h1" style="font-size:54px;max-width:16ch">Conversioni di tracciati.</h1>
      <p class="lede">Strumento interno Insert. L'accesso è nominale: ogni conversione resta associata a chi l'ha lanciata.</p>
    </div>
    <p class="mono" style="color:rgba(32,30,29,.45);margin-top:var(--space-8)">conversione tracciati · v1.1 · interno</p>
    <div class="reg" style="left:18px;top:18px"></div>
  </div>

  <div style="padding:var(--space-8);border-left:1px solid var(--color-divider);display:flex;flex-direction:column;justify-content:center;gap:var(--space-4)">
    <h2 class="h2" style="font-size:26px;margin-bottom:var(--space-2)">Accedi</h2>

    <?php if ($errore !== null): ?>
      <p class="avviso" role="alert"><?= Vista::e($errore) ?></p>
    <?php endif; ?>

    <form method="post" action="?p=accesso" style="display:flex;flex-direction:column;gap:var(--space-4)">
      <input type="hidden" name="csrf" value="<?= Vista::e(Auth::gettone()) ?>">
      <div class="field">
        <label for="email">Email</label>
        <input class="input" id="email" name="email" type="email" autocomplete="username" required autofocus
               value="<?= Vista::e($_POST['email'] ?? '') ?>">
      </div>
      <div class="field">
        <label for="password">Password</label>
        <input class="input" id="password" name="password" type="password" autocomplete="current-password" required>
      </div>
      <label style="display:flex;gap:10px;font-size:14.5px;align-items:center">
        <input type="checkbox" name="ricordami" value="1">Ricordami su questo computer
      </label>
      <button class="btn btn-primary btn-block" style="margin-top:var(--space-2)" type="submit">Entra</button>
    </form>

    <p style="font-size:13.5px;color:rgba(32,30,29,.55);margin:var(--space-2) 0 0">
      Password dimenticata? Scrivi a <span style="color:var(--color-accent-700)">claudio@insertsrl.com</span> —
      gli utenti si creano a mano in Utenti.
    </p>
  </div>
</div>
