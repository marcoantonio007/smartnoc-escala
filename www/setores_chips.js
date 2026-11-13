/* SETORES_CHIPS_V1 — "Setores adicionais" no cadastro do colaborador, no mesmo estilo dos grupos de WhatsApp:
   digita, aparecem os setores já cadastrados, clica para adicionar; cada setor vira uma etiqueta com lixeira.
   O valor continua sendo gravado no campo oculto #func-setores-extras (lista separada por vírgula). */
(function (root) {
  'use strict';
  var campo, busca, lista, chips;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function norm(s) { return String(s || '').trim().toUpperCase(); }
  function principal() { var e = document.getElementById('func-setor'); return norm(e && e.value); }
  function atuais() {
    var p = principal(), out = [];
    String(campo.value || '').split(',').forEach(function (x) { x = norm(x); if (x && x !== p && out.indexOf(x) < 0) out.push(x); });
    return out;
  }
  function gravar(arr) { campo.value = arr.join(', '); desenhar(); }
  function todosSetores() {
    var base = (typeof tecnicosDB !== 'undefined' && tecnicosDB) || [], s = {};
    base.forEach(function (f) {
      if (f && f.setor) s[norm(f.setor)] = 1;
      String((f && f.setores_extras) || '').split(',').forEach(function (x) { x = norm(x); if (x) s[x] = 1; });
    });
    return Object.keys(s).sort();
  }

  function desenhar() {
    var sel = atuais();
    chips.innerHTML = sel.length ? sel.map(function (s) {
      return '<div class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-md text-[10px] font-bold bg-indigo-100 text-indigo-800 border border-indigo-200 shadow-sm">' +
        '<i data-lucide="layers" class="w-3.5 h-3.5"></i><span>' + esc(s) + '</span>' +
        '<button type="button" data-rm="' + esc(s) + '" class="hover:bg-indigo-200 p-0.5 rounded-full text-red-500 hover:text-red-700 transition-colors ml-1" title="Remover setor"><i data-lucide="trash-2" class="w-3 h-3"></i></button></div>';
    }).join('') : '<span class="text-xs text-slate-400 italic pt-1">Nenhum setor adicional.</span>';
    Array.prototype.forEach.call(chips.querySelectorAll('[data-rm]'), function (b) {
      b.onclick = function () { var r = b.getAttribute('data-rm'); gravar(atuais().filter(function (x) { return x !== r; })); };
    });
    if (typeof root.lucide !== 'undefined') root.lucide.createIcons();
  }

  function adicionar(s) {
    s = norm(s); if (!s) return;
    if (s === principal()) { if (root.showToast) root.showToast('Esse já é o setor principal.', 'error'); return; }
    var a = atuais(); if (a.indexOf(s) < 0) a.push(s);
    busca.value = ''; gravar(a); abrirLista();
  }

  function abrirLista() {
    var t = norm(busca.value), sel = atuais(), p = principal();
    var opc = todosSetores().filter(function (s) { return s !== p && sel.indexOf(s) < 0 && (!t || s.indexOf(t) >= 0); });
    var html = opc.map(function (s) {
      return '<div data-add="' + esc(s) + '" class="p-2.5 text-xs cursor-pointer hover:bg-indigo-50 border-b border-slate-100 last:border-0 font-bold text-slate-700 flex items-center gap-2 transition-colors"><i data-lucide="plus-circle" class="w-3.5 h-3.5 text-indigo-600"></i> ' + esc(s) + '</div>';
    }).join('');
    if (t && t !== p && sel.indexOf(t) < 0 && opc.indexOf(t) < 0)
      html += '<div data-add="' + esc(t) + '" class="p-2.5 text-xs cursor-pointer hover:bg-indigo-50 font-bold text-indigo-700 flex items-center gap-2"><i data-lucide="plus" class="w-3.5 h-3.5"></i> Adicionar novo setor "' + esc(t) + '"</div>';
    if (!html) html = '<div class="p-2.5 text-xs text-slate-400 text-center">Nenhum setor disponível</div>';
    lista.innerHTML = html; lista.classList.remove('hidden');
    Array.prototype.forEach.call(lista.querySelectorAll('[data-add]'), function (d) {
      d.onmousedown = function (e) { e.preventDefault(); adicionar(d.getAttribute('data-add')); };  // antes do blur fechar a lista
    });
    if (typeof root.lucide !== 'undefined') root.lucide.createIcons();
  }

  function montar() {
    campo = document.getElementById('func-setores-extras');
    if (!campo || campo.getAttribute('data-chips')) return;
    campo.setAttribute('data-chips', '1'); campo.type = 'hidden';
    var lbl = campo.previousElementSibling; if (lbl && lbl.tagName === 'LABEL') lbl.textContent = 'Setores adicionais (também atende)';
    var box = document.createElement('div'); box.className = 'relative';
    box.innerHTML =
      '<div class="relative"><div class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none"><i data-lucide="search" class="w-4 h-4 text-slate-400"></i></div>' +
      '<input type="text" id="setx-busca" autocomplete="off" placeholder="Pesquisar e adicionar setor..." class="w-full border border-slate-300 rounded-md pl-8 pr-3 py-1.5 text-sm bg-slate-50 uppercase focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all shadow-sm"></div>' +
      '<div id="setx-lista" class="hidden absolute z-[400] w-full bg-white border border-slate-200 rounded-md shadow-xl max-h-40 overflow-y-auto custom-scrollbar mt-1"></div>' +
      '<div id="setx-chips" class="flex flex-wrap gap-2 mt-2"></div>';
    campo.parentNode.insertBefore(box, campo.nextSibling);
    busca = box.querySelector('#setx-busca'); lista = box.querySelector('#setx-lista'); chips = box.querySelector('#setx-chips');
    busca.onfocus = abrirLista; busca.oninput = abrirLista;
    busca.onblur = function () { setTimeout(function () { lista.classList.add('hidden'); }, 150); };
    busca.onkeydown = function (e) {
      if (e.key === 'Enter') { e.preventDefault(); var t = norm(busca.value); if (!t) return; var p = lista.querySelector('[data-add]'); adicionar(todosSetores().indexOf(t) >= 0 || !p ? t : p.getAttribute('data-add')); } // Enter: o setor digitado, ou a 1ª sugestão da lista
      else if (e.key === 'Escape') lista.classList.add('hidden');
    };
    var sp = document.getElementById('func-setor'); if (sp) sp.addEventListener('input', function () { gravar(atuais()); });
    desenhar();
  }

  ['abrirFormFuncionario', 'editarFuncionario'].forEach(function (fn) {   // recarrega as etiquetas sempre que o formulário abre
    var orig = root[fn]; if (typeof orig !== 'function') return;
    root[fn] = function () { var r = orig.apply(this, arguments); try { montar(); if (busca) busca.value = ''; if (lista) lista.classList.add('hidden'); if (campo) desenhar(); } catch (e) {} return r; };
  });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', montar); else montar();
})(window);
