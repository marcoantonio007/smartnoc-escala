/* dashboard_pdf.js — DASH_PDF_V1: baixar o Dashboard Gerencial em PDF e enviar por e-mail (com o PDF anexo). */
(function (root) {
  'use strict';
  var doc = root.document;
  function el(id) { return doc.getElementById(id); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function toast(m, t) { if (typeof showToast === 'function') showToast(m, t || 'success'); else alert(m); }
  function snap() { return root.__dashSnap || null; }
  var G = function () { return root.GestaoCore || {}; };
  function fH(v) { return G().fmtH ? G().fmtH(v) : (Math.round(v * 100) / 100) + 'h'; }
  function fM(v) { return G().fmtMin ? G().fmtMin(v) : Math.floor(v / 60) + 'h' + String(v % 60).padStart(2, '0') + 'm'; }
  function txt(s) { return String(s == null ? '' : s).replace(/[🎫]/g, '').trim(); }
  function dmy(s) { var p = String(s).split('-'); return p[2] + '/' + p[1] + '/' + p[0]; }

  function gerarPdf() {
    var s = snap(); if (!s) throw new Error('Abra o Dashboard para gerar o relatório.');
    if (!root.jspdf || !root.jspdf.jsPDF) throw new Error('A biblioteca de PDF não carregou. Recarregue a página (Ctrl+F5).');
    var r = s.r, t = r.totais, L = r.linhas.slice().sort(function (a, b) { return String(a.nome).localeCompare(String(b.nome), 'pt-BR'); });
    var d = new root.jspdf.jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' }), W = d.internal.pageSize.getWidth(), H = d.internal.pageSize.getHeight();
    var logo = (typeof configuracoes !== 'undefined' && configuracoes && configuracoes.logo_pdf_base64) || null, dim = null;
    if (logo) { try { var pr = d.getImageProperties(logo), k = Math.min(38 / pr.width, 14 / pr.height); dim = [pr.width * k, pr.height * k]; } catch (e) { logo = null; } }
    var fmtLogo = logo && /^data:image\/png/i.test(logo) ? 'PNG' : 'JPEG';
    var titulo = s.nomeFolha;
    // cabeçalho
    if (logo && dim) d.addImage(logo, fmtLogo, W - 14 - dim[0], 10, dim[0], dim[1]);
    d.setFont('helvetica', 'bold'); d.setFontSize(15); d.setTextColor(30, 41, 59); d.text('DASHBOARD GERENCIAL', 14, 17);
    d.setFont('helvetica', 'normal'); d.setFontSize(9); d.setTextColor(100, 116, 139);
    d.text('Folha ' + titulo + ' (' + dmy(s.comp.ini) + ' a ' + dmy(s.comp.fim) + ')  ·  ' + (s.setor ? 'Setor: ' + s.setor : 'Todos os setores'), 14, 23);
    d.text('Gerado em ' + new Date().toLocaleString('pt-BR'), 14, 28);
    // KPIs
    var kp = [
      ['SOBREAVISO LÍQUIDO', fH(t.horasLiquidas), 'Bruto ' + fH(t.horasBrutas) + (t.descontoMin ? ' − ' + fM(t.descontoMin) + ' de HE' : ''), [37, 99, 235]],
      ['HORA EXTRA', fM(t.heMin), t.comHE + ' colaborador(es)', [245, 158, 11]],
      ['TICKETS EXTRAS', String(t.tickets), 'Dias com mais de 2h de HE', [124, 58, 237]],
      ['EM SOBREAVISO', String(L.filter(function (l) { return l.dias > 0; }).length), L.reduce(function (a, l) { return a + l.dias; }, 0) + ' dias de sobreaviso', [5, 150, 105]]
    ];
    var bw = (W - 28 - 9) / 4, y = 33;
    kp.forEach(function (k, i) {
      var x = 14 + i * (bw + 3);
      d.setDrawColor(226, 232, 240); d.setFillColor(255, 255, 255); d.roundedRect(x, y, bw, 22, 2, 2, 'FD');
      d.setFillColor(k[3][0], k[3][1], k[3][2]); d.rect(x, y, 1.2, 22, 'F');
      d.setFontSize(6.5); d.setTextColor(100, 116, 139); d.setFont('helvetica', 'bold'); d.text(k[0], x + 4, y + 5.5);
      d.setFontSize(14); d.setTextColor(15, 23, 42); d.text(k[1], x + 4, y + 13);
      d.setFontSize(6.5); d.setFont('helvetica', 'normal'); d.setTextColor(100, 116, 139); d.text(d.splitTextToSize(k[2], bw - 6)[0], x + 4, y + 18.5);
    });
    y += 28;
    // barras: hora extra por colaborador
    var he = L.filter(function (l) { return l.heMin > 0; }).sort(function (a, b) { return b.heMin - a.heMin; }).slice(0, 12);
    d.setFont('helvetica', 'bold'); d.setFontSize(9); d.setTextColor(30, 41, 59); d.text('HORA EXTRA POR COLABORADOR', 14, y); y += 4;
    if (!he.length) { d.setFont('helvetica', 'normal'); d.setFontSize(8); d.setTextColor(148, 163, 184); d.text('Sem hora extra no período.', 14, y + 3); y += 8; }
    else {
      var max = he[0].heMin, xb = 62, wb = W - 14 - xb - 22;
      he.forEach(function (l) {
        d.setFont('helvetica', 'normal'); d.setFontSize(7.5); d.setTextColor(51, 65, 85); d.text(d.splitTextToSize(l.nome, 46)[0], 14, y + 3.2);
        d.setFillColor(241, 245, 249); d.rect(xb, y, wb, 4, 'F');
        d.setFillColor(245, 158, 11); d.rect(xb, y, Math.max(0.8, wb * l.heMin / max), 4, 'F');
        d.setFont('helvetica', 'bold'); d.setTextColor(15, 23, 42); d.text(fM(l.heMin) + (l.tickets ? '  · ' + l.tickets + ' tk' : ''), W - 14, y + 3.2, { align: 'right' });
        y += 5.6;
      });
      y += 3;
    }
    var cab = function (dt) {
      if (dt.pageNumber > 1 && logo && dim) d.addImage(logo, fmtLogo, W - 14 - dim[0] * 0.7, 6, dim[0] * 0.7, dim[1] * 0.7);
      d.setFont('helvetica', 'normal'); d.setFontSize(7); d.setTextColor(148, 163, 184);
      d.text('Dashboard Gerencial · Folha ' + titulo, 14, H - 7); d.text('Página ' + dt.pageNumber, W - 14, H - 7, { align: 'right' });
    };
    // tabela por colaborador
    d.autoTable({
      startY: y + 1, margin: { left: 14, right: 14, top: 20, bottom: 14 },
      head: [['Colaborador', 'Setor', 'Dias', 'Sobreav. bruto', 'Desc. HE', 'Sobreav. líquido', 'Hora extra', 'Períodos', 'Tickets']],
      body: L.map(function (l) { return [l.nome, l.setor, l.dias || '—', l.horasBrutas ? fH(l.horasBrutas) : '—', l.descontoMin ? fM(l.descontoMin) : '—', l.horasLiquidas ? fH(l.horasLiquidas) : '—', l.heMin ? fM(l.heMin) : '—', l.periodos || '—', l.tickets || '—']; }),
      foot: [['TOTAL', '', String(L.reduce(function (a, l) { return a + l.dias; }, 0)), fH(t.horasBrutas), t.descontoMin ? fM(t.descontoMin) : '—', fH(t.horasLiquidas), fM(t.heMin), String(L.reduce(function (a, l) { return a + l.periodos; }, 0)), String(t.tickets)]],
      theme: 'grid', styles: { fontSize: 7, cellPadding: 1.4, lineColor: [226, 232, 240], lineWidth: 0.1, textColor: [30, 41, 59] },
      headStyles: { fillColor: [30, 58, 138], textColor: 255, fontStyle: 'bold', fontSize: 6.8 },
      footStyles: { fillColor: [241, 245, 249], textColor: [15, 23, 42], fontStyle: 'bold' },
      columnStyles: { 0: { cellWidth: 38, fontStyle: 'bold' }, 1: { cellWidth: 22 }, 2: { halign: 'right' }, 3: { halign: 'right' }, 4: { halign: 'right' }, 5: { halign: 'right' }, 6: { halign: 'right' }, 7: { halign: 'right' }, 8: { halign: 'right' } },
      didDrawPage: cab
    });
    // hora extra por setor e por dia
    var setores = r.porSetor.filter(function (x) { return x.heMin > 0 || x.horas > 0; });
    if (setores.length) d.autoTable({
      startY: d.lastAutoTable.finalY + 6, margin: { left: 14, right: 14, top: 20, bottom: 14 },
      head: [['Setor', 'Colaboradores', 'Sobreaviso líquido', 'Hora extra', '% da HE', 'Tickets']],
      body: setores.map(function (x) { return [x.setor, x.colaboradores, fH(x.horas), x.heMin ? fM(x.heMin) : '—', t.heMin ? Math.round(100 * x.heMin / t.heMin) + '%' : '—', x.tickets || '—']; }),
      theme: 'grid', styles: { fontSize: 7, cellPadding: 1.4, lineColor: [226, 232, 240], lineWidth: 0.1 }, headStyles: { fillColor: [71, 85, 105], textColor: 255, fontStyle: 'bold' },
      columnStyles: { 1: { halign: 'right' }, 2: { halign: 'right' }, 3: { halign: 'right' }, 4: { halign: 'right' }, 5: { halign: 'right' } }, didDrawPage: cab
    });
    var dias = r.porDia.filter(function (x) { return x.heMin > 0; });
    if (dias.length) d.autoTable({
      startY: d.lastAutoTable.finalY + 6, margin: { left: 14, right: 14, top: 20, bottom: 14 },
      head: [['Dia', 'Hora extra', 'Colaboradores']],
      body: dias.map(function (x) { return [dmy(x.data), fM(x.heMin), Object.keys(x.pessoas || {}).map(function (n) { return n + ' (' + fM(x.pessoas[n].min) + ')'; }).join(', ')]; }),
      theme: 'grid', styles: { fontSize: 7, cellPadding: 1.4, lineColor: [226, 232, 240], lineWidth: 0.1 }, headStyles: { fillColor: [180, 83, 9], textColor: 255, fontStyle: 'bold' },
      columnStyles: { 0: { cellWidth: 22 }, 1: { cellWidth: 22, halign: 'right' } }, didDrawPage: cab
    });
    var yf = d.lastAutoTable.finalY + 6; if (yf > H - 24) { d.addPage(); cab({ pageNumber: d.internal.getNumberOfPages() }); yf = 20; }
    d.setFont('helvetica', 'italic'); d.setFontSize(6.8); d.setTextColor(100, 116, 139);
    d.text(d.splitTextToSize('Sobreaviso líquido = sobreaviso bruto menos as horas extras que coincidem com a janela de sobreaviso. Ticket extra: um por dia do calendário em que a soma da hora extra supera 2h (horas de dias diferentes não se acumulam).', W - 28), 14, yf);
    var arq = 'Dashboard_' + titulo.replace(/[^A-Za-z0-9]+/g, '_') + (s.setor ? '_' + s.setor.replace(/[^A-Za-z0-9]+/g, '_') : '') + '.pdf';
    return { doc: d, nome: arq, titulo: titulo };
  }

  // DASH_CORPO_V1: dados do dashboard para o corpo do e-mail (sem PDF anexo)
  function resumoEmail() {
    var s = snap(); if (!s) throw new Error('Abra o Dashboard para enviar.');
    var r = s.r, t = r.totais, L = r.linhas.slice().sort(function (a, b) { return String(a.nome).localeCompare(String(b.nome), 'pt-BR'); });
    var he = L.filter(function (l) { return l.heMin > 0; }).sort(function (a, b) { return b.heMin - a.heMin; }), max = he.length ? he[0].heMin : 1;
    var res = {
      titulo: s.nomeFolha, periodo: dmy(s.comp.ini) + ' a ' + dmy(s.comp.fim), setor: s.setor || '', gerado: new Date().toLocaleString('pt-BR'),
      kpis: [
        ['Sobreaviso líquido', fH(t.horasLiquidas), 'Bruto ' + fH(t.horasBrutas) + (t.descontoMin ? ' − ' + fM(t.descontoMin) + ' de HE' : ''), '#2563eb'],
        ['Hora extra', fM(t.heMin), t.comHE + ' colaborador(es)', '#f59e0b'],
        ['Tickets extras', String(t.tickets), 'Dias com mais de 2h de HE', '#7c3aed'],
        ['Em sobreaviso', String(L.filter(function (l) { return l.dias > 0; }).length), L.reduce(function (a, l) { return a + l.dias; }, 0) + ' dias de sobreaviso', '#059669']],
      heTop: he.slice(0, 15).map(function (l) { return [l.nome, fM(l.heMin), l.tickets || 0, Math.max(2, Math.round(100 * l.heMin / max))]; }),
      linhas: L.map(function (l) { return [l.nome, l.setor, l.dias || '—', l.horasBrutas ? fH(l.horasBrutas) : '—', l.descontoMin ? fM(l.descontoMin) : '—', l.horasLiquidas ? fH(l.horasLiquidas) : '—', l.heMin ? fM(l.heMin) : '—', l.periodos || '—', l.tickets || '—']; }),
      total: ['TOTAL', '', String(L.reduce(function (a, l) { return a + l.dias; }, 0)), fH(t.horasBrutas), t.descontoMin ? fM(t.descontoMin) : '—', fH(t.horasLiquidas), fM(t.heMin), String(L.reduce(function (a, l) { return a + l.periodos; }, 0)), String(t.tickets)],
      setores: r.porSetor.filter(function (x) { return x.heMin > 0 || x.horas > 0; }).map(function (x) { return [x.setor, x.colaboradores, fH(x.horas), x.heMin ? fM(x.heMin) : '—', t.heMin ? Math.round(100 * x.heMin / t.heMin) + '%' : '—', x.tickets || '—']; }),
      dias: r.porDia.filter(function (x) { return x.heMin > 0; }).map(function (x) { return [dmy(x.data), fM(x.heMin), Object.keys(x.pessoas || {}).map(function (n) { return n + ' (' + fM(x.pessoas[n].min) + ')'; }).join(', ')]; })
    };
    return { titulo: s.nomeFolha, resumo: res };
  }
  function baixar() { try { var g = gerarPdf(); g.doc.save(g.nome); } catch (e) { toast(e.message, 'error'); } }

  function modalEmail() {
    var m = el('dash-mail-modal'); if (m) return m;
    m = doc.createElement('div'); m.id = 'dash-mail-modal';
    m.className = 'hidden fixed inset-0 bg-slate-900/70 z-[420] flex items-center justify-center p-4';
    m.innerHTML = '<div class="bg-white rounded-xl shadow-2xl w-full max-w-md p-5">' +
      '<h3 class="text-sm font-black text-slate-800 uppercase mb-1">Enviar dashboard por e-mail</h3><div id="dm-info" class="text-xs text-slate-500 mb-3"></div>' +
      '<label class="block text-[10px] font-bold uppercase mb-1">Para (separe vários e-mails por vírgula)</label><textarea id="dm-para" rows="2" class="w-full border rounded px-2 py-1 text-sm" placeholder="gestor@empresa.com.br, outro@empresa.com.br"></textarea>' +
      '<label class="block text-[10px] font-bold uppercase mb-1 mt-2">Assunto</label><input id="dm-assunto" maxlength="150" class="w-full border rounded px-2 py-1 text-sm">' +
      '<label class="block text-[10px] font-bold uppercase mb-1 mt-2">Mensagem (opcional)</label><textarea id="dm-msg" rows="3" maxlength="2000" class="w-full border rounded px-2 py-1 text-sm"></textarea>' +
      '<div class="text-[11px] text-slate-500 mt-2">O PDF do dashboard vai anexo, com um resumo no corpo do e-mail.</div><div id="dm-erro" class="hidden text-red-600 text-xs mt-2"></div>' +
      '<div class="flex gap-2 pt-3"><button type="button" id="dm-cancelar" class="flex-1 bg-slate-200 py-1.5 rounded font-bold">Cancelar</button>' +
      '<button type="button" id="dm-enviar" class="flex-1 bg-blue-600 text-white py-1.5 rounded font-bold disabled:opacity-50">Enviar</button></div></div>';
    doc.body.appendChild(m);
    el('dm-cancelar').onclick = function () { m.classList.add('hidden'); };
    el('dm-enviar').onclick = enviar;
    return m;
  }
  // DASH_MEU_EMAIL_V1: com e-mail cadastrado para o login, envia na hora para ele; sem e-mail (ex.: admin), abre o formulário
  async function abrirEmail() {
    var s = snap(); if (!s) return;
    var b = el('dash-btn-mail'), rot = b ? b.innerHTML : '';
    if (b) { b.disabled = true; b.innerHTML = 'Enviando…'; }
    try {
      var r = await fetch('api_escala.php?action=so_meu_email', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: '{}' });
      var me; try { me = await r.json(); } catch (e) { me = {}; }
      if (!me.sucesso || !me.email) { abrirEmailForm(); return; }
      var g = resumoEmail(), pp = gerarPdf(), b64 = pp.doc.output('datauristring').split(',')[1];
      var r2 = await fetch('api_escala.php?action=so_dash_email', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ para: [me.email], assunto: 'Dashboard Gerencial — ' + s.nomeFolha + (s.setor ? ' — ' + s.setor : ''), mensagem: '', titulo: g.titulo, resumo: g.resumo, arquivo: pp.nome, pdf: b64 }) });
      var j; try { j = await r2.json(); } catch (e) { j = { sucesso: false, erro: 'Resposta inválida do servidor.' }; }
      if (j.sucesso) toast('Dashboard enviado para ' + me.email + '.'); else toast(j.erro || 'Falha ao enviar.', 'error');
    } catch (e) { toast(e.message, 'error'); }
    finally { if (b) { b.disabled = false; b.innerHTML = rot; if (root.lucide && root.lucide.createIcons) try { root.lucide.createIcons(); } catch (e) {} } }
  }
  function abrirEmailForm() {
    var s = snap(); if (!s) return;
    var m = modalEmail(), salvo = '';
    try { salvo = root.localStorage.getItem('dash_email_para') || ''; } catch (e) {}
    el('dm-info').textContent = 'Folha ' + s.nomeFolha + ' · ' + (s.setor ? 'Setor ' + s.setor : 'Todos os setores');
    if (!el('dm-para').value) el('dm-para').value = salvo;
    el('dm-assunto').value = 'Dashboard Gerencial — ' + s.nomeFolha + (s.setor ? ' — ' + s.setor : '');
    el('dm-erro').classList.add('hidden'); m.classList.remove('hidden'); el('dm-para').focus();
  }
  async function enviar() {
    var b = el('dm-enviar'), err = el('dm-erro'); err.classList.add('hidden');
    var para = el('dm-para').value.split(/[,;\s]+/).map(function (x) { return x.trim(); }).filter(Boolean);
    if (!para.length) { err.textContent = 'Informe pelo menos um e-mail.'; err.classList.remove('hidden'); return; }
    var ruim = para.filter(function (x) { return !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(x); });
    if (ruim.length) { err.textContent = 'E-mail inválido: ' + ruim.join(', '); err.classList.remove('hidden'); return; }
    b.disabled = true; b.textContent = 'Enviando…';
    try {
      var g = resumoEmail(), pp = gerarPdf(), b64 = pp.doc.output('datauristring').split(',')[1];
      var r = await fetch('api_escala.php?action=so_dash_email', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ para: para, assunto: el('dm-assunto').value, mensagem: el('dm-msg').value, titulo: g.titulo, resumo: g.resumo, arquivo: pp.nome, pdf: b64 }) });
      var j; try { j = await r.json(); } catch (e) { j = { sucesso: false, erro: 'Resposta inválida do servidor.' }; }
      if (!j.sucesso) { err.textContent = j.erro || 'Falha ao enviar.'; err.classList.remove('hidden'); return; }
      try { root.localStorage.setItem('dash_email_para', para.join(', ')); } catch (e) {}
      el('dash-mail-modal').classList.add('hidden'); toast('Dashboard enviado para ' + j.enviados + ' destinatário(s).' + (j.falhas && j.falhas.length ? ' Falhou: ' + j.falhas.join(', ') : ''), j.falhas && j.falhas.length ? 'error' : 'success');
    } catch (e) { err.textContent = e.message; err.classList.remove('hidden'); }
    finally { b.disabled = false; b.textContent = 'Enviar'; }
  }

  root.dashExportInstalar = function () {
    var ant = el('gestao-ant'); if (!ant || el('dash-btn-pdf')) return;
    var grupo = ant.parentNode.parentNode;
    var mk = function (id, cls, tit, ic, txtB, fn) {
      var b = doc.createElement('button'); b.type = 'button'; b.id = id; b.title = tit;
      b.className = 'flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-xs font-bold shadow-sm text-white ' + cls;
      b.innerHTML = '<i data-lucide="' + ic + '" class="w-3.5 h-3.5"></i> ' + txtB; b.onclick = fn; grupo.appendChild(b);
    };
    mk('dash-btn-pdf', 'bg-red-600 hover:bg-red-700', 'Baixar o dashboard em PDF', 'file-down', 'PDF', baixar);
    mk('dash-btn-mail', 'bg-blue-600 hover:bg-blue-700', 'Enviar o dashboard em PDF para o seu e-mail cadastrado', 'mail', 'E-mail', abrirEmail);
    if (root.lucide && root.lucide.createIcons) try { root.lucide.createIcons(); } catch (e) {}
  };
})(window);
