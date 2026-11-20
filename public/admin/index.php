<?php
declare(strict_types=1);
require __DIR__ . '/../../app/agenda.php';

headers_seguranca();
header('Cache-Control: no-store');
$u = exigir_login();
$csrf = csrf_token();
$procs = procedimentos();
$dents = dentistas();
$dadosJs = [
    'procedimentos' => array_map(fn ($p) => ['id' => (int)$p['id'], 'nome' => $p['nome'], 'duracao' => (int)$p['duracao_min']], $procs),
    'dentistas' => array_map(fn ($d) => ['id' => (int)$d['id'], 'nome' => $d['nome'], 'procedimentos' => $d['procedimentos']], $dents),
];
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Agenda · Recepção (demo)</title>
<link rel="stylesheet" href="/assets/css/admin.css?v=1">
<link rel="icon" href="/assets/img/marca.svg" type="image/svg+xml">
</head>
<body class="painel" data-csrf="<?= e($csrf) ?>">
<a class="pular" href="#conteudo">Pular para a agenda</a>
<header class="barra">
  <a class="barra-marca" href="/admin/"><svg viewBox="0 0 40 40" aria-hidden="true"><path d="M6 30c0-14 6-22 14-22s14 8 14 22" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round"/><circle cx="20" cy="28" r="3.4" fill="currentColor"/></svg><span>Esmalte</span><small>recepção · demo</small></a>
  <nav class="barra-nav" aria-label="Painel">
    <a href="/admin/" aria-current="page">Agenda</a>
    <a href="/admin/horarios.php">Horários e folgas</a>
  </nav>
  <form class="barra-sair" method="post" action="/admin/logout.php">
    <span class="barra-user"><?= e($u['nome']) ?></span>
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <button class="b b-fantasma b-p" type="submit">Sair</button>
  </form>
</header>

<main id="conteudo" class="painel-grade">
  <section class="dia-painel" aria-labelledby="titulo-dia">
    <div class="dia-cabeca">
      <div class="dia-nav">
        <button class="b b-icone" type="button" id="anterior" aria-label="Dia anterior">←</button>
        <button class="b b-icone" type="button" id="proximo" aria-label="Próximo dia">→</button>
        <button class="b b-fantasma b-p" type="button" id="hoje">Hoje</button>
        <label class="sr" for="escolher-data">Ir para a data</label>
        <input type="date" id="escolher-data" class="data-input">
      </div>
      <h1 id="titulo-dia" aria-live="polite">Agenda</h1>
      <button class="b b-lima" type="button" id="novo">+ Encaixe</button>
    </div>
    <dl class="resumo" id="resumo"></dl>
    <div class="grade-wrap">
      <div class="grade" id="grade" aria-busy="true"></div>
    </div>
    <p class="legenda" aria-hidden="true"><i class="lg lg-pendente"></i>a confirmar <i class="lg lg-confirmado"></i>confirmado <i class="lg lg-concluido"></i>atendido <i class="lg lg-bloq"></i>bloqueado</p>
  </section>

  <aside class="fila" aria-labelledby="fila-titulo">
    <h2 id="fila-titulo">A confirmar <span id="fila-n" class="fila-n">0</span></h2>
    <p class="fila-dica">Consultas marcadas pelo site esperando um “ok” da recepção.</p>
    <ol class="fila-lista" id="fila"></ol>
  </aside>
</main>

<dialog class="gaveta" id="detalhe" aria-labelledby="det-nome">
  <div class="gaveta-in">
    <button class="fechar" type="button" data-fechar aria-label="Fechar">×</button>
    <p class="det-topo"><span class="selo" id="det-status"></span> <span id="det-codigo" class="det-codigo"></span></p>
    <h2 id="det-nome"></h2>
    <dl class="det-dados">
      <div><dt>Quando</dt><dd id="det-quando"></dd></div>
      <div><dt>Tratamento</dt><dd id="det-proc"></dd></div>
      <div><dt>Profissional</dt><dd id="det-dent"></dd></div>
      <div><dt>WhatsApp</dt><dd id="det-tel"></dd></div>
      <div><dt>Origem</dt><dd id="det-origem"></dd></div>
      <div id="det-obs-wrap"><dt>Observação</dt><dd id="det-obs"></dd></div>
    </dl>
    <div class="det-acoes" id="det-acoes">
      <a class="b b-whats" id="det-whats" href="#" target="_blank" rel="noopener">Mensagem no WhatsApp</a>
      <button class="b b-lima" type="button" data-acao="confirmar">Confirmar presença</button>
      <button class="b b-contorno" type="button" data-acao="remarcar">Remarcar</button>
      <button class="b b-contorno" type="button" data-acao="concluir">Atendido</button>
      <button class="b b-contorno" type="button" data-acao="faltou">Não veio</button>
      <button class="b b-perigo" type="button" data-acao="cancelar">Cancelar consulta</button>
    </div>
    <p class="erro" id="det-erro" role="alert" hidden></p>
  </div>
</dialog>

<dialog class="gaveta" id="marcar" aria-labelledby="marcar-titulo">
  <form class="gaveta-in" id="marcar-form" novalidate>
    <button class="fechar" type="button" data-fechar aria-label="Fechar">×</button>
    <h2 id="marcar-titulo">Novo encaixe</h2>
    <p class="marcar-sub" id="marcar-sub"></p>
    <div class="f-grade">
      <div class="f" data-so-novo>
        <label for="m-proc">Tratamento</label>
        <select id="m-proc" required>
          <?php foreach ($procs as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['nome']) ?> · <?= (int)$p['duracao_min'] ?> min</option><?php endforeach; ?>
        </select>
      </div>
      <div class="f">
        <label for="m-dent">Profissional</label>
        <select id="m-dent"></select>
      </div>
      <div class="f">
        <label for="m-data">Dia</label>
        <input type="date" id="m-data" required>
      </div>
    </div>
    <fieldset class="m-horas-wrap">
      <legend>Horário livre</legend>
      <div class="m-horas" id="m-horas" aria-live="polite"></div>
    </fieldset>
    <div class="f-grade" data-so-novo>
      <div class="f">
        <label for="m-nome">Nome do paciente</label>
        <input id="m-nome" type="text" maxlength="80" autocomplete="off">
      </div>
      <div class="f">
        <label for="m-whats">WhatsApp</label>
        <input id="m-whats" type="tel" inputmode="tel" maxlength="16" autocomplete="off" placeholder="(17) 99999-9999">
      </div>
      <div class="f f-largo">
        <label for="m-obs">Observação <span class="opc">(opcional)</span></label>
        <input id="m-obs" type="text" maxlength="200" autocomplete="off">
      </div>
    </div>
    <p class="erro" id="marcar-erro" role="alert" hidden></p>
    <button class="b b-lima b-largo" type="submit" id="marcar-enviar">Salvar</button>
  </form>
</dialog>

<div class="aviso-toast" id="toast" role="status" aria-live="polite"></div>
<script type="application/json" id="dados"><?= json_encode($dadosJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="/assets/js/admin.js?v=1" defer></script>
</body>
</html>
