<?php
// Pedaços de HTML compartilhados pelas páginas públicas.
declare(strict_types=1);

function abrir_pagina(string $titulo, string $descricao, string $classe = ''): void
{
    headers_seguranca();
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titulo) ?></title>
<meta name="description" content="<?= e($descricao) ?>">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#13211d">
<link rel="preload" href="/assets/fonts/fraunces-latin-400-italic.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/assets/fonts/karla-latin-400-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="/assets/css/site.css?v=1">
<link rel="icon" href="/assets/img/marca.svg" type="image/svg+xml">
</head>
<body class="<?= e($classe) ?>">
<a class="pular" href="#conteudo">Pular para o conteúdo</a>
<p class="faixa-demo" role="note"><strong>Demonstração NEXTECH.</strong> Clínica, profissionais, registros e horários são fictícios.</p>
    <?php
}

function marca_svg(): string
{
    // Arco estilizado (a curva de uma arcada) + ponto: a marca da clínica fictícia.
    return '<svg class="marca-icone" viewBox="0 0 40 40" aria-hidden="true" focusable="false"><path d="M6 30c0-14 6-22 14-22s14 8 14 22" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round"/><circle cx="20" cy="28" r="3.4" fill="currentColor"/></svg>';
}

function rodape_pagina(): void
{
    ?>
<footer class="rodape">
  <div class="rodape-in">
    <div>
      <a class="marca marca-rodape" href="/"><?= marca_svg() ?><span><?= e(config('clinica')) ?></span></a>
      <p><?= e(config('clinica_desc')) ?> · <?= e(config('endereco')) ?></p>
      <p><?= e(config('responsavel')) ?></p>
    </div>
    <nav aria-label="Rodapé">
      <a href="/privacidade.php">Privacidade e seus dados</a>
      <a href="/admin/">Acesso da recepção</a>
    </nav>
    <p class="rodape-nextech">Demonstração criada pela <a href="https://nextechwebstudio.netlify.app" rel="noopener">NEXTECH</a>. Nada aqui é uma clínica real.</p>
  </div>
</footer>
</body>
</html>
    <?php
}
