/* sobreaviso_ferramentas.js — SO_FERRAMENTAS_V1
   1) Configurações → Sobreaviso: "Atualizar sobreavisos da folha" (reaplica os períodos atuais aos sobreavisos já lançados).
   2) Mensal: "Sobreaviso pontual" no menu do dia, com horário escolhido no lançamento e observação opcional. */
(function (root) {
  'use strict';
  var doc = root.document;
  var MESES = ['JANEIRO', 'FEVEREIRO', 'MARÇO', 'ABRIL', 'MAIO', 'JUNHO', 'JULHO', 'AGOSTO', 'SETEMBRO', 'OUTUBRO', 'NOVEMBRO', 'DEZEMBRO'];
  function el(id) { return doc.getElementById(id); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function pad(n) { return String(n).padStart(2, '0'); }
  function dmy(s) { var p = String(s).split('-'); return p[2] + '/' + p[1] + '/' + p[0]; }
  function toast(m, t) { if (typeof showToast === 'function') showToast(m, t || 'success'); else alert(m); }
  async function api(acao, dados) {
    var r = await fetch('api_escala.php?action=' + acao, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(dados) });
    try { return await r.json(); } catch (e) { return { sucesso: false, erro: 'Resposta inválida do servidor.' }; }
  }
  function hm(t) { return String(t || '').slice(0, 5); }

  /* ---------- mensal: marcador e dica do sobreaviso pontual ---------- */
  function ehPontual(c) { return c && typeof c === 'object' && c.status === 'plantao_pontual'; }
  function faixa(c) {
    var f = (c && c.fracoes) || []; if (!f.length) return '';
    var a = f[0], b = f[f.length - 1], fim = hm(b.hora_fim) === '23:59' ? '24:00' : hm(b.hora_fim);
    return hm(a.hora_inicio) + ' às ' + fim + (b.data_inicio !== a.data_inicio ? ' (+1 dia)' : '');
  }
  root.soTituloPontual = function (c) {
    if (!ehPontual(c)) return '';
    return ('Sobreaviso pontual: ' + faixa(c) + (c.obs ? ' — ' + c.obs : '')).replace(/"/g, '&quot;');
  };
  root.soMarcadorPontual = function (c) {
    if (!ehPontual(c)) return '';
    return '<div class="absolute inset-0 flex items-center justify-center text-[9px] font-black text-white pointer-events-none" style="text-shadow:0 0 2px rgba(0,0,0,.4)">P' + (c.obs ? '*' : '') + '</div>';
  };

  /* ---------- mensal: lançamento do sobreaviso pontual ---------- */
  function modalPontual() {
    var m = el('so-pontual-modal'); if (m) return m;
    m = doc.createElement('div'); m.id = 'so-pontual-modal';
    m.className = 'hidden fixed inset-0 bg-black/60 z-[320] flex items-center justify-center p-4';
    m.innerHTML = '<div class="bg-white p-5 rounded-xl shadow-xl w-full max-w-sm">' +
      '<h3 class="text-md font-bold mb-1">Sobreaviso pontual</h3><div id="sp-quem" class="text-xs text-slate-500 mb-3"></div>' +
      '<div class="grid grid-cols-2 gap-3">' +
      '<div><label class="block text-[10px] font-bold uppercase mb-1">Início</label><input type="time" id="sp-ini" class="w-full border rounded px-2 py-1 text-sm"></div>' +
      '<div><label class="block text-[10px] font-bold uppercase mb-1">Fim</label><input type="time" id="sp-fim" class="w-full border rounded px-2 py-1 text-sm"></div>' +
      '<div class="col-span-2 text-[11px] text-slate-600" id="sp-dur"></div>' +
      '<div class="col-span-2"><label class="block text-[10px] font-bold uppercase mb-1">Observação (opcional)</label><textarea id="sp-obs" maxlength="300" rows="3" class="w-full border rounded px-2 py-1 text-sm" placeholder="Ex.: cobertura do chamado X, troca combinada com…"></textarea></div>' +
      '</div><div id="sp-erro" class="hidden text-red-600 text-xs mt-2"></div>' +
      '<div class="flex gap-2 pt-3"><button type="button" id="sp-cancelar" class="flex-1 bg-slate-200 py-1.5 rounded font-bold">Cancelar</button>' +
      '<button type="button" id="sp-salvar" class="flex-1 bg-blue-600 text-white py-1.5 rounded font-bold disabled:opacity-50">Lançar</button></div></div>';
    doc.body.appendChild(m);
    var dur = function () {
      var a = el('sp-ini').value, b = el('sp-fim').value; if (!a || !b) { el('sp-dur').textContent = ''; return; }
      var x = +a.slice(0, 2) * 60 + +a.slice(3, 5), y = +b.slice(0, 2) * 60 + +b.slice(3, 5), mais = y <= x; if (mais) y += 1440;
      el('sp-dur').innerHTML = 'Duração: <b>' + Math.floor((y - x) / 60) + 'h' + pad((y - x) % 60) + '</b>' + (mais ? ' · termina no dia seguinte' : '');
    };
    el('sp-ini').oninput = dur; el('sp-fim').oninput = dur;
    el('sp-cancelar').onclick = function () { m.classList.add('hidden'); };
    m._dur = dur;
    return m;
  }
  root.abrirSobreavisoPontual = function () {
    var menu = el('cell-context-menu'); if (menu) menu.classList.add('hidden');
    var alvo = (typeof targetCellData !== 'undefined' && targetCellData) ? { nome: targetCellData.nome, dataStr: targetCellData.dataStr } : null;
    if (!alvo) return;
    var c = (typeof plantaoMensalDados !== 'undefined' && plantaoMensalDados[alvo.nome]) ? plantaoMensalDados[alvo.nome][alvo.dataStr] : null;
    var st = c && typeof c === 'object' ? c.status : c;
    if ((root.REGRAS_SO || {}).bloquear_ausencia !== false && ['ferias', 'atestado', 'licenca', 'folga_aniversario'].indexOf(st) >= 0) {
      alert('⚠️ ' + alvo.nome + ' tem ' + String(st).toUpperCase() + ' em ' + dmy(alvo.dataStr) + '.\n\nRemova a ausência (Livre/Limpar) primeiro.'); return;
    }
    var m = modalPontual();
    el('sp-quem').innerHTML = '<b>' + esc(alvo.nome) + '</b> · dia ' + dmy(alvo.dataStr) + (st && String(st).indexOf('plantao') === 0 && st !== 'plantao_pontual' ? ' · <span class="text-amber-700">substitui o sobreaviso deste dia</span>' : '');
    var f = (c && c.fracoes) || [];
    if (ehPontual(c) && f.length) { el('sp-ini').value = hm(f[0].hora_inicio); var hf = hm(f[f.length - 1].hora_fim); el('sp-fim').value = hf === '23:59' ? '00:00' : hf; el('sp-obs').value = c.obs || ''; }
    else { el('sp-ini').value = '18:00'; el('sp-fim').value = '08:00'; el('sp-obs').value = ''; }
    el('sp-erro').classList.add('hidden'); m._dur(); m.classList.remove('hidden');
    el('sp-salvar').onclick = async function () {
      var b = this; b.disabled = true; el('sp-erro').classList.add('hidden');
      try {
        var j = await api('so_pontual', { nome: alvo.nome, data: alvo.dataStr, hora_inicio: el('sp-ini').value, hora_fim: el('sp-fim').value, observacao: el('sp-obs').value });
        if (!j.sucesso) { el('sp-erro').textContent = j.erro || 'Não foi possível lançar.'; el('sp-erro').classList.remove('hidden'); return; }
        m.classList.add('hidden'); toast('Sobreaviso pontual lançado.');
        if (typeof recarregarDadosBanco === 'function') await recarregarDadosBanco();
      } finally { b.disabled = false; }
    };
  };
  function itemMenu() {
    var menu = el('cell-context-menu'); if (!menu || el('so-item-pontual')) return;
    var ref = menu.querySelector('[onclick="setCellStatus(\'plantao\')"]'); if (!ref) return;
    var it = doc.createElement('div'); it.id = 'so-item-pontual';
    it.className = 'px-3 py-1.5 hover:bg-slate-100 cursor-pointer flex items-center gap-2';
    it.innerHTML = '<div class="w-2.5 h-2.5 rounded bg-green-500 shadow-sm flex items-center justify-center text-[7px] text-white font-black">P</div>Sobreaviso pontual (horário)';
    it.onclick = function (e) { e.stopPropagation(); root.abrirSobreavisoPontual(); };
    ref.parentNode.insertBefore(it, ref.nextSibling);
  }

  /* ---------- Configurações → Sobreaviso: atualizar a folha ---------- */
  function folhaEmExibicao() {
    var c = (typeof currentMonthDate !== 'undefined' && currentMonthDate) ? currentMonthDate : null, h = new Date();
    var d = c ? new Date(c.getFullYear(), c.getMonth() + 1, 1) : new Date(h.getFullYear(), h.getMonth() + (h.getDate() >= 15 ? 1 : 0), 1); // folha em exibição = mês seguinte ao dia 15 inicial
    return d.getFullYear() + '-' + pad(d.getMonth() + 1);
  }
  function opcoesFolha() {
    var atual = folhaEmExibicao(), p = atual.split('-'), base = new Date(+p[0], +p[1] - 1, 1), out = [];
    for (var i = 2; i >= -12; i--) {
      var d = new Date(base.getFullYear(), base.getMonth() + i, 1), ant = new Date(d.getFullYear(), d.getMonth() - 1, 1);
      var v = d.getFullYear() + '-' + pad(d.getMonth() + 1);
      out.push('<option value="' + v + '"' + (v === atual ? ' selected' : '') + '>' + MESES[d.getMonth()] + '/' + d.getFullYear() + ' (15/' + pad(ant.getMonth() + 1) + ' a 14/' + pad(d.getMonth() + 1) + ')</option>');
    }
    return out.join('');
  }
  // SO_ANO_MES_V1: escolhe primeiro o ano e depois o mês da folha
  function anoMesFolha() {
    var atual = folhaEmExibicao().split('-'), anoA = +atual[0], selA = el('so-folha-ano'), selM = el('so-folha-mes'), h = new Date().getFullYear(), a = [];
    for (var y = Math.max(h, anoA) + 1; y >= Math.min(h, anoA) - 3; y--) a.push('<option value="' + y + '"' + (y === anoA ? ' selected' : '') + '>' + y + '</option>');
    selA.innerHTML = a.join('');
    var meses = function (sel) {
      selM.innerHTML = MESES.map(function (n, i) { return '<option value="' + pad(i + 1) + '"' + (i + 1 === sel ? ' selected' : '') + '>' + n + ' (15/' + pad(i === 0 ? 12 : i) + ' a 14/' + pad(i + 1) + ')</option>'; }).join('');
    };
    meses(+atual[1]);
    selA.onchange = function () { el('so-folha-msg').textContent = ''; };
    selM.onchange = function () { el('so-folha-msg').textContent = ''; };
  }
  function cardFolha() {
    var box = doc.querySelector('#config-modal .custom-scrollbar'); if (!box) return;
    var card = el('so-folha-card');
    if (!card) {
      card = doc.createElement('div'); card.id = 'so-folha-card'; card.setAttribute('data-cfgtab', 'sobreaviso');
      card.className = 'bg-amber-50 p-4 rounded border border-amber-200 shrink-0'; card.style.display = 'none';
      card.innerHTML = '<h3 class="text-sm font-bold text-slate-700 mb-1">🔄 Atualizar sobreavisos da folha</h3>' +
        '<p class="text-[11px] text-slate-600 mb-3">Reaplica os períodos de sobreaviso atuais a todos os sobreavisos já lançados na folha escolhida, mantendo quem está marcado em cada dia na escala mensal. Sobreavisos pontuais (com horário próprio) não são alterados.</p>' +
        '<div class="flex flex-wrap gap-2 items-end"><div><label class="block text-[10px] font-bold uppercase mb-1">Ano</label><select id="so-folha-ano" class="border rounded px-2 py-1.5 text-sm bg-white"></select></div>' +
        '<div><label class="block text-[10px] font-bold uppercase mb-1">Mês da folha</label><select id="so-folha-mes" class="border rounded px-2 py-1.5 text-sm bg-white"></select></div>' +
        '<button type="button" id="so-folha-btn" class="bg-amber-500 hover:bg-amber-600 text-white px-4 py-1.5 rounded text-sm font-bold disabled:opacity-50">Atualizar</button></div>' +
        '<div id="so-folha-msg" class="text-xs mt-2"></div>';
      var ref = box.querySelector('[data-cfgtab="sobreaviso"]');
      if (ref && ref.nextSibling) box.insertBefore(card, ref.nextSibling); else box.appendChild(card);
      el('so-folha-btn').onclick = atualizarFolha;
    }
    anoMesFolha(); el('so-folha-msg').textContent = '';
  }
  async function atualizarFolha() {
    var b = el('so-folha-btn'), msg = el('so-folha-msg'), folha = el('so-folha-ano').value + '-' + el('so-folha-mes').value, nomeF = el('so-folha-mes').selectedOptions[0].textContent.replace(' (', '/' + el('so-folha-ano').value + ' (');
    b.disabled = true; msg.className = 'text-xs mt-2 text-slate-500'; msg.textContent = 'Verificando…';
    try {
      var s = await api('so_folha_atualizar', { folha: folha, simular: 1 });
      if (!s.sucesso) { msg.className = 'text-xs mt-2 text-red-600'; msg.textContent = s.erro; return; }
      if (!s.alterar) { msg.className = 'text-xs mt-2 text-emerald-700 font-bold'; msg.textContent = 'Tudo já está de acordo com os períodos atuais (' + s.total + ' dia(s) de sobreaviso verificados' + (s.pontuais ? ', ' + s.pontuais + ' pontual(is) mantido(s)' : '') + ').'; return; }
      if (!confirm('Folha ' + nomeF + '\n\n' + s.alterar + ' de ' + s.total + ' dia(s) de sobreaviso (' + s.colaboradores + ' colaborador(es)) terão os horários atualizados para os períodos atuais.' + (s.pontuais ? '\n' + s.pontuais + ' sobreaviso(s) pontual(is) não serão alterados.' : '') + '\n\nConfirma a atualização?')) { msg.textContent = ''; return; }
      msg.textContent = 'Atualizando…';
      var j = await api('so_folha_atualizar', { folha: folha });
      if (!j.sucesso) { msg.className = 'text-xs mt-2 text-red-600'; msg.textContent = j.erro; return; }
      msg.className = 'text-xs mt-2 text-emerald-700 font-bold'; msg.textContent = '✓ ' + j.alterados + ' dia(s) de sobreaviso atualizados (' + j.colaboradores + ' colaborador(es)).';
      toast('Sobreavisos da folha atualizados.');
      if (typeof recarregarDadosBanco === 'function') recarregarDadosBanco();
    } finally { b.disabled = false; }
  }
  if (typeof root.abrirConfiguracoes === 'function') {
    var origCfg = root.abrirConfiguracoes;
    root.abrirConfiguracoes = function () { var r = origCfg.apply(this, arguments); try { cardFolha(); } catch (e) {} return r; };
  }
  function iniciar() { itemMenu(); try { cardFolha(); } catch (e) {} }
  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', iniciar); else iniciar();
})(window);
