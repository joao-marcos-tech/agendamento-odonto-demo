<?php
// GET /api/horarios.php?p=ID&d=ID&data=AAAA-MM-DD -> horários livres do dia.
declare(strict_types=1);
require __DIR__ . '/../../app/agenda.php';
headers_seguranca();
exigir_metodo('GET');

$chave = chave_ip('consulta');
if (limite_excedido($chave, 240, 600)) {
    json_resposta(['erro' => 'Muitas consultas seguidas. Espere um pouco.'], 429);
}
registrar_tentativa($chave);

$proc = filter_input(INPUT_GET, 'p', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$dent = filter_input(INPUT_GET, 'd', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
$data = data_valida($_GET['data'] ?? null);
$limite = date('Y-m-d', strtotime('+' . ((int)config('janela_dias') - 1) . ' days'));
if (!$proc || $dent === false || $dent === null || !$data || $data < date('Y-m-d') || $data > $limite || !procedimento($proc)) {
    json_resposta(['erro' => 'Dia inválido.'], 422);
}
$disp = disponibilidade($proc, $dent ?: null, $data, $data);
$lista = [];
foreach ($disp[$data] ?? [] as $hora => $ids) {
    $lista[] = ['hora' => $hora, 'dentistas' => $ids];
}
json_resposta(['data' => $data, 'horarios' => $lista]);
