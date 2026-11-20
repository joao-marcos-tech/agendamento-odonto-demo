<?php
// GET /admin/api/horarios.php?p=&d=&data=&ignorar= — horários livres para encaixe/remarcação.
declare(strict_types=1);
require __DIR__ . '/../../../app/agenda.php';
headers_seguranca();
exigir_metodo('GET');
exigir_login(true);

$proc = filter_input(INPUT_GET, 'p', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$dent = filter_input(INPUT_GET, 'd', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) ?: null;
$ign = filter_input(INPUT_GET, 'ignorar', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$data = data_valida($_GET['data'] ?? null);
if (!$proc || !$data || $data < date('Y-m-d')) {
    json_resposta(['erro' => 'Dados inválidos.'], 422);
}
$disp = disponibilidade($proc, $dent, $data, $data, $ign, true);
$lista = [];
foreach ($disp[$data] ?? [] as $hora => $ids) {
    $lista[] = ['hora' => $hora, 'dentistas' => $ids];
}
json_resposta(['horarios' => $lista]);
