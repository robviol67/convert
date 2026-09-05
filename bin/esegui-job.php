<?php
declare(strict_types=1);
/** Processo di sfondo: esegue una conversione gia' registrata in tabella jobs. */

require __DIR__ . '/../vendor/autoload.php';

use Vblite\Convert\Job;

$id = (int) ($argv[1] ?? 0);
if ($id <= 0) {
    fwrite(STDERR, "uso: esegui-job.php <job_id>\n");
    exit(1);
}

set_time_limit(0);
ignore_user_abort(true);
Job::esegui($id);
