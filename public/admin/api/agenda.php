<?php
// GET /admin/api/agenda.php?data=AAAA-MM-DD — agenda do dia + fila de pendentes.
declare(strict_types=1);
require __DIR__ . '/../../../app/agenda.php';
headers_seguranca();
exigir_metodo('GET');
exigir_login(true);

$data = data_valida($_GET['data'] ?? null) ?? date('Y-m-d');

function para_json(array $a): array
{
    return [
        'id'           => (int)$a['id'],
        'codigo'       => $a['codigo'],
        'inicio'       => substr($a['inicio'], 11),
        'fim'          => substr($a['fim'], 11),
        'data'         => substr($a['inicio'], 0, 10),
        'dataExtenso'  => data_extenso($a['inicio']),
        'status'       => $a['status'],
        'statusNome'   => STATUS[$a['status']],
        'procedimento' => $a['procedimento'],
        'procedimentoId' => (int)$a['procedimento_id'],
        'dentistaId'   => (int)$a['dentista_id'],
        'dentista'     => $a['dentista'],
        'paciente'     => $a['paciente_nome'],
        'telefone'     => $a['paciente_whatsapp'] ? telefone_bonito($a['paciente_whatsapp']) : '',
        'whatsapp'     => $a['paciente_whatsapp'] ? link_whatsapp_paciente($a) : '',
        'observacao'   => $a['observacao'],
        'origem'       => $a['origem'],
        'criadoEm'     => $a['criado_em'],
    ];
}

$sql = 'SELECT a.*, p.nome AS procedimento, d.nome AS dentista FROM agendamentos a
        JOIN procedimentos p ON p.id = a.procedimento_id JOIN dentistas d ON d.id = a.dentista_id';

$st = db()->prepare($sql . ' WHERE a.inicio >= :de AND a.inicio < :ate ORDER BY a.inicio');
$st->execute([':de' => $data . ' 00:00', ':ate' => $data . ' 24:00']);
$doDia = array_map('para_json', $st->fetchAll());

$st = db()->prepare($sql . " WHERE a.status = 'pendente' AND a.inicio >= :agora ORDER BY a.inicio LIMIT 50");
$st->execute([':agora' => date('Y-m-d H:i')]);
$pendentes = array_map('para_json', $st->fetchAll());

// Expediente do dia (para desenhar a grade).
$dow = (int)(new DateTimeImmutable($data))->format('w');
$st = db()->prepare('SELECT dentista_id, inicio, fim FROM expediente WHERE dia_semana = :d ORDER BY inicio');
$st->execute([':d' => $dow]);
$exp = [];
foreach ($st as $r) {
    $exp[(int)$r['dentista_id']][] = [$r['inicio'], $r['fim']];
}
$st = db()->prepare('SELECT id, dentista_id, inicio, fim, motivo FROM bloqueios WHERE fim > :de AND inicio < :ate');
$st->execute([':de' => $data . ' 00:00', ':ate' => $data . ' 24:00']);
$bloq = array_map(fn ($b) => [
    'dentistaId' => $b['dentista_id'] === null ? null : (int)$b['dentista_id'],
    'inicio' => max($b['inicio'], $data . ' 00:00') === $b['inicio'] ? substr($b['inicio'], 11) : '00:00',
    'fim' => $b['fim'] < $data . ' 24:00' ? substr($b['fim'], 11) : '24:00',
    'motivo' => $b['motivo'],
], $st->fetchAll());

json_resposta([
    'data'        => $data,
    'dataExtenso' => data_extenso($data),
    'hoje'        => date('Y-m-d'),
    'agora'       => date('H:i'),
    'dentistas'   => array_map(fn ($d) => ['id' => (int)$d['id'], 'nome' => $d['nome'], 'cor' => $d['cor'], 'expediente' => $exp[(int)$d['id']] ?? []], dentistas()),
    'agendamentos' => $doDia,
    'pendentes'   => $pendentes,
    'bloqueios'   => $bloq,
]);
