/* relatorios.js — RELATORIOS_V1: botão do Formulário VR Extra na Escala e aba "Relatórios" (envio automático por e-mail) nas Configurações */
(function (root) {
  'use strict';
  var doc = root.document;
  function el(id) { return doc.getElementById(id); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function toast(m, t) { if (typeof root.showToast === 'function') root.showToast(m, t || 'success'); else alert(m); }
  async function post(action, body) {
    var r = await fetch('api_escala.php?action=' + action, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body || {}), credentials: 'same-origin' });
    var t = await r.text(); try { return JSON.parse(t); } catch (e) { throw new Error('Resposta inválida do servidor.'); }
  }
  function pad(n) { return String(n).padStart(2, '0'); }

  /* ---------- download (competência e colaboradores visíveis na tela, igual ao Relatório de Horas) ---------- */
  function competenciaTela() {
    var d = (typeof currentMonthDate !== 'undefined') ? currentMonthDate : new Date();
    var a = d.getFullYear(), m = d.getMonth(), fa = a, fm = m + 1; if (fm > 11) { fm = 0; fa++; }
    return { ini: a + '-' + pad(m + 1) + '-15', fim: fa + '-' + pad(fm + 1) + '-14' };
  }
  function nomesVisiveis() {
    try {
      var vis = getOrderedAndFilteredTecnicos(), base = tecnicosDB.filter(function (f) { return vis.indexOf(f.nome) >= 0; });
      if (typeof currentAccess !== 'undefined' && currentAccess !== 'mestre' && typeof setoresPermitidosLogin !== 'undefined' && setoresPermitidosLogin) {
        var s = setoresPermitidosLogin.split(',').map(function (x) { return x.trim().toUpperCase(); });
        base = base.filter(function (f) { return s.indexOf(String(f.setor || '').toUpperCase()) >= 0; });
      }
      return base.map(function (f) { return f.nome; });
    } catch (e) { return null; }
  }
  root.baixarRelatorioServidor = async function (tipo, btn, formato) { // RELATORIOS_WORD_V1
    var c = competenciaTela(), body = { tipo: tipo, ini: c.ini, fim: c.fim, formato: formato || 'pdf' }, n = nomesVisiveis();
    if (n) body.nomes = n;
    if (btn) btn.disabled = true;
    try {
      var r = await fetch('api_escala.php?action=rel_pdf', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body), credentials: 'same-origin' });
      if (!/pdf|wordprocessingml/.test(r.headers.get('Content-Type') || '')) { var j = {}; try { j = await r.json(); } catch (e) {} throw new Error(j.erro || 'Não foi possível gerar o arquivo.'); }
      var nome = ((r.headers.get('Content-Disposition') || '').match(/filename="([^"]+)"/) || [])[1] || 'relatorio.pdf';
      var u = URL.createObjectURL(await r.blob()), a = doc.createElement('a');
      a.href = u; a.download = nome; doc.body.appendChild(a); a.click(); a.remove(); setTimeout(function () { URL.revokeObjectURL(u); }, 5000);
    } catch (e) { toast(e.message, 'error'); } finally { if (btn) btn.disabled = false; }
  };
  root.baixarVRExtra = function (btn) { return root.baixarRelatorioServidor('vr', btn); };
  // Menu dos ícones: escolher PDF ou Word. O PDF do Relatório de Horas continua sendo o gerado na própria tela.
  root.menuRelatorio = function (ev, tipo) {
    ev.stopPropagation();
    var btn = ev.currentTarget, m = el('rel-menu');
    if (m) { var mesmo = m.getAttribute('data-tipo') === tipo; m.remove(); if (mesmo) return; }
    m = doc.createElement('div'); m.id = 'rel-menu'; m.setAttribute('data-tipo', tipo);
    m.className = 'fixed z-[500] bg-white border border-slate-200 rounded-lg shadow-xl py-1 text-xs font-bold text-slate-700';
    m.style.minWidth = '200px';
    var r = btn.getBoundingClientRect(); m.style.top = (r.bottom + 6) + 'px'; m.style.left = Math.max(8, Math.min(r.left, root.innerWidth - 210)) + 'px';
    m.innerHTML = '<div class="px-3 py-1.5 text-[10px] uppercase text-slate-400">' + (tipo === 'vr' ? 'Formulário VR Extra' : 'Relatório de Horas') + '</div>' +
      '<button type="button" data-f="pdf" class="block w-full text-left px-3 py-2 hover:bg-slate-100">📄 Baixar em PDF</button>' +
      '<button type="button" data-f="docx" class="block w-full text-left px-3 py-2 hover:bg-slate-100">📝 Baixar em Word (.docx)</button>';
    doc.body.appendChild(m);
    m.onclick = function (e) {
      var b = e.target.closest('[data-f]'); if (!b) return; m.remove();
      var f = b.getAttribute('data-f');
      if (tipo === 'horas' && f === 'pdf' && typeof gerarRelatorioPDF === 'function') return gerarRelatorioPDF();
      root.baixarRelatorioServidor(tipo, btn, f);
    };
    setTimeout(function () {
      doc.addEventListener('click', function fechar(e) { if (!m.contains(e.target)) { m.remove(); doc.removeEventListener('click', fechar); } });
    }, 0);
  };

  /* ---------- aba Relatórios nas Configurações ---------- */
  var TIPOS = { horas: 'Relatório de Horas (sobreaviso)', vr: 'Formulário VR Extra (ticket extra)' };
  var L = 'block text-[10px] font-bold text-slate-600 uppercase mb-1', I = 'w-full border rounded px-3 py-1.5 text-xs bg-white';
  var estado = { envios: [], setores: [], editando: null };

  function garantirCard() {
    var box = doc.querySelector('#config-modal .custom-scrollbar');
    if (!box) return null;
    var card = el('rel-card');
    if (card) return card;
    card = doc.createElement('div'); card.id = 'rel-card'; card.className = 'bg-slate-50 p-4 rounded border shrink-0'; card.setAttribute('data-cfgtab', 'relatorios');
    card.style.display = 'none';
    card.innerHTML = '<h3 class="text-sm font-bold text-slate-700 mb-1 border-b pb-1">📊 Relatórios por e-mail</h3>' +
      '<p class="text-[11px] text-slate-500 mb-3">O sistema envia o PDF da <b>última competência fechada</b> (15 a 14) no dia e hora escolhidos. Ex.: dia 15 às 08:00 envia a competência que terminou no dia 14.</p>' +
      '<div id="rel-aviso"></div>' +
      '<div class="bg-white border rounded p-3 mb-3"><div class="text-[11px] font-black text-slate-700 uppercase mb-2">Dados que saem nos relatórios</div>' +
      '<div class="grid grid-cols-1 md:grid-cols-3 gap-2">' +
      '<div class="md:col-span-3"><label class="' + L + '">Empresa</label><input id="rel-empresa" class="' + I + '"></div>' +
      '<div><label class="' + L + '">Centro de custo (VR Extra)</label><input id="rel-cc" class="' + I + '" placeholder="210300406 - OI BA - POLICIA"></div>' +
      '<div><label class="' + L + '">Centro de custo (Relatório de Horas)</label><input id="rel-cch" class="' + I + '" placeholder="210300406"></div>' +
      '<div><label class="' + L + '">Responsável</label><input id="rel-resp" class="' + I + '"></div></div>' +
      '<button type="button" id="rel-cfg-salvar" class="mt-2 bg-blue-600 hover:bg-blue-700 text-white px-4 py-1.5 rounded font-bold text-xs">Salvar dados</button></div>' +
      '<div class="flex items-center justify-between mb-1"><div class="text-[11px] font-black text-slate-700 uppercase">Envios automáticos</div>' +
      '<button type="button" id="rel-novo" class="bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1.5 rounded font-bold text-xs">+ Novo envio</button></div>' +
      '<div id="rel-form" class="hidden bg-white border rounded p-3 mb-2"></div>' +
      '<div id="rel-lista" class="space-y-2"></div>';
    box.appendChild(card);
    el('rel-cfg-salvar').onclick = salvarCfg;
    el('rel-novo').onclick = function () { abrirForm(null); };
    return card;
  }
  async function carregar() {
    var card = garantirCard(); if (!card) return;
    var j; try { j = await post('rel_listar', {}); } catch (e) { return; }
    if (!j.sucesso) { card.remove(); return; } // só gestores
    estado.envios = j.envios || []; estado.setores = j.setores || [];
    el('rel-empresa').value = j.cfg.empresa || ''; el('rel-cc').value = j.cfg.centro_custo || ''; el('rel-cch').value = j.cfg.cc_horas || ''; el('rel-resp').value = j.cfg.responsavel || '';
    el('rel-aviso').innerHTML = j.smtp ? '' : '<div class="mb-3 p-2 rounded bg-amber-50 border border-amber-300 text-[11px] font-bold text-amber-800">O e-mail (SMTP) ainda não está configurado. Configure na aba ✉️ E-mail para os envios funcionarem.</div>';
    renderLista();
  }
  function renderLista() {
    var l = el('rel-lista'); if (!l) return;
    if (!estado.envios.length) { l.innerHTML = '<div class="text-xs text-slate-400 italic p-2">Nenhum envio cadastrado. Clique em "+ Novo envio".</div>'; return; }
    l.innerHTML = estado.envios.map(function (e) {
      var ativo = e.ativo === true || e.ativo === 't';
      var st = e.ultimo_status ? '<div class="text-[10px] mt-1 ' + (/^ERRO/.test(e.ultimo_status) ? 'text-red-600 font-bold' : 'text-emerald-700') + '">Último: ' + esc(e.ultimo_em || '') + ' — ' + esc(e.ultimo_status) + '</div>' : '';
      return '<div class="bg-white border rounded p-3 flex flex-wrap items-start gap-3' + (ativo ? '' : ' opacity-60') + '">' +
        '<label class="flex items-center gap-1 text-[11px] font-bold text-slate-600 cursor-pointer" title="Habilitar/desabilitar"><input type="checkbox" data-ativo="' + e.id + '"' + (ativo ? ' checked' : '') + '> Ativo</label>' +
        '<div class="flex-1 min-w-[220px]"><div class="text-xs font-black text-slate-800">' + esc(TIPOS[e.tipo] || e.tipo) + '</div>' +
        '<div class="text-[11px] text-slate-600">Todo dia <b>' + e.dia + '</b> às <b>' + esc(e.hora) + '</b>' + (e.setores ? ' · setores: <b>' + esc(e.setores) + '</b>' : ' · todos os setores') + '</div>' +
        '<div class="text-[11px] text-slate-600 break-all">Para: ' + esc(e.destinatarios) + '</div>' + st + '</div>' +
        '<div class="flex gap-1"><button type="button" data-ed="' + e.id + '" class="bg-slate-200 hover:bg-slate-300 text-slate-700 font-bold text-[11px] px-2 py-1 rounded">Editar</button>' +
        '<button type="button" data-env="' + e.id + '" class="bg-blue-100 hover:bg-blue-200 text-blue-700 font-bold text-[11px] px-2 py-1 rounded" title="Envia agora a última competência fechada">Enviar agora</button>' +
        '<button type="button" data-rm="' + e.id + '" class="bg-red-50 hover:bg-red-100 text-red-600 font-bold text-[11px] px-2 py-1 rounded">Remover</button></div></div>';
    }).join('');
    Array.prototype.forEach.call(l.querySelectorAll('[data-ativo]'), function (c) {
      c.onchange = async function () { var j = await post('rel_ativo', { id: +c.getAttribute('data-ativo'), ativo: c.checked }); if (!j.sucesso) toast(j.erro, 'error'); carregar(); };
    });
    Array.prototype.forEach.call(l.querySelectorAll('[data-ed]'), function (b) { b.onclick = function () { abrirForm(estado.envios.filter(function (e) { return String(e.id) === b.getAttribute('data-ed'); })[0]); }; });
    Array.prototype.forEach.call(l.querySelectorAll('[data-rm]'), function (b) {
      b.onclick = async function () { if (!confirm('Remover este envio automático?')) return; var j = await post('rel_remover', { id: +b.getAttribute('data-rm') }); if (!j.sucesso) toast(j.erro, 'error'); carregar(); };
    });
    Array.prototype.forEach.call(l.querySelectorAll('[data-env]'), function (b) {
      b.onclick = async function () {
        if (!confirm('Enviar agora a última competência fechada para os destinatários deste envio?')) return;
        b.disabled = true; b.textContent = 'Enviando…';
        try { var j = await post('rel_enviar_agora', { id: +b.getAttribute('data-env') }); toast(j.sucesso ? 'Relatório ' + j.comp + ' enviado.' : j.erro, j.sucesso ? 'success' : 'error'); }
        catch (e) { toast(e.message, 'error'); }
        carregar();
      };
    });
  }
  function abrirForm(e) {
    var f = el('rel-form'); estado.editando = e ? e.id : null;
    var sel = (e && e.setores ? e.setores.split(',').map(function (s) { return s.trim().toUpperCase(); }) : []);
    f.innerHTML = '<div class="text-[11px] font-black text-slate-700 uppercase mb-2">' + (e ? 'Editar envio' : 'Novo envio') + '</div>' +
      '<div class="grid grid-cols-1 md:grid-cols-4 gap-2">' +
      '<div class="md:col-span-2"><label class="' + L + '">Relatório</label><select id="rf-tipo" class="' + I + '"><option value="horas">' + TIPOS.horas + '</option><option value="vr">' + TIPOS.vr + '</option></select></div>' +
      '<div><label class="' + L + '">Dia do mês (1 a 28)</label><input id="rf-dia" type="number" min="1" max="28" class="' + I + '"></div>' +
      '<div><label class="' + L + '">Hora</label><input id="rf-hora" type="time" class="' + I + '"></div>' +
      '<div class="md:col-span-4"><label class="' + L + '">Quem recebe (e-mails separados por vírgula)</label><textarea id="rf-dest" rows="2" class="' + I + '" placeholder="gestor@empresa.com.br, rh@empresa.com.br"></textarea></div>' +
      '<div class="md:col-span-4"><label class="' + L + '">Setores (nenhum marcado = todos)</label><div class="flex flex-wrap gap-3">' +
      (estado.setores.length ? estado.setores.map(function (s) { return '<label class="flex items-center gap-1 text-[11px] font-semibold text-slate-600"><input type="checkbox" data-set="' + esc(s) + '"' + (sel.indexOf(s) >= 0 ? ' checked' : '') + '> ' + esc(s) + '</label>'; }).join('') : '<span class="text-[11px] text-slate-400">Sem setores cadastrados.</span>') +
      '</div></div></div>' +
      '<label class="mt-2 flex items-center gap-2 text-xs font-semibold text-slate-600"><input id="rf-ativo" type="checkbox"> Envio habilitado</label>' +
      '<div class="mt-2 flex gap-2"><button type="button" id="rf-salvar" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-1.5 rounded font-bold text-xs">Salvar envio</button>' +
      '<button type="button" id="rf-cancelar" class="bg-slate-200 hover:bg-slate-300 text-slate-700 px-4 py-1.5 rounded font-bold text-xs">Cancelar</button></div>';
    el('rf-tipo').value = e ? e.tipo : 'horas'; el('rf-dia').value = e ? e.dia : 15; el('rf-hora').value = e ? e.hora : '08:00';
    el('rf-dest').value = e ? e.destinatarios : ''; el('rf-ativo').checked = e ? (e.ativo === true || e.ativo === 't') : true;
    f.classList.remove('hidden');
    el('rf-cancelar').onclick = function () { f.classList.add('hidden'); };
    el('rf-salvar').onclick = async function () {
      var set = Array.prototype.map.call(f.querySelectorAll('[data-set]:checked'), function (c) { return c.getAttribute('data-set'); }).join(', ');
      var j = await post('rel_salvar', { id: estado.editando, tipo: el('rf-tipo').value, dia: +el('rf-dia').value, hora: el('rf-hora').value, destinatarios: el('rf-dest').value, setores: set, ativo: el('rf-ativo').checked });
      if (!j.sucesso) return toast(j.erro, 'error');
      toast('Envio salvo.'); f.classList.add('hidden'); carregar();
    };
  }
  async function salvarCfg() {
    var j = await post('rel_cfg', { empresa: el('rel-empresa').value, centro_custo: el('rel-cc').value, cc_horas: el('rel-cch').value, responsavel: el('rel-resp').value });
    toast(j.sucesso ? 'Dados dos relatórios salvos.' : j.erro, j.sucesso ? 'success' : 'error');
  }
  if (typeof root.abrirConfiguracoes === 'function') {
    var orig = root.abrirConfiguracoes;
    root.abrirConfiguracoes = function () { var r = orig.apply(this, arguments); setTimeout(carregar, 50); return r; };
  }
})(window);
