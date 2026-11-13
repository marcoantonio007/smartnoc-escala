/* FILTRO_SOBREAVISO_V1 — botão "Com sobreaviso" na barra de filtros da tela Mensal:
   quando ligado, mostra só os colaboradores que têm sobreaviso marcado na folha em exibição (15 a 14).
   Soma com os outros filtros (setor, base, cidade...). Fica lembrado neste navegador. */
(function (root) {
  'use strict';
  var CHAVE = 'escala_filtro_so_sobreaviso';
  var ligado = false;
  try { ligado = localStorage.getItem(CHAVE) === '1'; } catch (e) {}

  function p2(n) { return String(n).padStart(2, '0'); }
  function periodoFolha() {
    var d = (typeof currentMonthDate !== 'undefined' && currentMonthDate) ? currentMonthDate : new Date();
    var ini = new Date(d.getFullYear(), d.getMonth(), 15), fim = new Date(d.getFullYear(), d.getMonth() + 1, 14);
    function s(x) { return x.getFullYear() + '-' + p2(x.getMonth() + 1) + '-' + p2(x.getDate()); }
    return { ini: s(ini), fim: s(fim) };
  }
  function temSobreaviso(nome, per) {
    var dias = (typeof plantaoMensalDados !== 'undefined' && plantaoMensalDados && plantaoMensalDados[nome]) || {};
    return Object.keys(dias).some(function (ds) {
      if (ds < per.ini || ds > per.fim) return false;
      var v = dias[ds], st = typeof v === 'string' ? v : (v && v.status);
      return !!st && st.indexOf('plantao') === 0;
    });
  }

  function pintarBotao() {
    var b = document.getElementById('btn-filtro-sobreaviso'); if (!b) return;
    b.className = 'rounded font-bold text-[10px] px-2 py-1.5 flex items-center gap-1 shrink-0 transition-colors border ' +
      (ligado ? 'bg-emerald-600 border-emerald-700 text-white hover:bg-emerald-700' : 'bg-emerald-50 border-emerald-200 text-emerald-700 hover:bg-emerald-100');
    b.title = ligado ? 'Mostrando só quem tem sobreaviso na folha. Clique para mostrar todos.' : 'Mostrar só os colaboradores com sobreaviso marcado nesta folha';
  }
  function alternar() {
    ligado = !ligado;
    try { localStorage.setItem(CHAVE, ligado ? '1' : '0'); } catch (e) {}
    pintarBotao();
    try { renderAll(); } catch (e) {}
  }
  root.alternarFiltroSobreaviso = alternar;

  function montarBotao() {
    if (document.getElementById('btn-filtro-sobreaviso')) return;
    var ref = document.getElementById('btn-save-filter'); if (!ref) return;
    var b = document.createElement('button'); b.type = 'button'; b.id = 'btn-filtro-sobreaviso';
    b.innerHTML = '<i data-lucide="calendar-check" class="w-3.5 h-3.5"></i> Com sobreaviso';
    b.onclick = alternar;
    ref.parentNode.insertBefore(b, ref.nextSibling);
    pintarBotao();
    if (typeof root.lucide !== 'undefined') root.lucide.createIcons();
  }

  // aplica o filtro por cima da lista já filtrada/ordenada (só na tela Mensal)
  var orig = root.getOrderedAndFilteredTecnicos;
  if (typeof orig === 'function') {
    root.getOrderedAndFilteredTecnicos = function () {
      var lista = orig.apply(this, arguments);
      if (!ligado || typeof currentView === 'undefined' || currentView !== 'month') return lista;
      var per = periodoFolha();
      var f = lista.filter(function (n) { return temSobreaviso(n, per); });
      f.sort = function () { return this; };   // mantém a ordem por setor (a lista original também "trava" o sort)
      return f;
    };
  }
  var origLimpar = root.limparFiltros;   // "Limpar" também desliga este filtro
  if (typeof origLimpar === 'function') {
    root.limparFiltros = function () { if (ligado) { ligado = false; try { localStorage.setItem(CHAVE, '0'); } catch (e) {} pintarBotao(); } return origLimpar.apply(this, arguments); };
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', montarBotao); else montarBotao();
  if (ligado) setTimeout(function () { try { renderAll(); } catch (e) {} }, 0);
})(window);
