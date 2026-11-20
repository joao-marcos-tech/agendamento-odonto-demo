<?php
// POST /api/cancelar.php  {codigo, token}  — o próprio paciente cancela pelo link secreto.
declare(strict_types=1);
require __DIR__ . '/../../app/agenda.php';
headers_seguranca();
exigir_metodo('POST');
exigir_csrf_api();

$chave = chave_ip('cancelar');
if (limite_excedido($chave, 10, 900)) {
    json_resposta(['erro' => 'Muitas tentativas. Espere alguns minutos.'], 429);
}
registrar_tentativa($chave);

$in = corpo_json(512);
$ag = agendamento_por_token((string)($in['codigo'] ?? ''), (string)($in['token'] ?? ''));
if (!$ag) {
    json_resposta(['erro' => 'Link inválido ou expirado.'], 404);
}
if (!in_array($ag['status'], STATUS_ATIVOS, true) || $ag['inicio'] <= date('Y-m-d H:i')) {
    json_resposta(['erro' => 'Esta consulta não pode mais ser cancelada pelo site. Fale com a recepção.'], 409);
}
mudar_status((int)$ag['id'], 'cancelado', null, 'paciente');
json_resposta(['ok' => true]);
