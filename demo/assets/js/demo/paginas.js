// Partes das páginas que, na versão PHP, o servidor montava (próximo horário, página da consulta,
// login, sair, horários e folgas). Aqui tudo vem do DemoServidor (servidor.js). Sem innerHTML.
(() => {
  'use strict';
  const S = window.DemoServidor;
  if (!S) return;
  const $ = (id) => document.getElementById(id);
  const el = (tag, props = {}, filhos = []) => {
    const n = Object.assign(document.createElement(tag), props);
    for (const f of [].concat(filhos)) if (f) n.append(f);
    return n;
  };

  // ---------- Início: próximo horário livre ----------
  if ($('proximo-quando')) {
    const p = S.proximoLivre();
    if (p) {
      $('proximo-quando').textContent = `${p.quando}, ${p.hora}`;
      $('proximo-dent').textContent = p.dentista;
      $('proximo').hidden = false;
    }
  }

  // ---------- Página da consulta (link secreto) ----------
  if ($('c-valido')) {
    const q = new URLSearchParams(location.search);
    const c = q.get('c') || '', t = q.get('t') || '';
    S.porToken(c, t).then((a0) => {
      if (!a0) { $('c-invalido').hidden = false; return; }
      const a = S.completo(a0);
      const ativo = S.ATIVOS.includes(a.status) && a.inicio > S.agoraTxt();
      $('c-codigo').textContent = a.codigo;
      $('c-dia').textContent = S.dataExtenso(a.inicio).replace(/^./, (x) => x.toUpperCase());
      $('c-hora').textContent = a.inicio.slice(11);
      $('c-proc').textContent = `${a.procedimento} · ${a.duracao_min} min`;
      $('c-dent').textContent = a.dentista;
      $('situacao').textContent = S.STATUS[a.status];
      $('situacao').className = `selo selo-${a.status}`;
      if (a.preparo) { $('c-preparo').textContent = a.preparo; $('c-preparo').hidden = false; }
      $('c-valido').hidden = false;
      if (!ativo) return;
      const b = $('cancelar');
      b.dataset.codigo = a.codigo;
      b.dataset.token = t;
      const ics = $('c-ics');
      ics.href = URL.createObjectURL(new Blob([arquivoIcs(a)], { type: 'text/calendar' }));
      ics.download = `consulta-${a.codigo}.ics`;
      $('c-acoes').hidden = false;
      if (q.has('ics')) ics.click();
    });
  }

  function arquivoIcs(a) {
    const utc = (s) => { const [d, h] = s.split(' '); const [y, m, dd] = d.split('-').map(Number); const [hh, mm] = h.split(':').map(Number); return new Date(y, m - 1, dd, hh, mm).toISOString().replace(/[-:]/g, '').replace(/\.\d{3}/, ''); };
    const esc = (x) => x.replace(/\\/g, '\\\\').replace(/;/g, '\\;').replace(/,/g, '\\,').replace(/\n/g, '\\n');
    return [
      'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//NEXTECH//Agenda demo//PT', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
      'BEGIN:VEVENT', `UID:${a.codigo}@agenda-demo`, `DTSTAMP:${utc(S.agoraTxt())}`, `DTSTART:${utc(a.inicio)}`, `DTEND:${utc(a.fim)}`,
      `SUMMARY:${esc(`${a.procedimento} · ${S.CFG.clinica}`)}`, `DESCRIPTION:${esc(`Com ${a.dentista}. Código ${a.codigo}. (Demonstração)`)}`,
      `LOCATION:${esc(S.CFG.endereco)}`, 'BEGIN:VALARM', 'TRIGGER:-PT2H', 'ACTION:DISPLAY', 'DESCRIPTION:Consulta em 2 horas', 'END:VALARM',
      'END:VEVENT', 'END:VCALENDAR', '',
    ].join('\r\n');
  }

  // ---------- Login (dados de acesso públicos da demo) ----------
  if ($('login')) {
    if (S.logado()) location.replace('/admin/');
    $('login').addEventListener('submit', (ev) => {
      ev.preventDefault();
      if (S.entrar($('usuario').value.trim(), $('senha').value)) { location.href = '/admin/'; return; }
      $('erro').textContent = 'Usuário ou senha incorretos. Use os dados que já vêm preenchidos.';
      $('erro').hidden = false;
    });
  }

  // ---------- Sair ----------
  if ($('sair')) $('sair').addEventListener('click', () => { S.sair(); location.href = '/admin/login.html'; });

  // ---------- Horários e folgas ----------
  if ($('bloq-lista')) {
    const msg = (texto, erro) => {
      $('msg').hidden = !!erro; $('erro').hidden = !erro;
      (erro ? $('erro') : $('msg')).textContent = texto;
      window.scrollTo({ top: 0 });
    };
    const horaOk = (h) => (/^([01]\d|2[0-3]):(00|30)$/.test(h) ? h : null);

    function preencher() {
      const db = S.ler();
      document.querySelectorAll('.exp-form').forEach((f) => {
        const did = Number(f.elements.dentista.value);
        for (let dia = 0; dia < 7; dia++) {
          const turnos = db.expediente.filter((e) => e.dentista_id === did && e.dia_semana === dia).sort((x, y) => (x.inicio < y.inicio ? -1 : 1));
          for (const t of [1, 2]) {
            f.elements[`d${dia}i${t}`].value = turnos[t - 1] ? turnos[t - 1].inicio : '';
            f.elements[`d${dia}f${t}`].value = turnos[t - 1] ? turnos[t - 1].fim : '';
          }
        }
      });
      const agora = S.agoraTxt();
      const fmt = (s) => { const [d, h] = s.split(' '); const [, m, dd] = d.split('-'); return `${dd}/${m} ${h === '24:00' ? '23:59' : h}`; };
      const lista = db.bloqueios.filter((b) => b.fim >= agora).sort((x, y) => (x.inicio < y.inicio ? -1 : 1));
      $('bloq-lista').replaceChildren(...lista.map((b) => el('li', {}, [
        el('span', {}, [el('strong', { textContent: b.dentista_id ? S.DENTS.find((d) => d.id === b.dentista_id).nome : 'Clínica inteira' }),
          ` · ${fmt(b.inicio)} até ${fmt(b.fim)}${b.motivo ? ' · ' + b.motivo : ''}`]),
        el('button', { type: 'button', className: 'b b-fantasma b-p', textContent: 'Remover', onclick: () => { S.desbloquear(b.id); preencher(); msg('Bloqueio removido.'); } }),
      ])));
      $('bloq-vazio').hidden = lista.length > 0;
      $('bloq-lista').hidden = lista.length === 0;
    }

    document.querySelectorAll('.exp-form').forEach((f) => f.addEventListener('submit', (ev) => {
      ev.preventDefault();
      const linhas = [];
      for (const dia of [0, 1, 2, 3, 4, 5, 6]) {
        let ult = '';
        for (const t of [1, 2]) {
          const i = f.elements[`d${dia}i${t}`].value, fim = f.elements[`d${dia}f${t}`].value;
          if (!i && !fim) continue;
          const hi = horaOk(i), hf = horaOk(fim);
          if (!hi || !hf || hf <= hi || hi < ult) {
            return msg(`Confira os horários de ${S.DIAS[dia]}: use horas cheias ou meias (ex.: 08:00, 13:30) e o fim depois do início.`, true);
          }
          ult = hf;
          linhas.push([dia, hi, hf]);
        }
      }
      S.salvarExpediente(Number(f.elements.dentista.value), linhas);
      preencher();
      msg('Expediente salvo. Consultas já marcadas continuam como estão.');
    }));

    const fb = document.querySelector('.bloq-form');
    const hoje = S.ymd(new Date());
    fb.elements.data_ini.min = hoje; fb.elements.data_fim.min = hoje;
    fb.addEventListener('submit', (ev) => {
      ev.preventDefault();
      const e = fb.elements;
      const did = e.dentista.value === 'todos' ? null : Number(e.dentista.value);
      const di = e.data_ini.value, df = e.data_fim.value || di;
      const hi = horaOk(e.hora_ini.value) || '00:00', hf = horaOk(e.hora_fim.value) || '24:00';
      if (!/^\d{4}-\d{2}-\d{2}$/.test(di) || !/^\d{4}-\d{2}-\d{2}$/.test(df) || `${df} ${hf}` <= `${di} ${hi}`) return msg('Confira o período do bloqueio.', true);
      S.bloquear(did, `${di} ${hi}`, `${df} ${hf}`, e.motivo.value.replace(/[\x00-\x1F\x7F]+/g, ' ').trim().slice(0, 60));
      fb.reset();
      preencher();
      msg('Bloqueio criado. Esses horários sumiram do site.');
    });

    preencher();
  }
})();
