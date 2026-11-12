/* colab_acesso.js — COLAB_ACESSO_V1
   Colaboradores em ordem alfabética (Nome Resumido) com filtro por nome resumido/nome completo (sugestões ao digitar),
   coluna Login, e o acesso ao sistema (login, perfil, setores) gerenciado dentro do cadastro do colaborador.
   As contas que não pertencem a um colaborador continuam numa lista própria, na mesma aba Colaboradores. */
(function (root) {
  'use strict';
  var doc = root.document;
  function el(id) { return doc.getElementById(id); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function norm(s) { return String(s || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toUpperCase().trim(); }
  function funcs() { return (typeof listaFuncionariosCache !== 'undefined' && Array.isArray(listaFuncionariosCache)) ? listaFuncionariosCache : []; }
  function users() { return (typeof listaUsuariosCache !== 'undefined' && Array.isArray(listaUsuariosCache)) ? listaUsuariosCache : []; }
  // mesma regra do Ponto: o nome completo do login = nome completo OU nome resumido do colaborador
  function userDoColab(nome, nomeComp) {
    var a = norm(nomeComp), b = norm(nome), l = users();
    return (a && l.find(function (u) { return norm(u.nome_completo) === a; })) || (b && l.find(function (u) { return norm(u.nome_completo) === b; })) || null;
  }
  function colabDoUser(u) {
    var n = norm(u.nome_completo); if (!n) return null;
    return funcs().find(function (f) { return norm(f.nome_completo) === n || norm(f.nome) === n; }) || null;
  }
  var COR = { mestre: 'bg-purple-100 text-purple-700', admin: 'bg-red-100 text-red-700' };
  var ROT = { mestre: 'Mestre', admin: 'Admin', usuario: 'Usuário' };
  function badge(p) { return '<span class="px-1.5 py-0.5 rounded text-[9px] font-bold uppercase ' + (COR[p] || 'bg-blue-100 text-blue-700') + '">' + esc(ROT[p] || p) + '</span>'; }

  /* ---------- lista de colaboradores: ordem alfabética + filtro com sugestões ---------- */
  var filtro = '';
  function casa(f, t) { if (!t) return true; t = norm(t); return norm(f.nome).indexOf(t) >= 0 || norm(f.nome_completo).indexOf(t) >= 0; }
  function ordenados() { return funcs().slice().sort(function (a, b) { return String(a.nome || '').localeCompare(String(b.nome || ''), 'pt-BR', { sensitivity: 'base' }); }); }
  function prepararCard() {
    var tb = el('lista-funcionarios-body'); if (!tb) return null;
    var card = tb.closest('.bg-slate-50'); if (!card) return null;
    if (!el('func-filtro')) {
      var box = doc.createElement('div'); box.className = 'relative mb-2';
      box.innerHTML = '<input id="func-filtro" type="search" autocomplete="off" placeholder="Filtrar por nome resumido ou nome completo…" class="w-full border rounded px-2 py-1.5 text-xs bg-white">' +
        '<div id="func-filtro-sug" class="hidden absolute z-50 w-full bg-white border border-slate-200 shadow-xl mt-1 rounded max-h-56 overflow-y-auto divide-y divide-slate-100"></div>';
      var cab = card.firstElementChild; cab.parentNode.insertBefore(box, cab.nextSibling);
      var inp = el('func-filtro'), sug = el('func-filtro-sug');
      inp.addEventListener('input', function () { filtro = inp.value; desenharLista(); sugerir(); });
      inp.addEventListener('focus', sugerir);
      inp.addEventListener('keydown', function (e) { if (e.key === 'Escape') { sug.classList.add('hidden'); } if (e.key === 'Enter') { e.preventDefault(); sug.classList.add('hidden'); } });
      doc.addEventListener('click', function (e) { if (!box.contains(e.target)) sug.classList.add('hidden'); });
      sug.addEventListener('mousedown', function (e) {
        var b = e.target.closest('[data-nome]'); if (!b) return; e.preventDefault();
        inp.value = b.getAttribute('data-nome'); filtro = inp.value; sug.classList.add('hidden'); desenharLista();
      });
      var th = card.querySelector('thead tr'); // coluna Login depois de Nome Completo
      if (th && th.children.length >= 2 && !th.querySelector('[data-col-login]')) {
        var c = doc.createElement('th'); c.className = 'p-2 border-b'; c.setAttribute('data-col-login', '1'); c.textContent = 'Login';
        th.insertBefore(c, th.children[2]);
      }
      card.classList.remove('h-[380px]'); card.style.height = '480px';
    }
    return card;
  }
  function sugerir() {
    var inp = el('func-filtro'), sug = el('func-filtro-sug'); if (!inp || !sug) return;
    var t = inp.value.trim();
    if (!t) { sug.classList.add('hidden'); return; }
    var l = ordenados().filter(function (f) { return casa(f, t); }).slice(0, 10);
    if (!l.length) { sug.innerHTML = '<div class="px-3 py-2 text-xs text-slate-400 italic">Nenhum colaborador encontrado</div>'; sug.classList.remove('hidden'); return; }
    sug.innerHTML = l.map(function (f) {
      return '<div data-nome="' + esc(f.nome) + '" class="px-3 py-1.5 cursor-pointer hover:bg-blue-50"><div class="text-xs font-bold text-slate-700">' + esc(f.nome) + '</div>' +
        (f.nome_completo ? '<div class="text-[10px] text-slate-500">' + esc(f.nome_completo) + '</div>' : '') + '</div>';
    }).join('');
    sug.classList.remove('hidden');
  }
  function desenharLista() {
    var tb = el('lista-funcionarios-body'); if (!tb) return;
    prepararCard();
    var l = ordenados().filter(function (f) { return casa(f, filtro); });
    tb.innerHTML = l.length ? l.map(function (f) {
      var u = userDoColab(f.nome, f.nome_completo), j = esc(JSON.stringify(f));
      return '<tr class="hover:bg-slate-50"><td class="p-2 font-bold truncate">' + esc(f.nome) + '</td><td class="p-2 text-[10px] truncate max-w-[150px]">' + esc(f.nome_completo || '-') + '</td>' +
        '<td class="p-2 text-[10px] whitespace-nowrap">' + (u ? '<span class="font-mono">' + esc(u.usuario) + '</span> ' + badge(u.perfil) : '<span class="text-slate-400">sem acesso</span>') + '</td>' +
        '<td class="p-2 text-[10px] text-slate-500 truncate max-w-[150px]">' + esc(f.local_base || '') + ' - ' + esc(f.localidade || '') + ' (' + esc(f.setor || '') + ')</td>' +
        '<td class="p-2 text-[10px] text-slate-600 truncate">' + esc(f.supervisor || '') + '</td>' +
        '<td class="p-2 text-center flex justify-center gap-1"><button data-func="' + j + '" class="cx-edit p-1 bg-slate-200 hover:bg-slate-300 rounded text-blue-600"><i data-lucide="edit-2" class="w-3.5 h-3.5"></i></button>' +
        '<button onclick="excluirFuncionario(' + (parseInt(f.id, 10) || 0) + ')" class="p-1 bg-slate-200 hover:bg-slate-300 rounded text-red-600"><i data-lucide="trash" class="w-3.5 h-3.5"></i></button></td></tr>';
    }).join('') : '<tr><td colspan="6" class="p-4 text-center text-slate-400 italic">Nenhum colaborador com esse filtro.</td></tr>';
    Array.prototype.forEach.call(tb.querySelectorAll('.cx-edit'), function (b) { b.onclick = function () { root.editarFuncionario(JSON.parse(b.getAttribute('data-func'))); }; });
    if (root.lucide && root.lucide.createIcons) try { root.lucide.createIcons(); } catch (e) {}
  }
  root.renderListaFuncionarios = desenharLista;

  /* ---------- contas de acesso: ficam na aba Colaboradores, só as que não são de um colaborador ---------- */
  function desenharContasAvulsas() {
    var tb = el('lista-usuarios-body'); if (!tb) return;
    var card = tb.closest('.bg-slate-50');
    if (card && !card.getAttribute('data-cx')) {
      card.setAttribute('data-cx', '1'); card.setAttribute('data-cfgtab', 'colab');
      var h = card.querySelector('h3'); if (h) h.innerHTML = '<i data-lucide="key" class="inline w-4 h-4 mr-1"></i> Contas de acesso sem colaborador vinculado';
      var fc = el('lista-funcionarios-body'), fcard = fc && fc.closest('.bg-slate-50');
      if (fcard && fcard.parentNode === card.parentNode && card.compareDocumentPosition(fcard) & Node.DOCUMENT_POSITION_FOLLOWING) fcard.parentNode.insertBefore(card, fcard.nextSibling);
      card.classList.remove('h-[340px]'); card.style.height = '260px';
    }
    var l = users().filter(function (u) { return !colabDoUser(u); });
    tb.innerHTML = l.length ? l.map(function (u) {
      return '<tr class="hover:bg-slate-50"><td class="p-2 font-bold truncate">' + esc(u.nome_completo || '') + '</td><td class="p-2 truncate font-mono">' + esc(u.usuario) + '</td><td class="p-2">' + badge(u.perfil) + '</td>' +
        '<td class="p-2 text-[10px] text-slate-500 truncate max-w-[80px]" title="' + esc(u.setores_permitidos || 'Todos') + '">' + esc(u.setores_permitidos || 'Todos') + '</td>' +
        '<td class="p-2 text-[10px] text-slate-500 truncate max-w-[80px]" title="' + esc(u.setores_gerenciaveis || 'Todos') + '">' + esc(u.setores_gerenciaveis || 'Todos') + '</td>' +
        '<td class="p-2 text-center flex justify-center gap-1"><button data-user="' + esc(JSON.stringify(u)) + '" class="cx-uedit p-1 bg-slate-200 hover:bg-slate-300 rounded text-blue-600"><i data-lucide="edit-2" class="w-3.5 h-3.5"></i></button>' +
        '<button onclick="excluirUsuario(' + (parseInt(u.id, 10) || 0) + ')" class="p-1 bg-slate-200 hover:bg-slate-300 rounded text-red-600"><i data-lucide="trash" class="w-3.5 h-3.5"></i></button></td></tr>';
    }).join('') : '<tr><td colspan="6" class="p-4 text-center text-slate-400 italic">Todas as contas pertencem a um colaborador (veja a coluna Login acima).</td></tr>';
    Array.prototype.forEach.call(tb.querySelectorAll('.cx-uedit'), function (b) { b.onclick = function () { abrirUser(JSON.parse(b.getAttribute('data-user'))); }; });
    if (root.lucide && root.lucide.createIcons) try { root.lucide.createIcons(); } catch (e) {}
  }
  root.renderListaUsuarios = function () { desenharContasAvulsas(); desenharLista(); blocoAcesso(); };

  /* ---------- bloco "Acesso ao sistema" dentro do cadastro do colaborador ---------- */
  function abrirUser(u) {
    var m = el('user-form-modal'); if (m) m.style.zIndex = '330'; // por cima do cadastro do colaborador
    if (u) root.editarUsuario(u); else root.abrirFormUsuario();
  }
  function sugestaoLogin(nomeComp) {
    var p = norm(nomeComp).toLowerCase().replace(/[^a-z\s]/g, '').split(/\s+/).filter(function (x) { return x && ['da', 'de', 'do', 'das', 'dos', 'e'].indexOf(x) < 0; });
    return p.length ? (p.length > 1 ? p[0] + '.' + p[p.length - 1] : p[0]) : '';
  }
  function blocoAcesso() {
    var mail = el('func-email'), nomeI = el('func-nome'), compI = el('func-nomecomp'); if (!nomeI) return;
    var bx = el('func-acesso');
    if (!bx) {
      var ref = (mail && mail.parentNode) || (el('func-cpf') && el('func-cpf').parentNode); if (!ref) return;
      bx = doc.createElement('div'); bx.id = 'func-acesso'; bx.className = 'col-span-2 border rounded-lg p-3 bg-slate-50';
      ref.parentNode.insertBefore(bx, ref.nextSibling);
      nomeI.addEventListener('input', blocoAcesso); if (compI) compI.addEventListener('input', blocoAcesso);
      var box = el('func-form-modal') && el('func-form-modal').firstElementChild;
      if (box) { box.style.maxHeight = '95vh'; box.style.overflowY = 'auto'; }
    }
    var nome = nomeI.value.trim(), comp = compI ? compI.value.trim() : '', u = userDoColab(nome, comp);
    var tit = '<div class="text-[10px] font-bold uppercase text-slate-600 mb-1 flex items-center gap-1">🔑 Acesso ao sistema (Escala e Ponto)</div>';
    if (u) {
      bx.innerHTML = tit + '<div class="text-xs text-slate-700">Login <b class="font-mono">' + esc(u.usuario) + '</b> ' + badge(u.perfil) + '</div>' +
        '<div class="text-[10px] text-slate-500 mt-0.5">Visualiza: ' + esc(u.setores_permitidos || 'Todos') + ' · Gerencia: ' + esc(u.setores_gerenciaveis || 'Todos') + '</div>' +
        '<div class="flex gap-2 mt-2"><button type="button" id="cx-acc-edit" class="px-2 py-1 rounded bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold">Editar acesso / senha</button>' +
        '<button type="button" id="cx-acc-del" class="px-2 py-1 rounded bg-red-50 hover:bg-red-100 text-red-600 text-xs font-bold">Remover acesso</button></div>';
      el('cx-acc-edit').onclick = function () { abrirUser(u); };
      el('cx-acc-del').onclick = function () { root.excluirUsuario(u.id); };
    } else {
      bx.innerHTML = tit + '<div class="text-xs text-slate-500">Sem acesso. Crie um login para o colaborador entrar na Escala e bater o ponto.</div>' +
        '<button type="button" id="cx-acc-new" class="mt-2 px-2 py-1 rounded bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold"' + (nome || comp ? '' : ' disabled') + '>+ Criar acesso</button>';
      el('cx-acc-new').onclick = function () {
        abrirUser(null);
        el('user-nome').value = comp || nome; el('user-login').value = sugestaoLogin(comp || nome);
        el('user-form-title').innerText = 'Novo acesso — ' + (nome || comp);
      };
    }
  }
  ['abrirFormFuncionario', 'editarFuncionario'].forEach(function (fn) {
    var orig = root[fn]; if (typeof orig !== 'function') return;
    root[fn] = function () { var r = orig.apply(this, arguments); blocoAcesso(); return r; };
  });

  function iniciar() { prepararCard(); desenharLista(); desenharContasAvulsas(); }
  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', iniciar); else iniciar();
})(window);
