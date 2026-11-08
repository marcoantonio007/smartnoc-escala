<div id="view-ranking" class="flex-1 w-full h-full bg-slate-50 flex flex-col hidden overflow-hidden">
    <div class="w-full flex-1 overflow-auto custom-scrollbar p-4 sm:p-8">
        <div class="max-w-5xl mx-auto">
            
            <!-- Título e Período -->
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-8 gap-4 mt-2">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-indigo-600 rounded-lg flex items-center justify-center shadow-md">
                        <i data-lucide="bar-chart-2" class="w-6 h-6 text-white"></i>
                    </div>
                    <h1 class="text-xl sm:text-2xl font-black text-slate-800 tracking-tight uppercase">Ranking de Horas de Sobreaviso</h1>
                </div>
                <div class="bg-white border border-slate-200 px-4 py-2 rounded-full text-xs sm:text-sm font-semibold text-slate-600 shadow-sm flex items-center gap-2">
                    <i data-lucide="calendar" class="w-4 h-4 text-slate-400"></i>
                    Período: <span id="ranking-periodo">...</span>
                </div>
            </div>

            <!-- Tabela Principal -->
            <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
                <div class="grid grid-cols-12 gap-4 px-6 py-4 bg-slate-50 border-b border-slate-200 text-[11px] sm:text-xs font-bold text-slate-500 uppercase tracking-wider">
                    <div class="col-span-2 sm:col-span-1 text-center">POS</div>
                    <div class="col-span-6 sm:col-span-7">COLABORADOR</div>
                    <div class="col-span-2 text-center">DIAS ATIVOS</div>
                    <div class="col-span-2 text-right">TOTAL HORAS</div>
                </div>
                <!-- Lista de Itens -->
                <div id="ranking-list-container" class="divide-y divide-slate-100 bg-white"></div>
            </div>
            
        </div>
    </div>
</div>

<script>
    function renderRankingScreen() {
        const container = document.getElementById('ranking-list-container');
        if (!container) return;

        // Pega as datas da view mensal para calcular competência corretamente
        let dt = typeof currentMonthDate !== 'undefined' ? currentMonthDate : new Date();
        const startAno = dt.getFullYear(); 
        const startMes = dt.getMonth(); 
        let endAno = startAno; 
        let endMes = startMes + 1; 
        if (endMes > 11) { endMes = 0; endAno++; }
        
        const startDateStr = `${startAno}-${String(startMes+1).padStart(2,'0')}-15`; 
        const endDateStr = `${endAno}-${String(endMes+1).padStart(2,'0')}-14`;
        const nomeMesesList = typeof nomesMeses !== 'undefined' ? nomesMeses : ['JANEIRO','FEVEREIRO','MARÇO','ABRIL','MAIO','JUNHO','JULHO','AGOSTO','SETEMBRO','OUTUBRO','NOVEMBRO','DEZEMBRO'];
        
        document.getElementById('ranking-periodo').innerText = `${nomeMesesList[endMes]} / ${endAno} (15/${String(startMes+1).padStart(2,'0')} a 14/${String(endMes+1).padStart(2,'0')})`;

        let listaRank = [];
        if (typeof tecnicosDB !== 'undefined' && typeof plantaoMensalDados !== 'undefined') {
            tecnicosDB.forEach(t => {
                let dias = 0; let horasTotais = 0;
                if (plantaoMensalDados[t.nome]) {
                    for (const [dataStr, dado] of Object.entries(plantaoMensalDados[t.nome])) {
                        let st = typeof dado === 'object' ? dado.status : dado;
                        if (dataStr >= startDateStr && dataStr <= endDateStr && st && st.startsWith('plantao')) {
                            dias++;
                            horasTotais += (typeof getHorasParaData === 'function' ? getHorasParaData(dataStr) : 0);
                        }
                    }
                }
                if (dias > 0) listaRank.push({ nome: t.nome.split(' ')[0], fullName: t.nome, dias, horasTotais });
            });
        }

        // Ordena por quantidade de horas
        listaRank.sort((a, b) => b.horasTotais - a.horasTotais);
        
        let html = '';
        if (listaRank.length === 0) {
            html = '<div class="p-8 text-center text-slate-400 font-bold text-sm">Nenhum plantão registrado neste período.</div>';
        } else {
            listaRank.forEach((item, index) => {
                const pos = index + 1;
                let badgeClass = 'bg-white text-slate-500 border-slate-200';
                
                // Cores do Top 3
                if (pos === 1) badgeClass = 'bg-amber-100 text-amber-700 border-amber-300';
                else if (pos === 2) badgeClass = 'bg-slate-100 text-slate-600 border-slate-300';
                else if (pos === 3) badgeClass = 'bg-orange-50 text-orange-700 border-orange-200';

                html += `
                <div class="grid grid-cols-12 gap-4 px-6 py-4 items-center hover:bg-slate-50 transition-colors">
                    <div class="col-span-2 sm:col-span-1 flex justify-center">
                        <span class="w-8 h-8 flex items-center justify-center font-black rounded-md text-sm shadow-sm border ${badgeClass}">#${pos}</span>
                    </div>
                    <div class="col-span-6 sm:col-span-7">
                        <div class="text-sm font-black text-slate-800 uppercase leading-tight">${item.fullName}</div>
                        <div class="text-[9px] sm:text-[10px] text-slate-400 font-bold uppercase mt-1 truncate">${item.fullName}</div>
                    </div>
                    <div class="col-span-2 text-center">
                        <span class="text-sm font-bold text-slate-600">${item.dias} dias</span>
                    </div>
                    <div class="col-span-2 flex justify-end">
                        <span class="bg-blue-600 text-white px-3 py-1.5 rounded-lg text-sm font-black shadow-sm text-center min-w-[70px]">${item.horas} h</span>
                    </div>
                </div>`;
            });
        }
        
        container.innerHTML = html;
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }
</script>
