<?php
// Página do paciente: ver, salvar na agenda (.ics) ou cancelar, usando o link secreto.
declare(strict_types=1);
require __DIR__ . '/../app/agenda.php';
require __DIR__ . '/../app/layout.php';

iniciar_sessao();
$chave = chave_ip('gestao');
if (limite_excedido($chave, 30, 900)) {
    headers_seguranca();
    http_response_code(429);
    exit('Muitas tentativas. Espere alguns minutos.');
}

$codigo = is_string($_GET['c'] ?? null) ? $_GET['c'] : '';
$token = is_string($_GET['t'] ?? null) ? $_GET['t'] : '';
$ag = agendamento_por_token($codigo, $token);
if (!$ag) {
    registrar_tentativa($chave);
}

// O link carrega um segredo: não deixe vazar por Referer nem por cache.
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

if ($ag && isset($_GET['ics'])) {
    headers_seguranca();
    header('Referrer-Policy: no-referrer');
    $fmt = fn (string $dt) => (new DateTimeImmutable($dt, new DateTimeZone('America/Sao_Paulo')))->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
    $esc = fn (string $t) => str_replace(["\\", ';', ',', "\n"], ["\\\\", '\;', '\,', '\n'], $t);
    $ics = implode("\r\n", [
        'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//NEXTECH//Agenda demo//PT', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
        'BEGIN:VEVENT',
        'UID:' . $ag['codigo'] . '@agenda-demo',
        'DTSTAMP:' . gmdate('Ymd\THis\Z'),
        'DTSTART:' . $fmt($ag['inicio']),
        'DTEND:' . $fmt($ag['fim']),
        'SUMMARY:' . $esc($ag['procedimento'] . ' · ' . config('clinica')),
        'DESCRIPTION:' . $esc('Com ' . $ag['dentista'] . '. Código ' . $ag['codigo'] . '. (Demonstração)'),
        'LOCATION:' . $esc(config('endereco')),
        'BEGIN:VALARM', 'TRIGGER:-PT2H', 'ACTION:DISPLAY', 'DESCRIPTION:Consulta em 2 horas', 'END:VALARM',
        'END:VEVENT', 'END:VCALENDAR', '',
    ]);
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="consulta-' . $ag['codigo'] . '.ics"');
    echo $ics;
    exit;
}

abrir_pagina('Sua consulta · ' . config('clinica') . ' (demo)', 'Veja ou cancele sua consulta.', 'pg-consulta');
header('Referrer-Policy: no-referrer');
?>
<header class="topo topo-claro">
  <a class="marca" href="/"><?= marca_svg() ?><span><?= e(config('clinica')) ?></span></a>
</header>
<main id="conteudo" class="consulta">
<?php if (!$ag): ?>
  <h1>Link inválido</h1>
  <p>Confira se copiou o link inteiro. Se precisar, fale com a recepção pelo <a href="https://wa.me/<?= e(config('whatsapp')) ?>" rel="noopener">WhatsApp</a>.</p>
<?php else:
    $ativo = in_array($ag['status'], STATUS_ATIVOS, true) && $ag['inicio'] > date('Y-m-d H:i'); ?>
  <p class="sobretitulo sobretitulo-escuro">Consulta <?= e($ag['codigo']) ?></p>
  <h1><?= e(ucfirst(data_extenso($ag['inicio']))) ?>, <em><?= e(substr($ag['inicio'], 11)) ?></em></h1>
  <dl class="consulta-dados">
    <div><dt>Tratamento</dt><dd><?= e($ag['procedimento']) ?> · <?= (int)$ag['duracao_min'] ?> min</dd></div>
    <div><dt>Profissional</dt><dd><?= e($ag['dentista']) ?></dd></div>
    <div><dt>Situação</dt><dd><span class="selo selo-<?= e($ag['status']) ?>" id="situacao"><?= e(STATUS[$ag['status']]) ?></span></dd></div>
  </dl>
  <?php if ($ag['preparo']): ?><p class="preparo"><?= e($ag['preparo']) ?></p><?php endif; ?>
  <?php if ($ativo): ?>
  <div class="consulta-acoes">
    <a class="btn btn-contorno" href="/consulta.php?c=<?= e($ag['codigo']) ?>&amp;t=<?= e($token) ?>&amp;ics=1">Salvar na minha agenda</a>
    <button class="btn btn-perigo" type="button" id="cancelar" data-csrf="<?= e(csrf_token()) ?>" data-codigo="<?= e($ag['codigo']) ?>" data-token="<?= e($token) ?>">Cancelar consulta</button>
  </div>
  <p class="erro-form" id="erro-cancelar" role="alert" hidden></p>
  <script src="/assets/js/consulta.js?v=1" defer></script>
  <?php endif; ?>
<?php endif; ?>
</main>
<?php rodape_pagina();
