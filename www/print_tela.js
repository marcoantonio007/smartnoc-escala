/* PRINT_TELA_V1 — botão "câmera" ao lado do Salvar (Semanal/Mensal): gera uma imagem PNG da tela em exibição,
   sem o menu do topo, com a tabela INTEIRA (todas as linhas e colunas, mesmo as que estão fora da rolagem),
   em alta resolução, e copia para colar no WhatsApp (ou baixa / compartilha no celular). */
(function (root) {
  'use strict';
  var LIBS = ['https://cdn.jsdelivr.net/npm/html-to-image@1.11.11/dist/html-to-image.js',
              'https://unpkg.com/html-to-image@1.11.11/dist/html-to-image.js'];
  var ocupado = false, ultimo = null;

  function aviso(msg, tipo) { if (typeof root.showToast === 'function') root.showToast(msg, tipo || 'success'); else alert(msg); }
  function carregarLib(i) {
    i = i || 0;
    if (root.htmlToImage) return Promise.resolve();
    if (i >= LIBS.length) return Promise.reject(new Error('Não foi possível carregar o gerador de imagem (sem internet?).'));
    return new Promise(function (ok, erro) {
      var s = document.createElement('script'); s.src = LIBS[i]; s.async = true;
      s.onload = function () { root.htmlToImage ? ok() : erro(); }; s.onerror = function () { s.remove(); erro(); };
      document.head.appendChild(s);
    }).catch(function () { return carregarLib(i + 1); });
  }
  function telaAtual() {
    var ids = ['view-mensal', 'view-semanal'];
    for (var i = 0; i < ids.length; i++) { var e = document.getElementById(ids[i]); if (e && !e.classList.contains('hidden') && e.offsetWidth > 0) return e; }
    return null;
  }
  function p2(n) { return String(n).padStart(2, '0'); }
  function agora() { var d = new Date(); return { txt: p2(d.getDate()) + '/' + p2(d.getMonth() + 1) + '/' + d.getFullYear() + ' ' + p2(d.getHours()) + ':' + p2(d.getMinutes()), arq: d.getFullYear() + '-' + p2(d.getMonth() + 1) + '-' + p2(d.getDate()) + '_' + p2(d.getHours()) + p2(d.getMinutes()) }; }

  // monta, fora da tela, uma cópia "esticada" da tela atual (sem rolagem, sem botões de navegação)
  function montarCopia(orig) {
    var largura = orig.scrollWidth;
    var origTodos = [orig].concat(Array.prototype.slice.call(orig.querySelectorAll('*')));
    origTodos.forEach(function (el) { if (el.scrollWidth > el.clientWidth + 1) largura = Math.max(largura, el.scrollWidth + (orig.offsetWidth - el.clientWidth)); });
    var clone = orig.cloneNode(true);
    var cloneTodos = [clone].concat(Array.prototype.slice.call(clone.querySelectorAll('*')));
    origTodos.forEach(function (el, i) {
      var c = cloneTodos[i]; if (!c || !c.style) return;
      var cs = getComputedStyle(el);
      if (/(auto|scroll|hidden)/.test(cs.overflowY + cs.overflowX) && (el.scrollHeight > el.clientHeight + 1 || el.scrollWidth > el.clientWidth + 1 || i === 0)) {
        c.style.overflow = 'visible'; c.style.height = 'auto'; c.style.maxHeight = 'none'; c.style.flex = 'none';
      }
      if (cs.position === 'sticky') c.style.position = 'relative';
    });
    clone.style.height = 'auto'; clone.style.maxHeight = 'none'; clone.style.width = largura + 'px'; clone.style.flex = 'none';
    cloneTodos.forEach(function (c) { if (c.removeAttribute) c.removeAttribute('id'); });
    Array.prototype.forEach.call(clone.querySelectorAll('button, input, select'), function (b) {   // setas e controles não aparecem no print
      if (b.tagName !== 'BUTTON' || b.querySelector('.lucide-chevron-left, .lucide-chevron-right, [data-lucide="chevron-left"], [data-lucide="chevron-right"]') || /change(Month|Week)|semana/i.test(b.getAttribute('onclick') || '')) b.style.visibility = 'hidden';
    });

    var filtros = Array.prototype.map.call(document.querySelectorAll('#active-filters-container > *'), function (x) { return x.textContent.replace(/\s+/g, ' ').trim(); }).filter(Boolean);
    var t = agora();
    var topo = document.createElement('div');
    topo.style.cssText = 'display:flex;justify-content:space-between;align-items:center;gap:16px;padding:10px 16px;background:#1e2a5a;color:#fff;font:600 13px/1.3 ui-sans-serif,system-ui,Segoe UI,Roboto,Arial,sans-serif;';
    topo.innerHTML = '<div style="display:flex;align-items:center;gap:10px"><span style="font-weight:900;letter-spacing:.5px">PLANTÃO TÉCNICO</span><span style="opacity:.75;font-weight:500">Escala de Sobreaviso · ' + (orig.id === 'view-mensal' ? 'Mensal' : 'Semanal') + '</span>' +
      (filtros.length ? '<span style="background:rgba(255,255,255,.14);padding:2px 8px;border-radius:999px;font-size:11px">Filtro: ' + filtros.join(', ').replace(/[<>&]/g, '') + '</span>' : '') +
      '</div><div style="opacity:.75;font-weight:500;font-size:11px">Gerado em ' + t.txt + '</div>';

    var caixa = document.createElement('div');
    caixa.style.cssText = 'position:fixed;left:-100000px;top:0;z-index:-1;background:#fff;width:' + largura + 'px;';
    caixa.appendChild(topo); caixa.appendChild(clone);
    document.body.appendChild(caixa);
    return { caixa: caixa, arquivo: 'escala_' + (orig.id === 'view-mensal' ? 'mensal' : 'semanal') + '_' + t.arq + '.png' };
  }

  function gerarBlob(caixa, comFontes) {
    var w = caixa.scrollWidth, h = caixa.scrollHeight;
    var ratio = Math.max(1, Math.min(2, 12000 / Math.max(w, h)));   // nítido, sem passar do limite do navegador
    return root.htmlToImage.toBlob(caixa, { pixelRatio: ratio, backgroundColor: '#ffffff', width: w, height: h, cacheBust: true, skipFonts: !comFontes, style: { left: '0', top: '0', position: 'static' } });
  }

  function copiar(blob) {
    if (!navigator.clipboard || !root.ClipboardItem) return Promise.reject(new Error('sem área de transferência'));
    return navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]);
  }
  function baixar(blob, nome) {
    var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = nome;
    document.body.appendChild(a); a.click(); setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1500);
  }

  function janela(blob, nome, copiou) {
    var velho = document.getElementById('print-modal'); if (velho) velho.remove();
    var url = URL.createObjectURL(blob);
    var arqObj = null; try { arqObj = new File([blob], nome, { type: 'image/png' }); } catch (e) {}
    var podeShare = !!(arqObj && navigator.canShare && navigator.canShare({ files: [arqObj] }));
    var m = document.createElement('div'); m.id = 'print-modal';
    m.className = 'fixed inset-0 bg-black/60 z-[500] flex items-center justify-center p-3';
    m.innerHTML =
      '<div class="bg-white rounded-xl shadow-2xl w-full max-w-5xl max-h-[92vh] flex flex-col overflow-hidden">' +
        '<div class="flex items-center justify-between px-4 py-2.5 border-b">' +
          '<div><div class="text-sm font-black text-slate-800 flex items-center gap-2"><i data-lucide="camera" class="w-4 h-4 text-blue-600"></i> Print da tela</div>' +
          '<div id="print-status" class="text-[11px] font-bold ' + (copiou ? 'text-emerald-600' : 'text-slate-500') + '">' + (copiou ? '✔ Imagem copiada! No WhatsApp, clique na conversa e cole (Ctrl+V).' : 'Escolha abaixo: copiar, baixar ou compartilhar.') + '</div></div>' +
          '<button type="button" data-x class="p-1.5 rounded hover:bg-slate-100 text-slate-500" title="Fechar"><i data-lucide="x" class="w-5 h-5"></i></button></div>' +
        '<div class="flex-1 overflow-auto bg-slate-100 p-2 custom-scrollbar"><img src="' + url + '" alt="Print da tela" class="max-w-none w-full h-auto rounded shadow border border-slate-200 bg-white"></div>' +
        '<div class="flex flex-wrap items-center justify-end gap-2 px-4 py-2.5 border-t">' +
          '<span class="text-[10px] text-slate-400 mr-auto">Dica: no WhatsApp, envie em <b>HD</b> (ou como documento) para manter a nitidez.</span>' +
          (podeShare ? '<button type="button" data-share class="px-3 py-1.5 rounded bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold flex items-center gap-1.5"><i data-lucide="share-2" class="w-3.5 h-3.5"></i> Compartilhar</button>' : '') +
          '<button type="button" data-dl class="px-3 py-1.5 rounded bg-slate-200 hover:bg-slate-300 text-slate-700 text-xs font-bold flex items-center gap-1.5"><i data-lucide="download" class="w-3.5 h-3.5"></i> Baixar PNG</button>' +
          '<button type="button" data-cp class="px-3 py-1.5 rounded bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold flex items-center gap-1.5"><i data-lucide="copy" class="w-3.5 h-3.5"></i> Copiar imagem</button>' +
        '</div></div>';
    document.body.appendChild(m);
    var st = m.querySelector('#print-status');
    function fechar() { m.remove(); URL.revokeObjectURL(url); document.removeEventListener('keydown', esc); }
    function esc(e) { if (e.key === 'Escape') fechar(); }
    document.addEventListener('keydown', esc);
    m.addEventListener('click', function (e) { if (e.target === m) fechar(); });
    m.querySelector('[data-x]').onclick = fechar;
    m.querySelector('[data-dl]').onclick = function () { baixar(blob, nome); };
    m.querySelector('[data-cp]').onclick = function () {
      copiar(blob).then(function () { st.className = 'text-[11px] font-bold text-emerald-600'; st.textContent = '✔ Imagem copiada! No WhatsApp, clique na conversa e cole (Ctrl+V).'; })
        .catch(function () { st.className = 'text-[11px] font-bold text-red-600'; st.textContent = 'Este navegador não deixou copiar. Use "Baixar PNG" e anexe no WhatsApp.'; });
    };
    var sh = m.querySelector('[data-share]');
    if (sh) sh.onclick = function () { navigator.share({ files: [arqObj], title: 'Escala de Sobreaviso' }).catch(function () {}); };
    if (typeof root.lucide !== 'undefined') root.lucide.createIcons();
  }

  root.printTela = function (btn) {
    if (ocupado) return;
    var orig = telaAtual();
    if (!orig) { aviso('Abra a tela Semanal ou Mensal para tirar o print.', 'error'); return; }
    ocupado = true;
    var htmlBtn = btn ? btn.innerHTML : '';
    if (btn) { btn.disabled = true; btn.style.opacity = '.6'; btn.style.cursor = 'wait'; }
    var montagem = null;
    carregarLib().then(function () {
      montagem = montarCopia(orig);
      return new Promise(function (r) { requestAnimationFrame(function () { requestAnimationFrame(r); }); });
    }).then(function () {
      return gerarBlob(montagem.caixa, true).catch(function () { return gerarBlob(montagem.caixa, false); });   // se as fontes falharem, gera sem elas
    }).then(function (blob) {
      if (!blob) throw new Error('imagem vazia');
      ultimo = blob;
      return copiar(blob).then(function () { return true; }, function () { return false; }).then(function (ok) { janela(blob, montagem.arquivo, ok); });
    }).catch(function (e) {
      aviso('Não foi possível gerar o print: ' + (e && e.message ? e.message : e), 'error');
    }).then(function () {
      if (montagem && montagem.caixa) montagem.caixa.remove();
      if (btn) { btn.disabled = false; btn.style.opacity = ''; btn.style.cursor = ''; btn.innerHTML = htmlBtn; }
      ocupado = false;
    });
  };
})(window);
