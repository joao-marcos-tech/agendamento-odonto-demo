<?php
// Expediente de cada profissional e bloqueios (folgas, feriados, reuniões).
declare(strict_types=1);
require __DIR__ . '/../../app/agenda.php';

headers_seguranca();
header('Cache-Control: no-store');
$u = exigir_login();
$dents = dentistas(false);
$msg = '';
$erro = '';

function hora_ok(mixed $h): ?string
{
    return (is_string($h) && preg_match('/^([01]\d|2[0-3]):(00|30)$/', $h)) ? $h : null;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrf_ok($_POST['csrf'] ?? null)) {
        $erro = 'A página ficou aberta muito tempo. Tente de novo.';
    } else {
        $acao = $_POST['acao'] ?? '';
        $idsValidos = array_map(fn ($d) => (int)$d['id'], $dents);

        if ($acao === 'expediente') {
            $did = filter_var($_POST['dentista'] ?? null, FILTER_VALIDATE_INT);
            $linhas = [];
            if (!in_array($did, $idsValidos, true)) {
                $erro = 'Profissional inválido.';
            } else {
                foreach (range(0, 6) as $dia) {
                    $ult = '';
                    foreach ([1, 2] as $turno) {
                        $i = $_POST["d{$dia}i{$turno}"] ?? '';
                        $f = $_POST["d{$dia}f{$turno}"] ?? '';
                        if ($i === '' && $f === '') {
                            continue;
                        }
                        $hi = hora_ok($i);
                        $hf = hora_ok($f);
                        if (!$hi || !$hf || $hf <= $hi || $hi < $ult) {
                            $erro = 'Confira os horários de ' . DIAS_SEMANA[$dia] . ': use horas cheias ou meias (ex.: 08:00, 13:30) e o fim depois do início.';
                            break 2;
                        }
                        $ult = $hf;
                        $linhas[] = [$dia, $hi, $hf];
                    }
                }
            }
            if (!$erro) {
                $pdo = db();
                $pdo->exec('BEGIN IMMEDIATE');
                $pdo->prepare('DELETE FROM expediente WHERE dentista_id = :d')->execute([':d' => $did]);
                $st = $pdo->prepare('INSERT INTO expediente (dentista_id, dia_semana, inicio, fim) VALUES (:d, :w, :i, :f)');
                foreach ($linhas as [$w, $i, $f]) {
                    $st->execute([':d' => $did, ':w' => $w, ':i' => $i, ':f' => $f]);
                }
                $pdo->exec('COMMIT');
                auditar((int)$u['id'], 'painel:expediente', null, 'dentista ' . $did);
                $msg = 'Expediente salvo. Consultas já marcadas continuam como estão.';
            }
        }

        if ($acao === 'bloquear') {
            $did = $_POST['dentista'] ?? '';
            $did = $did === 'todos' ? null : filter_var($did, FILTER_VALIDATE_INT);
            $di = data_valida($_POST['data_ini'] ?? null);
            $df = data_valida($_POST['data_fim'] ?? null) ?? $di;
            $hi = hora_ok($_POST['hora_ini'] ?? '') ?? '00:00';
            $hf = hora_ok($_POST['hora_fim'] ?? '') ?? '24:00';
            $motivo = texto_limpo($_POST['motivo'] ?? '', 60);
            if (($did !== null && !in_array($did, $idsValidos, true)) || !$di || !$df || "$df $hf" <= "$di $hi") {
                $erro = 'Confira o período do bloqueio.';
            } else {
                db()->prepare('INSERT INTO bloqueios (dentista_id, inicio, fim, motivo) VALUES (:d, :i, :f, :m)')
                    ->execute([':d' => $did, ':i' => "$di $hi", ':f' => "$df $hf", ':m' => $motivo]);
                auditar((int)$u['id'], 'painel:bloqueio', null, "$di $hi - $df $hf");
                $msg = 'Bloqueio criado. Esses horários sumiram do site.';
            }
        }

        if ($acao === 'desbloquear') {
            $bid = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            if ($bid) {
                db()->prepare('DELETE FROM bloqueios WHERE id = :id')->execute([':id' => $bid]);
                auditar((int)$u['id'], 'painel:desbloqueio', null, (string)$bid);
                $msg = 'Bloqueio removido.';
            }
        }
    }
}

$exp = [];
foreach (db()->query('SELECT dentista_id, dia_semana, inicio, fim FROM expediente ORDER BY inicio') as $r) {
    $exp[(int)$r['dentista_id']][(int)$r['dia_semana']][] = [$r['inicio'], $r['fim']];
}
$bloqueios = db()->prepare('SELECT b.*, d.nome AS dentista FROM bloqueios b LEFT JOIN dentistas d ON d.id = b.dentista_id WHERE b.fim >= :agora ORDER BY b.inicio');
$bloqueios->execute([':agora' => date('Y-m-d H:i')]);
$bloqueios = $bloqueios->fetchAll();
$csrf = csrf_token();
$aba = filter_input(INPUT_POST, 'dentista', FILTER_VALIDATE_INT) ?: (int)($dents[0]['id'] ?? 0);
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Horários e folgas · Recepção (demo)</title>
<link rel="stylesheet" href="/assets/css/admin.css?v=1">
<link rel="icon" href="/assets/img/marca.svg" type="image/svg+xml">
</head>
<body class="painel">
<a class="pular" href="#conteudo">Pular para o conteúdo</a>
<header class="barra">
  <a class="barra-marca" href="/admin/"><svg viewBox="0 0 40 40" aria-hidden="true"><path d="M6 30c0-14 6-22 14-22s14 8 14 22" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round"/><circle cx="20" cy="28" r="3.4" fill="currentColor"/></svg><span>Esmalte</span><small>recepção · demo</small></a>
  <nav class="barra-nav" aria-label="Painel">
    <a href="/admin/">Agenda</a>
    <a href="/admin/horarios.php" aria-current="page">Horários e folgas</a>
  </nav>
  <form class="barra-sair" method="post" action="/admin/logout.php">
    <span class="barra-user"><?= e($u['nome']) ?></span>
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <button class="b b-fantasma b-p" type="submit">Sair</button>
  </form>
</header>
<main id="conteudo" class="config">
  <h1>Horários e folgas</h1>
  <?php if ($msg): ?><p class="ok" role="status"><?= e($msg) ?></p><?php endif; ?>
  <?php if ($erro): ?><p class="erro" role="alert"><?= e($erro) ?></p><?php endif; ?>

  <section class="cartao" aria-labelledby="exp-titulo">
    <h2 id="exp-titulo">Expediente de cada profissional</h2>
    <p class="sub">Deixe em branco o turno que não atende. Os horários aparecem no site em blocos de 30 minutos.</p>
    <?php foreach ($dents as $d): $did = (int)$d['id']; ?>
    <details class="exp" <?= $did === $aba ? 'open' : '' ?>>
      <summary><i class="ponto cor-<?= e($d['cor']) ?>" aria-hidden="true"></i> <?= e($d['nome']) ?> <small><?= e($d['especialidade']) ?></small></summary>
      <form method="post" action="/admin/horarios.php" class="exp-form">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="acao" value="expediente">
        <input type="hidden" name="dentista" value="<?= $did ?>">
        <table class="exp-tab">
          <thead><tr><th scope="col">Dia</th><th scope="col">1º turno</th><th scope="col">2º turno</th></tr></thead>
          <tbody>
          <?php foreach ([1, 2, 3, 4, 5, 6, 0] as $dia): $t = $exp[$did][$dia] ?? []; ?>
            <tr>
              <th scope="row"><?= e(ucfirst(DIAS_SEMANA[$dia])) ?></th>
              <?php foreach ([1, 2] as $turno): $par = $t[$turno - 1] ?? ['', '']; ?>
              <td>
                <label class="sr" for="d<?= $did ?>-<?= $dia ?>-<?= $turno ?>i"><?= e(DIAS_SEMANA[$dia]) ?>, turno <?= $turno ?>, início</label>
                <input type="time" step="1800" id="d<?= $did ?>-<?= $dia ?>-<?= $turno ?>i" name="d<?= $dia ?>i<?= $turno ?>" value="<?= e($par[0]) ?>">
                <span aria-hidden="true">–</span>
                <label class="sr" for="d<?= $did ?>-<?= $dia ?>-<?= $turno ?>f"><?= e(DIAS_SEMANA[$dia]) ?>, turno <?= $turno ?>, fim</label>
                <input type="time" step="1800" id="d<?= $did ?>-<?= $dia ?>-<?= $turno ?>f" name="d<?= $dia ?>f<?= $turno ?>" value="<?= e($par[1]) ?>">
              </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <button class="b b-lima" type="submit">Salvar expediente</button>
      </form>
    </details>
    <?php endforeach; ?>
  </section>

  <section class="cartao" aria-labelledby="bloq-titulo">
    <h2 id="bloq-titulo">Bloquear horários</h2>
    <p class="sub">Feriado, folga, curso, manutenção. O período some do site na hora. Consultas já marcadas não são canceladas.</p>
    <form method="post" action="/admin/horarios.php" class="bloq-form">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="acao" value="bloquear">
      <div class="f"><label for="b-dent">Quem</label>
        <select id="b-dent" name="dentista"><option value="todos">Clínica inteira</option><?php foreach ($dents as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e($d['nome']) ?></option><?php endforeach; ?></select></div>
      <div class="f"><label for="b-di">De</label><input id="b-di" type="date" name="data_ini" required min="<?= e(date('Y-m-d')) ?>"></div>
      <div class="f"><label for="b-hi">Hora <span class="opc">(vazio = dia todo)</span></label><input id="b-hi" type="time" step="1800" name="hora_ini"></div>
      <div class="f"><label for="b-df">Até</label><input id="b-df" type="date" name="data_fim" min="<?= e(date('Y-m-d')) ?>"></div>
      <div class="f"><label for="b-hf">Hora</label><input id="b-hf" type="time" step="1800" name="hora_fim"></div>
      <div class="f f-largo"><label for="b-mot">Motivo <span class="opc">(aparece só aqui)</span></label><input id="b-mot" type="text" name="motivo" maxlength="60" placeholder="Ex.: Feriado municipal"></div>
      <button class="b b-escuro" type="submit">Bloquear</button>
    </form>

    <?php if ($bloqueios): ?>
    <ul class="bloq-lista">
      <?php foreach ($bloqueios as $b): ?>
      <li>
        <span><strong><?= e($b['dentista'] ?? 'Clínica inteira') ?></strong> · <?= e(date('d/m H:i', strtotime(str_replace(' 24:00', ' 23:59', $b['inicio'])))) ?> até <?= e(date('d/m H:i', strtotime(str_replace(' 24:00', ' 23:59', $b['fim'])))) ?><?= $b['motivo'] ? ' · ' . e($b['motivo']) : '' ?></span>
        <form method="post" action="/admin/horarios.php">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="acao" value="desbloquear">
          <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
          <button class="b b-fantasma b-p" type="submit">Remover</button>
        </form>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php else: ?>
    <p class="sub">Nenhum bloqueio futuro.</p>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
