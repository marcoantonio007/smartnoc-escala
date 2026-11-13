<div id="view-semanal" class="bg-white rounded-lg shadow border border-slate-200 overflow-hidden flex-1 flex flex-col w-full h-full relative">
    <div class="bg-slate-100 p-1 border-b border-slate-300 flex justify-between items-center shrink-0 w-full h-[32px] px-2 relative">
        <span class="text-[10px] font-bold text-slate-600 uppercase tracking-widest pl-1 hidden sm:block">ESCALA SEMANAL</span>
        <div class="absolute left-1/2 -translate-x-1/2 flex items-center gap-2 sm:gap-4 z-10">
            <button onclick="changeWeek(-1)" class="w-6 h-6 flex items-center justify-center bg-slate-300 hover:bg-slate-400 text-slate-700 rounded"><i data-lucide="chevron-left" class="w-3.5 h-3.5"></i></button>
            <span class="text-[10px] sm:text-sm font-black uppercase text-slate-700 whitespace-nowrap" id="semanal-title">...</span>
            <button onclick="changeWeek(1)" class="w-6 h-6 flex items-center justify-center bg-slate-300 hover:bg-slate-400 text-slate-700 rounded"><i data-lucide="chevron-right" class="w-3.5 h-3.5"></i></button>
        </div>
    </div>
    <div id="tabela-swipe-container" class="w-full flex-1 overflow-auto custom-scrollbar bg-white select-none relative">
        <table id="weekly-table" class="w-full table-fixed border-collapse"><thead class="sticky top-0 z-30"><tr class="bg-blue-950 text-white text-scale-semanal-cell" id="weekly-headers"></tr></thead><tbody class="divide-y divide-slate-200" id="weekly-body"></tbody></table>
    </div>
</div>

<script>
    const linhasEscalaConfig = [
        { id: 'rec_1', titulo: 'RECORRÊNCIA 1', subtitulo: 'GERAL', cor: 'bg-neutral-600', match: (t, st) => st === 'plantao_rec1' || (t.setor === 'RECORRENCIA' && st === 'plantao') }, /* SEM_SETAS_V1 */
        // REMOVIDO_RESERVA_V1 (linha não usada mais no sobreaviso): { id: 'rec_2', titulo: 'RECORRÊNCIA 2', subtitulo: 'GERAL - RESERVA', cor: 'bg-neutral-800', isHidden: true, isReserva: true, parentId: 'rec_1', match: (t, st) => st === 'plantao_rec2' },
        { id: 'l_lte_tit', titulo: 'SALVADOR - LTE 1', subtitulo: 'PRINCIPAL', cor: 'bg-blue-600', match: (t, st) => t.setor === 'LTE' && (st === 'plantao' || st === 'plantao_lte_titular') },
        // REMOVIDO_RESERVA_V1 (linha não usada mais no sobreaviso): { id: 'l_lte_res', titulo: 'SALVADOR - LTE 2', subtitulo: 'RESERVA', cor: 'bg-indigo-400', isHidden: true, isReserva: true, parentId: 'l_lte_tit', match: (t, st) => t.setor === 'LTE' && st === 'plantao_reserva' },
        { id: 'l1', titulo: 'SALVADOR - COI', subtitulo: 'ELE/REF', cor: 'bg-amber-600', match: (t, st) => t.localidade === 'SALVADOR' && t.local_base === 'COI' && t.setor === 'ELE/REF' && st.startsWith('plantao') },
        { id: 'l3', titulo: 'SALVADOR - COI', subtitulo: 'VIDEOWALL', cor: 'bg-purple-600', match: (t, st) => t.setor === 'VIDEOWALL' && st.startsWith('plantao') },
        { id: 'l4', titulo: 'TAF/PGU', subtitulo: 'ELE/REF', cor: 'bg-emerald-600', match: (t, st) => ['TAF', 'PGU'].includes(t.sigla) && t.setor === 'ELE/REF' && st.startsWith('plantao') },
        { id: 'l5', titulo: 'FSA/SEH', subtitulo: 'ELE/REF', cor: 'bg-teal-600', match: (t, st) => ['FSA', 'SEH'].includes(t.sigla) && t.setor === 'ELE/REF' && st.startsWith('plantao') },
        { id: 'l6', titulo: 'SNJ/ALH', subtitulo: 'ELE/REF', cor: 'bg-cyan-600', match: (t, st) => ['SNJ', 'ALH'].includes(t.sigla) && t.setor === 'ELE/REF' && st.startsWith('plantao') },
        { id: 'l7', titulo: 'VCA/GNB', subtitulo: 'ELE/REF', cor: 'bg-indigo-600', match: (t, st) => ['VCA', 'GNB'].includes(t.sigla) && t.setor === 'ELE/REF' && st.startsWith('plantao') },
        { id: 'l8', titulo: 'BES/SMV', subtitulo: 'ELE/REF', cor: 'bg-violet-600', match: (t, st) => ['BES', 'SMV'].includes(t.sigla) && t.setor === 'ELE/REF' && st.startsWith('plantao') },
        { id: 'l9', titulo: 'SBM/JUA', subtitulo: 'ELE/REF', cor: 'bg-pink-600', match: (t, st) => ['SBM', 'JUA'].includes(t.sigla) && t.setor === 'ELE/REF' && st.startsWith('plantao') },
        { id: 'l10', titulo: 'ITB/JEE', subtitulo: 'ELE/REF', cor: 'bg-rose-600', match: (t, st) => ['ITB', 'JEE'].includes(t.sigla) && t.setor === 'ELE/REF' && st.startsWith('plantao') },
        { id: 'l11', titulo: 'ECN/PAF', subtitulo: 'ELE/REF', cor: 'bg-orange-600', match: (t, st) => ['ECN', 'PAF'].includes(t.sigla) && t.setor === 'ELE/REF' && st.startsWith('plantao') },
        { id: 'l12', titulo: 'IRE/IEB', subtitulo: 'ELE/REF', cor: 'bg-slate-600', match: (t, st) => ['IRE', 'IEB'].includes(t.sigla) && t.setor === 'ELE/REF' && st.startsWith('plantao') }
    ];

    function getFeriadosMoveis(ano) {
        let a = ano % 19, b = Math.floor(ano / 100), c = ano % 100;
        let d = Math.floor(b / 4), e = b % 4, f = Math.floor((b + 8) / 25);
        let g = Math.floor((b - f + 1) / 3), h = (19 * a + b - d - g + 15) % 30;
        let i = Math.floor(c / 4), k = c % 4, l = (32 + 2 * e + 2 * i - h - k) % 7;
        let m = Math.floor((a + 11 * h + 22 * l) / 451);
        let mesPascoa = Math.floor((h + l - 7 * m + 114) / 31) - 1;
        let diaPascoa = ((h + l - 7 * m + 114) % 31) + 1;
        let pascoa = new Date(ano, mesPascoa, diaPascoa);
        let addDias = (dataBase, dias) => { let dt = new Date(dataBase); dt.setDate(dt.getDate() + dias); return String(dt.getMonth() + 1).padStart(2, '0') + '-' + String(dt.getDate()).padStart(2, '0'); };
        return {
            [addDias(pascoa, -47)]: { nome: 'Carnaval', tipo: 'Nacional', cor: 'bg-emerald-400' },
            [addDias(pascoa, -2)]: { nome: 'Sexta-feira Santa', tipo: 'Nacional', cor: 'bg-emerald-400' },
            [addDias(pascoa, 60)]: { nome: 'Corpus Christi', tipo: 'Nacional', cor: 'bg-emerald-400' }
        };
    }

    function getMondayOfCurrentWeek(d) { const date = new Date(d); const day = date.getDay(); const diff = date.getDate() - day + (day === 0 ? -6 : 1); const monday = new Date(date.setDate(diff)); monday.setHours(0, 0, 0, 0); return monday; }
    function formatarTelefoneBR(telefone) { if (!telefone) return ''; let telLimpo = String(telefone).replace(/\D/g, ''); if (telLimpo.startsWith('55') && (telLimpo.length === 13 || telLimpo.length === 12)) telLimpo = telLimpo.substring(2); if (telLimpo.length === 11) return `(${telLimpo.substring(0,2)}) ${telLimpo.substring(2,7)}-${telLimpo.substring(7,11)}`; else if (telLimpo.length === 10) return `(${telLimpo.substring(0,2)}) ${telLimpo.substring(2,6)}-${telLimpo.substring(6,10)}`; return telefone; }

    let currentWeekStartDate = getMondayOfCurrentWeek(new Date()); let mobileDayOffset = 0; let globalExpanded = false; let individualExpanded = {};
    window.toggleAllHiddenRows = function() { globalExpanded = !globalExpanded; linhasEscalaConfig.forEach(l => { if (l.isHidden) individualExpanded[l.id] = globalExpanded; }); renderWeekly(); }
    window.toggleSpecificChild = function(childId) { individualExpanded[childId] = !individualExpanded[childId]; renderWeekly(); }
    window.addEventListener('resize', () => { if(currentView === 'week') { renderWeekly(); lucide.createIcons(); } });

    function changeWeek(delta) { const isLandscape = window.innerWidth > window.innerHeight; if (!isLandscape && window.innerWidth < 768) { mobileDayOffset += delta * 3; } else { currentWeekStartDate.setDate(currentWeekStartDate.getDate() + (delta * 7)); } renderWeekly(); lucide.createIcons(); }
    function abrirWhatsApp(tel) { if (!tel) { alert("Sem número cadastrado."); return; } let limpo = tel.replace(/\D/g, ''); if (limpo.length <= 11 && !limpo.startsWith('55')) limpo = '55' + limpo; window.open(`https://wa.me/${limpo}`, '_blank'); }

    function renderWeekly() {
        const isLandscape = window.innerWidth > window.innerHeight; const isMobile = window.innerWidth < 768 && !isLandscape;
        const tableElement = document.getElementById('weekly-table'); if (isMobile) { tableElement.className = "w-full table-fixed border-collapse"; } else { tableElement.className = "w-full table-fixed border-collapse min-w-[750px] md:min-w-full"; }

        let days = [];
        if (isMobile) {
            let baseDate = new Date(); baseDate.setDate(baseDate.getDate() + mobileDayOffset);
            for (let i = 0; i < 3; i++) { let d = new Date(baseDate); d.setDate(d.getDate() + i); days.push(d); }
            document.getElementById('semanal-title').innerText = `${days[0].getDate()}/${String(days[0].getMonth()+1).padStart(2,'0')} A ${days[2].getDate()}/${String(days[2].getMonth()+1).padStart(2,'0')}`;
        } else {
            let tempDate = new Date(currentWeekStartDate);
            for (let i = 0; i < 7; i++) { days.push(new Date(tempDate)); tempDate.setDate(tempDate.getDate() + 1); }
            document.getElementById('semanal-title').innerText = `${days[0].getDate()} A ${days[6].getDate()} DE ${nomesMeses[currentWeekStartDate.getMonth()]} ${currentWeekStartDate.getFullYear()}`;
        }

        const larguraSetor = isMobile ? 'w-[150px] min-w-[150px] max-w-[150px]' : 'w-[200px] min-w-[200px] max-w-[200px]'; const txtSetor = isMobile ? 'SETOR/LOCAL' : 'Setor / Localidade';
        let headersHTML = `<th class="${larguraSetor} p-1 sm:p-1.5 text-left font-bold bg-blue-950 border-r border-slate-700 sticky left-0 z-40"><div class="flex items-center justify-between"><span class="text-scale-semanal-tit pl-1 truncate">${txtSetor}</span></div></th>`;
        
        days.forEach((d, i) => {
            const isHoje = d.toDateString() === simulatedTodayStr;
            let dataStrClean = `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
            let dataStrMMDD = dataStrClean.substring(5, 10);
            let holidayIndicator = '';
            
            let feriadosMoveis = getFeriadosMoveis(d.getFullYear());
            let fMovel = feriadosMoveis[dataStrMMDD];

            if (fMovel) {
                let tooltip = `${fMovel.tipo.toUpperCase()}: ${fMovel.nome}`;
                holidayIndicator = `<div class="relative flex h-1.5 w-1.5 sm:h-2 sm:w-2 mx-auto mt-0.5 cursor-help" title="${tooltip}"><span class="animate-ping absolute inline-flex h-full w-full rounded-full ${fMovel.cor} opacity-75"></span><span class="relative inline-flex rounded-full h-full w-full ${fMovel.cor} border border-black/10"></span></div>`;
            } else if (typeof listaFeriadosCache !== 'undefined') {
                let feriadosDoDia = listaFeriadosCache.filter(f => f.data_feriado && f.data_feriado.substring(5, 10) === dataStrMMDD);
                if (feriadosDoDia.length > 0) {
                    let fPrin = feriadosDoDia.find(f => f.tipo === 'nacional') || feriadosDoDia.find(f => f.tipo === 'estadual') || feriadosDoDia[0];
                    let cBg = fPrin.tipo === 'nacional' ? 'bg-emerald-400' : (fPrin.tipo === 'estadual' ? 'bg-blue-400' : 'bg-amber-400');
                    let tooltip = feriadosDoDia.map(f => `${f.tipo.toUpperCase()}: ${f.nome} ${f.localidade ? '('+f.localidade+')' : ''}`).join(' | ');
                    holidayIndicator = `<div class="relative flex h-1.5 w-1.5 sm:h-2 sm:w-2 mx-auto mt-0.5 cursor-help" title="${tooltip}"><span class="animate-ping absolute inline-flex h-full w-full rounded-full ${cBg} opacity-75"></span><span class="relative inline-flex rounded-full h-full w-full ${cBg} border border-black/10"></span></div>`;
                }
            }

            headersHTML += `<th class="py-0.5 px-1 sm:py-1 sm:px-1 text-center font-semibold bg-blue-950 text-white ${isHoje?'hoje-col-header bg-emerald-700':'border-l border-slate-700'} relative"><div class="text-scale-semanal-sub text-blue-200 opacity-90">${isMobile ? ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'][d.getDay()] : ['Seg','Ter','Qua','Qui','Sex','Sáb','Dom'][i]}</div><div class="text-scale-semanal-tit font-bold mt-0.5">${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth()+1).padStart(2, '0')}</div>${holidayIndicator}</th>`;
        });
        document.getElementById('weekly-headers').innerHTML = headersHTML;

        let bodyHTML = ''; let rowIndex = 0;
        linhasEscalaConfig.forEach((linha) => {
            let isVisible = true; if (linha.isHidden) { isVisible = !!individualExpanded[linha.id]; } const trStyle = !isVisible ? 'display: none;' : '';
            let bgLinhaClass = ''; if (linha.isReserva) { bgLinhaClass = 'bg-[#87CEFA]/30 hover:bg-[#87CEFA]/50 text-blue-950'; } else { bgLinhaClass = rowIndex % 2 === 0 ? 'bg-white hover:bg-slate-50/80' : 'bg-slate-50 hover:bg-slate-100/80'; rowIndex++; }
            let htmlArrow = ''; let cursorClass = ''; let onclickAction = '';
            if (linha.hasChild) {
                const childId = linha.hasChild; const isChildExp = !!individualExpanded[childId]; cursorClass = 'cursor-pointer hover:bg-slate-200 transition-colors'; onclickAction = `onclick="toggleSpecificChild('${childId}')"`;
                htmlArrow = `<div class="ml-auto pr-1 shrink-0"><i data-lucide="${isChildExp ? 'chevron-up' : 'chevron-down'}" class="w-3.5 h-3.5 text-slate-500"></i></div>`;
            }
            bodyHTML += `<tr class="border-b border-slate-200 transition-colors duration-150 ${bgLinhaClass}" style="${trStyle}"><td class="${larguraSetor} p-1 border-r border-slate-200 text-left pl-1.5 sm:pl-2 sticky left-0 z-20 ${cursorClass} whitespace-nowrap overflow-hidden" style="background: inherit;" ${onclickAction}><div class="flex items-center w-full min-h-[30px] sm:min-h-[42px]"><div class="flex flex-col justify-center flex-1 overflow-hidden pr-1"><div class="text-scale-semanal-tit font-black flex items-center gap-1 sm:gap-1.5 w-full"><span class="w-1 h-1 sm:w-1.5 sm:h-1.5 rounded-full ${linha.cor} shrink-0"></span><span class="truncate leading-none text-blue-950">${linha.titulo}</span></div><div class="text-scale-semanal-sub font-bold text-slate-500 uppercase pl-2 sm:pl-3 mt-[2px] truncate w-full">${linha.subtitulo}</div></div>${htmlArrow}</div></td>`;
            
            days.forEach(d => {
                const isHoje = d.toDateString() === simulatedTodayStr; const dataStrClean = `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
                let nomesNoPlantao = tecnicosDB.filter(t => { const status = plantaoMensalDados[t.nome]?.[dataStrClean]?.status || plantaoMensalDados[t.nome]?.[dataStrClean]; return (typeof status === 'string' && status.startsWith('plantao')) ? linha.match(t, status) : false; });
                let tdClass = isHoje ? '!bg-emerald-100 border-x-2 border-emerald-400 relative z-10' : (linha.isReserva ? 'border-l border-blue-300/30' : 'border-l border-slate-200');
                let innerDivClass = 'py-0.5 sm:py-1 bg-transparent';
                if (nomesNoPlantao.length > 0) { if (isHoje) innerDivClass = 'p-0.5 sm:p-1 shadow-sm border bg-emerald-50 border-emerald-400'; else if (linha.isReserva) innerDivClass = 'p-0.5 sm:p-1 shadow-sm border bg-[#87CEFA] border-blue-400'; else innerDivClass = 'p-0.5 sm:p-1 shadow-sm border bg-white border-slate-200'; }
                let separatorLinha = linha.isReserva ? '<div class="border-t border-blue-400/50 my-[2px] w-full"></div>' : '<div class="border-t border-slate-200/60 my-[2px] w-full"></div>';

                bodyHTML += `<td class="p-0.5 align-middle ${tdClass}"><div class="w-full ${innerDivClass} min-h-[30px] sm:min-h-[42px] flex flex-col items-center justify-center rounded transition-colors duration-150">${nomesNoPlantao.length > 0 ? nomesNoPlantao.map(t => { let telFmt = formatarTelefoneBR(t.telefone); let corNome = 'text-slate-900'; let corTel = 'text-slate-600'; return `<div onclick="abrirWhatsApp('${t.telefone || ''}')" class="flex flex-col items-center justify-center cursor-pointer hover:text-blue-600 w-full py-[1px] overflow-hidden" title="Chamar no WhatsApp"><span class="text-[10px] sm:text-[12px] font-black ${corNome} uppercase truncate w-full text-center leading-none tracking-tight">${t.nome.split(' ')[0]}</span><span class="text-[9px] sm:text-[10.5px] font-bold ${corTel} mt-[3px] tracking-tighter leading-none truncate w-full text-center">${telFmt}</span></div>`; }).join(separatorLinha) : `<span class="text-[10px] font-bold text-slate-400/50">--</span>`}</div></td>`;
            });
            bodyHTML += `</tr>`;
        });
        document.getElementById('weekly-body').innerHTML = bodyHTML; lucide.createIcons();
    }
</script>
