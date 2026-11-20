<?php
declare(strict_types=1);

require __DIR__ . '/../app/agenda.php';
require __DIR__ . '/../app/layout.php';

iniciar_sessao();
$csrf = csrf_token();
$procs = procedimentos();
$dents = dentistas();

// Próximo horário livre de verdade (aparece no topo). Usa a Avaliação como referência.
$proximo = null;
if ($procs) {
    $hoje = date('Y-m-d');
    $disp = disponibilidade((int)$procs[0]['id'], null, $hoje, date('Y-m-d', strtotime('+7 days')));
    foreach ($disp as $data => $horas) {
        $hora = array_key_first($horas);
        $nomeDent = '';
        foreach ($dents as $d) {
            if ((int)$d['id'] === $horas[$hora][0]) {
                $nomeDent = $d['nome'];
            }
        }
        $quando = $data === $hoje ? 'hoje' : ($data === date('Y-m-d', strtotime('+1 day')) ? 'amanhã' : data_extenso($data));
        $proximo = ['quando' => $quando, 'hora' => $hora, 'dentista' => $nomeDent];
        break;
    }
}

$dadosJs = [
    'procedimentos' => array_map(fn ($p) => [
        'id' => (int)$p['id'], 'nome' => $p['nome'], 'resumo' => $p['resumo'], 'duracao' => (int)$p['duracao_min'], 'preparo' => $p['preparo'],
    ], $procs),
    'dentistas' => array_map(fn ($d) => [
        'id' => (int)$d['id'], 'nome' => $d['nome'], 'especialidade' => $d['especialidade'], 'cor' => $d['cor'], 'procedimentos' => $d['procedimentos'],
    ], $dents),
];

function iniciais(string $nome): string
{
    $partes = array_values(array_filter(explode(' ', preg_replace('/^(Dra?\.)\s*/u', '', $nome))));
    return mb_substr($partes[0] ?? '', 0, 1) . mb_substr(end($partes) ?: '', 0, 1);
}

abrir_pagina(config('clinica') . ' · Agende sua consulta (demo)', 'Demonstração de agendamento online para consultório odontológico, feita pela NEXTECH.', 'pg-inicio');
?>
<header class="topo" id="topo">
  <a class="marca" href="/" aria-label="<?= e(config('clinica')) ?>, página inicial"><?= marca_svg() ?><span><?= e(config('clinica')) ?></span></a>
  <nav class="topo-nav" aria-label="Principal">
    <a href="#equipe">Equipe</a>
    <a href="#antes">Antes de vir</a>
    <a class="btn btn-lima btn-pequeno" href="#agendar">Agendar</a>
  </nav>
</header>

<main id="conteudo">
  <section class="hero" aria-labelledby="hero-titulo">
    <div class="hero-midia" aria-hidden="true">
      <video class="hero-video" muted loop playsinline preload="none" poster="/assets/video/hero-poster.jpg" data-autoplay>
        <source src="/assets/video/hero.webm" type="video/webm">
        <source src="/assets/video/hero.mp4" type="video/mp4">
      </video>
      <div class="hero-grao"></div>
    </div>
    <div class="hero-in">
      <p class="sobretitulo">Odontologia · Cidade Demo</p>
      <h1 id="hero-titulo" class="hero-titulo">
        <span class="linha"><span>Sua consulta</span></span>
        <span class="linha"><span>no horário que</span></span>
        <span class="linha"><span><em>cabe</em> no seu dia.</span></span>
      </h1>
      <p class="hero-texto">Escolha o tratamento, quem vai te atender e um horário que está livre de verdade. Leva menos de um minuto e não precisa ligar.</p>
      <div class="hero-acoes">
        <a class="btn btn-lima" href="#agendar">Escolher meu horário <span class="seta" aria-hidden="true">→</span></a>
        <?php if ($proximo): ?>
        <p class="proximo"><span class="pulso" aria-hidden="true"></span><span>Próximo horário livre: <strong><?= e($proximo['quando']) ?>, <?= e($proximo['hora']) ?></strong> com <?= e($proximo['dentista']) ?></span></p>
        <?php endif; ?>
      </div>
    </div>
    <button class="video-controle" type="button" aria-pressed="false" hidden>
      <span class="vc-pausar">Pausar animação</span><span class="vc-tocar">Tocar animação</span>
    </button>
  </section>

  <section class="agendar" id="agendar" aria-labelledby="agendar-titulo">
    <div class="agendar-cabeca">
      <p class="sobretitulo sobretitulo-escuro">Agendamento</p>
      <h2 id="agendar-titulo">Quatro escolhas e pronto.</h2>
    </div>

    <noscript><p class="aviso">Para ver os horários livres, ative o JavaScript. Ou chame a recepção no <a href="https://wa.me/<?= e(config('whatsapp')) ?>">WhatsApp</a>.</p></noscript>

    <div class="agendar-grade">
      <form class="fluxo" id="fluxo" novalidate data-csrf="<?= e($csrf) ?>">
        <ol class="passos-indice" aria-label="Etapas">
          <li data-indice="1" aria-current="step"><span>01</span> Tratamento</li>
          <li data-indice="2"><span>02</span> Profissional</li>
          <li data-indice="3"><span>03</span> Dia e hora</li>
          <li data-indice="4"><span>04</span> Seus dados</li>
        </ol>

        <fieldset class="passo" data-passo="1">
          <legend><span class="passo-num">01</span> O que você precisa?</legend>
          <div class="lista-proc" role="radiogroup" aria-label="Tratamento">
            <?php foreach ($procs as $p): $blocos = (int)$p['duracao_min'] / 30; ?>
            <label class="proc">
              <input type="radio" name="procedimento" value="<?= (int)$p['id'] ?>">
              <span class="proc-nome"><?= e($p['nome']) ?></span>
              <span class="proc-resumo"><?= e($p['resumo']) ?></span>
              <span class="proc-dur" aria-label="Duração: <?= (int)$p['duracao_min'] ?> minutos">
                <span class="dur-blocos" aria-hidden="true"><?= str_repeat('<i></i>', $blocos) ?></span><?= (int)$p['duracao_min'] ?> min
              </span>
            </label>
            <?php endforeach; ?>
          </div>
        </fieldset>

        <fieldset class="passo" data-passo="2" disabled>
          <legend><span class="passo-num">02</span> Com quem?</legend>
          <div class="lista-dent" role="radiogroup" aria-label="Profissional">
            <label class="dent dent-qualquer">
              <input type="radio" name="dentista" value="0">
              <span class="dent-avatar" aria-hidden="true">✳︎</span>
              <span class="dent-nome">Primeiro horário livre</span>
              <span class="dent-esp">Quem estiver disponível antes</span>
            </label>
            <?php foreach ($dents as $d): ?>
            <label class="dent" data-procs="<?= e(implode(',', $d['procedimentos'])) ?>">
              <input type="radio" name="dentista" value="<?= (int)$d['id'] ?>">
              <span class="dent-avatar cor-<?= e($d['cor']) ?>" aria-hidden="true"><?= e(iniciais($d['nome'])) ?></span>
              <span class="dent-nome"><?= e($d['nome']) ?></span>
              <span class="dent-esp"><?= e($d['especialidade']) ?></span>
            </label>
            <?php endforeach; ?>
          </div>
        </fieldset>

        <fieldset class="passo" data-passo="3" disabled>
          <legend><span class="passo-num">03</span> Quando fica bom?</legend>
          <div class="dias-wrap">
            <div class="dias" id="dias" role="radiogroup" aria-label="Dia"></div>
          </div>
          <div class="horarios" id="horarios" aria-live="polite"></div>
        </fieldset>

        <fieldset class="passo" data-passo="4" disabled>
          <legend><span class="passo-num">04</span> Para quem é a consulta?</legend>
          <div class="campos">
            <div class="campo">
              <label for="nome">Nome completo</label>
              <input id="nome" name="nome" type="text" autocomplete="name" maxlength="80" required>
            </div>
            <div class="campo">
              <label for="whatsapp">WhatsApp com DDD</label>
              <input id="whatsapp" name="whatsapp" type="tel" inputmode="tel" autocomplete="tel-national" maxlength="16" placeholder="(17) 99999-9999" required aria-describedby="whats-ajuda">
              <small id="whats-ajuda">É por ele que a clínica confirma o horário.</small>
            </div>
            <div class="campo campo-largo">
              <label for="observacao">Algo que a recepção deva saber? <span class="opcional">(opcional)</span></label>
              <textarea id="observacao" name="observacao" rows="2" maxlength="200" aria-describedby="obs-ajuda"></textarea>
              <small id="obs-ajuda">Ex.: prefere ser chamado pelo apelido. Não precisa contar detalhes de saúde aqui.</small>
            </div>
            <div class="campo-isca" aria-hidden="true">
              <label for="site">Deixe em branco</label>
              <input id="site" name="site" type="text" tabindex="-1" autocomplete="off">
            </div>
            <label class="consentimento campo-largo">
              <input type="checkbox" name="consentimento" id="consentimento" required>
              <span>Autorizo a clínica a usar meu nome e WhatsApp só para cuidar deste agendamento. <a href="/privacidade.php" target="_blank" rel="noopener">Como meus dados são tratados</a>.</span>
            </label>
          </div>
          <p class="erro-form" id="erro-form" role="alert" hidden></p>
          <button class="btn btn-escuro btn-enviar" type="submit">
            <span class="txt">Confirmar agendamento</span><span class="carregando" aria-hidden="true"></span>
          </button>
        </fieldset>
      </form>

      <section class="sucesso" id="sucesso" hidden tabindex="-1" aria-labelledby="sucesso-titulo">
        <p class="carimbo" aria-hidden="true">Reservado</p>
        <h2 id="sucesso-titulo">Horário reservado, <span data-s="nome"></span>.</h2>
        <p class="sucesso-texto">Guardamos <strong data-s="quando"></strong> para você. A recepção confirma pelo WhatsApp. Se quiser adiantar, mande a mensagem pronta abaixo.</p>
        <div class="sucesso-acoes">
          <a class="btn btn-lima" data-s-href="whatsapp" href="#" rel="noopener" target="_blank">Avisar a clínica no WhatsApp</a>
          <a class="btn btn-contorno" data-s-href="ics" href="#">Salvar na minha agenda</a>
        </div>
        <div class="sucesso-link">
          <p><strong>Guarde este link.</strong> Por ele você vê ou cancela a consulta. Ele não aparece de novo.</p>
          <div class="copiar">
            <input type="text" readonly id="link-gestao" aria-label="Link para ver ou cancelar a consulta">
            <button class="btn btn-contorno btn-pequeno" type="button" id="copiar-link">Copiar</button>
          </div>
        </div>
        <p class="preparo" data-s="preparo" hidden></p>
      </section>

      <aside class="bilhete" id="bilhete" aria-label="Resumo do agendamento">
        <div class="bilhete-topo">
          <span class="marca-mini"><?= marca_svg() ?></span>
          <span class="bilhete-rotulo" id="bilhete-rotulo">Seu horário</span>
          <span class="bilhete-codigo" id="bilhete-codigo" aria-hidden="true">— — —</span>
        </div>
        <dl class="bilhete-dados">
          <div><dt>Tratamento</dt><dd data-campo="procedimento">a escolher</dd></div>
          <div><dt>Profissional</dt><dd data-campo="dentista">a escolher</dd></div>
          <div><dt>Dia</dt><dd data-campo="dia">a escolher</dd></div>
          <div><dt>Hora</dt><dd data-campo="hora" class="bilhete-hora">--:--</dd></div>
        </dl>
        <div class="bilhete-picote" aria-hidden="true"></div>
        <p class="bilhete-rodape" id="bilhete-rodape">Nada é reservado até você confirmar.</p>
      </aside>
    </div>

  </section>

  <section class="equipe" id="equipe" aria-labelledby="equipe-titulo">
    <div class="equipe-cabeca">
      <p class="sobretitulo sobretitulo-escuro">Equipe</p>
      <h2 id="equipe-titulo">Três pessoas, <em>uma agenda</em>.</h2>
      <p>Todo mundo enxerga os mesmos horários. Quando você reserva, o horário some para os outros na hora.</p>
    </div>
    <ol class="equipe-lista">
      <?php foreach ($dents as $i => $d): ?>
      <li class="pessoa">
        <span class="pessoa-num" aria-hidden="true">0<?= $i + 1 ?></span>
        <span class="dent-avatar dent-avatar-g cor-<?= e($d['cor']) ?>" aria-hidden="true"><?= e(iniciais($d['nome'])) ?></span>
        <div>
          <h3><?= e($d['nome']) ?></h3>
          <p class="pessoa-esp"><?= e($d['especialidade']) ?> · <span class="placeholder"><?= e($d['registro']) ?></span></p>
          <p><?= e($d['bio']) ?></p>
        </div>
      </li>
      <?php endforeach; ?>
    </ol>
  </section>

  <section class="antes" id="antes" aria-labelledby="antes-titulo">
    <h2 id="antes-titulo">Antes de vir</h2>
    <div class="faq">
      <details>
        <summary>Preciso confirmar o horário?</summary>
        <p>A recepção manda uma mensagem pelo WhatsApp. Enquanto isso, o horário já fica guardado no seu nome.</p>
      </details>
      <details>
        <summary>E se eu não puder ir?</summary>
        <p>Use o link que aparece depois de agendar para cancelar. O horário volta a ficar livre para outra pessoa.</p>
      </details>
      <details>
        <summary>É urgência?</summary>
        <p>Escolha “Dor ou urgência” para o primeiro encaixe. Com febre, inchaço que aumenta rápido ou dificuldade para respirar, procure um pronto-socorro.</p>
      </details>
      <details>
        <summary>O que vocês fazem com meus dados?</summary>
        <p>Só nome e WhatsApp, usados para este agendamento. Depois de 180 dias, os dados são apagados. <a href="/privacidade.php">Detalhes</a>.</p>
      </details>
    </div>
  </section>
</main>

<script type="application/json" id="dados"><?= json_encode($dadosJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="/assets/js/hero.js?v=1" defer></script>
<script src="/assets/js/agendar.js?v=1" defer></script>
<?php rodape_pagina();
