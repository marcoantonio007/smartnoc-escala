/* ponto_admin.js — Painel de gestão do ponto eletrônico (somente admin/mestre): aprovações, registros,
   colaboradores (PIN / rosto) e regra de horário. Carregado no fim do index.php. */
(function (root) {
  'use strict';
  if (!root.document || !root.document.body) return;
  var doc = root.document;
  var DIAS = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
  var ORDEM_DIAS = [1, 2, 3, 4, 5, 6, 0];
  var TIPOS = ['ENTRADA', 'SAIDA_ALMOCO', 'RETORNO_ALMOCO', 'SAIDA'];
  var ROT = { ENTRADA: 'Início da atividade', SAIDA_ALMOCO: 'Saída para o almoço', RETORNO_ALMOCO: 'Retorno do almoço', SAIDA: 'Fim de expediente' };
  var aba = 'aprovacoes';

  function el(id) { return doc.getElementById(id); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function gAccess() { return typeof currentAccess !== 'undefined' ? currentAccess : ''; }
  function ehGestor() { var a = gAccess(); return a === 'admin' || a === 'mestre'; }
  function toast(m, t) { if (typeof root.showToast === 'function') root.showToast(m, t || 'success'); else if (t === 'error') alert(m); }
  function hhmm(ts) { return ts ? String(ts).substring(11, 16) : ''; }
  function dataBR(s) { var p = s.split('-'); return p[2] + '/' + p[1] + '/' + p[0]; }
  function fmtMin(m) { m = Math.abs(Math.round(m)); return Math.floor(m / 60) + 'h' + (m % 60 ? String(m % 60).padStart(2, '0') + 'm' : ''); }
  function hoje() { var d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
  function diasAtras(n) { var d = new Date(); d.setDate(d.getDate() - n); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }

  async function api(action, body) {
    var r = await fetch('api_ponto.php?action=' + action, body === undefined ? { credentials: 'same-origin' } :
      { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    var txt = await r.text(), j;
    try { j = JSON.parse(txt); } catch (e) { throw new Error('Resposta inválida do servidor.'); }
    return j;
  }

  /* ------------------------------ modal ------------------------------ */
  function garantirModal() {
    if (el('ponto-modal')) return;
    var d = doc.createElement('div');
    d.id = 'ponto-modal';
    d.className = 'hidden fixed inset-0 bg-slate-900/70 z-[420] flex items-center justify-center p-3';
    d.innerHTML =
      '<div class="bg-white rounded-xl shadow-2xl w-full max-w-5xl max-h-[92vh] flex flex-col">' +
        '<div class="flex justify-between items-center px-5 py-3 border-b shrink-0">' +
          '<h3 class="text-sm font-black text-slate-800 uppercase">⏱ Ponto eletrônico — Gestão</h3>' +
          '<button type="button" id="ponto-fechar" class="text-slate-400 hover:text-red-500 text-xl leading-none" aria-label="Fechar">&times;</button></div>' +
        '<div class="flex gap-1 px-5 pt-3 border-b shrink-0 text-xs font-bold overflow-x-auto" id="ponto-abas"></div>' +
        '<div id="ponto-corpo" class="p-5 overflow-y-auto"></div></div>';
    doc.body.appendChild(d);
    el('ponto-fechar').onclick = function () { d.classList.add('hidden'); };
    d.addEventListener('mousedown', function (ev) { if (ev.target === d) d.classList.add('hidden'); });
    doc.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { d.classList.add('hidden'); var f = el('ponto-foto'); if (f) f.classList.add('hidden'); } });
  }
  var ABAS = [['aprovacoes', 'Aprovações'], ['registros', 'Registros'], ['colaboradores', 'Colaboradores'], ['regra', 'Regra de horário']];
  function renderAbas(pend) {
    el('ponto-abas').innerHTML = ABAS.map(function (a) {
      var ativa = a[0] === aba;
      return '<button type="button" data-aba="' + a[0] + '" class="px-3 py-2 -mb-px border-b-2 whitespace-nowrap ' + (ativa ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500 hover:text-slate-700') + '">' + a[1] +
        (a[0] === 'aprovacoes' && pend ? ' <span class="ml-1 px-1.5 py-0.5 rounded-full bg-red-500 text-white text-[10px]">' + pend + '</span>' : '') + '</button>';
    }).join('');
    Array.prototype.forEach.call(el('ponto-abas').querySelectorAll('[data-aba]'), function (b) {
      b.onclick = function () { aba = b.getAttribute('data-aba'); abrirAba(); };
    });
  }
  async function abrirPonto() {
    if (!ehGestor()) return;
    garantirModal(); el('ponto-modal').classList.remove('hidden'); aba = 'aprovacoes'; abrirAba();
  }
  async function abrirAba() {
    renderAbas(contPend);
    var c = el('ponto-corpo'); c.innerHTML = '<div class="text-sm text-slate-400">Carregando…</div>';
    try {
      if (aba === 'aprovacoes') await abaAprovacoes(c);
      else if (aba === 'registros') abaRegistros(c);
      else if (aba === 'colaboradores') await abaColaboradores(c);
      else await abaRegra(c);
    } catch (e) { c.innerHTML = '<div class="text-sm font-bold text-red-600">' + esc(e.message) + '</div>'; }
  }

  /* ------------------------------ aprovações ------------------------------ */
  var contPend = 0;
  async function abaAprovacoes(c) {
    var j = await api('ponto_admin_pendencias');
    if (!j.sucesso) throw new Error(j.erro);
    contPend = j.itens.length; atualizarBadge(); renderAbas(contPend);
    if (!j.itens.length) { c.innerHTML = '<div class="text-sm text-slate-400 font-semibold py-6 text-center">Nenhuma batida manual aguardando aprovação.</div>'; return; }
    c.innerHTML = '<div class="overflow-x-auto"><table class="w-full text-xs"><thead class="bg-slate-50 text-[10px] uppercase text-slate-500"><tr>' +
      '<th class="text-left px-2 py-2">Colaborador</th><th class="text-left px-2 py-2">Dia</th><th class="text-left px-2 py-2">Batida</th><th class="text-left px-2 py-2">Hora</th><th class="text-left px-2 py-2">Justificativa</th><th></th></tr></thead><tbody class="divide-y">' +
      j.itens.map(function (i) {
        return '<tr><td class="px-2 py-2 font-bold uppercase">' + esc(i.nome) + '</td><td class="px-2 py-2">' + dataBR(i.data) + '</td><td class="px-2 py-2">' + esc(i.rotulo) + '</td>' +
          '<td class="px-2 py-2 font-black tabular-nums">' + esc(i.hora) + '</td><td class="px-2 py-2 max-w-xs">' + esc(i.justificativa) + '</td>' +
          '<td class="px-2 py-2 whitespace-nowrap text-right"><button data-dec="aprovar" data-id="' + i.id + '" class="px-2 py-1 rounded bg-emerald-600 text-white font-bold mr-1">Aprovar</button>' +
          '<button data-dec="recusar" data-id="' + i.id + '" class="px-2 py-1 rounded bg-red-600 text-white font-bold">Recusar</button></td></tr>';
      }).join('') + '</tbody></table></div>';
    Array.prototype.forEach.call(c.querySelectorAll('[data-dec]'), function (b) {
      b.onclick = async function () {
        var dec = b.getAttribute('data-dec'), motivo = '';
        if (dec === 'recusar') { motivo = prompt('Motivo da recusa (obrigatório):') || ''; if (motivo.trim().length < 5) return toast('Informe o motivo da recusa.', 'error'); }
        b.disabled = true;
        var r = await api('ponto_admin_decidir', { id: +b.getAttribute('data-id'), decisao: dec, motivo: motivo });
        if (!r.sucesso) { toast(r.erro, 'error'); b.disabled = false; return; }
        toast(dec === 'aprovar' ? 'Batida aprovada.' : 'Batida recusada.'); abrirAba();
      };
    });
  }

  /* ------------------------------ registros ------------------------------ */
  function abaRegistros(c) {
    c.innerHTML = '<div class="flex flex-wrap gap-2 items-end mb-3">' +
      '<label class="text-[10px] font-bold text-slate-500 uppercase">Colaborador<input id="pr-nome" class="mt-1 border rounded px-2 py-1.5 text-xs w-44 block" placeholder="Todos"></label>' +
      '<label class="text-[10px] font-bold text-slate-500 uppercase">De<input id="pr-ini" type="date" class="mt-1 border rounded px-2 py-1.5 text-xs block" value="' + diasAtras(7) + '"></label>' +
      '<label class="text-[10px] font-bold text-slate-500 uppercase">Até<input id="pr-fim" type="date" class="mt-1 border rounded px-2 py-1.5 text-xs block" value="' + hoje() + '"></label>' +
      '<button id="pr-buscar" class="px-3 py-1.5 rounded bg-blue-600 text-white text-xs font-bold">Buscar</button></div><div id="pr-lista" class="space-y-2"></div>';
    var buscar = async function () {
      var l = el('pr-lista'); l.innerHTML = '<div class="text-sm text-slate-400">Carregando…</div>';
      try {
        var j = await api('ponto_admin_registros&inicio=' + el('pr-ini').value + '&fim=' + el('pr-fim').value + '&nome=' + encodeURIComponent(el('pr-nome').value.trim()));
        if (!j.sucesso) { l.innerHTML = '<div class="text-sm font-bold text-red-600">' + esc(j.erro) + '</div>'; return; }
        l.innerHTML = j.dias.length ? j.dias.map(cartaoDia).join('') : '<div class="text-sm text-slate-400 py-4 text-center">Sem registros no período.</div>';
        Array.prototype.forEach.call(l.querySelectorAll('[data-foto]'), function (b) { b.onclick = function () { verFoto(+b.getAttribute('data-foto')); }; });
      } catch (e) { l.innerHTML = '<div class="text-sm font-bold text-red-600">' + esc(e.message) + '</div>'; }
    };
    el('pr-buscar').onclick = buscar; buscar();
  }
  function cartaoDia(d) {
    var linhas = d.batidas.map(function (b) {
      var cor = b.status === 'pendente' ? 'bg-amber-100 text-amber-700' : b.status === 'recusada' ? 'bg-red-100 text-red-700' : b.status === 'aprovada' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600';
      var gps = b.lat != null ? '<a target="_blank" rel="noopener" class="text-blue-600 underline" href="https://www.google.com/maps?q=' + b.lat + ',' + b.lng + '">📍 mapa</a> <span class="text-slate-400">±' + Math.round(b.precisao || 0) + 'm</span>' : '';
      var foto = b.tem_foto ? '<button data-foto="' + b.id + '" class="text-blue-600 underline">📷 foto</button>' : '';
      var just = b.justificativa ? '<div class="text-[11px] text-slate-500 pl-1">“' + esc(b.justificativa) + '”' + (b.motivo_decisao ? ' — gestor: ' + esc(b.motivo_decisao) : '') + '</div>' : '';
      return '<div class="py-1"><div class="flex flex-wrap gap-x-3 gap-y-1 items-center text-xs"><span class="w-44 font-bold text-slate-600">' + ROT[b.tipo] + '</span><span class="font-black tabular-nums">' + hhmm(b.momento) + '</span>' +
        '<span class="px-1.5 py-0.5 rounded text-[10px] font-black uppercase ' + cor + '">' + b.origem + ' · ' + b.status + '</span>' + foto + gps +
        (b.distancia != null ? '<span class="text-slate-400">similaridade ' + Number(b.distancia).toFixed(2) + '</span>' : '') + '</div>' + just + '</div>';
    }).join('');
    return '<div class="border rounded-lg p-3 ' + (d.alerta ? 'border-red-300 bg-red-50/40' : '') + '"><div class="flex justify-between flex-wrap gap-1 mb-1"><div class="text-sm font-black uppercase">' + esc(d.nome) +
      ' <span class="text-slate-400 font-bold normal-case">· ' + DIAS[d.dow] + ' ' + dataBR(d.data) + '</span></div><div class="text-xs font-bold text-slate-500">' +
      (d.trabalhado_min != null ? 'Trabalhado ' + fmtMin(d.trabalhado_min) + (d.saldo_min ? ' · saldo ' + (d.saldo_min > 0 ? '+' : '−') + fmtMin(d.saldo_min) : '') : '') + '</div></div>' +
      (d.alerta ? '<div class="text-xs font-bold text-red-600 mb-1">⚠ ' + esc(d.alerta) + '</div>' : '') + linhas + '</div>';
  }
  async function verFoto(id) {
    var j = await api('ponto_admin_foto&id=' + id);
    if (!j.sucesso) return toast(j.erro, 'error');
    var f = el('ponto-foto');
    if (!f) {
      f = doc.createElement('div'); f.id = 'ponto-foto';
      f.className = 'hidden fixed inset-0 bg-black/80 z-[430] flex items-center justify-center p-4';
      f.innerHTML = '<img id="ponto-foto-img" class="max-h-[85vh] rounded-lg shadow-2xl" alt="Foto da batida">';
      f.onclick = function () { f.classList.add('hidden'); };
      doc.body.appendChild(f);
    }
    el('ponto-foto-img').src = j.foto; f.classList.remove('hidden');
  }

  /* ------------------------------ colaboradores ------------------------------ */
  async function abaColaboradores(c) {
    var j = await api('ponto_admin_colaboradores');
    if (!j.sucesso) throw new Error(j.erro);
    var link = root.location.origin + '/ponto.html';
    c.innerHTML = '<div class="mb-3 p-3 rounded bg-blue-50 border border-blue-200 text-xs text-slate-700">Link para os colaboradores baterem o ponto (abrir no celular): ' +
      '<b class="select-all">' + esc(link) + '</b> <button id="pc-copiar" class="ml-2 px-2 py-0.5 rounded bg-blue-600 text-white font-bold">Copiar</button>' +
      '<div class="mt-1 text-slate-500">Defina um PIN para cada pessoa; no primeiro acesso ela troca o PIN e cadastra o rosto.</div></div>' +
      '<div id="pc-pin" class="hidden mb-3 p-3 rounded bg-emerald-50 border border-emerald-300 text-sm font-bold text-emerald-800"></div>' +
      '<div class="overflow-x-auto"><table class="w-full text-xs"><thead class="bg-slate-50 text-[10px] uppercase text-slate-500"><tr><th class="text-left px-2 py-2">Colaborador</th><th class="text-left px-2 py-2">Setor</th><th class="px-2 py-2">PIN</th><th class="px-2 py-2">Rosto</th><th class="px-2 py-2">Situação</th><th></th></tr></thead><tbody class="divide-y">' +
      j.itens.map(function (i) {
        return '<tr><td class="px-2 py-2 font-bold uppercase">' + esc(i.nome) + '</td><td class="px-2 py-2 text-slate-500">' + esc(i.setor || '') + '</td>' +
          '<td class="px-2 py-2 text-center">' + (i.tem_pin ? (i.pin_provisorio ? '<span class="text-amber-600 font-bold">provisório</span>' : '✔') : '—') + '</td>' +
          '<td class="px-2 py-2 text-center">' + (i.cadastrado ? '✔' : '—') + '</td><td class="px-2 py-2 text-center">' + (i.tem_pin ? (i.ativo ? 'Ativo' : '<span class="text-red-600 font-bold">Desativado</span>') : '—') + '</td>' +
          '<td class="px-2 py-2 text-right whitespace-nowrap"><button data-a="pin" data-n="' + esc(i.nome) + '" class="px-2 py-1 rounded bg-amber-500 text-white font-bold mr-1">' + (i.tem_pin ? 'Novo PIN' : 'Criar PIN') + '</button>' +
          (i.cadastrado ? '<button data-a="rosto" data-n="' + esc(i.nome) + '" class="px-2 py-1 rounded bg-slate-600 text-white font-bold mr-1">Resetar rosto</button>' : '') +
          (i.tem_pin ? '<button data-a="ativo" data-v="' + (i.ativo ? 0 : 1) + '" data-n="' + esc(i.nome) + '" class="px-2 py-1 rounded border font-bold">' + (i.ativo ? 'Desativar' : 'Ativar') + '</button>' : '') + '</td></tr>';
      }).join('') + '</tbody></table></div>';
    el('pc-copiar').onclick = function () { if (navigator.clipboard) navigator.clipboard.writeText(link); toast('Link copiado.'); };
    Array.prototype.forEach.call(c.querySelectorAll('[data-a]'), function (b) {
      b.onclick = async function () {
        var a = b.getAttribute('data-a'), n = b.getAttribute('data-n'), r;
        if (a === 'pin') {
          r = await api('ponto_admin_definir_pin', { nome: n });
          if (!r.sucesso) return toast(r.erro, 'error');
          var box = el('pc-pin'); box.classList.remove('hidden');
          box.innerHTML = 'PIN provisório de ' + esc(n) + ': <span class="text-xl tracking-widest select-all">' + esc(r.pin) + '</span> — anote e entregue agora; ele não será mostrado novamente.';
          box.scrollIntoView({ block: 'nearest' }); abrirAbaSemLimpar();
          return;
        }
        if (a === 'rosto') { if (!confirm('Resetar o cadastro facial de ' + n + '? Ela(e) precisará cadastrar o rosto de novo.')) return; r = await api('ponto_admin_resetar_rosto', { nome: n }); }
        else r = await api('ponto_admin_ativar', { nome: n, ativo: b.getAttribute('data-v') === '1' });
        if (!r.sucesso) return toast(r.erro, 'error');
        toast('Atualizado.'); abrirAba();
      };
    });
  }
  async function abrirAbaSemLimpar() { // atualiza só a tabela mantendo a caixa com o PIN visível
    var caixa = el('pc-pin') && el('pc-pin').innerHTML, j = await api('ponto_admin_colaboradores');
    if (!j.sucesso) return;
    await abaColaboradores(el('ponto-corpo'));
    if (caixa) { var b = el('pc-pin'); b.innerHTML = caixa; b.classList.remove('hidden'); }
  }

  /* ------------------------------ regra de horário ------------------------------ */
  async function abaRegra(c) {
    var j = await api('ponto_admin_regra');
    if (!j.sucesso) throw new Error(j.erro);
    var r = j.regra, dias = r.dias;
    c.innerHTML = '<p class="text-xs text-slate-500 mb-3">Define a jornada esperada de cada dia da semana. Ela gera os alertas de batida faltando e as sugestões de horário nas batidas manuais. Dias desmarcados não têm expediente.</p>' +
      '<div class="overflow-x-auto"><table class="text-xs"><thead class="bg-slate-50 text-[10px] uppercase text-slate-500"><tr><th class="px-2 py-2 text-left">Dia</th><th class="px-2 py-2">Trabalha</th><th class="px-2 py-2">Início</th><th class="px-2 py-2">Saída almoço</th><th class="px-2 py-2">Retorno almoço</th><th class="px-2 py-2">Fim</th></tr></thead><tbody class="divide-y">' +
      ORDEM_DIAS.map(function (i) {
        var d = dias[i] || dias[String(i)] || null, v = d || { ini: '08:00', alm_ini: '12:00', alm_fim: '13:00', fim: '18:00' };
        return '<tr data-dia="' + i + '"><td class="px-2 py-2 font-bold">' + DIAS[i] + '</td><td class="px-2 py-2 text-center"><input type="checkbox" class="rg-on" ' + (d ? 'checked' : '') + '></td>' +
          ['ini', 'alm_ini', 'alm_fim', 'fim'].map(function (k) { return '<td class="px-2 py-1"><input type="time" class="rg-' + k + ' border rounded px-2 py-1" value="' + v[k] + '"></td>'; }).join('') + '</tr>';
      }).join('') + '</tbody></table></div>' +
      '<div class="mt-3 flex items-end gap-3"><label class="text-[10px] font-bold text-slate-500 uppercase">Tolerância (min)<input id="rg-tol" type="number" min="0" max="60" value="' + (r.tolerancia || 0) + '" class="mt-1 border rounded px-2 py-1.5 text-xs w-24 block"></label>' +
      '<button id="rg-salvar" class="px-4 py-2 rounded bg-blue-600 text-white text-xs font-bold">Salvar regra</button></div>' +
      '<p class="mt-2 text-[11px] text-slate-400">A tolerância define quando o sistema passa a alertar uma batida do dia como atrasada.</p>';
    el('rg-salvar').onclick = async function () {
      var out = { tolerancia: +el('rg-tol').value, dias: {} };
      Array.prototype.forEach.call(c.querySelectorAll('tr[data-dia]'), function (tr) {
        var i = tr.getAttribute('data-dia'), q = function (k) { return tr.querySelector('.rg-' + k).value; };
        out.dias[i] = tr.querySelector('.rg-on').checked ? { ini: q('ini'), alm_ini: q('alm_ini'), alm_fim: q('alm_fim'), fim: q('fim') } : null;
      });
      var s = await api('ponto_admin_regra', out);
      if (!s.sucesso) return toast(s.erro, 'error');
      toast('Regra de horário salva.');
    };
  }

  /* ------------------------------ botão no cabeçalho ------------------------------ */
  function atualizarBadge() {
    var b = el('btn-ponto'); if (!b) return;
    var s = b.querySelector('.pt-badge');
    if (!s) { s = doc.createElement('span'); s.className = 'pt-badge absolute -top-1 -right-1 min-w-[14px] h-[14px] px-1 rounded-full bg-red-500 text-white text-[9px] font-black flex items-center justify-center'; b.appendChild(s); }
    s.textContent = contPend; s.style.display = contPend ? 'flex' : 'none';
  }
  async function checarPendencias() {
    try { var j = await api('ponto_admin_pendencias'); if (j.sucesso) { contPend = j.itens.length; atualizarBadge(); } } catch (e) {}
  }
  function injetarBotao() {
    if (!ehGestor() || el('btn-ponto')) return !!el('btn-ponto');
    var ref = el('btn-gestao');
    if (!ref) return false;
    var b = doc.createElement('button');
    b.id = 'btn-ponto'; b.type = 'button'; b.title = 'Ponto eletrônico (aprovações, registros, regra de horário)';
    b.className = 'relative p-1.5 bg-teal-600 hover:bg-teal-500 text-white rounded transition-colors shadow-sm text-sm leading-none w-8 h-8 flex items-center justify-center';
    b.textContent = '⏱'; b.onclick = abrirPonto;
    ref.parentNode.insertBefore(b, ref.nextSibling);
    return true;
  }
  root.abrirPonto = abrirPonto;

  function iniciar() {
    if (!ehGestor()) return;
    var n = 0, t = setInterval(function () { if (injetarBotao() || ++n > 40) { clearInterval(t); checarPendencias(); setInterval(checarPendencias, 300000); } }, 250);
  }
  iniciar();
})(typeof window !== 'undefined' ? window : globalThis);
