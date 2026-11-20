// "Servidor" da versão estática da demo.
// A Netlify não roda PHP, então este arquivo faz no navegador o papel de app/agenda.php e das APIs:
// intercepta os fetch para /api/... e /admin/api/... e responde com o mesmo JSON do PHP.
// Os dados ficam só no sessionStorage desta aba e somem ao fechar. Nada vai para servidor nenhum.
// agendar.js, admin.js e consulta.js são os MESMOS arquivos da versão PHP.
(() => {
  'use strict';

  const CHAVE = 'esmalte-demo-v1';
  const CFG = {
    clinica: 'Esmalte',
    endereco: 'Rua Exemplo, 000 · Centro · Cidade Demo/SP',
    whatsapp: '5517997565260', // NEXTECH (a clínica é fictícia)
    slot: 30,
    antecedencia: 120,
    janela: 30,
    maxAtivosPorTel: 2,
    usuario: 'recepcao',
    senha: 'demo-esmalte', // senha PÚBLICA, só desta demonstração
  };
  const STATUS = { pendente: 'Aguardando confirmação', confirmado: 'Confirmado', concluido: 'Atendido', faltou: 'Não compareceu', cancelado: 'Cancelado' };
  const ATIVOS = ['pendente', 'confirmado'];
  const DIAS = ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'];
  const MESES = ['', 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

  // ---------- Dados iniciais (iguais ao bin/setup.php) ----------
  const PROCS = [
    ['Avaliação', 'Primeira conversa, exame clínico e plano de tratamento sem pressa.', 'Traga exames ou radiografias recentes, se tiver.', 30],
    ['Limpeza e prevenção', 'Profilaxia, remoção de tártaro e orientação de escovação.', '', 60],
    ['Clareamento', 'Sessão de clareamento em consultório, com avaliação de sensibilidade.', 'Evite café, vinho e alimentos com corante nas 48 h seguintes.', 60],
    ['Restauração', 'Tratamento de cárie ou troca de restauração antiga.', '', 60],
    ['Manutenção de aparelho', 'Ajuste mensal de aparelho fixo ou alinhadores.', 'Escove bem os dentes antes de vir.', 30],
    ['Implante · avaliação', 'Estudo do caso e pedido de tomografia, quando necessário.', 'Traga a lista de remédios que usa.', 30],
    ['Dor ou urgência', 'Encaixe para dor, dente quebrado ou inchaço.', 'Com febre ou inchaço que piora rápido, procure um pronto-socorro.', 30],
  ].map(([nome, resumo, preparo, dur], i) => ({ id: i + 1, nome, resumo, preparo, duracao_min: dur }));

  const DENTS = [
    ['Dra. Helena Prado', 'Clínica geral e estética', 'Cuida da maior parte das avaliações e dos clareamentos.', 'a', [1, 2, 3, 4, 7]],
    ['Dr. Caio Moreira', 'Ortodontia', 'Aparelhos fixos, alinhadores e acompanhamento de crianças.', 'b', [1, 5, 7]],
    ['Dra. Lívia Tanaka', 'Implantes e reabilitação', 'Planejamento de implantes e casos de reabilitação oral.', 'c', [1, 4, 6, 7]],
  ].map(([nome, especialidade, bio, cor, procedimentos], i) => ({ id: i + 1, nome, especialidade, registro: 'CRO-SP 00000 · fictício', bio, cor, procedimentos }));

  function expedienteInicial() {
    const e = [];
    const add = (d, dias, turnos) => dias.forEach((w) => turnos.forEach(([i, f]) => e.push({ dentista_id: d, dia_semana: w, inicio: i, fim: f })));
    add(1, [1, 2, 3, 4, 5], [['08:00', '12:00'], ['13:30', '18:00']]);
    add(2, [1, 3, 5], [['09:00', '12:00'], ['14:00', '19:00']]);
    add(2, [6], [['08:00', '12:00']]);
    add(3, [2, 4], [['08:00', '12:00'], ['13:00', '17:30']]);
    add(3, [5], [['13:00', '17:30']]);
    return e;
  }

  // ---------- Datas ----------
  const pad = (n) => String(n).padStart(2, '0');
  const ymd = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  const hm = (d) => `${pad(d.getHours())}:${pad(d.getMinutes())}`;
  const agoraTxt = () => { const d = new Date(); return `${ymd(d)} ${hm(d)}`; };
  const paraData = (s) => { const [a, m, d] = s.split('-').map(Number); return new Date(a, m - 1, d); };
  const somarDias = (s, n) => { const d = paraData(s); d.setDate(d.getDate() + n); return ymd(d); };
  const min = (h) => { const [a, b] = h.split(':').map(Number); return a * 60 + b; };
  const hhmm = (m) => `${pad(Math.floor(m / 60))}:${pad(m % 60)}`;
  const dataExtenso = (s) => { const d = paraData(s.slice(0, 10)); return `${DIAS[d.getDay()]}, ${d.getDate()} de ${MESES[d.getMonth() + 1]}`; };
  const dataValida = (s) => (typeof s === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(s) && ymd(paraData(s)) === s ? s : null);
  const horaValida = (s) => (typeof s === 'string' && /^([01]\d|2[0-3]):[0-5]\d$/.test(s) ? s : null);
  const inteiro = (v, minimo) => { const n = Number(v); return Number.isInteger(n) && n >= minimo ? n : null; };

  // ---------- Armazenamento (sessionStorage, com plano B em memória) ----------
  let mem = null;
  function ler() {
    if (mem) return mem;
    try { mem = JSON.parse(sessionStorage.getItem(CHAVE) || 'null'); } catch { mem = null; }
    if (!mem || mem.v !== 1) { mem = semear(); salvar(); }
    return mem;
  }
  function salvar() { try { sessionStorage.setItem(CHAVE, JSON.stringify(mem)); } catch { /* aba anônima sem storage: segue em memória */ } }

  function aleatorio(n) { const b = new Uint32Array(1); crypto.getRandomValues(b); return b[0] % n; }
  function codigo(db) {
    const alfa = 'ACDEFGHJKMNPQRTUVWXY34679';
    let c;
    do { c = Array.from({ length: 6 }, () => alfa[aleatorio(alfa.length)]).join(''); } while (db.agendamentos.some((a) => a.codigo === c));
    return c;
  }
  function tokenNovo() { const b = new Uint8Array(24); crypto.getRandomValues(b); return [...b].map((x) => x.toString(16).padStart(2, '0')).join(''); }
  async function sha256(t) {
    const h = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(t));
    return [...new Uint8Array(h)].map((x) => x.toString(16).padStart(2, '0')).join('');
  }

  function semear() {
    const db = { v: 1, seq: { ag: 0, bloq: 0 }, expediente: expedienteInicial(), bloqueios: [], agendamentos: [], slots: {}, logado: false };
    const nomes = ['Marina Exemplo', 'Rafael Exemplo', 'Beatriz Exemplo', 'Otávio Exemplo', 'Clara Exemplo', 'Igor Exemplo', 'Sofia Exemplo', 'Tiago Exemplo', 'Lúcia Exemplo', 'Bruno Exemplo'];
    let n = 0;
    const hoje = ymd(new Date());
    for (let d = 0; d < 6; d++) {
      const data = somarDias(hoje, d);
      for (const proc of [1, 2, 3, 4, 5, 7, 1, 2, 5, 6, 4]) {
        const horas = Object.keys(disponibilidade(db, proc, null, data, data, null, true)[data] || {});
        if (!horas.length || aleatorio(4) === 0) continue;
        const ag = reservarSync(db, proc, null, data, horas[aleatorio(horas.length)], {
          nome: nomes[n % nomes.length], whatsapp: '551190000' + String(n + 1).padStart(4, '0'), observacao: '',
        }, true, null);
        if (ag && aleatorio(3) === 0) { ag.status = 'pendente'; ag.origem = 'site'; }
        n++;
      }
    }
    return db;
  }

  // ---------- Regras da agenda (tradução de app/agenda.php) ----------
  const proc = (id) => PROCS.find((p) => p.id === id) || null;
  const dent = (id) => DENTS.find((d) => d.id === id) || null;
  const dentistasPara = (procId, dentId) => DENTS.filter((d) => d.procedimentos.includes(procId) && (!dentId || d.id === dentId));

  function disponibilidade(db, procId, dentId, de, ate, ignorarAg, admin) {
    const p = proc(procId);
    if (!p) return {};
    const dur = p.duracao_min;
    const ds = dentistasPara(procId, dentId).map((d) => d.id);
    const lim = new Date(Date.now() + (admin ? 0 : CFG.antecedencia) * 60000);
    const limite = `${ymd(lim)} ${hm(lim)}`;
    const res = {};
    for (let data = de; data <= ate; data = somarDias(data, 1)) {
      const dow = paraData(data).getDay();
      for (const did of ds) {
        for (const e of db.expediente.filter((x) => x.dentista_id === did && x.dia_semana === dow).sort((a, b) => (a.inicio < b.inicio ? -1 : 1))) {
          for (let t = min(e.inicio); t + dur <= min(e.fim); t += CFG.slot) {
            const inicio = `${data} ${hhmm(t)}`;
            if (inicio < limite) continue;
            const fim = `${data} ${hhmm(t + dur)}`;
            let livre = true;
            for (let s = t; s < t + dur; s += CFG.slot) {
              const dono = db.slots[`${did}|${data} ${hhmm(s)}`];
              if (dono && dono !== ignorarAg) { livre = false; break; }
            }
            if (livre && db.bloqueios.some((b) => (b.dentista_id === null || b.dentista_id === did) && b.inicio < fim && b.fim > inicio)) livre = false;
            if (livre) ((res[data] ||= {})[hhmm(t)] ||= []).push(did);
          }
        }
      }
      if (res[data]) res[data] = Object.fromEntries(Object.entries(res[data]).sort(([a], [b]) => (a < b ? -1 : 1)));
    }
    return res;
  }

  function menosOcupado(db, ids, data) {
    const carga = Object.fromEntries(ids.map((i) => [i, 0]));
    for (const k of Object.keys(db.slots)) { const [d, s] = k.split('|'); if (s.startsWith(data) && d in carga) carga[d]++; }
    return Number(Object.entries(carga).sort((a, b) => a[1] - b[1])[0][0]);
  }

  function ocupar(db, ag) {
    for (let t = min(ag.inicio.slice(11)); t < min(ag.fim.slice(11)); t += CFG.slot) {
      const k = `${ag.dentista_id}|${ag.inicio.slice(0, 10)} ${hhmm(t)}`;
      if (db.slots[k]) throw new Error('UNIQUE agenda_slots'); // mesma garantia da PRIMARY KEY do SQLite
      db.slots[k] = ag.id;
    }
  }
  function liberar(db, agId) { for (const k of Object.keys(db.slots)) if (db.slots[k] === agId) delete db.slots[k]; }

  // JS no navegador roda numa linha só: não existe "duas reservas ao mesmo tempo" aqui,
  // então a conferência dentro da função já faz o papel do BEGIN IMMEDIATE.
  function reservarSync(db, procId, dentId, data, hora, pac, admin, tokenHash) {
    const cand = (disponibilidade(db, procId, dentId, data, data, null, admin)[data] || {})[hora] || [];
    if (!cand.length) return null;
    const did = dentId || menosOcupado(db, cand, data);
    const dur = proc(procId).duracao_min;
    const agora = agoraTxt();
    const ag = {
      id: ++db.seq.ag, codigo: codigo(db), procedimento_id: procId, dentista_id: did,
      inicio: `${data} ${hora}`, fim: `${data} ${hhmm(min(hora) + dur)}`,
      status: admin ? 'confirmado' : 'pendente', paciente_nome: pac.nome, paciente_whatsapp: pac.whatsapp,
      observacao: pac.observacao || '', token_hash: tokenHash, origem: admin ? 'recepcao' : 'site', criado_em: agora,
    };
    ocupar(db, ag);
    db.agendamentos.push(ag);
    return ag;
  }

  function completo(a) {
    const p = proc(a.procedimento_id), d = dent(a.dentista_id);
    return { ...a, procedimento: p.nome, duracao_min: p.duracao_min, preparo: p.preparo, dentista: d.nome, cor: d.cor };
  }

  const telBonito = (t) => { const n = t.slice(2), r = n.slice(2); return `(${n.slice(0, 2)}) ${r.length === 9 ? r.slice(0, 5) + '-' + r.slice(5) : r.slice(0, 4) + '-' + r.slice(4)}`; };
  function normalizarWhats(v) {
    if (typeof v !== 'string') return '';
    let d = v.replace(/\D+/g, '');
    if (d.length === 10 || d.length === 11) d = '55' + d;
    return /^55[1-9][0-9](9[0-9]{8}|[2-8][0-9]{7})$/.test(d) ? d : '';
  }
  function textoLimpo(v, max) {
    if (typeof v !== 'string') return '';
    return v.replace(/[\x00-\x1F\x7F]+/g, ' ').replace(/\s{2,}/g, ' ').trim().slice(0, max);
  }

  function linkWhatsClinica(a) {
    const msg = `Olá! Acabei de agendar pelo site.\n\nCódigo: ${a.codigo}\n${a.procedimento} com ${a.dentista}\n`
      + `${dataExtenso(a.inicio).replace(/^./, (c) => c.toUpperCase())} às ${a.inicio.slice(11)}\n\nPodem confirmar, por favor?\n\n(Teste da demo de agendamento da NEXTECH)`;
    return `https://wa.me/${CFG.whatsapp}?text=${encodeURIComponent(msg)}`;
  }
  function linkWhatsPaciente(a) {
    const nome = a.paciente_nome.trim().split(' ')[0];
    const msg = `Olá, ${nome}! Aqui é da ${CFG.clinica}.\n\nSua consulta (${a.procedimento}) com ${a.dentista} está marcada para `
      + `${dataExtenso(a.inicio)} às ${a.inicio.slice(11)}.\n\nPodemos confirmar sua presença?`;
    return `https://wa.me/${a.paciente_whatsapp}?text=${encodeURIComponent(msg)}`;
  }

  // ---------- Rotas (mesmos endereços e respostas das APIs PHP) ----------
  const resp = (dados, status = 200) => ({ dados, status });
  const exigeLogin = (db) => (db.logado ? null : resp({ erro: 'Sessão expirada. Entre de novo.' }, 401));

  const rotas = {
    'GET /api/dias.php': (db, q) => {
      const p = inteiro(q.get('p'), 1), d = inteiro(q.get('d'), 0);
      if (!p || d === null || !proc(p)) return resp({ erro: 'Escolha um tratamento.' }, 422);
      const de = ymd(new Date()), ate = somarDias(de, CFG.janela - 1);
      const disp = disponibilidade(db, p, d || null, de, ate, null, false);
      const dias = [];
      for (let x = de; x <= ate; x = somarDias(x, 1)) dias.push({ data: x, livres: Object.keys(disp[x] || {}).length });
      return resp({ dias });
    },

    'GET /api/horarios.php': (db, q) => {
      const p = inteiro(q.get('p'), 1), d = inteiro(q.get('d'), 0), data = dataValida(q.get('data'));
      const hoje = ymd(new Date());
      if (!p || d === null || !data || data < hoje || data > somarDias(hoje, CFG.janela - 1) || !proc(p)) return resp({ erro: 'Dia inválido.' }, 422);
      const disp = disponibilidade(db, p, d || null, data, data, null, false)[data] || {};
      return resp({ data, horarios: Object.entries(disp).map(([hora, dentistas]) => ({ hora, dentistas })) });
    },

    'POST /api/agendar.php': async (db, q, inp) => {
      if (inp.site) return resp({ ok: true, isca: true });
      const p = inteiro(inp.procedimento, 1), d = inteiro(inp.dentista ?? 0, 0);
      const data = dataValida(inp.data), hora = horaValida(inp.hora);
      const nome = textoLimpo(inp.nome, 80), whats = normalizarWhats(inp.whatsapp), obs = textoLimpo(inp.observacao, 200);
      const erros = {};
      if (!p || !proc(p)) erros.procedimento = 'Escolha um tratamento.';
      if (d === null) erros.dentista = 'Escolha um profissional.';
      if (!data || !hora) erros.hora = 'Escolha dia e horário.';
      if (nome.length < 3 || !/^[\p{L}\p{M}' .-]+$/u.test(nome)) erros.nome = 'Escreva seu nome (só letras).';
      if (!whats) erros.whatsapp = 'Confira o WhatsApp: DDD + número.';
      if (inp.consentimento !== true) erros.consentimento = 'Precisamos da sua autorização para guardar o agendamento.';
      const chaves = Object.keys(erros);
      if (chaves.length) return resp({ erro: erros[chaves[0]], campos: erros }, 422);
      const agora = agoraTxt();
      if (db.agendamentos.filter((a) => a.paciente_whatsapp === whats && ATIVOS.includes(a.status) && a.inicio >= agora).length >= CFG.maxAtivosPorTel) {
        return resp({ erro: 'Este WhatsApp já tem consultas marcadas. Para mudar, fale com a recepção.', campos: { whatsapp: 'Já há consultas para este número.' } }, 409);
      }
      const token = tokenNovo();
      const ag = reservarSync(db, p, d || null, data, hora, { nome, whatsapp: whats, observacao: obs }, false, await sha256(token));
      if (!ag) return resp({ erro: 'Alguém acabou de reservar este horário. Escolha outro, por favor.', ocupado: true }, 409);
      const c = completo(ag);
      const gestao = `/consulta.html?c=${c.codigo}&t=${token}`;
      return resp({
        ok: true, codigo: c.codigo, nome: c.paciente_nome.split(' ')[0],
        quando: `${dataExtenso(c.inicio)}, às ${c.inicio.slice(11)}`, dentista: c.dentista, preparo: c.preparo,
        whatsapp: linkWhatsClinica(c), gestao, ics: gestao + '&ics=1',
      });
    },

    'POST /api/cancelar.php': async (db, q, inp) => {
      const a = await porToken(db, inp.codigo, inp.token);
      if (!a) return resp({ erro: 'Link inválido ou expirado.' }, 404);
      if (!ATIVOS.includes(a.status) || a.inicio <= agoraTxt()) return resp({ erro: 'Esta consulta não pode mais ser cancelada pelo site. Fale com a recepção.' }, 409);
      a.status = 'cancelado';
      liberar(db, a.id);
      return resp({ ok: true });
    },

    'GET /admin/api/agenda.php': (db, q) => {
      const bloq = exigeLogin(db); if (bloq) return bloq;
      const data = dataValida(q.get('data')) || ymd(new Date());
      const json = (a0) => {
        const a = completo(a0);
        return {
          id: a.id, codigo: a.codigo, inicio: a.inicio.slice(11), fim: a.fim.slice(11), data: a.inicio.slice(0, 10), dataExtenso: dataExtenso(a.inicio),
          status: a.status, statusNome: STATUS[a.status], procedimento: a.procedimento, procedimentoId: a.procedimento_id,
          dentistaId: a.dentista_id, dentista: a.dentista, paciente: a.paciente_nome,
          telefone: a.paciente_whatsapp ? telBonito(a.paciente_whatsapp) : '', whatsapp: a.paciente_whatsapp ? linkWhatsPaciente(a) : '',
          observacao: a.observacao, origem: a.origem, criadoEm: a.criado_em,
        };
      };
      const ordem = (x, y) => (x.inicio < y.inicio ? -1 : x.inicio > y.inicio ? 1 : 0);
      const agora = agoraTxt();
      const dow = paraData(data).getDay();
      return resp({
        data, dataExtenso: dataExtenso(data), hoje: ymd(new Date()), agora: hm(new Date()),
        dentistas: DENTS.map((d) => ({
          id: d.id, nome: d.nome, cor: d.cor,
          expediente: db.expediente.filter((e) => e.dentista_id === d.id && e.dia_semana === dow).sort((x, y) => (x.inicio < y.inicio ? -1 : 1)).map((e) => [e.inicio, e.fim]),
        })),
        agendamentos: db.agendamentos.filter((a) => a.inicio.startsWith(data)).sort(ordem).map(json),
        pendentes: db.agendamentos.filter((a) => a.status === 'pendente' && a.inicio >= agora).sort(ordem).slice(0, 50).map(json),
        bloqueios: db.bloqueios.filter((b) => b.fim > `${data} 00:00` && b.inicio < `${data} 24:00`).map((b) => ({
          dentistaId: b.dentista_id, inicio: b.inicio >= `${data} 00:00` ? b.inicio.slice(11) : '00:00',
          fim: b.fim < `${data} 24:00` ? b.fim.slice(11) : '24:00', motivo: b.motivo,
        })),
      });
    },

    'GET /admin/api/horarios.php': (db, q) => {
      const bloq = exigeLogin(db); if (bloq) return bloq;
      const p = inteiro(q.get('p'), 1), d = inteiro(q.get('d'), 0) || null, ign = inteiro(q.get('ignorar'), 1), data = dataValida(q.get('data'));
      if (!p || !data || data < ymd(new Date())) return resp({ erro: 'Dados inválidos.' }, 422);
      const disp = disponibilidade(db, p, d, data, data, ign, true)[data] || {};
      return resp({ horarios: Object.entries(disp).map(([hora, dentistas]) => ({ hora, dentistas })) });
    },

    'POST /admin/api/acao.php': (db, q, inp) => {
      const bloq = exigeLogin(db); if (bloq) return bloq;
      const mapa = { confirmar: 'confirmado', cancelar: 'cancelado', concluir: 'concluido', faltou: 'faltou' };
      const acao = typeof inp.acao === 'string' ? inp.acao : '';
      const ocupado = resp({ erro: 'Esse horário acabou de ser ocupado. Escolha outro.', ocupado: true }, 409);
      if (mapa[acao]) {
        const a = db.agendamentos.find((x) => x.id === inteiro(inp.id, 1));
        if (!a) return resp({ erro: 'Agendamento não encontrado.' }, 404);
        if (!ATIVOS.includes(a.status) && ATIVOS.includes(mapa[acao])) return resp({ erro: 'Esta consulta já saiu da agenda. Crie um novo agendamento.' }, 409);
        a.status = mapa[acao];
        if (!ATIVOS.includes(a.status)) liberar(db, a.id);
        return resp({ ok: true });
      }
      if (acao === 'remarcar') {
        const a = db.agendamentos.find((x) => x.id === inteiro(inp.id, 1));
        const data = dataValida(inp.data), hora = horaValida(inp.hora), d = inteiro(inp.dentista ?? 0, 0) || null;
        if (!a || !data || !hora || data < ymd(new Date())) return resp({ erro: 'Escolha o novo dia e horário.' }, 422);
        if (!ATIVOS.includes(a.status)) return ocupado;
        const cand = (disponibilidade(db, a.procedimento_id, d, data, data, a.id, true)[data] || {})[hora] || [];
        if (!cand.length) return ocupado;
        const did = d || (cand.includes(a.dentista_id) ? a.dentista_id : menosOcupado(db, cand, data));
        liberar(db, a.id);
        Object.assign(a, { inicio: `${data} ${hora}`, fim: `${data} ${hhmm(min(hora) + proc(a.procedimento_id).duracao_min)}`, dentista_id: did });
        ocupar(db, a);
        return resp({ ok: true });
      }
      if (acao === 'criar') {
        const p = inteiro(inp.procedimento, 1), d = inteiro(inp.dentista ?? 0, 0) || null;
        const data = dataValida(inp.data), hora = horaValida(inp.hora), nome = textoLimpo(inp.nome, 80), whats = normalizarWhats(inp.whatsapp);
        if (!p || !proc(p) || !data || !hora || nome.length < 3 || !whats) return resp({ erro: 'Preencha tratamento, dia, horário, nome e WhatsApp válido.' }, 422);
        const ag = reservarSync(db, p, d, data, hora, { nome, whatsapp: whats, observacao: textoLimpo(inp.observacao, 200) }, true, null);
        return ag ? resp({ ok: true, id: ag.id }) : ocupado;
      }
      return resp({ erro: 'Ação desconhecida.' }, 422);
    },
  };

  async function porToken(db, codigo, token) {
    if (typeof codigo !== 'string' || typeof token !== 'string' || !/^[A-Z0-9]{6}$/.test(codigo) || !/^[a-f0-9]{48}$/.test(token)) return null;
    const a = db.agendamentos.find((x) => x.codigo === codigo);
    if (!a || !a.token_hash || a.token_hash !== await sha256(token)) return null;
    return a;
  }

  // ---------- Intercepta o fetch ----------
  const fetchOriginal = window.fetch.bind(window);
  window.fetch = async (entrada, opcoes = {}) => {
    const url = new URL(typeof entrada === 'string' ? entrada : entrada.url, location.href);
    const metodo = (opcoes.method || 'GET').toUpperCase();
    const rota = rotas[`${metodo} ${url.pathname}`];
    if (url.origin !== location.origin || !rota) return fetchOriginal(entrada, opcoes);
    await new Promise((r) => setTimeout(r, 120 + aleatorio(180))); // simula a rede
    let corpo = {};
    if (opcoes.body) { try { corpo = JSON.parse(opcoes.body); } catch { corpo = null; } }
    let r;
    if (!corpo || typeof corpo !== 'object') r = resp({ erro: 'Dados inválidos.' }, 400);
    else {
      try { r = await rota(ler(), url.searchParams, corpo); salvar(); } catch (e) { console.error('[demo]', e); r = resp({ erro: 'Não foi possível concluir agora. Tente de novo em instantes.' }, 500); }
    }
    return new Response(JSON.stringify(r.dados), { status: r.status, headers: { 'Content-Type': 'application/json; charset=utf-8' } });
  };

  // ---------- API usada pelas páginas estáticas ----------
  window.DemoServidor = {
    CFG, STATUS, ATIVOS, DIAS, PROCS, DENTS,
    ler, salvar, dataExtenso, porToken: (c, t) => porToken(ler(), c, t), completo,
    logado: () => ler().logado,
    entrar(usuario, senha) { if (usuario === CFG.usuario && senha === CFG.senha) { ler().logado = true; salvar(); return true; } return false; },
    sair() { ler().logado = false; salvar(); },
    zerar() { mem = semear(); salvar(); },
    proximoLivre() {
      const db = ler(), hoje = ymd(new Date());
      const disp = disponibilidade(db, 1, null, hoje, somarDias(hoje, 7), null, false);
      for (const [data, horas] of Object.entries(disp)) {
        const [hora, ids] = Object.entries(horas)[0];
        return { quando: data === hoje ? 'hoje' : data === somarDias(hoje, 1) ? 'amanhã' : dataExtenso(data), hora, dentista: dent(ids[0]).nome };
      }
      return null;
    },
    salvarExpediente(did, linhas) {
      const db = ler();
      db.expediente = db.expediente.filter((e) => e.dentista_id !== did).concat(linhas.map(([w, i, f]) => ({ dentista_id: did, dia_semana: w, inicio: i, fim: f })));
      salvar();
    },
    bloquear(did, inicio, fim, motivo) { const db = ler(); db.bloqueios.push({ id: ++db.seq.bloq, dentista_id: did, inicio, fim, motivo }); salvar(); },
    desbloquear(id) { const db = ler(); db.bloqueios = db.bloqueios.filter((b) => b.id !== id); salvar(); },
    agoraTxt, ymd,
  };

  // Páginas do painel: sem login, volta para a tela de entrada.
  if (document.documentElement.dataset.exigeLogin !== undefined && !ler().logado) location.replace('/admin/login.html');
})();
