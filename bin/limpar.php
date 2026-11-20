<?php
// LGPD: anonimiza pacientes de consultas antigas. Rode 1x por dia (cron).
// Ex.: 0 3 * * * php /caminho/bin/limpar.php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../app/agenda.php';
$n = anonimizar_antigos();
db()->prepare('DELETE FROM tentativas WHERE criado_em < ?')->execute([time() - 86400]);
echo "Anonimizados: $n\n";
