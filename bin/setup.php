<?php
// Cria o banco, os dados de exemplo e o usuário da recepção.
// Uso: php bin/setup.php            (cria se não existir)
//      php bin/setup.php --reset    (apaga e recria tudo)
//      AGENDA_SENHA='senhaForte' php bin/setup.php --reset
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../app/agenda.php';

$arq = config('db_path');
if (in_array('--reset', $argv, true)) {
    foreach ([$arq, $arq . '-wal', $arq . '-shm'] as $f) {
        if (is_file($f)) {
            unlink($f);
        }
    }
} elseif (is_file($arq)) {
    echo "Banco já existe em data/. Use --reset para recriar.\n";
    exit(0);
}

$pdo = db();
$pdo->exec(<<<'SQL'
CREATE TABLE usuarios (
    id INTEGER PRIMARY KEY,
    usuario TEXT NOT NULL UNIQUE,
    nome TEXT NOT NULL,
    senha_hash TEXT NOT NULL,
    ativo INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE procedimentos (
    id INTEGER PRIMARY KEY,
    nome TEXT NOT NULL,
    resumo TEXT NOT NULL,
    preparo TEXT NOT NULL DEFAULT '',
    duracao_min INTEGER NOT NULL CHECK (duracao_min > 0 AND duracao_min % 30 = 0),
    ordem INTEGER NOT NULL DEFAULT 0,
    ativo INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE dentistas (
    id INTEGER PRIMARY KEY,
    nome TEXT NOT NULL,
    especialidade TEXT NOT NULL,
    registro TEXT NOT NULL,
    bio TEXT NOT NULL DEFAULT '',
    cor TEXT NOT NULL DEFAULT 'a',
    ordem INTEGER NOT NULL DEFAULT 0,
    ativo INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE dentista_procedimento (
    dentista_id INTEGER NOT NULL REFERENCES dentistas(id) ON DELETE CASCADE,
    procedimento_id INTEGER NOT NULL REFERENCES procedimentos(id) ON DELETE CASCADE,
    PRIMARY KEY (dentista_id, procedimento_id)
);
CREATE TABLE expediente (
    id INTEGER PRIMARY KEY,
    dentista_id INTEGER NOT NULL REFERENCES dentistas(id) ON DELETE CASCADE,
    dia_semana INTEGER NOT NULL CHECK (dia_semana BETWEEN 0 AND 6),
    inicio TEXT NOT NULL CHECK (inicio GLOB '[0-2][0-9]:[0-5][0-9]'),
    fim TEXT NOT NULL CHECK (fim GLOB '[0-2][0-9]:[0-5][0-9]' AND fim > inicio)
);
CREATE TABLE bloqueios (
    id INTEGER PRIMARY KEY,
    dentista_id INTEGER REFERENCES dentistas(id) ON DELETE CASCADE, -- NULL = clínica inteira
    inicio TEXT NOT NULL,
    fim TEXT NOT NULL CHECK (fim > inicio),
    motivo TEXT NOT NULL DEFAULT ''
);
CREATE TABLE agendamentos (
    id INTEGER PRIMARY KEY,
    codigo TEXT NOT NULL UNIQUE,
    procedimento_id INTEGER NOT NULL REFERENCES procedimentos(id),
    dentista_id INTEGER NOT NULL REFERENCES dentistas(id),
    inicio TEXT NOT NULL,
    fim TEXT NOT NULL,
    status TEXT NOT NULL CHECK (status IN ('pendente','confirmado','concluido','faltou','cancelado')),
    paciente_nome TEXT NOT NULL,
    paciente_whatsapp TEXT NOT NULL,
    observacao TEXT NOT NULL DEFAULT '',
    token_hash TEXT,
    consentimento_em TEXT,
    origem TEXT NOT NULL DEFAULT 'site',
    criado_em TEXT NOT NULL,
    atualizado_em TEXT NOT NULL
);
CREATE INDEX ix_ag_inicio ON agendamentos(inicio);
CREATE INDEX ix_ag_whats ON agendamentos(paciente_whatsapp, status);
-- Cada pedaço de 30 min de cada dentista só pode pertencer a UMA consulta.
-- É isso que torna impossível marcar dois pacientes no mesmo horário.
CREATE TABLE agenda_slots (
    dentista_id INTEGER NOT NULL REFERENCES dentistas(id),
    slot TEXT NOT NULL,
    agendamento_id INTEGER NOT NULL REFERENCES agendamentos(id) ON DELETE CASCADE,
    PRIMARY KEY (dentista_id, slot)
);
CREATE INDEX ix_slots_ag ON agenda_slots(agendamento_id);
CREATE TABLE tentativas (
    id INTEGER PRIMARY KEY,
    chave TEXT NOT NULL,
    criado_em INTEGER NOT NULL
);
CREATE INDEX ix_tent ON tentativas(chave, criado_em);
CREATE TABLE auditoria (
    id INTEGER PRIMARY KEY,
    usuario_id INTEGER,
    acao TEXT NOT NULL,
    agendamento_id INTEGER,
    detalhe TEXT NOT NULL DEFAULT '',
    criado_em TEXT NOT NULL
);
SQL);

// ---------- Procedimentos ----------
$procs = [
    ['Avaliação', 'Primeira conversa, exame clínico e plano de tratamento sem pressa.', 'Traga exames ou radiografias recentes, se tiver.', 30],
    ['Limpeza e prevenção', 'Profilaxia, remoção de tártaro e orientação de escovação.', '', 60],
    ['Clareamento', 'Sessão de clareamento em consultório, com avaliação de sensibilidade.', 'Evite café, vinho e alimentos com corante nas 48 h seguintes.', 60],
    ['Restauração', 'Tratamento de cárie ou troca de restauração antiga.', '', 60],
    ['Manutenção de aparelho', 'Ajuste mensal de aparelho fixo ou alinhadores.', 'Escove bem os dentes antes de vir.', 30],
    ['Implante · avaliação', 'Estudo do caso e pedido de tomografia, quando necessário.', 'Traga a lista de remédios que usa.', 30],
    ['Dor ou urgência', 'Encaixe para dor, dente quebrado ou inchaço.', 'Com febre ou inchaço que piora rápido, procure um pronto-socorro.', 30],
];
$st = $pdo->prepare('INSERT INTO procedimentos (nome, resumo, preparo, duracao_min, ordem) VALUES (?,?,?,?,?)');
foreach ($procs as $i => $p) {
    $st->execute([$p[0], $p[1], $p[2], $p[3], $i]);
}

// ---------- Dentistas (fictícios) ----------
$dents = [
    ['Dra. Helena Prado', 'Clínica geral e estética', 'Cuida da maior parte das avaliações e dos clareamentos.', 'a', [1, 2, 3, 4, 7]],
    ['Dr. Caio Moreira', 'Ortodontia', 'Aparelhos fixos, alinhadores e acompanhamento de crianças.', 'b', [1, 5, 7]],
    ['Dra. Lívia Tanaka', 'Implantes e reabilitação', 'Planejamento de implantes e casos de reabilitação oral.', 'c', [1, 4, 6, 7]],
];
$st = $pdo->prepare("INSERT INTO dentistas (nome, especialidade, registro, bio, cor, ordem) VALUES (?, ?, 'CRO-SP 00000 · fictício', ?, ?, ?)");
$rel = $pdo->prepare('INSERT INTO dentista_procedimento (dentista_id, procedimento_id) VALUES (?, ?)');
foreach ($dents as $i => $d) {
    $st->execute([$d[0], $d[1], $d[2], $d[3], $i]);
    $id = (int)$pdo->lastInsertId();
    foreach ($d[4] as $p) {
        $rel->execute([$id, $p]);
    }
}

// ---------- Expediente ----------
$exp = $pdo->prepare('INSERT INTO expediente (dentista_id, dia_semana, inicio, fim) VALUES (?,?,?,?)');
foreach ([1, 2, 3, 4, 5] as $dia) {
    $exp->execute([1, $dia, '08:00', '12:00']);
    $exp->execute([1, $dia, '13:30', '18:00']);
}
foreach ([1, 3, 5] as $dia) {
    $exp->execute([2, $dia, '09:00', '12:00']);
    $exp->execute([2, $dia, '14:00', '19:00']);
}
$exp->execute([2, 6, '08:00', '12:00']);
foreach ([2, 4] as $dia) {
    $exp->execute([3, $dia, '08:00', '12:00']);
    $exp->execute([3, $dia, '13:00', '17:30']);
}
$exp->execute([3, 5, '13:00', '17:30']);

// ---------- Consultas de exemplo (pacientes fictícios) ----------
$nomes = ['Marina Exemplo', 'Rafael Exemplo', 'Beatriz Exemplo', 'Otávio Exemplo', 'Clara Exemplo', 'Igor Exemplo', 'Sofia Exemplo', 'Tiago Exemplo', 'Lúcia Exemplo', 'Bruno Exemplo'];
$n = 0;
$hoje = new DateTimeImmutable('today');
for ($d = 0; $d < 6; $d++) {
    $data = $hoje->modify("+$d day")->format('Y-m-d');
    foreach ([1, 2, 3, 4, 5, 7, 1, 2, 5, 6, 4] as $proc) {
        $disp = disponibilidade($proc, null, $data, $data, null, true);
        $horas = array_keys($disp[$data] ?? []);
        if (!$horas || random_int(0, 3) === 0) {
            continue;
        }
        $hora = $horas[random_int(0, count($horas) - 1)];
        $ag = reservar($proc, null, $data, $hora, [
            'nome' => $nomes[$n % count($nomes)],
            'whatsapp' => '551190000' . sprintf('%04d', $n + 1),
            'consentimento_em' => date('Y-m-d H:i:s'),
        ], true);
        if (random_int(0, 2) === 0) {
            $pdo->prepare("UPDATE agendamentos SET status = 'pendente', origem = 'site' WHERE id = ?")->execute([$ag['id']]);
        }
        $n++;
    }
}

// ---------- Usuário da recepção ----------
$senha = getenv('AGENDA_SENHA') ?: '';
$gerada = false;
if ($senha === '') {
    $senha = rtrim(strtr(base64_encode(random_bytes(12)), '+/', 'kM'), '=');
    $gerada = true;
} elseif (strlen($senha) < 10) {
    fwrite(STDERR, "A senha precisa ter pelo menos 10 caracteres.\n");
    exit(1);
}
$pdo->prepare('INSERT INTO usuarios (usuario, nome, senha_hash) VALUES (?, ?, ?)')
    ->execute(['recepcao', 'Recepção', password_hash($senha, PASSWORD_DEFAULT)]);

@chmod($arq, 0600);
echo "Pronto! Banco criado com $n consultas de exemplo.\n";
echo "Painel: /admin/  usuário: recepcao\n";
if ($gerada) {
    echo "Senha (aparece só agora, guarde): $senha\n";
}
