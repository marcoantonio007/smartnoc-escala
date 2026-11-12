/* gestao.js — Hora extra (VIDPOL), ticket extra, dashboard gerencial e períodos de sobreaviso.
   Carregado no fim do index.php. Só age para perfis admin/mestre (gestor ou superior). */
(function (root) {
  'use strict';

  var TICKET_MIN = 120; // ticket extra: soma do dia ESTRITAMENTE maior que 2h
  var TIPOS = { SEMANA: 'Semana (seg–sex)', FIM_DE_SEMANA: 'Final de semana', FERIADO: 'Feriado' };
  var DIA_LABEL = ['mesmo dia', '+1 dia', '+2 dias'];
  var CORES = ['#2563eb', '#f59e0b', '#059669', '#7c3aed', '#dc2626', '#0891b2', '#db2777', '#65a30d'];

  /* ======================= NÚCLEO PURO (testável) ======================= */
  function pad(n) { return String(n).padStart(2, '0'); }
  function hm(s) {
    if (typeof s !== 'string') return null;
    var m = /^([01]\d|2[0-3]):([0-5]\d)(?::\d\d)?$/.exec(s);
    return m ? (+m[1]) * 60 + (+m[2]) : null;
  }
  function dur(hi, hf) {
    var a = hm(hi), b = hm(hf);
    if (a === null || b === null) return null;
    return b > a ? b - a : b + 1440 - a;
  }
  function fmtMin(m) {
    m = Math.round(m || 0);
    var h = Math.floor(m / 60), r = m % 60;
    return h + 'h' + (r ? pad(r) + 'm' : '');
  }
  function fmtH(h) { return fmtMin(Math.round((h || 0) * 60)); }
  function diaNum(s) { var p = s.split('-'); return Date.UTC(+p[0], +p[1] - 1, +p[2]) / 86400000; }
  function addDias(s, n) {
    var d = new Date(diaNum(s) * 86400000 + n * 86400000);
    return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate());
  }
  function ivHE(e) { var a = diaNum(e.data) * 1440 + (e.dia_inicio ? 1440 : 0) + hm(e.hora_inicio); return [a, a + dur(e.hora_inicio, e.hora_fim)]; }
  function ivFracao(f) {
    var a = diaNum(String(f.data_inicio).substring(0, 10)) * 1440 + hm(String(f.hora_inicio));
    var fimStr = String(f.hora_fim);
    var b = diaNum(String(f.data_fim).substring(0, 10)) * 1440 + (fimStr.substring(0, 5) === '23:59' ? 1440 : hm(fimStr));
    return [a, b];
  }
  function overlap(a, b) { return Math.max(0, Math.min(a[1], b[1]) - Math.max(a[0], b[0])); }
  // Minutos de uma hora extra que caem dentro do sobreaviso do próprio colaborador (dia anterior, do dia e seguinte,
  // pois a janela pode atravessar a meia-noite). Esse tempo é abatido do sobreaviso para não pagar em duplicidade.
  function descontoHEMin(dados, e) {
    var iv = ivHE(e), tot = 0;
    [-1, 0, 1].forEach(function (o) {
      var c = dados && dados[addDias(e.data, o)];
      if (!c || !ehPlantao(c)) return;
      (c.fracoes || []).forEach(function (f) { tot += overlap(iv, ivFracao(f)); });
    });
    return tot;
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function statusDe(c) { return c == null ? null : (typeof c === 'string' ? c : c.status); }
  function ehPlantao(c) { var s = statusDe(c); return !!s && String(s).indexOf('plantao') === 0; }

  // Competência: do dia 15 do mês de referência ao dia 14 do mês seguinte
  function competencia(ano, mes0) {
    var fa = ano, fm = mes0 + 1;
    if (fm > 11) { fm = 0; fa++; }
    return { ini: ano + '-' + pad(mes0 + 1) + '-15', fim: fa + '-' + pad(fm + 1) + '-14', anoFim: fa, mesFim0: fm };
  }

  // Minutos de hora extra por DIA DO CALENDÁRIO (um período que passa da meia-noite é dividido).
  // Ticket extra: cada dia do calendário com soma > 2h gera 1 ticket; horas de dias diferentes não se somam.
  function diasCalendario(e) {
    var iv = ivHE(e), out = [];
    for (var d = Math.floor(iv[0] / 1440); d * 1440 < iv[1]; d++) out.push(addDias('1970-01-01', d));
    return out;
  }
  function ticketsPorDia(itens) {
    var dias = {};
    itens.forEach(function (e) {
      var iv = ivHE(e), cur = iv[0];
      while (cur < iv[1]) {
        var d = Math.floor(cur / 1440), fim = Math.min(iv[1], (d + 1) * 1440), k = addDias('1970-01-01', d);
        dias[k] = (dias[k] || 0) + (fim - cur); cur = fim;
      }
    });
    var tk = Object.keys(dias).filter(function (k) { return dias[k] > TICKET_MIN; }).sort();
    return { dias: dias, ticketDias: tk, tickets: tk.length };
  }

  // Resumo de hora extra de um colaborador em um dia de sobreaviso (apenas dados já carregados)
  function resumoDia(extras, nome, data) {
    var itens = extras.filter(function (e) { return e.nome === nome && e.data === data; });
    if (!itens.length) return null;
    var total = itens.reduce(function (s, e) { return s + e.minutos; }, 0);
    var todos = extras.filter(function (e) { return e.nome === nome; });
    var r = ticketsPorDia(todos), tocados = {};
    itens.forEach(function (e) { diasCalendario(e).forEach(function (d) { tocados[d] = true; }); });
    var meus = r.ticketDias.filter(function (d) { return tocados[d]; });
    var porDiaCal = Object.keys(r.dias).filter(function (d) { return tocados[d]; }).sort().map(function (d) { return { data: d, minutos: r.dias[d] }; });
    return { minutos: total, periodos: itens, ticket: meus.length > 0, tickets: meus.length, ticketDias: meus, porDiaCal: porDiaCal };
  }

  /* params: {plantao, tecnicos, extras, ini, fim, setor}  */
  function calcularGestao(p) {
    var linhas = [], porSetor = {}, porDia = {};
    var d0 = diaNum(p.ini), d1 = diaNum(p.fim);
    for (var d = d0; d <= d1; d++) {
      var s = addDias(p.ini, d - d0);
      porDia[s] = { data: s, heMin: 0, plantoes: 0, pessoas: {} };
    }
    (p.tecnicos || []).forEach(function (t) {
      if (p.setor && String(t.setor || '').toUpperCase() !== String(p.setor).toUpperCase()) return;
      var dados = (p.plantao && p.plantao[t.nome]) || {};
      var dias = 0, brutas = 0, diasPlantao = {};
      Object.keys(dados).forEach(function (data) {
        var c = dados[data];
        if (data < p.ini || data > p.fim || !ehPlantao(c)) return;
        dias++; diasPlantao[data] = c;
        (c.fracoes || []).forEach(function (f) { brutas += parseFloat(f.horas) || 0; });
        if (porDia[data]) porDia[data].plantoes++;
      });
      var heMin = 0, periodos = 0, descMin = 0, extrasCol = [];
      (p.extras || []).forEach(function (e) {
        if (e.nome !== t.nome || e.data < p.ini || e.data > p.fim) return;
        heMin += e.minutos; periodos++;
        extrasCol.push(e);
        if (porDia[e.data]) {
          porDia[e.data].heMin += e.minutos;
          var pd = porDia[e.data].pessoas[t.nome] || (porDia[e.data].pessoas[t.nome] = { min: 0, sob: !!diasPlantao[e.data] });
          pd.min += e.minutos; // DASH_TIP_V1
        }
        var iv = ivHE(e);
        descMin += descontoHEMin(dados, e);
      });
      var tickets = ticketsPorDia(extrasCol).tickets;
      if (!dias && !heMin) return;
      var liquidas = Math.max(0, brutas - descMin / 60);
      linhas.push({ nome: t.nome, nomeCompleto: t.nome_completo || t.nome, setor: t.setor || '—', dias: dias,
        horasBrutas: brutas, descontoMin: descMin, horasLiquidas: liquidas, heMin: heMin, periodos: periodos, tickets: tickets });
      var st = t.setor || '—';
      var o = porSetor[st] || (porSetor[st] = { setor: st, heMin: 0, tickets: 0, horas: 0, colaboradores: 0 });
      o.heMin += heMin; o.tickets += tickets; o.horas += liquidas; o.colaboradores++;
    });
    var tot = linhas.reduce(function (a, l) {
      a.horasLiquidas += l.horasLiquidas; a.horasBrutas += l.horasBrutas; a.heMin += l.heMin;
      a.tickets += l.tickets; a.descontoMin += l.descontoMin; if (l.heMin) a.comHE++; return a;
    }, { horasLiquidas: 0, horasBrutas: 0, heMin: 0, tickets: 0, descontoMin: 0, comHE: 0 });
    return {
      linhas: linhas, totais: tot,
      porSetor: Object.keys(porSetor).map(function (k) { return porSetor[k]; }).sort(function (a, b) { return b.heMin - a.heMin || b.horas - a.horas; }),
      porDia: Object.keys(porDia).sort().map(function (k) { return porDia[k]; })
    };
  }

  // Validação do formulário de hora extra (cliente). extrasDoDia: itens já registrados do dia
  function validarHE(hi, hf, vidpol, extrasDoDia, extrasVizinhos, dia) {
    if (!/^[0-9]{6}$/.test(vidpol || '')) return 'O VIDPOL é obrigatório e deve ter exatamente 6 dígitos numéricos.';
    if (hm(hi) === null || hm(hf) === null) return 'Informe a hora de início e a hora de fim.';
    if (hi === hf) return 'Hora de início e fim não podem ser iguais.';
    var a = hm(hi) + (dia ? 1440 : 0), novo = [a, a + dur(hi, hf)];
    var conflito = null;
    (extrasDoDia || []).forEach(function (e) {
      var b = hm(e.hora_inicio) + (e.dia_inicio ? 1440 : 0), ex = [b, b + dur(e.hora_inicio, e.hora_fim)];
      if (overlap(novo, ex) > 0) conflito = e;
    });
    (extrasVizinhos || []).forEach(function (v) { // {off: -1440|1440, e}
      var b = hm(v.e.hora_inicio) + (v.e.dia_inicio ? 1440 : 0) + v.off, ex = [b, b + dur(v.e.hora_inicio, v.e.hora_fim)];
      if (overlap(novo, ex) > 0) conflito = v.e;
    });
    if (conflito) return 'Este período conflita com outra hora extra já registrada (' + conflito.hora_inicio + '–' + conflito.hora_fim + ').';
    return null;
  }

  root.GestaoCore = { hm: hm, dur: dur, fmtMin: fmtMin, fmtH: fmtH, competencia: competencia, calcularGestao: calcularGestao,
    resumoDia: resumoDia, ticketsPorDia: ticketsPorDia, validarHE: validarHE, ivHE: ivHE, ivFracao: ivFracao, addDias: addDias, TICKET_MIN: TICKET_MIN };
  root.GestaoCore.descontoHEMin = descontoHEMin;
  root.GestaoCore.urlVidpol = function (v) { return 'https://videopolicia.atlassian.net/browse/VIDPOL-' + encodeURIComponent(String(v)); };

  /* ============================== UI ============================== */
  if (!root.document || !root.document.body) return;
  var doc = root.document;
  function el(id) { return doc.getElementById(id); }
  // let/const de outros <script> ficam no escopo global léxico (não em window): acesso por nome puro
  function gAccess() { return typeof currentAccess !== 'undefined' ? currentAccess : ''; }
  function gPlantao() { return typeof plantaoMensalDados !== 'undefined' ? plantaoMensalDados : {}; }
  function gTecnicos() { return typeof tecnicosDB !== 'undefined' ? tecnicosDB : []; }
  function gSetoresGer() { return typeof setoresGerenciaveisLogin !== 'undefined' ? setoresGerenciaveisLogin : ''; }
  function gMes() { return (typeof currentMonthDate !== 'undefined' && currentMonthDate instanceof Date) ? currentMonthDate : new Date(); }
  function gNomesMeses() { return typeof nomesMeses !== 'undefined' ? nomesMeses : ['JANEIRO', 'FEVEREIRO', 'MARÇO', 'ABRIL', 'MAIO', 'JUNHO', 'JULHO', 'AGOSTO', 'SETEMBRO', 'OUTUBRO', 'NOVEMBRO', 'DEZEMBRO']; }
  function gView() { return typeof currentView !== 'undefined' ? currentView : ''; }
  function ehGestor() { var a = gAccess(); return a === 'admin' || a === 'mestre'; }
  function toast(m, t) { if (typeof root.showToast === 'function') root.showToast(m, t || 'success'); else if (t === 'error') alert(m); }
  function icones() { if (root.lucide && root.lucide.createIcons) root.lucide.createIcons(); }

  var horasExtrasDB = [];
  var periodosDB = [];
  var alvoHE = null; // {nome, data}
  var editandoHE = null; // id da hora extra em edição
  var editandoPer = null; // id do período de sobreaviso em edição
  function urlVidpol(v) { return 'https://videopolicia.atlassian.net/browse/VIDPOL-' + encodeURIComponent(String(v)); }
  function listaVidpol(e) { return (e.vidpols && e.vidpols.length) ? e.vidpols : (e.vidpol ? [e.vidpol] : []); }
  function txtVidpol(e) { var l = listaVidpol(e); return l.length ? 'VIDPOL ' + l.join(', ') : 'sem VIDPOL'; }
  function linksVidpol(e) {
    var l = listaVidpol(e);
    return l.length ? l.map(function (v) { return linkVidpol(v); }).join(' ') : '<span class="text-amber-600 font-bold" title="O colaborador ainda não informou o VIDPOL no Ponto">sem VIDPOL</span>';
  }
  var BADGE_PONTO = ' <span class="ml-1 px-1 py-px rounded bg-blue-100 text-blue-700 border border-blue-300 text-[9px] font-black uppercase" title="Batido no Ponto pelo colaborador (somente leitura aqui)">Ponto</span>';
  function linkVidpol(v) {
    return '<a href="' + urlVidpol(v) + '" target="_blank" rel="noopener noreferrer" class="font-mono text-blue-600 hover:text-blue-800 underline" title="Abrir chamado no Jira">VIDPOL-' + esc(v) + '</a>';
  }

  async function api(action, opts) {
    var r = await fetch('api_escala.php?action=' + action, opts);
    var txt = await r.text(), j;
    try { j = JSON.parse(txt); } catch (e) { throw new Error('Resposta inválida do servidor.'); }
    return j;
  }
  function post(action, body) {
    return api(action, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  }

  async function carregarHorasExtras() {
    if (!ehGestor()) return;
    try {
      var j = await api('get_horas_extras');
      if (j.sucesso) horasExtrasDB = j.itens || [];
    } catch (e) { /* silencioso */ }
  }
  function rerenderTudo() {
    try { if (typeof root.renderAll === 'function') root.renderAll(); } catch (e) {}
    if (gView() === 'gestao') renderGestao();
  }

  // Usado pelo mensal.php (somente gestor): dados da hora extra de um dia
  function infoHoraExtra(nome, data) {
    if (!ehGestor()) return null;
    // DASH_PATCH_V1: hora extra tambem em dias sem sobreaviso
    return resumoDia(horasExtrasDB, nome, data);
  }
  function marcadorHoraExtra(nome, data) {
    var i = infoHoraExtra(nome, data);
    if (!i) return '';
    var det = i.periodos.map(function (e) { return e.hora_inicio + (e.dia_inicio ? '(+1d)' : '') + '–' + e.hora_fim + ' (' + txtVidpol(e) + (e.origem === 'ponto' ? ', Ponto' : '') + ')'; }).join(' | ');
    var ab = 0; i.periodos.forEach(function (e) { ab += descontoHEMin(gPlantao()[nome], e); });
    var tip = 'Hora extra: ' + fmtMin(i.minutos) + ' — ' + det + (i.ticket ? ' — direito a ' + i.tickets + ' ticket(s) extra(s)' : '') + (ab ? ' — abatido do sobreaviso: ' + fmtMin(ab) : '');
    // HE_BTN_V2: cartão (ticket extra) embaixo à esquerda e $ amarelo (hora extra) embaixo à direita, sem sobrepor
    var base = 'absolute bottom-[1px] w-[15px] h-[15px] rounded-full flex items-center justify-center shadow ring-1 ring-white ';
    var barra = '<div class="' + base + 'right-[-1px] bg-yellow-400" title="' + esc(tip) + '">' +
      '<svg viewBox="0 0 24 24" width="10" height="10" fill="none" stroke="#713f12" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20"/><path d="M17 6.5H9.8a3.3 3.3 0 0 0 0 6.6h4.4a3.3 3.3 0 0 1 0 6.6H6.5"/></svg></div>';
    var tk = i.ticket ? '<div class="' + base + 'left-[-1px] bg-violet-600" title="Direito a ' + i.tickets + ' ticket(s) extra(s) no cartão">' +
      '<svg viewBox="0 0 24 24" width="10" height="10" fill="none" stroke="#fff" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2.5"/><path d="M2 10h20"/></svg></div>' : '';
    return barra + tk;
  }
  root.infoHoraExtra = infoHoraExtra;
  root.marcadorHoraExtra = marcadorHoraExtra;
  // Total (minutos) de hora extra abatida do sobreaviso de um colaborador entre ini e fim (AAAA-MM-DD)
  root.descontoSobreavisoMin = function (nome, ini, fim) {
    var dados = gPlantao()[nome] || {}, tot = 0;
    horasExtrasDB.forEach(function (e) { if (e.nome === nome && e.data >= ini && e.data <= fim) tot += descontoHEMin(dados, e); });
    return tot;
  };

  /* ------------------------- Modal de hora extra ------------------------- */
  function garantirModalHE() {
    if (el('he-modal')) return;
    var d = doc.createElement('div');
    d.id = 'he-modal';
    d.className = 'hidden fixed inset-0 bg-slate-900/70 z-[400] flex items-center justify-center p-4';
    d.innerHTML =
      '<div class="bg-white rounded-xl shadow-2xl w-full max-w-md max-h-[90vh] overflow-y-auto">' +
        '<div class="flex justify-between items-center px-5 py-3 border-b">' +
          '<h3 class="text-sm font-black text-slate-800 uppercase flex items-center gap-2"><span class="inline-block w-3 h-1 bg-yellow-400 rounded"></span> Hora Extra</h3>' +
          '<button type="button" id="he-fechar" class="text-slate-400 hover:text-red-500 text-lg leading-none" aria-label="Fechar">&times;</button>' +
        '</div>' +
        '<div class="px-5 pt-3 text-[11px] font-bold text-slate-500 uppercase" id="he-titulo"></div>' +
        '<div class="px-5 pt-2"><div class="text-[10px] font-bold text-slate-500 uppercase mb-1">Períodos registrados</div><div id="he-lista" class="space-y-1"></div>' +
          '<div id="he-total" class="mt-2 text-xs font-black text-slate-700"></div></div>' +
        '<form id="he-form" class="px-5 py-4 mt-3 border-t bg-slate-50 rounded-b-xl" novalidate>' +
          '<div id="he-form-titulo" class="text-[10px] font-bold text-slate-500 uppercase mb-2">Adicionar período</div>' +
          '<div class="grid grid-cols-2 gap-3">' +
            '<label class="text-[10px] font-bold text-slate-600 uppercase">Hora início<input type="time" id="he-ini" class="mt-1 w-full border rounded px-2 py-1.5 text-sm font-bold text-slate-800" required></label>' +
            '<label class="text-[10px] font-bold text-slate-600 uppercase">Hora fim<input type="time" id="he-fim" class="mt-1 w-full border rounded px-2 py-1.5 text-sm font-bold text-slate-800" required></label>' +
            '<label class="col-span-2 text-[10px] font-bold text-slate-600 uppercase">Hora de início ocorre<select id="he-dia" class="mt-1 w-full border rounded px-2 py-1.5 text-sm font-bold text-slate-800 bg-white"><option value="0">No dia do plantão</option><option value="1">Na madrugada seguinte (+1 dia)</option></select></label>' +
            '<label class="col-span-2 text-[10px] font-bold text-slate-600 uppercase">VIDPOL (chamado) *<input type="text" id="he-vidpol" inputmode="numeric" autocomplete="off" maxlength="6" placeholder="6 dígitos" class="mt-1 w-full border rounded px-2 py-1.5 text-sm font-mono font-bold tracking-widest text-slate-800" required></label>' +
          '</div>' +
          '<div id="he-erro" class="hidden mt-2 text-[11px] font-bold text-red-600"></div>' +
          '<div class="flex justify-end gap-2 mt-3">' +
            '<button type="button" id="he-cancelar" class="px-3 py-1.5 text-xs font-bold text-slate-600 bg-white border rounded hover:bg-slate-100">Fechar</button>' +
            '<button type="button" id="he-cancelar-edicao" class="hidden px-3 py-1.5 text-xs font-bold text-amber-700 bg-amber-50 border border-amber-300 rounded hover:bg-amber-100">Cancelar edição</button>' +
            '<button type="submit" id="he-salvar" class="px-3 py-1.5 text-xs font-bold text-white bg-yellow-500 hover:bg-yellow-600 rounded shadow-sm">Adicionar período</button>' +
          '</div>' +
        '</form>' +
      '</div>';
    doc.body.appendChild(d);
    el('he-fechar').onclick = el('he-cancelar').onclick = fecharHE;
    el('he-cancelar-edicao').onclick = function () { cancelarEdicaoHE(true); };
    d.addEventListener('mousedown', function (ev) { if (ev.target === d) fecharHE(); });
    doc.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && !d.classList.contains('hidden')) fecharHE(); });
    el('he-vidpol').addEventListener('input', function () { this.value = this.value.replace(/\D/g, '').substring(0, 6); });
    el('he-form').addEventListener('submit', function (ev) { ev.preventDefault(); salvarPeriodoHE(); });
  }
  function fecharHE() { var m = el('he-modal'); if (m) m.classList.add('hidden'); alvoHE = null; editandoHE = null; }
  function fmtDataBR(s) { var p = s.split('-'); return p[2] + '/' + p[1] + '/' + p[0]; }

  function abrirHoraExtra(nome, data) {
    if (!ehGestor()) return;
    garantirModalHE();
    alvoHE = { nome: nome, data: data };
    el('he-titulo').textContent = nome + ' — dia ' + fmtDataBR(data);
    el('he-ini').value = ''; el('he-fim').value = ''; el('he-vidpol').value = ''; el('he-dia').value = '0';
    mostrarErroHE('');
    cancelarEdicaoHE(false);
    renderListaHE();
    el('he-modal').classList.remove('hidden');
    setTimeout(function () { el('he-ini').focus(); }, 30);
  }
  function mostrarErroHE(m) { var e = el('he-erro'); e.textContent = m; e.classList.toggle('hidden', !m); }
  function renderListaHE() {
    if (!alvoHE) return;
    var itens = horasExtrasDB.filter(function (e) { return e.nome === alvoHE.nome && e.data === alvoHE.data; })
      .sort(function (a, b) { return ((a.dia_inicio || 0) * 1440 + hm(a.hora_inicio)) - ((b.dia_inicio || 0) * 1440 + hm(b.hora_inicio)); });
    var lista = el('he-lista');
    lista.innerHTML = itens.length ? itens.map(function (e) {
      var vira = hm(e.hora_fim) <= hm(e.hora_inicio), seg = e.dia_inicio ? ' <span class="text-[9px] text-slate-400">(madrugada seguinte)</span>' : '';
      return '<div class="flex items-center justify-between gap-2 rounded px-2 py-1.5 text-xs border ' + (e.id === editandoHE ? 'bg-blue-50 border-blue-400 ring-1 ring-blue-300' : 'bg-yellow-50 border-yellow-200') + '">' +
        '<div class="font-bold text-slate-700">' + esc(e.hora_inicio) + seg + ' → ' + esc(e.hora_fim) + (vira ? ' <span class="text-[9px] text-slate-400">(+1 dia)</span>' : '') +
        ' <span class="text-slate-400 font-semibold">· ' + fmtMin(e.minutos) + '</span> <span class="text-slate-400">·</span> ' + linksVidpol(e) + (e.origem === 'ponto' ? BADGE_PONTO : '') + '</div>' +
        (e.origem === 'ponto' ? '<div class="shrink-0 text-[9px] text-slate-400 font-bold">ajuste no Ponto</div>' :
        '<div class="flex items-center shrink-0"><button type="button" data-id="' + e.id + '" class="he-edit text-blue-600 hover:text-blue-800 font-black px-1.5" title="Editar período">&#9998;</button>' +
        '<button type="button" data-id="' + e.id + '" class="he-del text-red-500 hover:text-red-700 font-black px-1" title="Excluir período">&times;</button></div>') + '</div>';
    }).join('') : '<div class="text-xs text-slate-400 font-semibold">Nenhum período registrado.</div>';
    Array.prototype.forEach.call(lista.querySelectorAll('.he-del'), function (b) {
      b.onclick = function () { excluirPeriodoHoraExtra(+b.getAttribute('data-id')); };
    });
    Array.prototype.forEach.call(lista.querySelectorAll('.he-edit'), function (b) {
      b.onclick = function () { iniciarEdicaoHE(+b.getAttribute('data-id')); };
    });
    var i = resumoDia(horasExtrasDB, alvoHE.nome, alvoHE.data);
    var t = el('he-total');
    if (!i) { t.textContent = ''; return; }
    var cal = i.porDiaCal.length > 1 ? '<div class="mt-1 text-[10px] font-semibold text-slate-500">Por dia do calendário: ' + i.porDiaCal.map(function (x) { return fmtDataBR(x.data).substring(0, 5) + ' = ' + fmtMin(x.minutos); }).join(' · ') + '</div>' : '';
    t.innerHTML = 'Total do plantão: ' + fmtMin(i.minutos) + (i.ticket
      ? ' <span class="ml-1 px-1.5 py-0.5 rounded bg-violet-100 text-violet-700 border border-violet-300">🎫 ' + i.tickets + ' ticket(s) extra(s) no cartão</span>'
      : ' <span class="text-slate-400 font-semibold">(ticket extra apenas com mais de 2h no mesmo dia)</span>') + cal;
  }
  function iniciarEdicaoHE(id) {
    var e = horasExtrasDB.filter(function (x) { return x.id === id; })[0];
    if (!e) return;
    editandoHE = id;
    el('he-ini').value = e.hora_inicio; el('he-fim').value = e.hora_fim;
    el('he-dia').value = e.dia_inicio ? '1' : '0'; el('he-vidpol').value = e.vidpol;
    el('he-form-titulo').textContent = 'Editando período — altere só o que precisa';
    el('he-salvar').textContent = 'Salvar alteração';
    el('he-cancelar-edicao').classList.remove('hidden');
    mostrarErroHE(''); renderListaHE();
    el('he-ini').focus();
  }
  function cancelarEdicaoHE(limparCampos) {
    editandoHE = null;
    if (limparCampos) { el('he-ini').value = ''; el('he-fim').value = ''; el('he-vidpol').value = ''; el('he-dia').value = '0'; }
    el('he-form-titulo').textContent = 'Adicionar período';
    el('he-salvar').textContent = 'Adicionar período';
    el('he-cancelar-edicao').classList.add('hidden');
    mostrarErroHE(''); renderListaHE();
  }
  async function salvarPeriodoHE() {
    if (!alvoHE) return;
    var hi = el('he-ini').value, hf = el('he-fim').value, v = el('he-vidpol').value.trim();
    var idEd = editandoHE;
    var doDia = horasExtrasDB.filter(function (e) { return e.nome === alvoHE.nome && e.data === alvoHE.data && e.id !== idEd; });
    var viz = [];
    horasExtrasDB.forEach(function (e) {
      if (e.nome !== alvoHE.nome || e.id === idEd) return;
      if (e.data === root.GestaoCore.addDias(alvoHE.data, -1)) viz.push({ off: -1440, e: e });
      if (e.data === root.GestaoCore.addDias(alvoHE.data, 1)) viz.push({ off: 1440, e: e });
    });
    var dia = +el('he-dia').value ? 1 : 0;
    var erro = validarHE(hi, hf, v, doDia, viz, dia);
    if (erro) { mostrarErroHE(erro); return; }
    mostrarErroHE('');
    var btn = el('he-salvar'); btn.disabled = true;
    try {
      var j = idEd
        ? await post('editar_hora_extra', { id: idEd, hora_inicio: hi, hora_fim: hf, dia_inicio: dia, vidpol: v })
        : await post('adicionar_hora_extra', { nome: alvoHE.nome, data: alvoHE.data, hora_inicio: hi, hora_fim: hf, dia_inicio: dia, vidpol: v });
      if (!j.sucesso) { mostrarErroHE(j.erro || 'Falha ao salvar.'); return; }
      await carregarHorasExtras();
      cancelarEdicaoHE(true);
      rerenderTudo();
      toast(idEd ? 'Hora extra atualizada!' : 'Período de hora extra registrado!');
    } catch (e) { mostrarErroHE(e.message || 'Falha ao salvar.'); }
    finally { btn.disabled = false; }
  }
  async function excluirPeriodoHoraExtra(id) {
    if (!confirm('Excluir este período de hora extra?')) return;
    try {
      var j = await post('remover_hora_extra', { id: id });
      if (!j.sucesso) { mostrarErroHE(j.erro || 'Falha ao excluir.'); return; }
      await carregarHorasExtras();
      if (editandoHE === id) cancelarEdicaoHE(true);
      renderListaHE(); rerenderTudo();
      toast('Período excluído.');
    } catch (e) { mostrarErroHE(e.message || 'Falha ao excluir.'); }
  }
  root.abrirHoraExtra = abrirHoraExtra;
  root.excluirPeriodoHoraExtra = excluirPeriodoHoraExtra;
  root.iniciarEdicaoHE = iniciarEdicaoHE;

  /* ------------------------ Dashboard gerencial ------------------------ */
  var gestaoRef = null; // Date (1º dia do mês de início da competência)
  function refInicial() {
    var d = gMes();
    return new Date(d.getFullYear(), d.getMonth(), 1);
  }
  function garantirViewGestao() {
    if (el('view-gestao')) return;
    var v = doc.createElement('div');
    v.id = 'view-gestao';
    v.className = 'hidden w-full h-full overflow-y-auto custom-scrollbar bg-slate-50 p-4 sm:p-6';
    v.innerHTML =
      '<div class="max-w-6xl mx-auto pb-10">' +
        '<div class="flex flex-col md:flex-row md:justify-between md:items-center mb-5 gap-3 border-b pb-4">' +
          '<h2 class="text-xl font-black text-slate-800 uppercase flex items-center gap-2"><i data-lucide="layout-dashboard" class="w-6 h-6 text-blue-600"></i> Dashboard Gerencial</h2>' +
          '<div class="flex flex-wrap items-center gap-2">' +
            '<select id="gestao-setor" class="border border-slate-300 rounded-lg px-2 py-1.5 text-xs font-bold bg-white text-slate-700"></select>' +
            '<div class="flex items-center bg-white border border-slate-200 rounded-lg shadow-sm">' +
              '<button type="button" id="gestao-ant" class="px-2.5 py-1.5 text-slate-500 hover:text-blue-600 font-black" aria-label="Período anterior">&#8249;</button>' +
              '<span id="gestao-periodo" class="px-2 text-xs font-bold text-slate-600 whitespace-nowrap"></span>' +
              '<button type="button" id="gestao-prox" class="px-2.5 py-1.5 text-slate-500 hover:text-blue-600 font-black" aria-label="Próximo período">&#8250;</button>' +
            '</div>' +
          '</div>' +
        '</div>' +
        '<div id="gestao-corpo"></div>' +
      '</div>';
    var host = el('main-container') || doc.body;
    host.appendChild(v);
    el('gestao-ant').onclick = function () { gestaoRef = new Date(gestaoRef.getFullYear(), gestaoRef.getMonth() - 1, 1); renderGestao(); };
    el('gestao-prox').onclick = function () { gestaoRef = new Date(gestaoRef.getFullYear(), gestaoRef.getMonth() + 1, 1); renderGestao(); };
    el('gestao-setor').onchange = renderGestao;
  }
  function tecnicosVisiveis() {
    var lista = gTecnicos();
    if (gAccess() === 'admin' && gSetoresGer()) {
      var ok = gSetoresGer().split(',').map(function (s) { return s.trim().toUpperCase(); });
      lista = lista.filter(function (t) { return ok.indexOf(String(t.setor || '').toUpperCase()) >= 0; });
    }
    return lista;
  }
  function kpi(titulo, valor, sub, cor) {
    return '<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 border-l-4" style="border-left-color:' + cor + '">' +
      '<div class="text-[10px] font-bold text-slate-500 uppercase tracking-wide">' + titulo + '</div>' +
      '<div class="text-2xl font-black text-slate-800 mt-1">' + valor + '</div>' +
      '<div class="text-[10px] font-semibold text-slate-400 mt-0.5">' + sub + '</div></div>';
  }
  function barras(itens, cor, fmt, vazio, clicavel) {
    if (!itens.length) return '<div class="text-xs text-slate-400 font-semibold py-4 text-center">' + vazio + '</div>';
    var max = Math.max.apply(null, itens.map(function (i) { return i.v; })) || 1;
    return itens.map(function (i) {
      var w = Math.max(2, Math.round(i.v / max * 100));
      var attr = clicavel ? ' data-he-nome="' + esc(i.nome) + '" role="button" tabindex="0" style="cursor:pointer" title="Ver detalhes da hora extra de ' + esc(i.nomeCompleto || i.nome) + '"' : '';
      return '<div class="flex items-center gap-2 text-[11px] mb-1.5' + (clicavel ? ' hover:bg-amber-50 rounded' : '') + '"' + attr + '><div class="w-28 sm:w-36 truncate font-bold uppercase ' + (clicavel ? 'text-blue-700 underline' : 'text-slate-600') + '" title="' + esc(i.nomeCompleto || i.nome) + '">' + esc(i.nome) + '</div>' +
        '<div class="flex-1 bg-slate-100 rounded h-4 overflow-hidden"><div class="h-4 rounded" style="width:' + w + '%;background:' + cor + '"></div></div>' +
        '<div class="w-16 text-right font-black text-slate-700">' + fmt(i.v) + '</div></div>';
    }).join('');
  }
  function donut(fatias, fmt) {
    var total = fatias.reduce(function (s, f) { return s + f.v; }, 0);
    if (!total) return '<div class="text-xs text-slate-400 font-semibold py-4 text-center">Sem hora extra no período.</div>';
    var R = 15.9155, acum = 0, arcos = '';
    fatias.forEach(function (f, i) {
      var pct = f.v / total * 100;
      arcos += '<circle r="' + R + '" cx="21" cy="21" fill="none" stroke="' + CORES[i % CORES.length] + '" stroke-width="6" stroke-dasharray="' + pct.toFixed(3) + ' ' + (100 - pct).toFixed(3) + '" stroke-dashoffset="' + (25 - acum).toFixed(3) + '"><title>' + esc(f.nome) + ': ' + fmt(f.v) + '</title></circle>';
      acum += pct;
    });
    var leg = fatias.map(function (f, i) {
      return '<div class="flex items-center gap-2 text-[11px] font-bold text-slate-600"><span class="inline-block w-2.5 h-2.5 rounded-sm" style="background:' + CORES[i % CORES.length] + '"></span><span class="truncate">' + esc(f.nome) + '</span><span class="ml-auto text-slate-800">' + fmt(f.v) + ' · ' + Math.round(f.v / total * 100) + '%</span></div>';
    }).join('');
    return '<div class="flex items-center gap-4"><svg viewBox="0 0 42 42" class="w-32 h-32 shrink-0" role="img" aria-label="Hora extra por setor">' +
      '<circle r="' + R + '" cx="21" cy="21" fill="none" stroke="#f1f5f9" stroke-width="6"/>' + arcos + '</svg><div class="flex-1 space-y-1 min-w-0">' + leg + '</div></div>';
  }
  var DIAS_SEMANA = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
  function diaSemanaIdx(s) { var p = s.split('-'); return new Date(Date.UTC(+p[0], +p[1] - 1, +p[2])).getUTCDay(); }
  // Feriados do dia (mesma fonte do calendário mensal: móveis calculados + cadastrados em Configurações)
  function feriadosDoDia(dataStr) {
    var mmdd = dataStr.substring(5, 10), out = [];
    try {
      if (typeof getFeriadosMoveis === 'function') {
        var m = getFeriadosMoveis(+dataStr.substring(0, 4))[mmdd];
        if (m) out.push(m.nome + ' (' + String(m.tipo || 'feriado').toLowerCase() + ')');
      }
      if (typeof listaFeriadosCache !== 'undefined' && listaFeriadosCache) {
        listaFeriadosCache.forEach(function (f) {
          if (f.data_feriado && String(f.data_feriado).substring(5, 10) === mmdd) out.push(f.nome + ' (' + (f.tipo || 'feriado') + (f.localidade ? ' - ' + f.localidade : '') + ')');
        });
      }
    } catch (e) { /* sem feriados */ }
    return out;
  }
  function tipDia(d, tipFer) {
    var l = [fmtDataBR(d.data) + (tipFer ? ' — ' + tipFer : ''), 'Hora extra: ' + (d.heMin ? fmtMin(d.heMin) : 'nenhuma')];
    var ps = Object.keys(d.pessoas || {}).map(function (n) { return { n: n, m: d.pessoas[n].min, s: d.pessoas[n].sob }; }).sort(function (a, b) { return b.m - a.m; });
    ps.forEach(function (x) { l.push('• ' + x.n + ' — ' + fmtMin(x.m)); /* DASH_HEONLY_V1 */ });
    return l.join('\n');
  }
  function colunasDia(dias) {
    var max = Math.max.apply(null, dias.map(function (d) { return d.heMin; })) || 0;
    if (!max) return '<div class="text-xs text-slate-400 font-semibold py-4 text-center">Sem hora extra no período.</div>';
    return '<div class="flex items-end gap-[3px] h-32 pt-4">' + dias.map(function (d) {
      var h = d.heMin ? Math.max(4, Math.round(d.heMin / max * 100)) : 0;
      var fer = feriadosDoDia(d.data), tipFer = fer.length ? 'Feriado: ' + fer.join(' / ') : '';
      var corLetra = (fer.length || diaSemanaIdx(d.data) % 6 === 0) ? 'text-red-400' : 'text-slate-500';
      var corNum = fer.length ? 'text-red-400' : 'text-slate-400';
      return '<div class="flex-1 flex flex-col items-center justify-end h-full min-w-0" title="' + esc(tipDia(d, tipFer)) + '">' +
        (d.heMin ? '<div class="text-[8px] font-black text-slate-600 leading-none mb-0.5 whitespace-nowrap">' + fmtMin(d.heMin) + '</div>' : '') +
        '<div class="w-full rounded-t" style="height:' + h + '%;background:#f59e0b"></div>' +
        '<div class="text-[8px] ' + corNum + ' font-bold mt-0.5"' + (tipFer ? ' title="' + esc(tipFer) + '"' : '') + '>' + d.data.substring(8) + '</div>' +
        '<div class="text-[8px] font-black leading-none ' + corLetra + '" title="' + esc(tipFer || DIAS_SEMANA[diaSemanaIdx(d.data)]) + '">' + DIAS_SEMANA[diaSemanaIdx(d.data)].charAt(0) + '</div></div>';
    }).join('') + '</div>';
  }
  var expandido = {}; // estado expandir/recolher dos blocos do dashboard
  function btnExp(id, aberto, rotulo) {
    return '<button type="button" data-exp="' + id + '" class="mt-2 w-full text-[11px] font-black text-blue-600 hover:text-blue-800 hover:bg-blue-50 rounded py-1.5 border border-slate-200">' + (aberto ? '&#9650; Recolher' : '&#9660; ' + rotulo) + '</button>';
  }
  function expansivel(id, itens, limite, render) {
    var aberto = !!expandido[id];
    return render(aberto ? itens : itens.slice(0, limite)) + (itens.length > limite ? btnExp(id, aberto, 'Ver todos (' + itens.length + ')') : '');
  }
  function card(titulo, corpo, extra) {
    return '<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 ' + (extra || '') + '"><div class="text-[11px] font-black text-slate-700 uppercase mb-3">' + titulo + '</div>' + corpo + '</div>';
  }

  function renderGestao() {
    if (!ehGestor()) return;
    garantirViewGestao();
    if (!gestaoRef) gestaoRef = refInicial();
    var comp = competencia(gestaoRef.getFullYear(), gestaoRef.getMonth());
    var nomes = gNomesMeses();
    el('gestao-periodo').textContent = nomes[comp.mesFim0] + ' / ' + comp.anoFim + ' (15/' + pad(gestaoRef.getMonth() + 1) + ' a 14/' + pad(comp.mesFim0 + 1) + ')';

    var tecs = tecnicosVisiveis();
    var sel = el('gestao-setor'), atual = sel.value;
    var setores = Array.from(new Set(tecs.map(function (t) { return t.setor; }).filter(Boolean))).sort();
    sel.innerHTML = '<option value="">Todos os setores</option>' + setores.map(function (s) { return '<option value="' + esc(s) + '">' + esc(s) + '</option>'; }).join('');
    sel.value = setores.indexOf(atual) >= 0 ? atual : '';

    var r = calcularGestao({ plantao: gPlantao(), tecnicos: tecs, extras: horasExtrasDB, ini: comp.ini, fim: comp.fim, setor: sel.value });
    var t = r.totais, L = r.linhas;
    var porSob = L.filter(function (l) { return l.horasLiquidas > 0; }).sort(function (a, b) { return b.horasLiquidas - a.horasLiquidas; })
      .map(function (l) { return { nome: l.nome, nomeCompleto: l.nomeCompleto, v: l.horasLiquidas }; });
    var porHE = L.filter(function (l) { return l.heMin > 0; }).sort(function (a, b) { return b.heMin - a.heMin; })
      .map(function (l) { return { nome: l.nome, nomeCompleto: l.nomeCompleto, v: l.heMin }; });
    var porTk = L.filter(function (l) { return l.tickets > 0; }).sort(function (a, b) { return b.tickets - a.tickets; })
      .map(function (l) { return { nome: l.nome, nomeCompleto: l.nomeCompleto, v: l.tickets }; });

    var nHE = L.filter(function (l) { return l.heMin > 0; }).length;
    var LT = L.filter(function (l) { return l.heMin > 0; }); // DASH_FIX2_V1
    var html =
      '<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">' +
        kpi('Sobreaviso líquido', fmtH(t.horasLiquidas), 'Bruto ' + fmtH(t.horasBrutas) + (t.descontoMin ? ' − ' + fmtMin(t.descontoMin) + ' de hora extra' : ''), '#2563eb') +
        kpi('Hora extra', fmtMin(t.heMin), t.comHE + ' colaborador(es)', '#f59e0b') +
        kpi('Tickets extras', String(t.tickets), 'Dias com mais de 2h de hora extra no mesmo dia', '#7c3aed') +
        kpi('Colaboradores em sobreaviso', String(L.filter(function (l) { return l.dias > 0; }).length), L.reduce(function (s, l) { return s + l.dias; }, 0) + ' dias de sobreaviso', '#059669') +
      '</div>' +
      '<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">' +
        card('Sobreaviso líquido por colaborador <span class="normal-case font-semibold text-slate-400">(10 maiores)</span>', expansivel('sob', porSob, 10, function (m) { return barras(m, '#2563eb', fmtH, 'Sem sobreaviso no período.'); })) +
        card('Hora extra por colaborador <span class="normal-case font-semibold text-slate-400">(clique no nome para ver os chamados)</span>', barras(porHE, '#f59e0b', fmtMin, 'Sem hora extra no período.', true)) +
        card('Tickets extras por colaborador', barras(porTk, '#7c3aed', function (v) { return v + ' 🎫'; }, 'Nenhum colaborador com direito a ticket extra.')) +
        card('Hora extra por setor', donut(r.porSetor.filter(function (s) { return s.heMin > 0; }).map(function (s) { return { nome: s.setor, v: s.heMin }; }), fmtMin)) +
      '</div>' +
      card('Hora extra por dia (horas)', colunasDia(r.porDia), 'mb-4') +
      '<div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-x-auto"><table class="w-full text-xs"><thead class="bg-slate-50 text-[10px] uppercase text-slate-500 font-bold"><tr>' +
        '<th class="text-left px-3 py-2">Colaborador</th><th class="text-left px-3 py-2">Setor</th>' +
        '<th class="text-right px-3 py-2">Hora extra</th><th class="text-right px-3 py-2">Períodos</th><th class="text-right px-3 py-2">Tickets extras</th></tr></thead><tbody class="divide-y divide-slate-100">' +
      (LT.length ? LT.slice().sort(function (a, b) { return b.heMin - a.heMin || b.horasLiquidas - a.horasLiquidas; }).map(function (l) {
        return '<tr class="hover:bg-slate-50"><td class="px-3 py-2 font-bold text-slate-700 uppercase">' + esc(l.nome) + '</td><td class="px-3 py-2 text-slate-500">' + esc(l.setor) + '</td>' +
          '<td class="px-3 py-2 text-right ' + (l.heMin ? 'font-black text-amber-600' : 'text-slate-300') + '">' + (l.heMin ? fmtMin(l.heMin) : '—') + '</td>' +
          '<td class="px-3 py-2 text-right">' + (l.periodos || '—') + '</td>' +
          '<td class="px-3 py-2 text-right ' + (l.tickets ? 'font-black text-violet-700' : 'text-slate-300') + '">' + (l.tickets ? l.tickets + ' 🎫' : '—') + '</td></tr>';
      }).join('') : '<tr><td colspan="5" class="px-3 py-8 text-center text-slate-400 font-bold">' + (L.length ? 'Nenhum colaborador com hora extra no período.' : 'Nenhum dado no período.') + '</td></tr>') +
      '</tbody></table></div>' +
      '<p class="text-[10px] text-slate-400 font-semibold mt-3">Sobreaviso líquido = sobreaviso bruto menos as horas extras que coincidem com a janela de sobreaviso (a empresa não paga as duas ao mesmo tempo). Ticket extra: um por dia do calendário em que a soma da hora extra supera 2h (horas de dias diferentes não se acumulam).</p>';
    el('gestao-corpo').innerHTML = html;
    Array.prototype.forEach.call(el('gestao-corpo').querySelectorAll('[data-he-nome]'), function (n) {
      var abre = function () { abrirDetalheHE(n.getAttribute('data-he-nome'), comp); };
      n.onclick = abre;
      n.onkeydown = function (ev) { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); abre(); } };
    });
    Array.prototype.forEach.call(el('gestao-corpo').querySelectorAll('[data-exp]'), function (b) {
      b.onclick = function () {
        var k = b.getAttribute('data-exp'), sc = el('view-gestao'), topo = sc ? sc.scrollTop : 0;
        expandido[k] = !expandido[k]; renderGestao(); if (sc) sc.scrollTop = topo;
      };
    });
    icones();
  }
  root.renderGestao = renderGestao;

  function garantirModalDetalhe() {
    if (el('he-det-modal')) return;
    var d = doc.createElement('div');
    d.id = 'he-det-modal';
    d.className = 'hidden fixed inset-0 bg-slate-900/70 z-[410] flex items-center justify-center p-4';
    d.innerHTML = '<div class="bg-white rounded-xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto">' +
      '<div class="flex justify-between items-center px-5 py-3 border-b"><h3 class="text-sm font-black text-slate-800 uppercase flex items-center gap-2"><span class="inline-block w-3 h-1 bg-yellow-400 rounded"></span> Hora extra do colaborador</h3>' +
      '<button type="button" id="he-det-fechar" class="text-slate-400 hover:text-red-500 text-lg leading-none" aria-label="Fechar">&times;</button></div>' +
      '<div id="he-det-corpo" class="px-5 py-4"></div></div>';
    doc.body.appendChild(d);
    var fechar = function () { d.classList.add('hidden'); };
    el('he-det-fechar').onclick = fechar;
    d.addEventListener('mousedown', function (ev) { if (ev.target === d) fechar(); });
    doc.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && !d.classList.contains('hidden')) fechar(); });
  }
  function abrirDetalheHE(nome, comp) {
    if (!ehGestor()) return;
    garantirModalDetalhe();
    var tec = gTecnicos().filter(function (t) { return t.nome === nome; })[0] || {};
    var itens = horasExtrasDB.filter(function (e) {
      return e.nome === nome && e.data >= comp.ini && e.data <= comp.fim;
    }).sort(function (a, b) { return a.data < b.data ? -1 : a.data > b.data ? 1 : (a.dia_inicio - b.dia_inicio) || (hm(a.hora_inicio) - hm(b.hora_inicio)); });
    var porDia = {}, ordem = [];
    itens.forEach(function (e) { if (!porDia[e.data]) { porDia[e.data] = []; ordem.push(e.data); } porDia[e.data].push(e); });
    var total = 0, tickets = ticketsPorDia(itens).tickets, tkDias = ticketsPorDia(itens).ticketDias;
    var blocos = ordem.map(function (dt) {
      var lista = porDia[dt], soma = lista.reduce(function (s, e) { return s + e.minutos; }, 0), tocados = {}, tk;
      lista.forEach(function (e) { diasCalendario(e).forEach(function (d) { tocados[d] = true; }); });
      tk = tkDias.filter(function (d) { return tocados[d]; });
      total += soma;
      return '<div class="border border-yellow-200 bg-yellow-50/60 rounded-lg mb-2"><div class="flex justify-between items-center px-3 py-1.5 border-b border-yellow-200 text-[11px] font-black text-slate-700">' +
        '<span>Dia ' + fmtDataBR(dt) + '</span><span>' + fmtMin(soma) + (tk.length ? ' <span class="ml-1 px-1.5 py-0.5 rounded bg-violet-100 text-violet-700 border border-violet-300">🎫 ticket extra (' + tk.map(function (d) { return fmtDataBR(d).substring(0, 5); }).join(', ') + ')</span>' : '') + '</span></div>' +
        lista.map(function (e) {
          var vira = hm(e.hora_fim) <= hm(e.hora_inicio);
          return '<div class="flex justify-between items-center px-3 py-1.5 text-xs"><div class="font-bold text-slate-700">' + esc(e.hora_inicio) + (e.dia_inicio ? ' <span class="text-[9px] text-slate-400">(madrugada seguinte)</span>' : '') + ' → ' + esc(e.hora_fim) + (vira ? ' <span class="text-[9px] text-slate-400">(+1 dia)</span>' : '') +
            ' <span class="text-slate-400 font-semibold">· ' + fmtMin(e.minutos) + '</span>' + (e.origem === 'ponto' ? BADGE_PONTO : '') + '</div><div class="text-right">' + linksVidpol(e) + '</div></div>';
        }).join('') + '</div>';
    }).join('');
    el('he-det-corpo').innerHTML =
      '<div class="text-sm font-black text-slate-800 uppercase">' + esc(tec.nome_completo || nome) + '</div>' +
      '<div class="text-[11px] font-bold text-slate-500 mb-3">' + esc(tec.setor || '') + ' · ' + fmtDataBR(comp.ini) + ' a ' + fmtDataBR(comp.fim) + '</div>' +
      (blocos || '<div class="text-xs text-slate-400 font-semibold py-4 text-center">Sem hora extra no período.</div>') +
      '<div class="mt-3 pt-3 border-t flex justify-between text-xs font-black text-slate-700"><span>Total: ' + fmtMin(total) + ' em ' + itens.length + ' período(s)</span><span class="text-violet-700">' + tickets + ' ticket(s) extra(s)</span></div>' +
      '<p class="text-[10px] text-slate-400 font-semibold mt-2">Clique no VIDPOL para abrir o chamado no Jira em uma nova aba.</p>';
    el('he-det-modal').classList.remove('hidden');
  }
  root.abrirDetalheHE = abrirDetalheHE;

  /* ------------------ Períodos de sobreaviso (Configurações) ------------------ */
  function vigentesPorTipo() { // sem vigência: todos os períodos do tipo valem para qualquer data
    var out = {};
    Object.keys(TIPOS).forEach(function (tp) {
      out[tp] = periodosDB.filter(function (p) { return p.tipo === tp; })
        .sort(function (a, b) { return (a.dia_inicio * 1440 + hm(a.hora_inicio)) - (b.dia_inicio * 1440 + hm(b.hora_inicio)); });
    });
    return out;
  }
  function hojeISO() { var d = new Date(); return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function txtPeriodo(p) {
    return p.hora_inicio + (p.dia_inicio ? ' (' + DIA_LABEL[p.dia_inicio] + ')' : '') + ' → ' + p.hora_fim + (p.dia_fim ? ' (' + DIA_LABEL[p.dia_fim] + ')' : '');
  }
  async function carregarRegrasSO() { // regras do sobreaviso (checkboxes na aba Sobreaviso; salvam ao marcar/desmarcar)
    var box = el('so-regras'); if (!box) return;
    try {
      var j = await api('get_regras_sobreaviso'); if (!j.sucesso) return;
      root.REGRAS_SO = j.regras;
      Array.prototype.forEach.call(box.querySelectorAll('[data-regra]'), function (c) {
        var k = c.getAttribute('data-regra'); c.checked = !!j.regras[k]; c.disabled = !ehGestor();
        c.onchange = async function () {
          var b = {}; b[k] = c.checked; c.disabled = true;
          try {
            var r = await post('salvar_regras_sobreaviso', b);
            if (!r.sucesso) { c.checked = !c.checked; return toast(r.erro || 'Falha ao salvar a regra.', 'error'); }
            root.REGRAS_SO = r.regras; toast('Regra ' + (c.checked ? 'habilitada' : 'desabilitada') + '.');
          } catch (e) { c.checked = !c.checked; toast(e.message, 'error'); } finally { c.disabled = false; }
        };
      });
    } catch (e) {}
  }
  async function carregarPeriodosSobreaviso() {
    if (!ehGestor() || !el('periodos-tabela')) return;
    try {
      var j = await api('get_periodos_sobreaviso');
      if (j.sucesso) { periodosDB = j.itens || []; renderPeriodos(); }
      carregarRegrasSO();
    } catch (e) { toast('Não foi possível carregar os períodos de sobreaviso.', 'error'); }
  }
  function renderPeriodos() {
    var resumo = el('periodos-resumo'), tabela = el('periodos-tabela');
    if (!resumo || !tabela) return;
    var vig = vigentesPorTipo();
    resumo.innerHTML = Object.keys(TIPOS).map(function (tp) {
      var tot = vig[tp].reduce(function (s, p) { return s + p.minutos; }, 0);
      return '<div class="bg-white border rounded p-2"><div class="text-[10px] font-black text-slate-500 uppercase">' + TIPOS[tp] + ' <span class="text-blue-700">· ' + fmtMin(tot) + '</span></div>' +
        (vig[tp].map(function (p) { return '<div class="text-xs font-bold text-slate-700">' + esc(txtPeriodo(p)) + '</div>'; }).join('') || '<div class="text-xs text-slate-400">—</div>') + '</div>';
    }).join('');
    var ordenados = periodosDB.slice().sort(function (x, y) {
      return (x.tipo < y.tipo ? -1 : x.tipo > y.tipo ? 1 : 0) || ((x.dia_inicio * 1440 + hm(x.hora_inicio)) - (y.dia_inicio * 1440 + hm(y.hora_inicio)));
    });
    tabela.innerHTML = ordenados.length ? ordenados.map(function (p) {
      return '<tr class="border-b ' + (p.id === editandoPer ? 'bg-blue-50' : '') + '"><td class="px-2 py-1 font-bold">' + TIPOS[p.tipo] + '</td><td class="px-2 py-1">' + esc(txtPeriodo(p)) + '</td><td class="px-2 py-1 text-right font-bold">' + fmtMin(p.minutos) + '</td>' +
        '<td class="px-2 py-1 text-right whitespace-nowrap"><button type="button" class="per-edit text-blue-600 hover:text-blue-800 font-black px-1.5" data-id="' + p.id + '" title="Editar">&#9998;</button>' +
        '<button type="button" class="per-del text-red-500 hover:text-red-700 font-black px-1" data-id="' + p.id + '" title="Excluir">&times;</button></td></tr>';
    }).join('') : '<tr><td colspan="4" class="px-2 py-3 text-center text-slate-400">Nenhum período cadastrado.</td></tr>';
    Array.prototype.forEach.call(tabela.querySelectorAll('.per-del'), function (b) { b.onclick = function () { removerPeriodoSobreaviso(+b.getAttribute('data-id')); }; });
    Array.prototype.forEach.call(tabela.querySelectorAll('.per-edit'), function (b) { b.onclick = function () { editarPeriodoSobreaviso(+b.getAttribute('data-id')); }; });
    var dica = el('periodos-dica');
    if (dica) dica.textContent = 'Os períodos valem para qualquer data em que o sobreaviso for lançado. Plantões já lançados mantêm seus horários; para aplicar a um existente, limpe e lance novamente.';
  }
  function editarPeriodoSobreaviso(id) {
    var p = periodosDB.filter(function (x) { return x.id === id; })[0];
    if (!p) return;
    editandoPer = id;
    el('per-tipo').value = p.tipo; el('per-di').value = String(p.dia_inicio); el('per-hi').value = p.hora_inicio;
    el('per-df').value = String(p.dia_fim); el('per-hf').value = p.hora_fim;
    var b = el('per-salvar'); if (b) b.textContent = 'Salvar alteração';
    var c = el('per-cancelar'); if (c) c.classList.remove('hidden');
    renderPeriodos();
  }
  function cancelarEdicaoPeriodo() {
    editandoPer = null;
    var b = el('per-salvar'); if (b) b.textContent = 'Adicionar período';
    var c = el('per-cancelar'); if (c) c.classList.add('hidden');
    renderPeriodos();
  }
  async function adicionarPeriodoSobreaviso() {
    var body = { tipo: el('per-tipo').value, dia_inicio: +el('per-di').value, hora_inicio: el('per-hi').value, dia_fim: +el('per-df').value, hora_fim: el('per-hf').value };
    if (hm(body.hora_inicio) === null || hm(body.hora_fim) === null) return toast('Informe hora de início e de fim.', 'error');
    if (body.dia_fim * 1440 + hm(body.hora_fim) <= body.dia_inicio * 1440 + hm(body.hora_inicio)) return toast('O fim deve ser posterior ao início.', 'error');
    var idEd = editandoPer;
    try {
      if (idEd) body.id = idEd;
      var j = await post(idEd ? 'editar_periodo_sobreaviso' : 'adicionar_periodo_sobreaviso', body);
      if (!j.sucesso) return toast(j.erro || 'Falha ao salvar.', 'error');
      editandoPer = null;
      var b = el('per-salvar'); if (b) b.textContent = 'Adicionar período';
      var c = el('per-cancelar'); if (c) c.classList.add('hidden');
      await carregarPeriodosSobreaviso();
      ajustarHorasPagasAosPeriodos();
      toast(idEd ? 'Período atualizado.' : 'Período adicionado.');
    } catch (e) { toast(e.message, 'error'); }
  }
  async function removerPeriodoSobreaviso(id) {
    if (!confirm('Excluir este período de sobreaviso?')) return;
    try {
      var j = await post('remover_periodo_sobreaviso', { id: id });
      if (!j.sucesso) return toast(j.erro || 'Falha ao excluir.', 'error');
      if (editandoPer === id) { editandoPer = null; var bs = el('per-salvar'); if (bs) bs.textContent = 'Adicionar período'; var cc = el('per-cancelar'); if (cc) cc.classList.add('hidden'); }
      await carregarPeriodosSobreaviso();
      ajustarHorasPagasAosPeriodos();
      toast('Período removido.');
    } catch (e) { toast(e.message, 'error'); }
  }
  // Preenche os campos "Horas Pagas" com a soma dos períodos vigentes (o gestor ainda precisa clicar em salvar)
  function ajustarHorasPagasAosPeriodos() {
    var v = vigentesPorTipo(), a = el('cfg-horas-uteis'), b = el('cfg-horas-fds');
    var soma = function (tp) { return v[tp].reduce(function (s, p) { return s + p.minutos; }, 0) / 60; };
    if (a && v.SEMANA.length) a.value = soma('SEMANA');
    if (b && v.FIM_DE_SEMANA.length) b.value = soma('FIM_DE_SEMANA');
    if (a || b) toast('Horas pagas atualizadas nos campos acima — clique em salvar as opções para confirmar.', 'info');
  }
  root.carregarPeriodosSobreaviso = carregarPeriodosSobreaviso;
  root.adicionarPeriodoSobreaviso = adicionarPeriodoSobreaviso;
  root.removerPeriodoSobreaviso = removerPeriodoSobreaviso;
  root.editarPeriodoSobreaviso = editarPeriodoSobreaviso;
  root.cancelarEdicaoPeriodo = cancelarEdicaoPeriodo;
  root.ajustarHorasPagasAosPeriodos = ajustarHorasPagasAosPeriodos;

  /* ------------------ E-mail (SMTP) nas Configurações ------------------ */
  var SMTP_PORTAS = { tls: 587, ssl: 465, none: 25 };
  function garantirSmtpUI() {
    if (el('smtp-card')) return true;
    var ref = el('cfg-evo-url'), linha = ref && ref.closest ? ref.closest('.bg-slate-50') : null;
    if (!linha || !linha.parentNode) return false;
    var L = 'block text-[10px] font-bold text-slate-600 uppercase mb-1', I = 'w-full border rounded px-3 py-1.5 text-xs bg-white';
    var card = doc.createElement('div'); card.id = 'smtp-card'; card.className = 'bg-slate-50 p-4 rounded border shrink-0';
    card.innerHTML = '<h3 class="text-sm font-bold text-slate-700 mb-1 border-b pb-1">✉️ E-mail (SMTP)</h3>' +
      '<p class="text-[10px] text-slate-500 font-semibold mb-3">Usado pelo Ponto para enviar o relatório da folha e os avisos de regularização aos colaboradores.</p>' +
      '<div class="grid grid-cols-1 md:grid-cols-4 gap-3">' +
      '<div class="md:col-span-2"><label class="' + L + '">Servidor SMTP</label><input id="smtp-host" type="text" class="' + I + '" placeholder="smtp.office365.com" autocomplete="off"></div>' +
      '<div><label class="' + L + '">Segurança</label><select id="smtp-seg" class="' + I + '"><option value="tls">STARTTLS (587)</option><option value="ssl">SSL/TLS (465)</option><option value="none">Nenhuma (25)</option></select></div>' +
      '<div><label class="' + L + '">Porta</label><input id="smtp-porta" type="number" min="1" max="65535" class="' + I + '"></div>' +
      '<div><label class="' + L + '">Usuário</label><input id="smtp-user" type="text" class="' + I + '" autocomplete="off"></div>' +
      '<div><label class="' + L + '">Senha</label><input id="smtp-senha" type="password" class="' + I + '" autocomplete="new-password"></div>' +
      '<div><label class="' + L + '">E-mail do remetente</label><input id="smtp-rem" type="email" class="' + I + '" placeholder="ponto@empresa.com.br"></div>' +
      '<div><label class="' + L + '">Nome do remetente</label><input id="smtp-nome" type="text" class="' + I + '" placeholder="SmartNOC Ponto"></div></div>' +
      '<label class="mt-3 flex items-center gap-2 text-xs font-semibold text-slate-600"><input id="smtp-cert" type="checkbox" checked> Verificar o certificado do servidor (desmarque só se o servidor usa certificado autoassinado)</label>' +
      '<div class="mt-3 flex flex-wrap items-center gap-2"><button type="button" id="smtp-salvar" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-1.5 rounded font-bold text-xs shadow">Salvar e-mail</button>' +
      '<input id="smtp-teste" type="email" class="border rounded px-3 py-1.5 text-xs bg-white w-56" placeholder="e-mail para o teste">' +
      '<button type="button" id="smtp-enviar-teste" class="bg-slate-200 hover:bg-emerald-100 hover:text-emerald-700 text-slate-700 font-bold text-xs px-3 py-1.5 rounded">Enviar e-mail de teste</button>' +
      '<span id="smtp-status" class="text-xs font-bold"></span></div>';
    var botao = linha.nextElementSibling; // "Salvar Opções Globais"
    linha.parentNode.insertBefore(card, botao ? botao.nextSibling : null);
    el('smtp-seg').onchange = function () {
      var p = el('smtp-porta'), v = +p.value;
      if (!v || v === 587 || v === 465 || v === 25) p.value = SMTP_PORTAS[this.value];
    };
    el('smtp-salvar').onclick = salvarSmtp;
    el('smtp-enviar-teste').onclick = testarSmtp;
    return true;
  }
  function smtpMsg(t, ok) { var e = el('smtp-status'); if (!e) return; e.textContent = t || ''; e.className = 'text-xs font-bold ' + (ok ? 'text-emerald-600' : 'text-red-600'); }
  async function carregarSmtp() {
    if (!ehGestor() || !el('smtp-card')) return;
    try {
      var j = await api('get_smtp');
      if (!j.sucesso) return smtpMsg(j.erro, false);
      var d = j.dados;
      el('smtp-host').value = d.host || ''; el('smtp-seg').value = d.seguranca || 'tls'; el('smtp-porta').value = d.porta || SMTP_PORTAS[d.seguranca || 'tls'];
      el('smtp-user').value = d.usuario || ''; el('smtp-senha').value = ''; el('smtp-senha').placeholder = d.tem_senha ? '•••••••• (mantida)' : '';
      el('smtp-rem').value = d.remetente || ''; el('smtp-nome').value = d.remetente_nome || ''; el('smtp-cert').checked = d.verificar_cert !== false;
      smtpMsg(d.configurado ? 'Configurado.' : 'Ainda não configurado.', d.configurado);
    } catch (e) { smtpMsg('Não foi possível carregar a configuração de e-mail.', false); }
  }
  async function salvarSmtp() {
    smtpMsg('Salvando…', true);
    try {
      var j = await post('set_smtp', { host: el('smtp-host').value, seguranca: el('smtp-seg').value, porta: +el('smtp-porta').value, usuario: el('smtp-user').value,
        senha: el('smtp-senha').value, remetente: el('smtp-rem').value, remetente_nome: el('smtp-nome').value, verificar_cert: el('smtp-cert').checked });
      if (!j.sucesso) return smtpMsg(j.erro, false);
      el('smtp-senha').value = ''; el('smtp-senha').placeholder = j.dados.tem_senha ? '•••••••• (mantida)' : '';
      smtpMsg('Configuração de e-mail salva.', true);
    } catch (e) { smtpMsg(e.message, false); }
  }
  async function testarSmtp() {
    var para = el('smtp-teste').value.trim();
    if (!para) return smtpMsg('Informe o e-mail que receberá o teste.', false);
    smtpMsg('Enviando teste…', true);
    try {
      var j = await post('testar_smtp', { para: para });
      smtpMsg(j.sucesso ? 'E-mail de teste enviado para ' + para + '.' : j.erro, !!j.sucesso);
    } catch (e) { smtpMsg(e.message, false); }
  }

  /* ------------------------------ Hooks ------------------------------ */
  function hook(nome, fabrica) {
    var orig = root[nome];
    if (typeof orig !== 'function' || orig.__gestao) return;
    var novo = fabrica(orig); novo.__gestao = true; root[nome] = novo;
  }
  function instalarHooks() {
    hook('switchView', function (orig) {
      return function (mode) {
        var v = el('view-gestao');
        if (mode === 'gestao') {
          if (!ehGestor()) return orig('week');
          garantirViewGestao();
          orig('gestao');
          el('view-gestao').classList.remove('hidden');
          var fb = el('filter-bar-container');
          if (fb) { fb.classList.add('hidden'); fb.classList.remove('flex'); }
          if (typeof root.adjustMainHeight === 'function') root.adjustMainHeight();
          renderGestao();
          return;
        }
        if (v) v.classList.add('hidden');
        return orig.apply(this, arguments);
      };
    });
    hook('openCellMenu', function (orig) {
      return function (ev, nome, data) {
        var b = el('btn-hora-extra');
        if (b) {
          var c = gPlantao()[nome] && gPlantao()[nome][data];
          b.classList.toggle('hidden', !ehGestor());
        }
        return orig.apply(this, arguments);
      };
    });
    hook('abrirConfiguracoes', function (orig) {
      return function () { var r = orig.apply(this, arguments); carregarPeriodosSobreaviso(); if (ehGestor() && garantirSmtpUI()) carregarSmtp(); return r; };
    });
  }

  function iniciar() {
    instalarHooks(); // os hooks já checam o perfil; assim 'gestao' nunca abre para quem não é gestor
    if (!ehGestor()) return;
    carregarHorasExtras().then(rerenderTudo);
  }
  root.carregarHorasExtras = carregarHorasExtras;
  iniciar();
})(typeof window !== 'undefined' ? window : globalThis);

/* CFG_ABAS_V1 — Painel de Administração em abas (agrupa os cartões do painel; cartões criados depois também são classificados) */
(function (root) {
  'use strict';
  var doc = root.document;
  if (!doc) return;
  var ABAS = [
    { id: 'sobreaviso', rot: 'Sobreaviso', ic: '⏰' },
    { id: 'geral', rot: 'Geral', ic: '⚙️' },
    { id: 'email', rot: 'E-mail', ic: '✉️' },
    { id: 'relatorios', rot: 'Relatórios', ic: '📊' },
    { id: 'feriados', rot: 'Feriados', ic: '📅' },
    { id: 'acessos', rot: 'Acessos', ic: '🔑' },
    { id: 'colab', rot: 'Colaboradores', ic: '👥' },
    { id: 'whatsapp', rot: 'WhatsApp', ic: '💬' },
    { id: 'auditoria', rot: 'Auditoria', ic: '🛡️' }, // AUDITORIA_ABA_V3
    { id: 'outros', rot: 'Outros', ic: '📋' }
  ];
  var ativa = 'sobreaviso', trabalhando = false, timer = null;

  function norm(t) { return String(t || '').toLowerCase().normalize('NFD').replace(/[^\x00-\x7f]/g, ''); }
  function classificar(card) {
    var h = card.querySelector('h3, h2'), t = norm(h ? h.textContent : '');
    if (card.id === 'smtp-card' || t.indexOf('e-mail') >= 0 || t.indexOf('smtp') >= 0) return 'email';
    if (!t) return null;
    if (t.indexOf('periodos de sobreaviso') >= 0) return 'sobreaviso';
    if (t.indexOf('regras de calculo') >= 0 || t.indexOf('evolution') >= 0 || t.indexOf('logo') >= 0) return 'geral';
    if (t.indexOf('feriado') >= 0) return 'feriados';
    if (t.indexOf('contas de acesso') >= 0 || t.indexOf('login') >= 0) return 'acessos';
    if (t.indexOf('colaboradores') >= 0) return 'colab';
    if (t.indexOf('whatsapp') >= 0 || t.indexOf('grupos') >= 0 || t.indexOf('fila') >= 0) return 'whatsapp';
    return 'outros';
  }
  function areaRolagem() { return doc.querySelector('#config-modal .custom-scrollbar'); }

  function aplicar() {
    var box = areaRolagem();
    if (!box || trabalhando) return;
    trabalhando = true;
    try {
      var barra = doc.getElementById('cfg-abas');
      if (!barra) {
        barra = doc.createElement('div');
        barra.id = 'cfg-abas';
        barra.className = 'flex flex-wrap gap-1 border-b mb-3 shrink-0'; barra.style.overflow = 'visible';
        box.parentNode.insertBefore(barra, box);
        var painel = box.parentNode;
        if (painel && painel.style) painel.style.height = '88vh'; // altura fixa: trocar de aba não "pula" a janela
      }
      var ult = 'geral', cont = {};
      Array.prototype.forEach.call(box.children, function (c) {
        var k = c.getAttribute('data-cfgtab') || classificar(c) || ult;
        c.setAttribute('data-cfgtab', k); ult = k; cont[k] = (cont[k] || 0) + 1;
        c.style.display = (k === ativa) ? '' : 'none';
      });
      var html = '';
      ABAS.forEach(function (a) {
        if (!cont[a.id] && a.id === 'outros') return;
        var on = a.id === ativa;
        html += '<button type="button" data-aba="' + a.id + '" class="whitespace-nowrap px-3 py-2 text-xs font-bold border-b-2 -mb-px ' +
          (on ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500 hover:text-slate-800 hover:border-slate-300') + '">' + a.ic + ' ' + a.rot + '</button>';
      });
      if (barra.getAttribute('data-sig') !== html) {
        barra.innerHTML = html; barra.setAttribute('data-sig', html);
        Array.prototype.forEach.call(barra.querySelectorAll('button'), function (b) {
          b.onclick = function () { ativa = b.getAttribute('data-aba'); aplicar(); box.scrollTop = 0; };
        });
      }
    } finally { trabalhando = false; }
  }
  function agendar() { clearTimeout(timer); timer = setTimeout(aplicar, 30); }

  function iniciar() {
    var box = areaRolagem();
    if (!box) return false;
    aplicar();
    new MutationObserver(agendar).observe(box, { childList: true });
    return true;
  }
  if (typeof root.abrirConfiguracoes === 'function') {
    var orig = root.abrirConfiguracoes;
    root.abrirConfiguracoes = function () { var r = orig.apply(this, arguments); aplicar(); setTimeout(aplicar, 300); setTimeout(aplicar, 1200); return r; };
  }
  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', iniciar); else iniciar();
})(typeof window !== 'undefined' ? window : globalThis);
