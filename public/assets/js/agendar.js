// Fluxo de agendamento do paciente. Sem innerHTML: todo texto entra por textContent.
(() => {
  'use strict';

  const form = document.getElementById('fluxo');
  if (!form) return;

  const dados = JSON.parse(document.getElementById('dados').textContent);
  const csrf = form.dataset.csrf;
  const calmo = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const passos = [...form.querySelectorAll('.passo')];
  const indice = [...form.querySelectorAll('.passos-indice li')];
  const diasEl = document.getElementById('dias');
  const horasEl = document.getElementById('horarios');
  const erroEl = document.getElementById('erro-form');
  const bilhete = document.getElementById('bilhete');
  const estado = { proc: null, dent: null, data: null, hora: null };

  const SEMANA = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];
  const SEMANA_LONGA = ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'];
  const MES = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

  // Cria elementos com segurança (texto sempre como textContent).
  function el(tag, props = {}, filhos = []) {
    const n = document.createElement(tag);
    for (const [k, v] of Object.entries(props)) {
      if (k === 'texto') n.textContent = v;
      else if (k === 'classe') n.className = v;
      else if (k === 'i') n.style.setProperty('--i', v); // CSSOM: permitido pela CSP
      else if (k.startsWith('data-') || k.startsWith('aria-') || k === 'role' || k === 'for') n.setAttribute(k, v);
      else n[k] = v;
    }
    for (const f of [].concat(filhos)) if (f) n.append(f);
    return n;
  }

  const paraData = (s) => { const [a, m, d] = s.split('-').map(Number); return new Date(a, m - 1, d); };
  const hojeTxt = () => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; };

  function diaLegivel(s) {
    const d = paraData(s);
    const amanha = new Date(); amanha.setDate(amanha.getDate() + 1);
    if (s === hojeTxt()) return `Hoje, ${d.getDate()} ${MES[d.getMonth()]}`;
    if (d.toDateString() === amanha.toDateString()) return `Amanhã, ${d.getDate()} ${MES[d.getMonth()]}`;
    return `${SEMANA_LONGA[d.getDay()]}, ${d.getDate()} ${MES[d.getMonth()]}`;
  }

  async function api(url, opcoes = {}) {
    const r = await fetch(url, {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json', ...(opcoes.body ? { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf } : {}) },
      ...opcoes,
    });
    let corpo = {};
    try { corpo = await r.json(); } catch { /* resposta vazia */ }
    if (!r.ok) { const e = new Error(corpo.erro || 'Não foi possível carregar agora.'); e.dados = corpo; e.status = r.status; throw e; }
    return corpo;
  }

  // ---------- Bilhete (resumo lateral) ----------
  function bilheteSet(campo, valor) {
    const dd = bilhete.querySelector(`[data-campo="${campo}"]`);
    const novo = valor || (campo === 'hora' ? '--:--' : 'a escolher');
    if (dd.textContent === novo) return;
    dd.textContent = novo;
    dd.classList.toggle('vazio', !valor);
    dd.classList.remove('mudou'); void dd.offsetWidth; dd.classList.add('mudou');
  }

  // ---------- Passos ----------
  function ativarAte(n) {
    passos.forEach((p) => {
      const k = Number(p.dataset.passo);
      p.disabled = k > n;
      p.classList.toggle('feito', k < n);
      p.classList.toggle('atual', k === n);
    });
    indice.forEach((li) => {
      const k = Number(li.dataset.indice);
      li.classList.toggle('feito', k < n);
      if (k === n) li.setAttribute('aria-current', 'step'); else li.removeAttribute('aria-current');
    });
  }

  function irPara(n, focar) {
    ativarAte(n);
    const alvo = passos[n - 1];
    requestAnimationFrame(() => {
      const r = alvo.getBoundingClientRect();
      if (r.top < 60 || r.top > window.innerHeight * 0.55) {
        alvo.scrollIntoView({ behavior: calmo ? 'auto' : 'smooth', block: 'start' });
      }
      if (focar) focar.focus({ preventScroll: true });
    });
  }

  function limparDepoisDe(n) {
    if (n < 2) { estado.dent = null; form.querySelectorAll('input[name="dentista"]').forEach((i) => { i.checked = false; }); bilheteSet('dentista', ''); }
    if (n < 3) { estado.data = null; diasEl.replaceChildren(); bilheteSet('dia', ''); }
    if (n < 4) { estado.hora = null; horasEl.replaceChildren(); bilheteSet('hora', ''); }
  }

  // 1. Tratamento
  form.addEventListener('change', (ev) => {
    const t = ev.target;
    if (t.name === 'procedimento') {
      estado.proc = dados.procedimentos.find((p) => p.id === Number(t.value));
      bilheteSet('procedimento', estado.proc.nome);
      limparDepoisDe(1);
      // Mostra só quem faz o tratamento escolhido.
      form.querySelectorAll('.dent[data-procs]').forEach((l) => {
        l.hidden = !l.dataset.procs.split(',').map(Number).includes(estado.proc.id);
      });
      irPara(2, form.querySelector('input[name="dentista"]'));
    }
    if (t.name === 'dentista') {
      const id = Number(t.value);
      estado.dent = id;
      bilheteSet('dentista', id ? dados.dentistas.find((d) => d.id === id).nome : 'Primeiro livre');
      limparDepoisDe(2);
      irPara(3);
      carregarDias();
    }
    if (t.name === 'dia') {
      estado.data = t.value;
      bilheteSet('dia', diaLegivel(t.value));
      limparDepoisDe(3);
      carregarHoras();
    }
    if (t.name === 'hora') {
      estado.hora = t.value;
      bilheteSet('hora', t.value);
      const ids = JSON.parse(t.dataset.dentistas || '[]');
      if (!estado.dent && ids.length === 1) {
        bilheteSet('dentista', dados.dentistas.find((d) => d.id === ids[0]).nome);
      } else if (!estado.dent) {
        bilheteSet('dentista', 'Primeiro livre');
      }
      irPara(4, document.getElementById('nome'));
    }
  });

  function esqueleto(alvo, n, classe) {
    alvo.replaceChildren(...Array.from({ length: n }, () => el('span', { classe: `esqueleto ${classe}` })));
    alvo.setAttribute('aria-busy', 'true');
  }

  // 3a. Dias
  async function carregarDias() {
    esqueleto(diasEl, 10, 'esq-dia');
    try {
      const r = await api(`/api/dias.php?p=${estado.proc.id}&d=${estado.dent}`);
      const itens = r.dias.map((d, i) => {
        const dt = paraData(d.data);
        const sem = d.livres === 0;
        const input = el('input', { type: 'radio', name: 'dia', value: d.data, disabled: sem });
        const nivel = d.livres === 0 ? 0 : d.livres < 4 ? 1 : d.livres < 9 ? 2 : 3;
        return el('label', {
          classe: `dia${sem ? ' dia-sem' : ''}${dt.getDate() === 1 || i === 0 ? ' dia-mes' : ''}`,
          'aria-label': `${diaLegivel(d.data)}: ${sem ? 'sem horários' : d.livres + (d.livres === 1 ? ' horário livre' : ' horários livres')}`,
          i: i,
        }, [
          input,
          el('span', { classe: 'dia-sem-nome', texto: i === 0 ? 'hoje' : SEMANA[dt.getDay()] }),
          el('span', { classe: 'dia-num', texto: String(dt.getDate()) }),
          el('span', { classe: 'dia-mes-nome', texto: MES[dt.getMonth()] }),
          el('span', { classe: `dia-nivel n${nivel}`, 'aria-hidden': 'true' }, [el('i'), el('i'), el('i')]),
        ]);
      });
      diasEl.replaceChildren(...itens);
      const primeiro = diasEl.querySelector('input:not(:disabled)');
      if (!primeiro) {
        horasEl.replaceChildren(el('p', { classe: 'vazio-msg', texto: 'Sem horários nos próximos dias para essa escolha. Tente “Primeiro horário livre” ou outro profissional.' }));
      } else {
        horasEl.replaceChildren(el('p', { classe: 'dica', texto: 'Escolha um dia. Os pontinhos mostram quanto ainda está livre.' }));
        primeiro.focus({ preventScroll: true });
      }
    } catch (e) {
      diasEl.replaceChildren();
      horasEl.replaceChildren(el('p', { classe: 'vazio-msg', texto: e.message }));
    } finally {
      diasEl.removeAttribute('aria-busy');
    }
  }

  // 3b. Horários
  async function carregarHoras(aviso) {
    esqueleto(horasEl, 8, 'esq-hora');
    try {
      const r = await api(`/api/horarios.php?p=${estado.proc.id}&d=${estado.dent}&data=${estado.data}`);
      const grupos = [['Manhã', (h) => h < '12:00'], ['Tarde', (h) => h >= '12:00' && h < '18:00'], ['Noite', (h) => h >= '18:00']];
      const blocos = [];
      if (aviso) blocos.push(el('p', { classe: 'aviso-ocupado', role: 'alert', texto: aviso }));
      let i = 0;
      for (const [nome, filtro] of grupos) {
        const lista = r.horarios.filter((h) => filtro(h.hora));
        if (!lista.length) continue;
        blocos.push(el('div', { classe: 'turno', role: 'radiogroup', 'aria-label': nome }, [
          el('p', { classe: 'turno-nome', texto: nome }),
          el('div', { classe: 'turno-horas' }, lista.map((h) => el('label', { classe: 'hora', i: i++ }, [
            el('input', { type: 'radio', name: 'hora', value: h.hora, 'data-dentistas': JSON.stringify(h.dentistas) }),
            el('span', { texto: h.hora }),
          ]))),
        ]));
      }
      if (!r.horarios.length) blocos.push(el('p', { classe: 'vazio-msg', texto: 'Esse dia acabou de lotar. Escolha outro.' }));
      horasEl.replaceChildren(...blocos);
    } catch (e) {
      horasEl.replaceChildren(el('p', { classe: 'vazio-msg', texto: e.message }));
    } finally {
      horasEl.removeAttribute('aria-busy');
    }
  }

  // ---------- Máscara do WhatsApp ----------
  const whats = document.getElementById('whatsapp');
  whats.addEventListener('input', () => {
    let d = whats.value.replace(/\D/g, '');
    if (d.startsWith('55') && d.length > 11) d = d.slice(2);
    d = d.slice(0, 11);
    let s = d;
    if (d.length > 2) s = `(${d.slice(0, 2)}) ${d.slice(2)}`;
    if (d.length > 7) s = `(${d.slice(0, 2)}) ${d.slice(2, d.length - 4)}-${d.slice(-4)}`;
    whats.value = s;
  });

  // ---------- Envio ----------
  function mostrarErro(msg, campo) {
    erroEl.textContent = msg;
    erroEl.hidden = false;
    form.querySelectorAll('[aria-invalid]').forEach((n) => n.removeAttribute('aria-invalid'));
    const alvo = campo && form.querySelector(`[name="${campo}"]`);
    if (alvo) { alvo.setAttribute('aria-invalid', 'true'); alvo.focus(); }
  }

  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    erroEl.hidden = true;
    const nome = form.nome.value.trim();
    const tel = whats.value.replace(/\D/g, '');
    if (!estado.proc || estado.dent === null || !estado.data || !estado.hora) return mostrarErro('Escolha tratamento, profissional, dia e horário.');
    if (nome.length < 3) return mostrarErro('Escreva seu nome.', 'nome');
    if (tel.length < 10) return mostrarErro('Confira o WhatsApp: DDD + número.', 'whatsapp');
    if (!form.consentimento.checked) return mostrarErro('Marque a autorização para guardarmos o agendamento.', 'consentimento');

    const botao = form.querySelector('.btn-enviar');
    botao.disabled = true;
    botao.classList.add('enviando');
    try {
      const r = await api('/api/agendar.php', {
        method: 'POST',
        body: JSON.stringify({
          procedimento: estado.proc.id, dentista: estado.dent, data: estado.data, hora: estado.hora,
          nome, whatsapp: tel, observacao: form.observacao.value, consentimento: true, site: form.site.value,
        }),
      });
      if (r.isca) return;
      sucesso(r);
    } catch (e) {
      if (e.dados && e.dados.ocupado) {
        estado.hora = null;
        bilheteSet('hora', '');
        irPara(3);
        carregarHoras(e.message);
        carregarDiasSilencioso();
        return;
      }
      const campos = (e.dados && e.dados.campos) || {};
      mostrarErro(e.message, Object.keys(campos)[0]);
    } finally {
      botao.disabled = false;
      botao.classList.remove('enviando');
    }
  });

  async function carregarDiasSilencioso() {
    try {
      const r = await api(`/api/dias.php?p=${estado.proc.id}&d=${estado.dent}`);
      r.dias.forEach((d) => {
        const inp = diasEl.querySelector(`input[value="${d.data}"]`);
        if (inp && d.livres === 0 && !inp.checked) { inp.disabled = true; inp.parentElement.classList.add('dia-sem'); }
      });
    } catch { /* tudo bem, os horários já foram recarregados */ }
  }

  function sucesso(r) {
    const sec = document.getElementById('sucesso');
    sec.querySelector('[data-s="nome"]').textContent = r.nome;
    sec.querySelector('[data-s="quando"]').textContent = `${r.quando}, com ${r.dentista}`;
    sec.querySelector('[data-s-href="whatsapp"]').href = r.whatsapp;
    sec.querySelector('[data-s-href="ics"]').href = r.ics;
    const prep = sec.querySelector('[data-s="preparo"]');
    if (r.preparo) { prep.textContent = `Para essa consulta: ${r.preparo}`; prep.hidden = false; }
    document.getElementById('link-gestao').value = new URL(r.gestao, location.origin).href;

    bilheteSet('dentista', r.dentista);
    document.getElementById('bilhete-codigo').textContent = r.codigo;
    document.getElementById('bilhete-codigo').removeAttribute('aria-hidden');
    document.getElementById('bilhete-rotulo').textContent = 'Reservado';
    document.getElementById('bilhete-rodape').textContent = 'Apresente o código na recepção.';
    bilhete.classList.add('confirmado');

    form.hidden = true;
    sec.hidden = false;
    document.querySelector('.agendar').classList.add('concluido');
    sec.scrollIntoView({ behavior: calmo ? 'auto' : 'smooth', block: 'start' });
    sec.focus({ preventScroll: true });
    // Limpa os dados pessoais do formulário (nada fica na página nem no navegador).
    form.reset();
  }

  document.getElementById('copiar-link').addEventListener('click', async (ev) => {
    const inp = document.getElementById('link-gestao');
    try { await navigator.clipboard.writeText(inp.value); ev.currentTarget.textContent = 'Copiado'; }
    catch { inp.select(); }
  });

  ativarAte(1);
})();
