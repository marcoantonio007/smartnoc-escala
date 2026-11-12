/* auditoria_export.js — AUDITORIA_EXPORT_V1: exporta a Trilha de Auditoria filtrada em XLSX e PDF (só perfil Mestre) */
(function (root) {
  'use strict';
  var doc = root.document;
  function v(id) { var e = doc.getElementById(id); return e ? e.value : ''; }
  function pad(n) { return String(n).padStart(2, '0'); }
  function carimbo() { var d = new Date(); return d.getFullYear() + pad(d.getMonth() + 1) + pad(d.getDate()) + '_' + pad(d.getHours()) + pad(d.getMinutes()); }
  function mesFiltro() { // mesmo tratamento da tela: aceita AAAA-MM ou "novembro de 2026"
    var m = v('audit-filtro-mes');
    if (!m || /^\d{4}-\d{2}$/.test(m)) return m;
    var mapa = { janeiro: '01', fevereiro: '02', 'março': '03', marco: '03', abril: '04', maio: '05', junho: '06', julho: '07', agosto: '08', setembro: '09', outubro: '10', novembro: '11', dezembro: '12' };
    var low = m.toLowerCase(), num = '', ano = m.match(/\d{4}/);
    for (var k in mapa) if (low.indexOf(k) >= 0) { num = mapa[k]; break; }
    return ano && num ? ano[0] + '-' + num : '';
  }
  function filtros() {
    return { mes: mesFiltro(), data: v('audit-filtro-data'), usuario: v('audit-filtro-usuario'), acao: v('audit-filtro-acao'), termo: v('audit-filtro-termo') };
  }
  function descricao(f) {
    var p = [];
    if (f.mes) p.push('Mês: ' + f.mes.split('-').reverse().join('/'));
    if (f.data) p.push('Data: ' + f.data.split('-').reverse().join('/'));
    if (f.usuario) p.push('Usuário: ' + f.usuario);
    if (f.acao) p.push('Ação: ' + f.acao);
    if (f.termo) p.push('Colaborador: ' + f.termo);
    return p.length ? p.join('  ·  ') : 'Sem filtros (todos os registros)';
  }
  async function buscar(f, fmt) {
    var q = new URLSearchParams({ action: 'get_auditoria', pagina: '1', todos: '1', exp: fmt });
    Object.keys(f).forEach(function (k) { if (f[k]) q.append(k, f[k]); });
    var r = await fetch('api_escala.php?' + q.toString(), { credentials: 'same-origin' });
    var j = await r.json();
    if (!j.sucesso) throw new Error(j.erro || 'Não foi possível buscar a trilha de auditoria.');
    return j.logs || [];
  }
  function baixar(blob, nome) {
    var u = URL.createObjectURL(blob), a = doc.createElement('a');
    a.href = u; a.download = nome; doc.body.appendChild(a); a.click(); a.remove(); setTimeout(function () { URL.revokeObjectURL(u); }, 5000);
  }
  async function xlsx(logs, f) {
    var wb = new ExcelJS.Workbook(), ws = wb.addWorksheet('Trilha de Auditoria');
    ws.columns = [{ header: 'Data/Hora', key: 'd', width: 20 }, { header: 'Usuário Responsável', key: 'u', width: 24 }, { header: 'Ação / Tipo', key: 'a', width: 22 }, { header: 'Detalhes da Alteração', key: 't', width: 110 }];
    ws.spliceRows(1, 0, ['TRILHA DE AUDITORIA'], [descricao(f) + '  ·  Gerado em ' + new Date().toLocaleString('pt-BR') + '  ·  ' + logs.length + ' registro(s)'], []);
    ws.getCell('A1').font = { bold: true, size: 14 }; ws.getCell('A2').font = { italic: true, size: 9, color: { argb: 'FF64748B' } };
    var cab = ws.getRow(4);
    cab.eachCell(function (c) { c.font = { bold: true, color: { argb: 'FFFFFFFF' } }; c.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF1E3A8A' } }; c.alignment = { vertical: 'middle' }; });
    logs.forEach(function (l) { ws.addRow([l.data_hora_fmt, l.usuario, l.acao, l.detalhes]).alignment = { vertical: 'top', wrapText: true }; });
    ws.autoFilter = { from: 'A4', to: 'D' + (4 + logs.length) };
    ws.views = [{ state: 'frozen', ySplit: 4 }];
    var buf = await wb.xlsx.writeBuffer();
    baixar(new Blob([buf], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }), 'Trilha_Auditoria_' + carimbo() + '.xlsx');
  }
  function pdf(logs, f) {
    var jsPDF = root.jspdf.jsPDF, d = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' }), W = d.internal.pageSize.getWidth();
    var logo = (typeof configuracoes !== 'undefined' && configuracoes && configuracoes.logo_pdf_base64) || null;
    var fmtLogo = logo && /^data:image\/png/i.test(logo) ? 'PNG' : 'JPEG', dim = null;
    if (logo) { try { var p = d.getImageProperties(logo); var r = Math.min(40 / p.width, 16 / p.height); dim = [p.width * r, p.height * r]; } catch (e) { logo = null; } }
    var cab = function () { if (logo && dim) d.addImage(logo, fmtLogo, W - 14 - dim[0], 8, dim[0], dim[1]); };
    d.setFont('helvetica', 'bold'); d.setFontSize(14); d.text('TRILHA DE AUDITORIA', 14, 16);
    d.setFont('helvetica', 'normal'); d.setFontSize(8.5); d.setTextColor(90);
    d.text(descricao(f), 14, 22); d.text('Gerado em ' + new Date().toLocaleString('pt-BR') + '  ·  ' + logs.length + ' registro(s)', 14, 27); d.setTextColor(0);
    d.autoTable({
      startY: 31, head: [['Data/Hora', 'Usuário Responsável', 'Ação / Tipo', 'Detalhes da Alteração']],
      body: logs.map(function (l) { return [l.data_hora_fmt, l.usuario, l.acao, l.detalhes]; }),
      theme: 'grid', styles: { fontSize: 7.5, cellPadding: 1.6, valign: 'top', lineColor: [200, 200, 200], lineWidth: 0.1 },
      headStyles: { fillColor: [30, 58, 138], textColor: 255, fontStyle: 'bold' },
      columnStyles: { 0: { cellWidth: 30 }, 1: { cellWidth: 36 }, 2: { cellWidth: 32 }, 3: { cellWidth: 'auto' } },
      margin: { top: 26, left: 14, right: 14 },
      didDrawPage: function (dt) { cab(); d.setFontSize(7.5); d.setTextColor(120); d.text('Página ' + dt.pageNumber, W - 14, d.internal.pageSize.getHeight() - 6, { align: 'right' }); d.setTextColor(0); }
    });
    d.save('Trilha_Auditoria_' + carimbo() + '.pdf');
  }
  root.exportarAuditoria = async function (fmt, btn) {
    var rot = btn ? btn.innerHTML : ''; if (btn) { btn.disabled = true; btn.innerHTML = '…'; }
    try {
      var f = filtros(), logs = await buscar(f, fmt);
      if (!logs.length) { if (typeof showToast === 'function') showToast('Nenhum registro com esses filtros.', 'error'); return; }
      if (fmt === 'xlsx') await xlsx(logs, f); else pdf(logs, f);
      if (typeof showToast === 'function') showToast('Exportados ' + logs.length + ' registro(s).', 'success');
    } catch (e) { if (typeof showToast === 'function') showToast(e.message, 'error'); else alert(e.message); }
    finally { if (btn) { btn.disabled = false; btn.innerHTML = rot; } }
  };
})(window);
