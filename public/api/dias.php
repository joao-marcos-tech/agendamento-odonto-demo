<?php
// GET /api/dias.php?p=ID&d=ID -> quantos horários livres em cada dia da janela.
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
if (!$proc || $dent === false || $dent === null || !procedimento($proc)) {
    json_resposta(['erro' => 'Escolha um tratamento.'], 422);
}
$de = date('Y-m-d');
$ate = date('Y-m-d', strtotime('+' . ((int)config('janela_dias') - 1) . ' days'));
$disp = disponibilidade($proc, $dent ?: null, $de, $ate);

$dias = [];
for ($t = strtotime($de); date('Y-m-d', $t) <= $ate; $t = strtotime('+1 day', $t)) {
    $d = date('Y-m-d', $t);
    $dias[] = ['data' => $d, 'livres' => count($disp[$d] ?? [])];
}
json_resposta(['dias' => $dias]);
