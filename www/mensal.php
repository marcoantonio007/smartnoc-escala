<div id="view-mensal" class="hidden bg-slate-50 rounded-lg shadow border border-slate-200 overflow-hidden flex-1 flex flex-col w-full h-full">
    <div class="bg-white p-1.5 sm:p-2 border-b border-slate-300 flex flex-col md:flex-row justify-between items-center shrink-0 gap-1.5 sm:gap-3 relative z-30 min-h-[42px]">
        <div class="hidden md:block flex-1"></div>
        <div class="flex items-center justify-center gap-1.5 sm:gap-4 z-10 w-full md:w-auto md:absolute md:left-1/2 md:-translate-x-1/2 order-1 md:order-none">
            <button onclick="changeMonth(-1)" class="w-6 h-6 shrink-0 flex items-center justify-center bg-slate-100 hover:bg-slate-200 text-slate-700 rounded border border-slate-200 shadow-sm"><i data-lucide="chevron-left" class="w-3.5 h-3.5"></i></button>
            <div class="text-center flex items-center justify-center gap-1 sm:gap-1.5 uppercase tracking-widest whitespace-nowrap" id="monthly-title">...</div>
            <button onclick="changeMonth(1)" class="w-6 h-6 shrink-0 flex items-center justify-center bg-slate-100 hover:bg-slate-200 text-slate-700 rounded border border-slate-200 shadow-sm"><i data-lucide="chevron-right" class="w-3.5 h-3.5"></i></button>
        </div>
        <div class="flex flex-wrap justify-center md:justify-end shrink-0 gap-x-2 gap-y-1 text-[7.5px] sm:text-[10px] font-bold uppercase items-center px-1 w-full md:w-auto order-2 md:order-none md:flex-1">
            <span class="flex items-center gap-1"><span class="w-3 h-2.5 sm:w-4 sm:h-3 rounded bg-green-500 shadow-sm border border-green-600/50"></span> Plantão (Útil)</span>
            <span class="flex items-center gap-1"><span class="w-3 h-2.5 sm:w-4 sm:h-3 rounded bg-sky-500 shadow-sm border border-sky-600/50"></span> Plantão (FDS/FER)</span>
            <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-full bg-amber-400 shadow-sm border border-white"></span> Plantão compartilhado</span>
            <span class="flex items-center gap-1"><span class="w-3 h-2.5 sm:w-4 sm:h-3 rounded bg-yellow-400 shadow-sm border border-yellow-500/50"></span> Folga</span>
            <span class="flex items-center gap-1"><span class="w-3 h-2.5 sm:w-4 sm:h-3 rounded bg-orange-500 shadow-sm border border-orange-600/50"></span> Férias</span>
            <span class="flex items-center gap-1"><span class="w-3 h-2.5 sm:w-4 sm:h-3 rounded bg-red-500 shadow-sm border border-red-600/50"></span> Atestado</span>
            <span class="flex items-center gap-1"><span class="w-3 h-2.5 sm:w-4 sm:h-3 rounded bg-purple-500 shadow-sm border border-purple-600/50"></span> Licença</span>
        </div>
    </div>
    <div class="w-full flex-1 overflow-auto relative custom-scrollbar bg-white">
        <table class="w-full table-fixed border-collapse min-w-[1200px]"><thead id="monthly-headers" class="sticky top-0 z-20"></thead><tbody class="divide-y divide-slate-200" id="monthly-body"></tbody></table>
    </div>
</div>

<script>
    let currentMonthDate = (() => {
    let h = new Date(), m = h.getMonth(), y = h.getFullYear();
    if (h.getDate() < 15) {
        m--;
        if (m < 0) { m = 11; y--; }
    }
    return new Date(y, m, 15);
})();
    
    function getFeriadosMoveis(ano) {
        let a = ano % 19, b = Math.floor(ano / 100), c = ano % 100;
        let d = Math.floor(b / 4), e = b % 4, f = Math.floor((b + 8) / 25);
        let g = Math.floor((b - f + 1) / 3), h = (19 * a + b - d - g + 15) % 30;
        let i = Math.floor(c / 4), k = c % 4, l = (32 + 2 * e + 2 * i - h - k) % 7;
        let m = Math.floor((a + 11 * h + 22 * l) / 451);
        let mesPascoa = Math.floor((h + l - 7 * m + 114) / 31) - 1;
        let diaPascoa = ((h + l - 7 * m + 114) % 31) + 1;
        
        let pascoa = new Date(ano, mesPascoa, diaPascoa);
        
        let addDias = (dataBase, dias) => {
            let dt = new Date(dataBase); dt.setDate(dt.getDate() + dias);
            return String(dt.getMonth() + 1).padStart(2, '0') + '-' + String(dt.getDate()).padStart(2, '0');
        };

        return {
            [addDias(pascoa, -47)]: { nome: 'Carnaval', tipo: 'Nacional', cor: 'bg-emerald-400' },
            [addDias(pascoa, -2)]: { nome: 'Sexta-feira Santa', tipo: 'Nacional', cor: 'bg-emerald-400' },
            [addDias(pascoa, 60)]: { nome: 'Corpus Christi', tipo: 'Nacional', cor: 'bg-emerald-400' }
        };
    }

    function changeMonth(delta) { 
        currentMonthDate.setMonth(currentMonthDate.getMonth() + delta); 
        renderMonthly(); 
    }

    function escCidNorm(s) { return String(s || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toUpperCase().trim().replace(/\s*[\/-]\s*[A-Z]{2}$/, '').trim(); } // FER_MUNICIPAL_V2

    function renderMonthly() {
        const thead = document.getElementById('monthly-headers'); const tbody = document.getElementById('monthly-body'); 
        if (!thead || !tbody) return;
        
        const ano = currentMonthDate.getFullYear(); const mes = currentMonthDate.getMonth(); 
        let nextMes = mes + 1; let nextAno = ano; if (nextMes > 11) { nextMes = 0; nextAno++; }

        const strDataInicio = `15/${String(mes+1).padStart(2,'0')}/${ano}`;
        const strDataFim = `14/${String(nextMes+1).padStart(2,'0')}/${nextAno}`;
        const nomeCompetencia = nomesMeses[nextMes]; 
        
        document.getElementById('monthly-title').innerHTML = `<span class="text-[8.5px] sm:text-[10px] font-bold text-slate-400">(${strDataInicio})</span> <span class="text-[11px] sm:text-base font-black text-blue-950">${nomeCompetencia}</span> <span class="text-[8.5px] sm:text-[10px] font-bold text-slate-400">(${strDataFim})</span>`;
        
        const diasInfo = [];
        let curDate = new Date(ano, mes, 15); let endDate = new Date(nextAno, nextMes, 14);

        while (curDate <= endDate) {
            let dAno = curDate.getFullYear(); let dMes = curDate.getMonth(); let dDia = curDate.getDate();
            let currentDayOfWeek = curDate.getDay(); let isWeekend = currentDayOfWeek === 0 || currentDayOfWeek === 6;
            let dataStr = `${dAno}-${String(dMes+1).padStart(2, '0')}-${String(dDia).padStart(2, '0')}`;
            let dataStrMMDD = dataStr.substring(5, 10); 
            const isHoje = curDate.toDateString() === simulatedTodayStr;

            let isHoliday = false; let holidayData = null;
            let feriadosMoveis = getFeriadosMoveis(dAno);
            let fMovel = feriadosMoveis[dataStrMMDD];

            if (fMovel) {
                isHoliday = true;
                holidayData = { cor: fMovel.cor, tooltip: `<strong>${fMovel.tipo.toUpperCase()}</strong>: ${fMovel.nome}` };
            } else if (typeof listaFeriadosCache !== 'undefined' && listaFeriadosCache.length > 0) {
                let feriadosDoDia = listaFeriadosCache.filter(f => f.data_feriado && f.data_feriado.substring(5, 10) === dataStrMMDD);
                if (feriadosDoDia.length > 0) {
                    isHoliday = true;
                    let fPrin = feriadosDoDia.find(f => f.tipo === 'nacional') || feriadosDoDia.find(f => f.tipo === 'estadual') || feriadosDoDia[0];
                    let corBg = fPrin.tipo === 'nacional' ? 'bg-emerald-400' : (fPrin.tipo === 'estadual' ? 'bg-blue-400' : 'bg-amber-400');
                    let tooltipText = feriadosDoDia.map(f => `<strong>${f.tipo.toUpperCase()}</strong>: ${f.nome} ${f.localidade ? '('+f.localidade+')' : ''}`).join('<br>');
                    holidayData = { cor: corBg, tooltip: tooltipText };
                }
            } 
            
            // FER_MUNICIPAL_V1: só feriado nacional/estadual (e os móveis) pinta a coluna; o municipal mantém a bolinha e pinta só o sobreaviso de quem é da cidade
            let ferDia = (typeof listaFeriadosCache !== 'undefined' && listaFeriadosCache) ? listaFeriadosCache.filter(f => f.data_feriado && f.data_feriado.substring(5, 10) === dataStrMMDD) : [];
            let isHolidayGeral = !!fMovel || ferDia.some(f => f.tipo !== 'municipal');
            let munCidades = ferDia.filter(f => f.tipo === 'municipal').map(f => escCidNorm(f.localidade));
            diasInfo.push({ dia: dDia, mes: dMes, ano: dAno, isWeekend, isHoliday, isHolidayGeral, munCidades, holidayData, isHoje, dataStr, dayOfWeek: currentDayOfWeek });
            curDate.setDate(curDate.getDate() + 1);
        }

        let topRow = `<tr class="bg-slate-100 border-b border-slate-200"><th rowspan="2" class="p-2 text-left bg-indigo-700 text-white pl-2 sm:pl-3 border-r border-indigo-800 font-bold text-[10px] sm:text-[11px] sticky left-0 z-30 uppercase tracking-wider shadow-sm w-[140px] sm:w-[340px] min-w-[140px] sm:min-w-[340px] max-w-[140px] sm:max-w-[340px] whitespace-nowrap overflow-hidden text-ellipsis">COLABORADOR / ÁREA</th>`;
        let botRow = `<tr class="bg-white border-b border-slate-300 shadow-sm">`;
        const colWidthPercent = 100 / diasInfo.length;

        diasInfo.forEach(d => {
            let topClasses = "p-1 text-center font-bold text-[8px] sm:text-[9px] ";
            let botClasses = "p-1 text-center font-black text-[10px] sm:text-[11px] relative ";
            if (d.isHoje) { topClasses += "bg-green-700 text-white border-l border-green-800"; botClasses += "hoje-col-header"; } 
            else if (d.isWeekend || d.isHolidayGeral) { topClasses += "bg-indigo-700 text-white border-l border-indigo-600/50"; botClasses += "bg-indigo-700 text-white border-l border-indigo-600/50 shadow-sm"; } 
            else { topClasses += "bg-slate-100 text-slate-700 border-l border-slate-200"; botClasses += "bg-white text-slate-800 border-l border-slate-200"; }

            topRow += `<th class="${topClasses}" style="width: ${colWidthPercent}%;">${['DOM', 'SEG', 'TER', 'QUA', 'QUI', 'SEX', 'SAB'][d.dayOfWeek]}</th>`;
            if (d.isHoliday) {
                botRow += `<th class="${botClasses} group cursor-pointer">
                    <div class="absolute inset-0 m-auto w-5 h-5 sm:w-6 sm:h-6 flex items-center justify-center">
                        <span class="absolute inline-flex h-full w-full rounded-full ${d.holidayData.cor} opacity-75 animate-ping"></span>
                        <span class="relative inline-flex rounded-full h-full w-full ${d.holidayData.cor} shadow-sm border border-black/10"></span>
                    </div>
                    <div class="hidden group-hover:block absolute top-full left-1/2 -translate-x-1/2 mt-1 bg-slate-800 text-white text-[10px] p-1.5 rounded shadow-xl whitespace-nowrap z-[100] text-left leading-tight font-normal">
                        ${d.holidayData.tooltip}
                        <div class="absolute -top-1 left-1/2 -translate-x-1/2 w-2 h-2 bg-slate-800 rotate-45"></div>
                    </div>
                    <span class="relative z-10 text-slate-900 drop-shadow-md font-black">${String(d.dia).padStart(2, '0')}</span>
                </th>`;
            } else {
                botRow += `<th class="${botClasses}">${String(d.dia).padStart(2, '0')}</th>`;
            }
        });
        topRow += `</tr>`; botRow += `</tr>`;
        thead.innerHTML = topRow + botRow;

        let bodyHTML = ''; let tecnicosParaExibir = getOrderedAndFilteredTecnicos(); tecnicosParaExibir.sort((a, b) => a.localeCompare(b, 'pt-BR'));
        const arrGerenciaveis = setoresGerenciaveisLogin ? setoresGerenciaveisLogin.split(',').map(s=>s.trim().toUpperCase()) : null;

        if (tecnicosParaExibir.length === 0) {
            bodyHTML = `<tr><td colspan="${diasInfo.length + 1}" class="text-center py-8 text-slate-500 text-xs font-bold">Nenhum colaborador encontrado para os filtros ativos.</td></tr>`;
        } else {
            tecnicosParaExibir.forEach((nome) => {
                const funcData = tecnicosDB.find(t => t.nome === nome) || { local_base: '', localidade: '', setor: '', telefone: '' };
                let podeEditar = false;
                if (currentAccess === 'mestre') podeEditar = true;
                else if (currentAccess === 'admin') { if (!arrGerenciaveis) podeEditar = true; else if (arrGerenciaveis.includes(funcData.setor.toUpperCase())) podeEditar = true; }
                let telContato = 'S/ NÚMERO';
                if (funcData.telefone) { let telLimpo = String(funcData.telefone).replace(/\D/g, ''); if (telLimpo.startsWith('55') && (telLimpo.length === 13 || telLimpo.length === 12)) { telLimpo = telLimpo.substring(2); } if (telLimpo.length === 11) { telContato = `(${telLimpo.substring(0,2)}) ${telLimpo.substring(2,7)}-${telLimpo.substring(7,11)}`; } else if (telLimpo.length === 10) { telContato = `(${telLimpo.substring(0,2)}) ${telLimpo.substring(2,6)}-${telLimpo.substring(6,10)}`; } else { telContato = funcData.telefone; } }

                bodyHTML += `<tr class="border-b border-slate-200 hover:bg-slate-50">
                    <td class="p-1 sm:p-2 bg-white border-r border-slate-300 pl-2 sm:pl-3 sticky left-0 z-10 overflow-hidden whitespace-nowrap w-[140px] sm:w-[340px] min-w-[140px] sm:min-w-[340px] max-w-[140px] sm:max-w-[340px]">
                        <div class="flex flex-col justify-center w-full pr-1 sm:pr-3">
                            <div class="text-scale-nome font-black text-slate-800 uppercase flex flex-col sm:flex-row sm:items-center justify-start sm:justify-between w-full overflow-hidden"><span class="text-left truncate w-full sm:w-auto text-ellipsis">${nome}</span><span class="text-slate-300 font-light px-2 shrink-0 hidden sm:block">|</span><span class="text-left sm:text-right shrink-0 text-[8.5px] sm:text-[10px] mt-0.5 sm:mt-0 text-slate-600 sm:text-slate-800 tracking-tight">${telContato}</span></div>
                            <div class="text-[8px] sm:text-[9px] text-slate-500 font-semibold uppercase mt-0.5 text-left truncate w-full text-ellipsis">${funcData.local_base || ''} <span class="mx-0.5">|</span> ${funcData.localidade || ''} <span class="mx-0.5">|</span> ${funcData.setor || ''}${(typeof setoresDo === 'function' && setoresDo(funcData).length > 1) ? ' <span class="text-indigo-600" title="Também atende">+ ' + setoresDo(funcData).slice(1).join(' + ') + '</span>' : ''}</div>
                        </div>
                    </td>`;

                diasInfo.forEach(d => {
                    const cellData = plantaoMensalDados[nome]?.[d.dataStr]; 
                    let statusEscala = (typeof cellData === 'object' && cellData !== null) ? cellData.status : cellData;
                    let anexoData = (typeof cellData === 'object' && cellData !== null) ? cellData.anexo : null;

                    let visualData = getStatusVisuals(statusEscala, d.isWeekend || d.isHolidayGeral || d.munCidades.includes(escCidNorm(funcData.localidade))); 
                    let hasAnexoMarker = anexoData ? `<div class="absolute -top-1 -right-1 w-2 h-2 bg-red-500 rounded-full border border-white"></div>` : '';
                    if (typeof marcadorHoraExtra === 'function') hasAnexoMarker += marcadorHoraExtra(nome, d.dataStr); // HE_PATCH_V1 DASH_PATCH_V1
                    if (typeof soMarcadorPontual === 'function') hasAnexoMarker += soMarcadorPontual(cellData); // SO_FERRAMENTAS_V1
                    const sobrepos = (typeof listarSobreposicoesPlantao === 'function') ? listarSobreposicoesPlantao(nome, d.dataStr) : null;
                    const avisoTitulo = sobrepos ? ((sobrepos.limite ? 'Acima do limite de 2 técnicos no dia. Também de plantão: ' : 'Divide o plantão neste dia com: ') + sobrepos.nomes.join(', ')).replace(/"/g, '&quot;') : '';
                    const tituloPonto = (cellData && cellData.origem === 'ponto') ? ('Abono aprovado no Ponto: ' + String(cellData.motivo || '').replace(/"/g, '&quot;')) : '';
                    const tituloFinal = [avisoTitulo, tituloPonto, (typeof soTituloPontual === 'function' ? soTituloPontual(cellData) : '')].filter(Boolean).join(' | ');
                    const atrTitulo = tituloFinal ? `title="${tituloFinal}"` : '';
                    const marcadorSobrepos = sobrepos ? `<div class="absolute -top-1 -left-1 w-2.5 h-2.5 bg-amber-400 rounded-full border border-white shadow" title="${avisoTitulo}"></div>` : '';
                    
                    bodyHTML += `<td class="relative p-[1px] align-middle ${d.isHoje?'hoje-col-body':'border-l border-slate-100'} ${podeEditar?'cursor-pointer hover:bg-slate-200':''}" onclick="openCellMenu(event, '${nome}', '${d.dataStr}')"><div class="w-full flex justify-center items-center h-6 sm:h-7 relative">${visualData ? `<div ${atrTitulo} class="w-[80%] h-[78%] rounded ${visualData.bg} border${visualData.border} shadow-sm transition-transform hover:scale-105 relative">${hasAnexoMarker}${marcadorSobrepos}</div>` : (hasAnexoMarker ? `<div class="w-[80%] h-[78%] rounded relative">${hasAnexoMarker}</div>` : '')}</div></td>`;
                });
                bodyHTML += `</tr>`;
            });
        }
        tbody.innerHTML = bodyHTML; lucide.createIcons();
    }
</script>
