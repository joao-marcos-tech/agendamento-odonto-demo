# Agendamento online para consultório odontológico (demo NEXTECH)

Demo de uma clínica **fictícia**, a *Esmalte · Estúdio de odontologia*. O paciente escolhe tratamento, profissional, dia e um horário que está livre de verdade, e reserva em menos de um minuto pelo celular. A recepção tem um painel com senha para ver a agenda do dia, confirmar, remarcar, cancelar, fazer encaixes e configurar expediente e folgas.

Clínica, profissionais, registros de CRO, pacientes e horários são fictícios. A página e o painel mostram isso em todo lugar.

Stack: PHP 8.1+ com SQLite (PDO), HTML, CSS e JS puro. Sem framework, sem CDN, sem build.

## Duas versões neste repositório

| Versão | Pasta | Para quê |
|---|---|---|
| **PHP + SQLite** | `app/`, `bin/`, `public/` | O sistema de verdade, para rodar num servidor com PHP. |
| **Estática (demo online)** | `demo/` | A que vai para a Netlify, que não roda PHP. |

Na versão estática, o arquivo `demo/assets/js/demo/servidor.js` faz no navegador o papel do PHP: responde nos mesmos endereços (`/api/agendar.php`, `/admin/api/agenda.php`...) com o mesmo JSON. Por isso `agendar.js`, `admin.js` e `consulta.js` são os mesmos arquivos nas duas versões. Os dados ficam só no `sessionStorage` da aba e somem ao fechá-la: nada é enviado para servidor nenhum.

- Painel da demo: `/admin/`, usuário `recepcao`, senha `demo-esmalte` (pública, só da demo; o login é simulado no navegador e **não** protege nada).
- Testar local: abra a pasta `demo/` com o Live Server, ou `cd demo && python3 -m http.server 8000`.
- Publicação: `netlify.toml` publica só a pasta `demo/`, com os cabeçalhos de segurança em `demo/_headers` (CSP só `'self'`).
- Para virar produção com banco na nuvem (ex.: Supabase), troque só o `servidor.js` por chamadas reais. A reserva precisa continuar no servidor, numa função do banco com chave única por profissional + horário, e o painel protegido por login de verdade e RLS.
- Se mudar CSS, fontes ou os JS compartilhados em `public/assets/`, copie para `demo/assets/` também.

## Como rodar (local)

Precisa de PHP 8.1+ com `pdo_sqlite` (e `curl` só se for usar o webhook). No CachyOS/Arch:

```bash
sudo pacman -S php php-sqlite
# em /etc/php/php.ini, descomente: extension=pdo_sqlite e extension=sqlite3
```

Na pasta do projeto:

```bash
php bin/setup.php                  # cria o banco, os dados de exemplo e o usuário "recepcao"
php -S localhost:8000 -t public    # sobe o servidor
```

O `setup.php` mostra a senha do painel **uma vez** no terminal. Para escolher a senha:
`AGENDA_SENHA='umaSenhaForte' php bin/setup.php --reset` (mínimo 10 caracteres).

- Site do paciente: http://localhost:8000
- Painel da recepção: http://localhost:8000/admin/ (usuário `recepcao`)

`--reset` apaga tudo e recria, com consultas de exemplo para hoje e os próximos dias.
Para testar no celular: `php -S 0.0.0.0:8000 -t public` e abra `http://IP-DO-NOTEBOOK:8000`.

## Testes

```bash
php bin/setup.php --reset
PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:8000 -t public &
bash bin/testar-seguranca.sh http://127.0.0.1:8000
```

São 30 verificações: cabeçalhos, cookie, CSRF, Origin, método, SQLi, HTML no nome, consentimento, campo isca, **10 reservas ao mesmo tempo para o mesmo horário** (só 1 passa), rate limit, link falso do paciente, painel sem login e força bruta no login. Rode só no seu servidor local. Depois do teste, rode `--reset` de novo.

## O que tem

**Paciente (site)**
- 4 passos numa tela só: tratamento → profissional (ou "primeiro horário livre") → dia e hora → nome e WhatsApp.
- Faixa de dias com pontinhos mostrando quanto ainda está livre; dias lotados aparecem riscados.
- "Bilhete" lateral que vai se preenchendo (no celular vira uma barra fixa embaixo).
- Se outra pessoa pegar o horário um segundo antes, a tela avisa e recarrega os horários sem perder o resto.
- Depois de reservar: código, mensagem pronta para a clínica no WhatsApp (`wa.me`), arquivo `.ics` para a agenda do celular e um **link secreto** para ver ou cancelar.
- Topo com o próximo horário livre real.

**Recepção (`/admin/`)**
- Agenda do dia em colunas por profissional, com expediente, folgas, linha do "agora" e cores por situação. No celular vira lista.
- Fila "A confirmar" com o que chegou pelo site. Atualiza sozinha a cada 30 s e avisa quando chega agendamento novo.
- Gaveta da consulta: WhatsApp do paciente com mensagem pronta, confirmar, remarcar (só mostra horários livres), atendido, não veio, cancelar.
- Encaixe para quem ligou ou chegou no balcão.
- *Horários e folgas*: expediente de cada profissional (dois turnos por dia) e bloqueios por período (feriado, curso, folga).

## Onde mexer

| O quê | Onde |
|---|---|
| WhatsApp que recebe as confirmações | `app/config.php` → `whatsapp` (hoje é o da NEXTECH, para a demo) ou variável `AGENDA_WHATSAPP` |
| Nome, endereço, responsável técnico | `app/config.php` |
| Antecedência mínima, janela de dias, limites | `app/config.php` |
| Tratamentos, profissionais e expediente inicial | `bin/setup.php` (depois `--reset`) |
| Cores e fontes | `public/assets/css/site.css` e `admin.css` (variáveis no `:root`) |
| Vídeo do topo | `public/assets/video/` (gerado por `bin/gerar-video.py`) |

## Como o horário duplicado é evitado

Cada consulta ocupa pedaços de 30 min na tabela `agenda_slots`, que tem `PRIMARY KEY (dentista_id, slot)`. Ao reservar:

1. `BEGIN IMMEDIATE` trava a escrita no SQLite, então duas reservas nunca conferem ao mesmo tempo;
2. a disponibilidade é calculada **de novo dentro da transação**;
3. se mesmo assim algo escapar, o banco recusa o pedaço repetido e a transação é desfeita.

Testado com 20 processos tentando o mesmo horário ao mesmo tempo: 1 reserva, 19 recusas.

## Webhook para o n8n (opcional)

Defina as duas variáveis no servidor e cada evento (`agendamento.criado`, `.confirmado`, `.cancelado`, `.remarcado`, `.concluido`, `.faltou`) vai para o n8n assinado:

```bash
export AGENDA_WEBHOOK_URL='https://seu-n8n/webhook/agenda'
export AGENDA_WEBHOOK_SECRET='um-segredo-longo-e-aleatorio'
```

Cabeçalhos: `X-Agenda-Timestamp` e `X-Agenda-Signature: sha256=HMAC_SHA256(timestamp + "." + corpo, segredo)`. Só aceita HTTPS (HTTP só para `localhost`/`n8n` na mesma máquina), não segue redirecionamento, tem timeout curto e roda depois de responder ao paciente (no PHP-FPM). Se o n8n cair, o agendamento não falha: só fica no log.

No n8n, ative *Raw Body* no Webhook e confira a assinatura num nó Code antes de qualquer coisa:

```js
const crypto = require('crypto');
const h = $input.first().json.headers;
const corpo = Buffer.from($input.first().binary.data.data, 'base64').toString('utf8');
const ts = h['x-agenda-timestamp'];
const esperado = 'sha256=' + crypto.createHmac('sha256', $env.AGENDA_WEBHOOK_SECRET).update(ts + '.' + corpo).digest('hex');
const ok = h['x-agenda-signature'] && esperado.length === h['x-agenda-signature'].length
  && crypto.timingSafeEqual(Buffer.from(esperado), Buffer.from(h['x-agenda-signature']))
  && Math.abs(Date.now() / 1000 - Number(ts)) < 300;
if (!ok) throw new Error('assinatura inválida');
return [{ json: JSON.parse(corpo) }];
```

Ideias de fluxo: lembrete no WhatsApp 24 h antes, planilha de faltas, aviso no celular da recepção.

## Segurança (regras NEXTECH aplicadas)

- PDO com prepared statements em tudo, sem emulação; listas de `IN (...)` só com placeholders; status por whitelist.
- Toda saída PHP com `htmlspecialchars(ENT_QUOTES | ENT_SUBSTITUTE)`; no JS, só `textContent` e `createElement` (sem `innerHTML`, sem `eval`). Variáveis de CSS via CSSOM, compatível com a CSP.
- Senha com `password_hash` / `password_verify` + rehash; verificação "falsa" quando o usuário não existe (o tempo não entrega quais usuários existem).
- Sessão: `HttpOnly`, `SameSite=Strict`, `Secure` e prefixo `__Host-` em HTTPS, `use_strict_mode`, `session_regenerate_id(true)` no login, expira com 1 h parado ou 10 h no total.
- CSRF com token de sessão e `hash_equals` em todo formulário e API que altera algo; nas APIs JSON, também confere o `Origin`.
- Rate limit: login (5 por usuário+IP e 20 por IP em 15 min), agendamento (5 por IP por hora), consultas de horários, link do paciente. IP guardado só como hash.
- Limite de 2 consultas futuras por WhatsApp e campo isca contra robôs.
- Link do paciente com token aleatório de 192 bits; no banco fica só o SHA-256. Página com `Referrer-Policy: no-referrer` e `no-store`.
- Erros genéricos para o usuário, detalhes no log. `display_errors` desligado.
- Cabeçalhos: CSP restrita a `'self'` (inclusive vídeo e fontes), `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`, `COOP`, `CORP` e HSTS em HTTPS.
- Nada de CDN: fontes, vídeo e scripts servidos pelo próprio site (sem SRI necessário e sem vazar IP do paciente).
- Auditoria das ações do painel (quem confirmou, cancelou, remarcou, mudou expediente).
- `.htaccess` negando `app/`, `bin/` e `data/` para quem usar Apache sem apontar o DocumentRoot para `public/`.

## LGPD

- Só pede nome e WhatsApp. Observação opcional com aviso para não colocar dados de saúde.
- Consentimento obrigatório com data registrada, e página de privacidade em linguagem simples (`/privacidade.php`, texto modelo).
- Nada pessoal fica no navegador (o formulário é limpo depois de reservar).
- `bin/limpar.php` anonimiza pacientes de consultas com mais de 180 dias. Agende 1x por dia no cron: `0 3 * * * php /caminho/bin/limpar.php`.
- Atenção: o tratamento escolhido é dado de saúde (dado sensível na LGPD). Uma clínica real precisa revisar a política com quem cuida da parte jurídica.

## Antes de usar com um cliente de verdade

- Servir em **HTTPS** com PHP-FPM (o cookie passa a ser `Secure` e `__Host-` sozinho).
- Apontar o `DocumentRoot` para `public/`, trocar o WhatsApp, os textos e tirar a faixa de demonstração.
- Trocar o CRO fictício pelo registro real do responsável técnico (exigência do CFO para publicidade).
- Backup diário de `data/agenda.sqlite` fora do servidor, e testar a restauração.
- Se for atender várias clínicas no mesmo sistema, adicionar `clinica_id` em todas as tabelas e filtrar toda consulta pela clínica do usuário logado.
- Considerar 2FA no painel e um usuário por pessoa da recepção.

## Créditos

- Fontes Fraunces e Karla: SIL Open Font License (licenças em `public/assets/fonts/`).
- Vídeo do topo: gerado do zero por código para esta demo (`bin/gerar-video.py`, Python + NumPy + ffmpeg). Sem música, sem imagens de terceiros, sem restrição de uso. Pausa automática para quem pede menos movimento ou está economizando dados, e botão para pausar.
- Marca e ilustrações: feitas para esta demo.

Demonstração feita pela NEXTECH. Nada aqui é uma clínica real.
