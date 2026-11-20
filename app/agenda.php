<?php
// Regras da agenda: disponibilidade, reserva sem horário duplicado, remarcação e webhook.
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function procedimentos(bool $soAtivos = true): array
{
    $sql = 'SELECT id, nome, resumo, duracao_min, preparo FROM procedimentos' . ($soAtivos ? ' WHERE ativo = 1' : '') . ' ORDER BY ordem, nome';
    return db()->query($sql)->fetchAll();
}

function dentistas(bool $soAtivos = true): array
{
    $sql = 'SELECT id, nome, especialidade, registro, bio, cor FROM dentistas' . ($soAtivos ? ' WHERE ativo = 1' : '') . ' ORDER BY ordem, nome';
    $lista = db()->query($sql)->fetchAll();
    $rel = db()->query('SELECT dentista_id, procedimento_id FROM dentista_procedimento')->fetchAll();
    foreach ($lista as &$d) {
        $d['procedimentos'] = array_values(array_map(
            fn ($r) => (int)$r['procedimento_id'],
            array_filter($rel, fn ($r) => (int)$r['dentista_id'] === (int)$d['id'])
        ));
    }
    return $lista;
}

function procedimento(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM procedimentos WHERE id = :id AND ativo = 1');
    $st->execute([':id' => $id]);
    return $st->fetch() ?: null;
}

// Dentistas ativos que fazem o procedimento (opcionalmente só um).
function dentistas_para(int $procId, ?int $dentistaId = null): array
{
    $sql = 'SELECT d.id, d.nome FROM dentistas d JOIN dentista_procedimento dp ON dp.dentista_id = d.id
            WHERE d.ativo = 1 AND dp.procedimento_id = :p' . ($dentistaId ? ' AND d.id = :d' : '') . ' ORDER BY d.ordem';
    $st = db()->prepare($sql);
    $par = [':p' => $procId];
    if ($dentistaId) {
        $par[':d'] = $dentistaId;
    }
    $st->execute($par);
    return $st->fetchAll();
}

function min_para_hora(int $m): string
{
    return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
}

function hora_para_min(string $h): int
{
    [$a, $b] = array_map('intval', explode(':', $h));
    return $a * 60 + $b;
}

function data_valida(mixed $d): ?string
{
    if (!is_string($d) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $d);
    return ($dt && $dt->format('Y-m-d') === $d) ? $d : null;
}

/**
 * Monta, para um intervalo de datas, tudo que ocupa a agenda: slots de consultas e bloqueios.
 * Retorna [dentista_id => ['Y-m-d H:i' => true]] e a lista de bloqueios.
 */
function ocupacao(array $dentistaIds, string $de, string $ate, ?int $ignorarAg = null): array
{
    $ocup = [];
    if (!$dentistaIds) {
        return [$ocup, []];
    }
    $in = implode(',', array_fill(0, count($dentistaIds), '?'));
    $sql = "SELECT dentista_id, slot FROM agenda_slots WHERE dentista_id IN ($in) AND slot >= ? AND slot < ?";
    $par = array_merge($dentistaIds, [$de . ' 00:00', $ate . ' 24:00']);
    if ($ignorarAg) {
        $sql .= ' AND agendamento_id <> ?';
        $par[] = $ignorarAg;
    }
    $st = db()->prepare($sql);
    $st->execute($par);
    foreach ($st as $r) {
        $ocup[(int)$r['dentista_id']][$r['slot']] = true;
    }
    $st = db()->prepare('SELECT dentista_id, inicio, fim FROM bloqueios WHERE fim > ? AND inicio < ?');
    $st->execute([$de . ' 00:00', $ate . ' 24:00']);
    return [$ocup, $st->fetchAll()];
}

function expedientes(array $dentistaIds): array
{
    $exp = [];
    if (!$dentistaIds) {
        return $exp;
    }
    $in = implode(',', array_fill(0, count($dentistaIds), '?'));
    $st = db()->prepare("SELECT dentista_id, dia_semana, inicio, fim FROM expediente WHERE dentista_id IN ($in) ORDER BY inicio");
    $st->execute($dentistaIds);
    foreach ($st as $r) {
        $exp[(int)$r['dentista_id']][(int)$r['dia_semana']][] = [hora_para_min($r['inicio']), hora_para_min($r['fim'])];
    }
    return $exp;
}

/**
 * Horários livres por dia. Retorna ['Y-m-d' => ['09:00' => [dentista_id, ...], ...]].
 * $admin = true ignora a antecedência mínima (a recepção pode encaixar).
 */
function disponibilidade(int $procId, ?int $dentistaId, string $de, string $ate, ?int $ignorarAg = null, bool $admin = false): array
{
    $proc = procedimento($procId);
    if (!$proc) {
        return [];
    }
    $passo = (int)config('slot_min');
    $dur = (int)$proc['duracao_min'];
    $ds = array_map(fn ($d) => (int)$d['id'], dentistas_para($procId, $dentistaId));
    $exp = expedientes($ds);
    [$ocup, $bloq] = ocupacao($ds, $de, $ate, $ignorarAg);
    $limite = (new DateTimeImmutable())->modify('+' . ($admin ? 0 : (int)config('antecedencia_min')) . ' minutes')->format('Y-m-d H:i');

    $res = [];
    $dia = new DateTimeImmutable($de);
    $fim = new DateTimeImmutable($ate);
    while ($dia <= $fim) {
        $data = $dia->format('Y-m-d');
        $dow = (int)$dia->format('w');
        foreach ($ds as $did) {
            foreach ($exp[$did][$dow] ?? [] as [$ini, $fimExp]) {
                for ($t = $ini; $t + $dur <= $fimExp; $t += $passo) {
                    $inicio = $data . ' ' . min_para_hora($t);
                    if ($inicio < $limite) {
                        continue;
                    }
                    $fimTxt = $data . ' ' . min_para_hora($t + $dur);
                    $livre = true;
                    for ($s = $t; $s < $t + $dur; $s += $passo) {
                        if (isset($ocup[$did][$data . ' ' . min_para_hora($s)])) {
                            $livre = false;
                            break;
                        }
                    }
                    if ($livre) {
                        foreach ($bloq as $b) {
                            if (($b['dentista_id'] === null || (int)$b['dentista_id'] === $did) && $b['inicio'] < $fimTxt && $b['fim'] > $inicio) {
                                $livre = false;
                                break;
                            }
                        }
                    }
                    if ($livre) {
                        $res[$data][min_para_hora($t)][] = $did;
                    }
                }
            }
        }
        if (isset($res[$data])) {
            ksort($res[$data]);
        }
        $dia = $dia->modify('+1 day');
    }
    return $res;
}

function gerar_codigo(): string
{
    $alfa = 'ACDEFGHJKMNPQRTUVWXY34679'; // sem letras/números que se confundem
    $c = '';
    for ($i = 0; $i < 6; $i++) {
        $c .= $alfa[random_int(0, strlen($alfa) - 1)];
    }
    return $c;
}

class HorarioIndisponivel extends RuntimeException {}

function inserir_slots(int $agId, int $dentistaId, string $inicio, int $dur): void
{
    $passo = (int)config('slot_min');
    [$data, $hora] = explode(' ', $inicio);
    $t0 = hora_para_min($hora);
    $st = db()->prepare('INSERT INTO agenda_slots (dentista_id, slot, agendamento_id) VALUES (:d, :s, :a)');
    for ($t = $t0; $t < $t0 + $dur; $t += $passo) {
        $st->execute([':d' => $dentistaId, ':s' => $data . ' ' . min_para_hora($t), ':a' => $agId]);
    }
}

/**
 * Reserva com garantia contra horário duplicado:
 * 1) BEGIN IMMEDIATE trava a escrita no SQLite (duas reservas nunca checam ao mesmo tempo);
 * 2) a disponibilidade é conferida de novo dentro da transação;
 * 3) a tabela agenda_slots tem PRIMARY KEY (dentista_id, slot): se algo escapar, o banco recusa.
 */
function reservar(int $procId, ?int $dentistaId, string $data, string $hora, array $paciente, bool $admin = false): array
{
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $disp = disponibilidade($procId, $dentistaId, $data, $data, null, $admin);
        $candidatos = $disp[$data][$hora] ?? [];
        if (!$candidatos) {
            throw new HorarioIndisponivel();
        }
        $did = $dentistaId ?: menos_ocupado($candidatos, $data);
        $proc = procedimento($procId);
        $dur = (int)$proc['duracao_min'];
        $inicio = $data . ' ' . $hora;
        $fim = $data . ' ' . min_para_hora(hora_para_min($hora) + $dur);
        $token = bin2hex(random_bytes(24));

        $st = $pdo->prepare('INSERT INTO agendamentos
            (codigo, procedimento_id, dentista_id, inicio, fim, status, paciente_nome, paciente_whatsapp, observacao,
             token_hash, consentimento_em, origem, criado_em, atualizado_em)
            VALUES (:c, :p, :d, :i, :f, :s, :n, :w, :o, :t, :ce, :or, :agora, :agora)');
        for ($tent = 0; ; $tent++) {
            $codigo = gerar_codigo();
            try {
                $st->execute([
                    ':c' => $codigo, ':p' => $procId, ':d' => $did, ':i' => $inicio, ':f' => $fim,
                    ':s' => $admin ? 'confirmado' : 'pendente',
                    ':n' => $paciente['nome'], ':w' => $paciente['whatsapp'], ':o' => $paciente['observacao'] ?? '',
                    ':t' => hash('sha256', $token), ':ce' => $paciente['consentimento_em'] ?? null,
                    ':or' => $admin ? 'recepcao' : 'site', ':agora' => date('Y-m-d H:i:s'),
                ]);
                break;
            } catch (PDOException $e) {
                if ($tent < 3 && str_contains($e->getMessage(), 'agendamentos.codigo')) {
                    continue; // código repetido (raríssimo): sorteia outro
                }
                throw $e;
            }
        }
        $id = (int)$pdo->lastInsertId();
        inserir_slots($id, $did, $inicio, $dur);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        if ($e instanceof PDOException && str_contains($e->getMessage(), 'UNIQUE') && str_contains($e->getMessage(), 'agenda_slots')) {
            throw new HorarioIndisponivel();
        }
        throw $e;
    }
    $ag = agendamento($id);
    $ag['token'] = $token; // só existe nesta resposta; no banco fica o hash
    return $ag;
}

function menos_ocupado(array $ids, string $data): int
{
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("SELECT dentista_id, COUNT(*) n FROM agenda_slots WHERE dentista_id IN ($in) AND slot LIKE ? GROUP BY dentista_id");
    $st->execute(array_merge($ids, [$data . '%']));
    $carga = array_fill_keys($ids, 0);
    foreach ($st as $r) {
        $carga[(int)$r['dentista_id']] = (int)$r['n'];
    }
    asort($carga);
    return (int)array_key_first($carga);
}

function agendamento(int $id): ?array
{
    $st = db()->prepare('SELECT a.*, p.nome AS procedimento, p.duracao_min, p.preparo, d.nome AS dentista, d.cor
        FROM agendamentos a JOIN procedimentos p ON p.id = a.procedimento_id JOIN dentistas d ON d.id = a.dentista_id
        WHERE a.id = :id');
    $st->execute([':id' => $id]);
    return $st->fetch() ?: null;
}

function agendamento_por_token(string $codigo, string $token): ?array
{
    if (!preg_match('/^[A-Z0-9]{6}$/', $codigo) || !preg_match('/^[a-f0-9]{48}$/', $token)) {
        return null;
    }
    $st = db()->prepare('SELECT id, token_hash FROM agendamentos WHERE codigo = :c');
    $st->execute([':c' => $codigo]);
    $r = $st->fetch();
    if (!$r || !$r['token_hash'] || !hash_equals($r['token_hash'], hash('sha256', $token))) {
        return null;
    }
    return agendamento((int)$r['id']);
}

function mudar_status(int $id, string $status, ?int $usuarioId, string $origem = 'painel'): array
{
    if (!isset(STATUS[$status])) {
        throw new InvalidArgumentException('status');
    }
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $pdo->prepare('UPDATE agendamentos SET status = :s, atualizado_em = :t WHERE id = :id')
            ->execute([':s' => $status, ':t' => date('Y-m-d H:i:s'), ':id' => $id]);
        if (!in_array($status, STATUS_ATIVOS, true)) {
            // Libera o horário para outra pessoa.
            $pdo->prepare('DELETE FROM agenda_slots WHERE agendamento_id = :id')->execute([':id' => $id]);
        }
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
    auditar($usuarioId, $origem . ':' . $status, $id);
    $ag = agendamento($id);
    webhook('agendamento.' . $status, $ag);
    return $ag;
}

function remarcar(int $id, string $data, string $hora, ?int $dentistaId, ?int $usuarioId): array
{
    $ag = agendamento($id);
    if (!$ag || !in_array($ag['status'], STATUS_ATIVOS, true)) {
        throw new HorarioIndisponivel();
    }
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $disp = disponibilidade((int)$ag['procedimento_id'], $dentistaId, $data, $data, $id, true);
        $cand = $disp[$data][$hora] ?? [];
        if (!$cand) {
            throw new HorarioIndisponivel();
        }
        $did = $dentistaId ?: (in_array((int)$ag['dentista_id'], $cand, true) ? (int)$ag['dentista_id'] : menos_ocupado($cand, $data));
        $dur = (int)$ag['duracao_min'];
        $inicio = $data . ' ' . $hora;
        $pdo->prepare('DELETE FROM agenda_slots WHERE agendamento_id = :id')->execute([':id' => $id]);
        $pdo->prepare('UPDATE agendamentos SET inicio = :i, fim = :f, dentista_id = :d, atualizado_em = :t WHERE id = :id')
            ->execute([':i' => $inicio, ':f' => $data . ' ' . min_para_hora(hora_para_min($hora) + $dur), ':d' => $did, ':t' => date('Y-m-d H:i:s'), ':id' => $id]);
        inserir_slots($id, $did, $inicio, $dur);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        if ($e instanceof PDOException && str_contains($e->getMessage(), 'UNIQUE')) {
            throw new HorarioIndisponivel();
        }
        throw $e;
    }
    auditar($usuarioId, 'painel:remarcado', $id, $ag['inicio'] . ' -> ' . $inicio);
    $novo = agendamento($id);
    webhook('agendamento.remarcado', $novo);
    return $novo;
}

// ---------- Datas em português ----------
const DIAS_SEMANA = ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'];
const MESES = ['', 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

function data_extenso(string $dataHora): string
{
    $d = new DateTimeImmutable($dataHora);
    return DIAS_SEMANA[(int)$d->format('w')] . ', ' . (int)$d->format('j') . ' de ' . MESES[(int)$d->format('n')];
}

// ---------- WhatsApp ----------
function link_whatsapp_clinica(array $ag): string
{
    $msg = "Olá! Acabei de agendar pelo site.\n\n"
        . "Código: {$ag['codigo']}\n"
        . "{$ag['procedimento']} com {$ag['dentista']}\n"
        . ucfirst(data_extenso($ag['inicio'])) . ' às ' . substr($ag['inicio'], 11) . "\n\n"
        . 'Podem confirmar, por favor?';
    return 'https://wa.me/' . config('whatsapp') . '?text=' . rawurlencode($msg);
}

function link_whatsapp_paciente(array $ag): string
{
    $nome = explode(' ', trim($ag['paciente_nome']))[0];
    $msg = "Olá, {$nome}! Aqui é da " . config('clinica') . ".\n\n"
        . "Sua consulta ({$ag['procedimento']}) com {$ag['dentista']} está marcada para "
        . data_extenso($ag['inicio']) . ' às ' . substr($ag['inicio'], 11) . ".\n\n"
        . 'Podemos confirmar sua presença?';
    return 'https://wa.me/' . $ag['paciente_whatsapp'] . '?text=' . rawurlencode($msg);
}

// ---------- Webhook n8n (opcional) ----------
/**
 * Envia o evento assinado: cabeçalho X-Agenda-Signature = sha256=HMAC(timestamp + "." + corpo, segredo).
 * O n8n confere a assinatura e recusa timestamps com mais de 5 minutos (evita reenvio).
 * Falha no webhook nunca derruba o agendamento: só registra no log.
 */
function webhook(string $evento, ?array $ag): void
{
    if ((string)config('webhook_url') === '' || (string)config('webhook_segredo') === '' || !$ag) {
        return;
    }
    // Envia DEPOIS de responder ao paciente (no PHP-FPM), para o n8n lento não travar a tela.
    register_shutdown_function(function () use ($evento, $ag): void {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        webhook_enviar($evento, $ag);
    });
}

function webhook_enviar(string $evento, array $ag): void
{
    $url = (string)config('webhook_url');
    $segredo = (string)config('webhook_segredo');
    if (!function_exists('curl_init')) {
        return;
    }
    $p = parse_url($url);
    $local = in_array($p['host'] ?? '', ['localhost', '127.0.0.1', 'n8n'], true);
    if (($p['scheme'] ?? '') !== 'https' && !$local) {
        error_log('[agenda] webhook ignorado: use HTTPS');
        return;
    }
    $corpo = json_encode([
        'evento'    => $evento,
        'id_evento' => bin2hex(random_bytes(8)),
        'agendamento' => [
            'codigo'       => $ag['codigo'],
            'status'       => $ag['status'],
            'inicio'       => $ag['inicio'],
            'fim'          => $ag['fim'],
            'procedimento' => $ag['procedimento'],
            'dentista'     => $ag['dentista'],
            'paciente'     => ['nome' => $ag['paciente_nome'], 'whatsapp' => $ag['paciente_whatsapp']],
        ],
    ], JSON_UNESCAPED_UNICODE);
    $ts = (string)time();
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $corpo,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | ($local ? CURLPROTO_HTTP : 0),
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT        => 3,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Agenda-Timestamp: ' . $ts,
            'X-Agenda-Signature: sha256=' . hash_hmac('sha256', $ts . '.' . $corpo, $segredo),
        ],
    ]);
    curl_exec($ch);
    $cod = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($cod < 200 || $cod >= 300) {
        error_log('[agenda] webhook ' . $evento . ' respondeu ' . $cod);
    }
    curl_close($ch);
}

// ---------- LGPD: retenção ----------
function anonimizar_antigos(): int
{
    $corte = (new DateTimeImmutable())->modify('-' . (int)config('reter_dias') . ' days')->format('Y-m-d H:i');
    $st = db()->prepare("UPDATE agendamentos SET paciente_nome = 'Paciente anonimizado', paciente_whatsapp = '', observacao = '', token_hash = NULL
        WHERE inicio < :c AND paciente_whatsapp <> ''");
    $st->execute([':c' => $corte]);
    return $st->rowCount();
}
