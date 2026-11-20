<?php
// POST /api/agendar.php  (JSON + cabeçalho X-CSRF-Token)
declare(strict_types=1);
require __DIR__ . '/../../app/agenda.php';
headers_seguranca();
exigir_metodo('POST');
exigir_csrf_api();

$chave = chave_ip('agendar');
if (limite_excedido($chave, (int)config('agendar_max_por_ip'), (int)config('agendar_janela_seg'))) {
    json_resposta(['erro' => 'Você fez muitos agendamentos seguidos. Fale com a recepção pelo WhatsApp.'], 429);
}

$in = corpo_json();

// Isca para robôs: campo invisível preenchido = resposta "ok" falsa, nada é salvo.
if (!empty($in['site'])) {
    registrar_tentativa($chave);
    json_resposta(['ok' => true, 'isca' => true]);
}

$proc = filter_var($in['procedimento'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$dent = filter_var($in['dentista'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
$data = data_valida($in['data'] ?? null);
$hora = (is_string($in['hora'] ?? null) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $in['hora'])) ? $in['hora'] : null;
$nome = texto_limpo($in['nome'] ?? '', 80);
$whats = normalizar_whatsapp($in['whatsapp'] ?? '');
$obs = texto_limpo($in['observacao'] ?? '', 200);

$erros = [];
if (!$proc || !procedimento($proc)) {
    $erros['procedimento'] = 'Escolha um tratamento.';
}
if ($dent === false) {
    $erros['dentista'] = 'Escolha um profissional.';
}
if (!$data || !$hora) {
    $erros['hora'] = 'Escolha dia e horário.';
}
if (mb_strlen($nome) < 3 || !preg_match('/^[\p{L}\p{M}\' .-]+$/u', $nome)) {
    $erros['nome'] = 'Escreva seu nome (só letras).';
}
if ($whats === '') {
    $erros['whatsapp'] = 'Confira o WhatsApp: DDD + número.';
}
if (($in['consentimento'] ?? false) !== true) {
    $erros['consentimento'] = 'Precisamos da sua autorização para guardar o agendamento.';
}
if ($erros) {
    json_resposta(['erro' => reset($erros), 'campos' => $erros], 422);
}

// Limite por paciente: evita uma pessoa (ou robô) ocupar a agenda inteira.
$st = db()->prepare("SELECT COUNT(*) FROM agendamentos WHERE paciente_whatsapp = :w AND status IN ('pendente','confirmado') AND inicio >= :agora");
$st->execute([':w' => $whats, ':agora' => date('Y-m-d H:i')]);
if ((int)$st->fetchColumn() >= (int)config('max_ativos_por_tel')) {
    registrar_tentativa($chave);
    json_resposta(['erro' => 'Este WhatsApp já tem consultas marcadas. Para mudar, fale com a recepção.', 'campos' => ['whatsapp' => 'Já há consultas para este número.']], 409);
}

registrar_tentativa($chave);
try {
    $ag = reservar($proc, $dent ?: null, $data, $hora, [
        'nome' => $nome, 'whatsapp' => $whats, 'observacao' => $obs, 'consentimento_em' => date('Y-m-d H:i:s'),
    ]);
} catch (HorarioIndisponivel) {
    json_resposta(['erro' => 'Alguém acabou de reservar este horário. Escolha outro, por favor.', 'ocupado' => true], 409);
}

auditar(null, 'site:criado', (int)$ag['id']);
if (random_int(1, 50) === 1) {
    anonimizar_antigos();
}
webhook('agendamento.criado', $ag);

$gestao = '/consulta.php?c=' . $ag['codigo'] . '&t=' . $ag['token'];
json_resposta([
    'ok'       => true,
    'codigo'   => $ag['codigo'],
    'nome'     => explode(' ', $ag['paciente_nome'])[0],
    'quando'   => data_extenso($ag['inicio']) . ', às ' . substr($ag['inicio'], 11),
    'dentista' => $ag['dentista'],
    'preparo'  => $ag['preparo'],
    'whatsapp' => link_whatsapp_clinica($ag),
    'gestao'   => $gestao,
    'ics'      => $gestao . '&ics=1',
]);
