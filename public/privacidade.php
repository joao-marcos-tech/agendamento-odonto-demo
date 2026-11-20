<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
abrir_pagina('Privacidade · ' . config('clinica') . ' (demo)', 'Como os dados do agendamento são tratados.', 'pg-texto');
?>
<header class="topo topo-claro">
  <a class="marca" href="/"><?= marca_svg() ?><span><?= e(config('clinica')) ?></span></a>
</header>
<main id="conteudo" class="texto">
  <p class="sobretitulo sobretitulo-escuro">Modelo para demonstração</p>
  <h1>Seus dados, <em>em poucas linhas</em></h1>
  <p class="nota">Este é um texto de exemplo. Uma clínica real precisa revisar com quem cuida da parte jurídica (LGPD).</p>
  <h2>O que pedimos</h2>
  <p>Nome e WhatsApp. O tratamento escolhido também fica registrado, porque é o motivo da consulta. Não pedimos CPF, endereço, e-mail nem detalhes de saúde no site.</p>
  <h2>Para que usamos</h2>
  <p>Só para guardar o horário, confirmar com você e avisar se algo mudar. Não usamos para propaganda e não vendemos nem repassamos a terceiros.</p>
  <h2>Por quanto tempo</h2>
  <p>Depois de 180 dias da data da consulta, nome e WhatsApp são apagados automaticamente do sistema de agendamento. O prontuário clínico segue regras próprias do conselho profissional e não fica neste site.</p>
  <h2>Segurança</h2>
  <p>O acesso ao painel é só da recepção, com senha. O site não guarda seus dados no navegador. O endereço IP é registrado só em forma embaralhada (hash), para bloquear abusos.</p>
  <h2>Seus direitos</h2>
  <p>Você pode pedir para ver, corrigir ou apagar seus dados a qualquer momento pelo WhatsApp da clínica.</p>
  <p><a class="btn btn-escuro" href="/#agendar">Voltar para o agendamento</a></p>
</main>
<?php rodape_pagina();
