<?php
declare(strict_types=1);
/**
 * Primo avvio: crea lo schema e i due utenti interni.
 *
 * Le password provvisorie si passano in ambiente, mai sulla riga di comando
 * (finirebbero nella cronologia della shell):
 *
 *   CONVERT_PASS_ROBVIOL='…' CONVERT_PASS_CLAUDIO='…' php bin/installa.php
 *
 * Se non sono impostate, ne genera due casuali e le stampa una volta sola.
 */

require __DIR__ . '/../vendor/autoload.php';

use Vblite\Convert\Auth;
use Vblite\Convert\Config;
use Vblite\Convert\Database;

$pdo = Database::pdo();

$utenti = [
    ['email' => 'robviol@insertsrl.com', 'nome' => 'Roberto Violi', 'env' => 'CONVERT_PASS_ROBVIOL'],
    ['email' => 'claudio@insertsrl.com', 'nome' => 'Claudio',       'env' => 'CONVERT_PASS_CLAUDIO'],
];

echo "vblite /convert — installazione\n";
echo 'database: ' . Config::percorsoDb() . "\n\n";

foreach ($utenti as $definizione) {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$definizione['email']]);
    if ($stmt->fetchColumn() !== false) {
        echo "· {$definizione['email']} esiste già, lasciato com'è\n";
        continue;
    }

    $password = getenv($definizione['env']) ?: bin2hex(random_bytes(8));
    Auth::creaUtente($definizione['email'], $definizione['nome'], $password);
    echo "· {$definizione['email']} creato";
    echo getenv($definizione['env']) ? " (password da ambiente)\n" : " — password provvisoria: {$password}\n";
}

foreach ([Config::cartellaIngresso(), Config::cartellaUscita(), dirname(Config::percorsoDb())] as $cartella) {
    if (!is_dir($cartella)) {
        mkdir($cartella, 0770, true);
    }
}

echo "\nLe password vanno cambiate al primo accesso (l'avviso compare in Utenti).\n";
