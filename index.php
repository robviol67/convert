<?php
declare(strict_types=1);
/**
 * vblite /convert — router.
 * Strumento interno di Insert Srl per la conversione di tracciati dati.
 */

require __DIR__ . '/vendor/autoload.php';

use Vblite\Convert\Auth;
use Vblite\Convert\Config;
use Vblite\Convert\Conversioni\OctoScidoo\Raggruppatore;
use Vblite\Convert\Conversioni\Registro;
use Vblite\Convert\Database;
use Vblite\Convert\Installazione;
use Vblite\Convert\Job;
use Vblite\Convert\Vista;

mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Rome');

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

Auth::avvia();
Database::pdo();

$pagina = (string) ($_GET['p'] ?? 'home');
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Hosting senza SSH: il primo avvio si fa dal browser. Finche' la tabella users
// e' vuota ogni pagina porta li'; appena c'e' un utente, la rotta si chiude.
if (Installazione::serve() && $pagina !== 'installa') {
    header('Location: ?p=installa');
    exit;
}

/** Le POST che cambiano stato richiedono il gettone anti-CSRF. */
$verificaCsrf = static function () use ($metodo): void {
    if ($metodo === 'POST' && !Auth::verificaGettone($_POST['csrf'] ?? null)) {
        http_response_code(400);
        exit('Sessione scaduta. Ricarica la pagina.');
    }
};

switch ($pagina) {

    // ─────────────────────────────────────────────── Primo avvio
    case 'installa':
        if (!Installazione::serve()) {
            header('Location: ?p=accesso');
            exit;
        }
        $errori = [];
        if ($metodo === 'POST') {
            $verificaCsrf();
            $errori = Installazione::esegui($_POST);
            if ($errori === []) {
                header('Location: ?p=accesso');
                exit;
            }
        }
        Vista::rendi('installa', [
            'errori'    => $errori,
            'controlli' => Installazione::controlli(),
            'nudo'      => true,
        ]);
        break;

    case 'diagnostica':
        Auth::richiedi();
        Vista::rendi('diagnostica', ['controlli' => Installazione::controlli()]);
        break;

    // ─────────────────────────────────────────────── 2a · Accesso
    case 'accesso':
        if (Auth::utente() !== null) {
            header('Location: ?p=home');
            exit;
        }
        $errore = null;
        if ($metodo === 'POST') {
            $verificaCsrf();
            $esito = Auth::entra((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));
            if ($esito['ok']) {
                header('Location: ?p=home');
                exit;
            }
            $errore = $esito['errore'];
        }
        Vista::rendi('accesso', ['errore' => $errore, 'nudo' => true]);
        break;

    case 'esci':
        Auth::esci();
        header('Location: ?p=accesso');
        break;

    // ─────────────────────────────────────────────── 2b · Home, tipologie
    case 'home':
        $utente = Auth::richiedi();
        Vista::rendi('home', [
            'tipologie' => Registro::tutte(),
            'in_arrivo' => Registro::inArrivo(),
            'ultime'    => Job::storico($utente['id'], 'tutte', 6),
            'conteggi'  => contaPerTipologia(),
        ]);
        break;

    // ─────────────────────────────────────────────── 2c · Step 1, carica
    case 'carica':
        $utente      = Auth::richiedi();
        $chiave      = (string) ($_GET['t'] ?? 'octo_scidoo');
        $conversione = Registro::trova($chiave);
        if ($conversione === null) {
            header('Location: ?p=home');
            exit;
        }
        $errore = $_SESSION['errore_upload'] ?? null;
        unset($_SESSION['errore_upload']);
        Vista::rendi('carica', ['conversione' => $conversione, 'manifest' => $conversione->manifest(), 'errore' => $errore]);
        break;

    case 'upload':
        $utente = Auth::richiedi();
        $verificaCsrf();
        $chiave      = (string) ($_POST['tipologia'] ?? 'octo_scidoo');
        $conversione = Registro::trova($chiave);
        if ($conversione === null) {
            header('Location: ?p=home');
            exit;
        }

        $errore = validaUpload($_FILES['file'] ?? null);
        if ($errore === null) {
            $destinazione = Config::cartellaIngresso() . '/' . bin2hex(random_bytes(8)) . '.pdf';
            if (!is_dir(dirname($destinazione))) {
                mkdir(dirname($destinazione), 0770, true);
            }
            move_uploaded_file($_FILES['file']['tmp_name'], $destinazione);

            $verifica = $conversione->verifica($destinazione);
            if (!$verifica['ok']) {
                @unlink($destinazione);
                $errore = $verifica['motivo'];
            } elseif ($verifica['pagine'] > Config::MAX_PAGINE) {
                @unlink($destinazione);
                $errore = 'Il PDF ha ' . $verifica['pagine'] . ' pagine: il massimo è ' . Config::MAX_PAGINE . '.';
            } else {
                $_SESSION['bozza'] = [
                    'tipologia'      => $chiave,
                    'file'           => $destinazione,
                    'nome_originale' => $_FILES['file']['name'],
                    'byte'           => $_FILES['file']['size'],
                    'verifica'       => $verifica,
                ];
                header('Location: ?p=regole');
                exit;
            }
        }

        $_SESSION['errore_upload'] = $errore;
        header('Location: ?p=carica&t=' . urlencode($chiave));
        break;

    // ─────────────────────────────────────────────── 2d · Step 2, regole
    case 'regole':
        $utente = Auth::richiedi();
        $bozza  = $_SESSION['bozza'] ?? null;
        if ($bozza === null || !is_file($bozza['file'])) {
            header('Location: ?p=home');
            exit;
        }
        $conversione = Registro::trova($bozza['tipologia']);
        $analisi     = $conversione->analizza($bozza['file'], Raggruppatore::REGOLE_DEFAULT);
        $_SESSION['bozza']['analisi'] = $analisi;

        Vista::rendi('regole', [
            'conversione' => $conversione,
            'manifest'    => $conversione->manifest(),
            'bozza'       => $bozza,
            'analisi'     => $analisi,
            'preset'      => preset($utente['id'], $bozza['tipologia']),
        ]);
        break;

    case 'converti':
        $utente = Auth::richiedi();
        $verificaCsrf();
        $bozza = $_SESSION['bozza'] ?? null;
        if ($bozza === null || !is_file($bozza['file'])) {
            header('Location: ?p=home');
            exit;
        }
        $regole = regoleDaPost($_POST);

        if (($_POST['azione'] ?? '') === 'salva_preset') {
            Database::pdo()
                ->prepare('INSERT INTO preset (user_id, tipologia, nome, regole_json) VALUES (?, ?, ?, ?)')
                ->execute([$utente['id'], $bozza['tipologia'], (string) ($_POST['nome_preset'] ?? 'Le mie regole'), json_encode($regole, JSON_UNESCAPED_UNICODE)]);
            header('Location: ?p=regole');
            exit;
        }

        $job = Job::crea($utente['id'], $bozza['tipologia'], $bozza['file'], $bozza['nome_originale'], $regole);
        unset($_SESSION['bozza']);
        Job::avviaInBackground((int) $job['id']);

        header('Location: ?p=avanzamento&job=' . $job['riferimento']);
        break;

    // ─────────────────────────────────────────────── 2e · Conversione in corso
    case 'avanzamento':
        $utente = Auth::richiedi();
        $job    = jobRichiesto();
        if ($job['esito'] !== Job::IN_CORSO) {
            header('Location: ?p=pronto&job=' . $job['riferimento']);
            exit;
        }
        Vista::rendi('avanzamento', ['job' => $job, 'manifest' => Registro::trova($job['tipologia'])?->manifest()]);
        break;

    case 'stato':
        Auth::richiedi();
        $job = jobRichiesto();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'esito'           => $job['esito'],
            'passo'           => $job['passo'],
            'pagina_corrente' => (int) $job['pagina_corrente'],
            'pagine'          => (int) ($job['pagine'] ?? 0),
            'righe_lette'     => (int) ($job['righe_lette'] ?? 0),
            'righe_scritte'   => (int) ($job['righe_scritte'] ?? 0),
            'da_rivedere'     => count(Job::anomalie((int) $job['id'], 'correggi')),
            'errore'          => $job['errore'],
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'annulla':
        Auth::richiedi();
        $verificaCsrf();
        $job = jobRichiesto();
        Database::pdo()->prepare("UPDATE jobs SET esito = 'errore', errore = 'Annullata dall''utente' WHERE id = ?")->execute([$job['id']]);
        header('Location: ?p=storico');
        break;

    // ─────────────────────────────────────────────── 2f · Pronto
    case 'pronto':
        $utente = Auth::richiedi();
        $job    = jobRichiesto();
        Vista::rendi('pronto', [
            'job'         => $job,
            'anomalie'    => Job::anomalie((int) $job['id'], 'correggi'),
            'informative' => Job::anomalie((int) $job['id'], 'informativa'),
            'anteprima'   => anteprimaUscita($job),
        ]);
        break;

    case 'scarica':
        Auth::richiedi();
        $job = jobRichiesto();
        if ($job['file_out'] === null || !is_file($job['file_out'])) {
            http_response_code(404);
            exit('File non disponibile.');
        }
        $tipo = str_ends_with($job['file_out'], '.csv')
            ? 'text/csv'
            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        header('Content-Type: ' . $tipo);
        header('Content-Disposition: attachment; filename="' . $job['nome_uscita'] . '"');
        header('Content-Length: ' . filesize($job['file_out']));
        readfile($job['file_out']);
        break;

    // ─────────────────────────────────────────────── 2g · Da rivedere
    case 'rivedere':
        $utente = Auth::richiedi();
        $job    = jobRichiesto();
        Vista::rendi('rivedere', [
            'job'      => $job,
            'anomalie' => Job::anomalie((int) $job['id']),
        ]);
        break;

    case 'correggi':
        Auth::richiedi();
        $verificaCsrf();
        $job = jobRichiesto();
        $pdo = Database::pdo();
        $agg = $pdo->prepare('UPDATE anomalie SET valore_corretto = ? WHERE id = ? AND job_id = ?');
        foreach ((array) ($_POST['correzione'] ?? []) as $idAnomalia => $valore) {
            $agg->execute([trim((string) $valore) !== '' ? trim((string) $valore) : null, (int) $idAnomalia, $job['id']]);
        }
        foreach ((array) ($_POST['salta'] ?? []) as $idAnomalia => $_) {
            $pdo->prepare('UPDATE anomalie SET risolta = 1 WHERE id = ? AND job_id = ?')->execute([(int) $idAnomalia, $job['id']]);
        }
        Job::applicaCorrezioni((int) $job['id']);
        header('Location: ?p=pronto&job=' . $job['riferimento']);
        break;

    // ─────────────────────────────────────────────── 2h · Storico
    case 'storico':
        $utente = Auth::richiedi();
        $filtro = (string) ($_GET['f'] ?? 'tutte');
        Vista::rendi('storico', [
            'filtro'  => $filtro,
            'jobs'    => Job::storico($utente['id'], $filtro),
            'totali'  => totaliArchivio(),
        ]);
        break;

    case 'rifai':
        $utente = Auth::richiedi();
        $verificaCsrf();
        $job = jobRichiesto();
        if (!is_file($job['file_in'])) {
            http_response_code(410);
            exit('Il PDF di partenza non è più disponibile.');
        }
        $nuovo = Job::crea((int) $utente['id'], $job['tipologia'], $job['file_in'], $job['nome_originale'], json_decode((string) $job['regole_json'], true) ?: []);
        Job::avviaInBackground((int) $nuovo['id']);
        header('Location: ?p=avanzamento&job=' . $nuovo['riferimento']);
        break;

    // ─────────────────────────────────────────────── 2i · Utenti
    case 'utenti':
        $utente = Auth::richiedi();
        Vista::rendi('utenti', [
            'utenti'  => Database::pdo()->query(
                'SELECT u.*, (SELECT COUNT(*) FROM jobs j WHERE j.user_id = u.id) AS conversioni
                 FROM users u ORDER BY u.creato_il'
            )->fetchAll(),
            'avviso'  => $_SESSION['avviso'] ?? null,
        ]);
        unset($_SESSION['avviso']);
        break;

    case 'utente_nuovo':
        $utente = Auth::richiedi();
        $verificaCsrf();
        $password = (string) ($_POST['password'] ?? '');
        if (strlen($password) < 10) {
            $_SESSION['avviso'] = 'La password provvisoria deve avere almeno 10 caratteri.';
        } else {
            try {
                Auth::creaUtente((string) $_POST['email'], (string) $_POST['nome'], $password, (string) ($_POST['ruolo'] ?? 'admin'));
                $_SESSION['avviso'] = 'Utente creato. La password va cambiata al primo accesso.';
            } catch (\PDOException) {
                $_SESSION['avviso'] = 'Esiste già un utente con questa email.';
            }
        }
        header('Location: ?p=utenti');
        break;

    case 'utente_reimposta':
        $utente = Auth::richiedi();
        $verificaCsrf();
        $password = (string) ($_POST['password'] ?? '');
        if (strlen($password) < 10) {
            $_SESSION['avviso'] = 'La password provvisoria deve avere almeno 10 caratteri.';
        } else {
            Auth::reimpostaPassword((int) $_POST['id'], $password);
            $_SESSION['avviso'] = 'Password reimpostata.';
        }
        header('Location: ?p=utenti');
        break;

    case 'cambia_password':
        $utente = Auth::richiedi();
        $verificaCsrf();
        $password = (string) ($_POST['password'] ?? '');
        if (strlen($password) < 10) {
            $_SESSION['avviso'] = 'La password deve avere almeno 10 caratteri.';
        } else {
            Auth::cambiaPassword((int) $utente['id'], $password);
            $_SESSION['avviso'] = 'Password aggiornata.';
        }
        header('Location: ?p=utenti');
        break;

    default:
        http_response_code(404);
        Vista::rendi('errore', ['messaggio' => 'Pagina non trovata.']);
}

// ─────────────────────────────────────────────────── funzioni di supporto

/** @return array<string,mixed> */
function jobRichiesto(): array
{
    $riferimento = (string) ($_GET['job'] ?? $_POST['job'] ?? '');
    $job = Job::perRiferimento($riferimento);
    if ($job === null) {
        http_response_code(404);
        exit('Conversione non trovata.');
    }

    return $job;
}

/** @param array<string,mixed>|null $file */
function validaUpload(?array $file): ?string
{
    if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return 'Nessun file caricato.';
    }
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        return 'Il file supera il limite del server. Massimo ' . (Config::MAX_BYTE / 1024 / 1024) . ' MB.';
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return 'Caricamento non riuscito (codice ' . $file['error'] . ').';
    }
    if ($file['size'] > Config::MAX_BYTE) {
        return 'Il file pesa ' . round($file['size'] / 1024 / 1024) . ' MB: il massimo è ' . (Config::MAX_BYTE / 1024 / 1024) . ' MB.';
    }

    $tipo = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if ($tipo !== 'application/pdf') {
        return 'Serve un PDF: questo file è ' . $tipo . '.';
    }

    return null;
}

/**
 * @param array<string,mixed> $post
 * @return array<string,mixed>
 */
function regoleDaPost(array $post): array
{
    $regole = Raggruppatore::REGOLE_DEFAULT;
    foreach (['una_riga_per_prenotazione', 'ripulisci_commenti', 'deduci_categoria_camera', 'salta_annullate', 'bambini_da_supplementi'] as $flag) {
        $regole[$flag] = isset($post['regole'][$flag]);
    }
    $regole['sorgente_camera'] = in_array($post['sorgente_camera'] ?? '', ['cam', 'gruppo', 'vuoto'], true)
        ? $post['sorgente_camera']
        : 'cam';
    $regole['formato']     = ($post['formato'] ?? 'xlsx') === 'csv' ? 'csv' : 'xlsx';
    $regole['periodo_dal'] = trim((string) ($post['periodo_dal'] ?? '')) ?: null;
    $regole['periodo_al']  = trim((string) ($post['periodo_al'] ?? '')) ?: null;

    return $regole;
}

/** @return array<string,int> conversioni per tipologia, per la tessera in home */
function contaPerTipologia(): array
{
    $righe = Database::pdo()->query(
        "SELECT tipologia, COUNT(*) AS n, MAX(creato_il) AS ultima FROM jobs GROUP BY tipologia"
    )->fetchAll();
    $out = [];
    foreach ($righe as $riga) {
        $out[$riga['tipologia']] = ['n' => (int) $riga['n'], 'ultima' => $riga['ultima']];
    }

    return $out;
}

/** @return array{conversioni:int,byte:int} */
function totaliArchivio(): array
{
    $riga = Database::pdo()->query('SELECT COUNT(*) AS n, COALESCE(SUM(byte_out), 0) AS b FROM jobs')->fetch();

    return ['conversioni' => (int) $riga['n'], 'byte' => (int) $riga['b']];
}

/** @return list<array<string,mixed>> */
function preset(int $userId, string $tipologia): array
{
    $stmt = Database::pdo()->prepare('SELECT * FROM preset WHERE user_id = ? AND tipologia = ? ORDER BY creato_il DESC');
    $stmt->execute([$userId, $tipologia]);

    return $stmt->fetchAll();
}

/**
 * Prime righe del file prodotto, per l'anteprima in 2f. Si legge il file vero:
 * cosi' l'anteprima mostra quello che l'utente scarichera', non una simulazione.
 *
 * @param array<string,mixed> $job
 * @return array{testate:list<string>,righe:list<list<string>>}
 */
function anteprimaUscita(array $job, int $quante = 4): array
{
    if ($job['file_out'] === null || !is_file($job['file_out']) || !str_ends_with($job['file_out'], '.xlsx')) {
        return ['testate' => [], 'righe' => []];
    }

    $lettore = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($job['file_out']);
    $lettore->setReadDataOnly(true);
    $foglio = $lettore->load($job['file_out']);
    $sh     = $foglio->getSheet(0);

    $colonne = ['B', 'C', 'D', 'E', 'F', 'G', 'I', 'L', 'O', 'P', 'T'];
    $testate = [];
    foreach ($colonne as $lettera) {
        $testate[] = trim((string) $sh->getCell($lettera . '1')->getValue());
    }

    $righe = [];
    for ($r = 2; $r < 2 + $quante; $r++) {
        if ($sh->getCell('B' . $r)->getValue() === null) {
            break;
        }
        $riga = [];
        foreach ($colonne as $lettera) {
            $cella  = $sh->getCell($lettera . $r);
            $valore = $cella->getValue();
            if ($valore !== null && in_array($lettera, ['E', 'F'], true)) {
                $valore = Vista::data((float) $valore);
            } elseif ($valore !== null && $lettera === 'T') {
                $valore = Vista::valuta((float) $valore);
            }
            $riga[] = (string) ($valore ?? '');
        }
        $righe[] = $riga;
    }
    $foglio->disconnectWorksheets();

    return ['testate' => $testate, 'righe' => $righe];
}
