// Painel da recepção. Sem innerHTML: todo dado do paciente entra por textContent.
(() => {
  'use strict';

  const csrf = document.body.dataset.csrf;
  const dados = JSON.parse(document.getElementById('dados').textContent);
  const grade = document.getElementById('grade');
  const fila = document.getElementById('fila');
  const resumo = document.getElementById('resumo');
  const titulo = document.getElementById('titulo-dia');
  const seletor = document.getElementById('escolher-data');
  const toast = document.getElementById('toast');
  const detalhe = document.getElementById('detalhe');
  const marcar = document.getElementById('marcar');
  const PASSO = 30; // minutos por linha
  let atual = null; // resposta da API do dia
  let dataAtual = hojeTxt();
  let aberto = null; // agendamento aberto na gaveta
  let modoMarcar = 'novo';
  let conhecidos = null; // ids pendentes já vistos (para destacar novos)

  function hojeTxt(d = new Date()) {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  }
  function somarDias(s, n) { const [a, m, d] = s.split('-').map(Number); return hojeTxt(new Date(a, m - 1, d + n)); }
  const min = (h) => { const [a, b] = h.split(':').map(Number); return a * 60 + b; };
  const hhmm = (m) => `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`;

  function el(tag, props = {}, filhos = []) {
    const n = document.createElement(tag);
    for (const [k, v] of Object.entries(props)) {
      if (v === undefined || v === null) continue;
      if (k === 'texto') n.textContent = v;
      else if (k === 'classe') n.className = v;
      else if (k === 'vars') for (const [vk, vv] of Object.entries(v)) n.style.setProperty(vk, vv);
      else if (k === 'on') for (const [ev, fn] of Object.entries(v)) n.addEventListener(ev, fn);
      else if (k.startsWith('data-') || k.startsWith('aria-') || k === 'role') n.setAttribute(k, v);
      else n[k] = v;
    }
    for (const f of [].concat(filhos)) if (f !== null && f !== undefined && f !== false) n.append(f);
    return n;
  }

  async function api(url, corpo) {
    const r = await fetch(url, {
      method: corpo ? 'POST' : 'GET',
      credentials: 'same-origin',
      headers: corpo ? { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, Accept: 'application/json' } : { Accept: 'application/json' },
      body: corpo ? JSON.stringify(corpo) : undefined,
    });
    if (r.status === 401) { location.href = '/admin/login.php'; throw new Error('Sessão expirada.'); }
    const j = await r.json().catch(() => ({}));
    if (!r.ok) { const e = new Error(j.erro || 'Não foi possível concluir.'); e.dados = j; throw e; }
    return j;
  }

  function avisar(msg) {
    toast.textContent = msg;
    toast.classList.add('on');
    clearTimeout(avisar.t);
    avisar.t = setTimeout(() => toast.classList.remove('on'), 3200);
  }

  // ---------- Carregar e desenhar ----------
  async function carregar(data = dataAtual, silencioso = false) {
    dataAtual = data;
    seletor.value = data;
    if (!silencioso) grade.setAttribute('aria-busy', 'true');
    try {
      atual = await api(`/admin/api/agenda.php?data=${data}`);
      desenhar();
    } catch (e) {
      if (!silencioso) avisar(e.message);
    } finally {
      grade.removeAttribute('aria-busy');
    }
  }

  function desenhar() {
    const a = atual;
    titulo.replaceChildren(
      el('span', { classe: 'dia-rel', texto: a.data === a.hoje ? 'Hoje' : a.data === somarDias(a.hoje, 1) ? 'Amanhã' : a.data === somarDias(a.hoje, -1) ? 'Ontem' : '' }),
      el('span', { texto: a.dataExtenso }),
    );

    // Resumo
    const ativos = a.agendamentos.filter((g) => g.status !== 'cancelado');
    const n = (s) => a.agendamentos.filter((g) => g.status === s).length;
    resumo.replaceChildren(...[
      ['Consultas', ativos.length, ''],
      ['A confirmar', n('pendente'), 'r-pendente'],
      ['Confirmadas', n('confirmado'), 'r-confirmado'],
      ['Atendidas', n('concluido'), 'r-concluido'],
    ].map(([rot, val, cls]) => el('div', { classe: `r ${cls}` }, [el('dt', { texto: rot }), el('dd', { texto: String(val) })])));

    // Janela de horas do dia
    let ini = 8 * 60, fim = 18 * 60;
    for (const d of a.dentistas) for (const [i, f] of d.expediente) { ini = Math.min(ini, min(i)); fim = Math.max(fim, min(f)); }
    for (const g of a.agendamentos) { ini = Math.min(ini, min(g.inicio)); fim = Math.max(fim, min(g.fim)); }
    ini = Math.floor(ini / 60) * 60; fim = Math.ceil(fim / 60) * 60;
    const linhas = (fim - ini) / PASSO;

    grade.style.setProperty('--linhas', linhas);
    grade.style.setProperty('--cols', a.dentistas.length);

    const horas = el('div', { classe: 'g-horas', 'aria-hidden': 'true' });
    for (let m = ini; m < fim; m += 60) horas.append(el('span', { texto: hhmm(m), vars: { '--l': (m - ini) / PASSO } }));

    const cols = a.dentistas.map((d) => {
      const col = el('div', { classe: 'g-col', role: 'list', 'aria-label': d.nome });
      // fundo: fora do expediente fica hachurado
      let cursor = ini;
      const fechados = [];
      for (const [i, f] of d.expediente) { if (min(i) > cursor) fechados.push([cursor, min(i)]); cursor = Math.max(cursor, min(f)); }
      if (cursor < fim) fechados.push([cursor, fim]);
      for (const [i, f] of fechados) col.append(el('div', { classe: 'g-fechado', 'aria-hidden': 'true', vars: { '--i': (i - ini) / PASSO, '--d': (f - i) / PASSO } }));
      for (const b of a.bloqueios.filter((b) => b.dentistaId === null || b.dentistaId === d.id)) {
        const i = Math.max(min(b.inicio), ini), f = Math.min(min(b.fim), fim);
        if (f > i) col.append(el('div', { classe: 'g-bloq', vars: { '--i': (i - ini) / PASSO, '--d': (f - i) / PASSO } }, [el('span', { texto: b.motivo || 'Bloqueado' })]));
      }
      for (const g of a.agendamentos.filter((g) => g.dentistaId === d.id && g.status !== 'cancelado')) {
        const dur = (min(g.fim) - min(g.inicio)) / PASSO;
        col.append(el('button', {
          type: 'button', classe: `g-ag st-${g.status}${dur < 2 ? ' curto' : ''}`, role: 'listitem',
          'aria-label': `${g.inicio} a ${g.fim}, ${g.paciente}, ${g.procedimento}, ${g.statusNome}`,
          vars: { '--i': (min(g.inicio) - ini) / PASSO, '--d': dur },
          on: { click: () => abrir(g) },
        }, [
          el('span', { classe: 'g-ag-hora', texto: `${g.inicio}–${g.fim}` }),
          el('span', { classe: 'g-ag-nome', texto: g.paciente }),
          el('span', { classe: 'g-ag-proc', texto: g.procedimento }),
        ]));
      }
      return col;
    });

    const cabeca = el('div', { classe: 'g-cabeca' }, [el('span'), ...a.dentistas.map((d) => el('span', { classe: 'g-dent' }, [
      el('i', { classe: `ponto cor-${d.cor}`, 'aria-hidden': 'true' }), el('span', { texto: d.nome }),
      el('small', { texto: d.expediente.length ? d.expediente.map(([i, f]) => `${i}–${f}`).join(' · ') : 'sem expediente' }),
    ]))]);

    const corpo = el('div', { classe: 'g-corpo' }, [horas, ...cols]);
    if (a.data === a.hoje && min(a.agora) >= ini && min(a.agora) <= fim) {
      corpo.append(el('div', { classe: 'g-agora', 'aria-hidden': 'true', vars: { '--i': (min(a.agora) - ini) / PASSO } }, [el('span', { texto: a.agora })]));
    }
    grade.replaceChildren(cabeca, corpo);

    // Lista (celular)
    const lista = el('ol', { classe: 'lista-dia' }, a.agendamentos.length ? a.agendamentos.map((g) => el('li', {}, [
      el('button', { type: 'button', classe: `l-ag st-${g.status}`, on: { click: () => abrir(g) } }, [
        el('span', { classe: 'l-hora', texto: g.inicio }),
        el('span', { classe: 'l-info' }, [el('strong', { texto: g.paciente }), el('span', { texto: `${g.procedimento} · ${g.dentista}` })]),
        el('span', { classe: `selo s-${g.status}`, texto: g.statusNome }),
      ]),
    ])) : [el('li', { classe: 'l-vazio', texto: 'Nenhuma consulta neste dia.' })]);
    grade.append(lista);

    // Fila de pendentes
    const novos = conhecidos ? a.pendentes.filter((p) => !conhecidos.has(p.id)) : [];
    conhecidos = new Set(a.pendentes.map((p) => p.id));
    document.getElementById('fila-n').textContent = String(a.pendentes.length);
    document.title = (a.pendentes.length ? `(${a.pendentes.length}) ` : '') + 'Agenda · Recepção (demo)';
    fila.replaceChildren(...(a.pendentes.length ? a.pendentes.map((p) => el('li', { classe: novos.some((x) => x.id === p.id) ? 'novo' : '' }, [
      el('button', { type: 'button', classe: 'f-item', on: { click: () => abrir(p) } }, [
        el('span', { classe: 'f-quando', texto: `${quandoCurto(p.data, a.hoje)} · ${p.inicio}` }),
        el('strong', { texto: p.paciente }),
        el('span', { classe: 'f-proc', texto: `${p.procedimento} · ${p.dentista}` }),
      ]),
    ])) : [el('li', { classe: 'f-vazio', texto: 'Tudo confirmado por aqui.' })]));
    if (novos.length) avisar(novos.length === 1 ? `Novo agendamento: ${novos[0].paciente}` : `${novos.length} novos agendamentos`);
  }

  function quandoCurto(d, hoje) {
    if (d === hoje) return 'hoje';
    if (d === somarDias(hoje, 1)) return 'amanhã';
    const [y, m, dia] = d.split('-').map(Number);
    return `${['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'][new Date(y, m - 1, dia).getDay()]} ${String(dia).padStart(2, '0')}/${String(m).padStart(2, '0')}`;
  }

  // ---------- Gaveta de detalhe ----------
  function abrir(g) {
    aberto = g;
    document.getElementById('det-nome').textContent = g.paciente;
    const st = document.getElementById('det-status');
    st.textContent = g.statusNome; st.className = `selo s-${g.status}`;
    document.getElementById('det-codigo').textContent = g.codigo;
    document.getElementById('det-quando').textContent = `${g.dataExtenso}, ${g.inicio}–${g.fim}`;
    document.getElementById('det-proc').textContent = g.procedimento;
    document.getElementById('det-dent').textContent = g.dentista;
    document.getElementById('det-tel').textContent = g.telefone || '—';
    document.getElementById('det-origem').textContent = g.origem === 'site' ? 'Site' : 'Recepção';
    document.getElementById('det-obs').textContent = g.observacao;
    document.getElementById('det-obs-wrap').hidden = !g.observacao;
    const w = document.getElementById('det-whats');
    w.hidden = !g.whatsapp; if (g.whatsapp) w.href = g.whatsapp;
    const ativo = g.status === 'pendente' || g.status === 'confirmado';
    detalhe.querySelector('[data-acao="confirmar"]').hidden = g.status !== 'pendente';
    detalhe.querySelectorAll('[data-acao="remarcar"],[data-acao="concluir"],[data-acao="faltou"],[data-acao="cancelar"]').forEach((b) => { b.hidden = !ativo; });
    const cancel = detalhe.querySelector('[data-acao="cancelar"]');
    cancel.textContent = 'Cancelar consulta'; delete cancel.dataset.armado;
    document.getElementById('det-erro').hidden = true;
    detalhe.showModal();
  }

  detalhe.addEventListener('click', async (ev) => {
    const b = ev.target.closest('[data-acao]');
    if (!b || !aberto) return;
    const acao = b.dataset.acao;
    if (acao === 'remarcar') { detalhe.close(); abrirMarcar('remarcar', aberto); return; }
    if (acao === 'cancelar' && !b.dataset.armado) { b.dataset.armado = '1'; b.textContent = 'Toque de novo para cancelar'; return; }
    b.disabled = true;
    try {
      await api('/admin/api/acao.php', { acao, id: aberto.id });
      detalhe.close();
      avisar({ confirmar: 'Presença confirmada.', cancelar: 'Consulta cancelada. Horário liberado.', concluir: 'Marcado como atendido.', faltou: 'Falta registrada. Horário liberado.' }[acao]);
      carregar(dataAtual, true);
    } catch (e) {
      const er = document.getElementById('det-erro'); er.textContent = e.message; er.hidden = false;
    } finally { b.disabled = false; }
  });

  document.querySelectorAll('[data-fechar]').forEach((b) => b.addEventListener('click', () => b.closest('dialog').close()));
  document.querySelectorAll('dialog').forEach((d) => d.addEventListener('click', (ev) => { if (ev.target === d) d.close(); }));

  // ---------- Encaixe / remarcação ----------
  const mProc = document.getElementById('m-proc');
  const mDent = document.getElementById('m-dent');
  const mData = document.getElementById('m-data');
  const mHoras = document.getElementById('m-horas');
  const mErro = document.getElementById('marcar-erro');

  function opcoesDentista(procId, selecionado) {
    const lista = dados.dentistas.filter((d) => d.procedimentos.includes(procId));
    mDent.replaceChildren(el('option', { value: '0', texto: 'Qualquer disponível' }), ...lista.map((d) => el('option', { value: String(d.id), texto: d.nome })));
    mDent.value = String(selecionado && lista.some((d) => d.id === selecionado) ? selecionado : 0);
  }

  function abrirMarcar(modo, g) {
    modoMarcar = modo;
    mErro.hidden = true;
    marcar.querySelectorAll('[data-so-novo]').forEach((n) => { n.hidden = modo !== 'novo'; });
    document.getElementById('marcar-titulo').textContent = modo === 'novo' ? 'Novo encaixe' : `Remarcar ${g.paciente}`;
    document.getElementById('marcar-sub').textContent = modo === 'novo' ? 'Para quem ligou ou chegou na recepção. Já entra como confirmado.' : `Horário atual: ${g.dataExtenso}, ${g.inicio} · ${g.procedimento}`;
    document.getElementById('marcar-enviar').textContent = modo === 'novo' ? 'Marcar consulta' : 'Salvar novo horário';
    if (modo === 'novo') { mProc.value = mProc.options[0].value; opcoesDentista(Number(mProc.value)); marcar.querySelector('#marcar-form').reset(); mProc.value = mProc.options[0].value; }
    else opcoesDentista(g.procedimentoId, g.dentistaId);
    mData.min = hojeTxt();
    mData.value = dataAtual < hojeTxt() ? hojeTxt() : dataAtual;
    marcar.showModal();
    carregarHorasMarcar();
  }

  async function carregarHorasMarcar() {
    const proc = modoMarcar === 'novo' ? Number(mProc.value) : aberto.procedimentoId;
    mHoras.replaceChildren(el('p', { classe: 'm-dica', texto: 'Carregando…' }));
    try {
      const ign = modoMarcar === 'remarcar' ? `&ignorar=${aberto.id}` : '';
      const r = await api(`/admin/api/horarios.php?p=${proc}&d=${mDent.value}&data=${mData.value}${ign}`);
      mHoras.replaceChildren(...(r.horarios.length ? r.horarios.map((h) => el('label', { classe: 'm-hora' }, [
        el('input', { type: 'radio', name: 'm-hora', value: h.hora }), el('span', { texto: h.hora }),
      ])) : [el('p', { classe: 'm-dica', texto: 'Nenhum horário livre nesse dia.' })]));
    } catch (e) { mHoras.replaceChildren(el('p', { classe: 'm-dica', texto: e.message })); }
  }

  mProc.addEventListener('change', () => { opcoesDentista(Number(mProc.value)); carregarHorasMarcar(); });
  mDent.addEventListener('change', carregarHorasMarcar);
  mData.addEventListener('change', carregarHorasMarcar);

  document.getElementById('marcar-form').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    mErro.hidden = true;
    const hora = marcar.querySelector('input[name="m-hora"]:checked');
    if (!hora) { mErro.textContent = 'Escolha um horário.'; mErro.hidden = false; return; }
    const botao = document.getElementById('marcar-enviar');
    botao.disabled = true;
    try {
      if (modoMarcar === 'novo') {
        await api('/admin/api/acao.php', {
          acao: 'criar', procedimento: Number(mProc.value), dentista: Number(mDent.value), data: mData.value, hora: hora.value,
          nome: document.getElementById('m-nome').value, whatsapp: document.getElementById('m-whats').value, observacao: document.getElementById('m-obs').value,
        });
        avisar('Consulta marcada.');
      } else {
        await api('/admin/api/acao.php', { acao: 'remarcar', id: aberto.id, data: mData.value, hora: hora.value, dentista: Number(mDent.value) });
        avisar('Consulta remarcada.');
      }
      marcar.close();
      carregar(mData.value, true);
    } catch (e) {
      mErro.textContent = e.message; mErro.hidden = false;
      if (e.dados && e.dados.ocupado) carregarHorasMarcar();
    } finally { botao.disabled = false; }
  });

  // ---------- Navegação ----------
  document.getElementById('anterior').addEventListener('click', () => carregar(somarDias(dataAtual, -1)));
  document.getElementById('proximo').addEventListener('click', () => carregar(somarDias(dataAtual, 1)));
  document.getElementById('hoje').addEventListener('click', () => carregar(hojeTxt()));
  seletor.addEventListener('change', () => { if (/^\d{4}-\d{2}-\d{2}$/.test(seletor.value)) carregar(seletor.value); });
  document.getElementById('novo').addEventListener('click', () => abrirMarcar('novo'));
  document.addEventListener('keydown', (ev) => {
    if (ev.target.closest('input, select, textarea, dialog')) return;
    if (ev.key === 'ArrowLeft') carregar(somarDias(dataAtual, -1));
    if (ev.key === 'ArrowRight') carregar(somarDias(dataAtual, 1));
  });

  // Atualiza sozinho a cada 30 s (só com a aba visível).
  setInterval(() => { if (!document.hidden && !document.querySelector('dialog[open]')) carregar(dataAtual, true); }, 30000);

  carregar();
})();
