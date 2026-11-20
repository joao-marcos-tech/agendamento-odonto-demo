<?php
// POST /admin/api/acao.php — confirmar, cancelar, concluir, faltou, remarcar ou criar (encaixe).
declare(strict_types=1);
require __DIR__ . '/../../../app/agenda.php';
headers_seguranca();
exigir_metodo('POST');
$u = exigir_login(true);
exigir_csrf_api();

$in = corpo_json();
$acao = is_string($in['acao'] ?? null) ? $in['acao'] : '';
$mapa = ['confirmar' => 'confirmado', 'cancelar' => 'cancelado', 'concluir' => 'concluido', 'faltou' => 'faltou'];

try {
    if (isset($mapa[$acao])) {
        $id = filter_var($in['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $ag = $id ? agendamento($id) : null;
        if (!$ag) {
            json_resposta(['erro' => 'Agendamento não encontrado.'], 404);
        }
        if (!in_array($ag['status'], STATUS_ATIVOS, true) && in_array($mapa[$acao], STATUS_ATIVOS, true)) {
            json_resposta(['erro' => 'Esta consulta já saiu da agenda. Crie um novo agendamento.'], 409);
        }
        mudar_status($id, $mapa[$acao], (int)$u['id']);
        json_resposta(['ok' => true]);
    }

    if ($acao === 'remarcar') {
        $id = filter_var($in['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $data = data_valida($in['data'] ?? null);
        $hora = (is_string($in['hora'] ?? null) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $in['hora'])) ? $in['hora'] : null;
        $dent = filter_var($in['dentista'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) ?: null;
        if (!$id || !$data || !$hora || $data < date('Y-m-d')) {
            json_resposta(['erro' => 'Escolha o novo dia e horário.'], 422);
        }
        remarcar($id, $data, $hora, $dent, (int)$u['id']);
        json_resposta(['ok' => true]);
    }

    if ($acao === 'criar') {
        $proc = filter_var($in['procedimento'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $dent = filter_var($in['dentista'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) ?: null;
        $data = data_valida($in['data'] ?? null);
        $hora = (is_string($in['hora'] ?? null) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $in['hora'])) ? $in['hora'] : null;
        $nome = texto_limpo($in['nome'] ?? '', 80);
        $whats = normalizar_whatsapp($in['whatsapp'] ?? '');
        if (!$proc || !procedimento($proc) || !$data || !$hora || mb_strlen($nome) < 3 || $whats === '') {
            json_resposta(['erro' => 'Preencha tratamento, dia, horário, nome e WhatsApp válido.'], 422);
        }
        $ag = reservar($proc, $dent, $data, $hora, [
            'nome' => $nome, 'whatsapp' => $whats, 'observacao' => texto_limpo($in['observacao'] ?? '', 200),
            'consentimento_em' => null, // marcado pela recepção (ex.: por telefone)
        ], true);
        auditar((int)$u['id'], 'painel:criado', (int)$ag['id']);
        webhook('agendamento.criado', $ag);
        json_resposta(['ok' => true, 'id' => (int)$ag['id']]);
    }
} catch (HorarioIndisponivel) {
    json_resposta(['erro' => 'Esse horário acabou de ser ocupado. Escolha outro.', 'ocupado' => true], 409);
}

json_resposta(['erro' => 'Ação desconhecida.'], 422);
