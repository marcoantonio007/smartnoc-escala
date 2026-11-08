#!/usr/bin/env python3
# Patch: hora extra / ticket extra / dashboard gerencial / períodos de sobreaviso. Rode em /opt/escala/www
import sys, os, shutil

BASE = os.environ.get('ESCALA_DIR', '/opt/escala/www')
MARK = 'HE_PATCH_V1'

def ler(n):
    with open(os.path.join(BASE, n), encoding='utf-8') as f: return f.read()

def unico(txt, ancora, nome):
    c = txt.count(ancora)
    if c != 1:
        sys.exit(f'ABORTADO (nada foi alterado): âncora "{nome}" encontrada {c}x em vez de 1.')
    return ancora

idx, api, men = ler('index.php'), ler('api_escala.php'), ler('mensal.php')
if all(MARK in t for t in (idx, api, men)):
    print('Patch 1 (hora extra) já estava aplicado - nada a fazer nesta etapa.'); sys.exit(0)
for n, t in (('index.php', idx), ('api_escala.php', api), ('mensal.php', men)):
    if MARK in t: sys.exit(f'ABORTADO: {n} está parcialmente alterado (restaure os backups .bak11 e rode de novo).')
for n in ('hora_extra.php', 'gestao.js'):
    if not os.path.exists(os.path.join(BASE, n)): sys.exit(f'ABORTADO: falta {n} em {BASE} (crie-o antes).')

# ---------- index.php ----------
a1 = unico(idx, '''        <div class="px-2 py-1 text-[8px] text-slate-400 font-bold uppercase bg-slate-50 border-y mt-1">Ausências</div>''', 'cabeçalho Ausências')
idx = idx.replace(a1, '''        <div id="btn-hora-extra" onclick="abrirHoraExtra(targetCellData.nome, targetCellData.dataStr)" class="hidden px-3 py-1.5 hover:bg-slate-100 cursor-pointer flex items-center gap-2 border-t border-slate-100 mt-1"><div class="w-2.5 h-1 rounded bg-yellow-400 shadow-sm"></div>Hora Extra</div>
''' + a1)

lin = [l for l in idx.split('\n') if "onclick=\"switchView('dashboard')\" class=\"p-1.5" in l]
if len(lin) != 1: sys.exit(f'ABORTADO (nada foi alterado): botão do ranking encontrado {len(lin)}x.')
idx = idx.replace(lin[0], lin[0] + '''
                    <button id="btn-gestao" onclick="switchView('gestao')" class="p-1.5 bg-amber-500 hover:bg-amber-400 text-white rounded transition-colors shadow-sm" title="Dashboard Gerencial (sobreaviso, hora extra e tickets)"><i data-lucide="layout-dashboard" class="w-4 h-4"></i></button>''')

lin2 = [l for l in idx.split('\n') if "onclick=\"switchView('dashboard'); toggleMobileMenu();\"" in l]
if len(lin2) != 1: sys.exit(f'ABORTADO (nada foi alterado): botão mobile do ranking encontrado {len(lin2)}x.')
idx = idx.replace(lin2[0], lin2[0] + '''
                <button onclick="switchView('gestao'); toggleMobileMenu();" class="flex items-center justify-center gap-2 p-2 bg-amber-500 hover:bg-amber-400 text-white rounded text-xs font-bold"><i data-lucide="layout-dashboard" class="w-3.5 h-3.5"></i> Gerencial</button>''')

a3 = unico(idx, '''            <div class="flex-1 overflow-y-auto custom-scrollbar pr-2 space-y-6">
                <div class="bg-slate-50 p-4 rounded border flex flex-col lg:flex-row gap-6 shrink-0">''', 'corpo do modal de config')
painel = '''            <div class="flex-1 overflow-y-auto custom-scrollbar pr-2 space-y-6">
                <!-- HE_PATCH_V1: períodos de sobreaviso -->
                <div class="bg-amber-50 p-4 rounded border border-amber-200 shrink-0">
                    <h3 class="text-sm font-bold text-slate-700 mb-3 border-b border-amber-200 pb-1"><i data-lucide="alarm-clock" class="inline w-4 h-4 mr-1"></i> Períodos de Sobreaviso</h3>
                    <div id="periodos-resumo" class="grid grid-cols-1 md:grid-cols-3 gap-2 mb-3"></div>
                    <div class="grid grid-cols-2 md:grid-cols-7 gap-2 items-end">
                        <label class="text-[10px] font-bold text-slate-600 uppercase">Tipo de dia
                            <select id="per-tipo" class="mt-1 w-full border rounded px-2 py-1.5 text-xs font-bold bg-white"><option value="SEMANA">Semana (seg–sex)</option><option value="FIM_DE_SEMANA">Final de semana</option><option value="FERIADO">Feriado</option></select></label>
                        <label class="text-[10px] font-bold text-slate-600 uppercase">Vigência (a partir de)
                            <input type="date" id="per-vig" class="mt-1 w-full border rounded px-2 py-1.5 text-xs font-bold bg-white"></label>
                        <label class="text-[10px] font-bold text-slate-600 uppercase">Início - dia
                            <select id="per-di" class="mt-1 w-full border rounded px-2 py-1.5 text-xs font-bold bg-white"><option value="0">Mesmo dia</option><option value="1">+1 dia</option><option value="2">+2 dias</option></select></label>
                        <label class="text-[10px] font-bold text-slate-600 uppercase">Início - hora
                            <input type="time" id="per-hi" value="22:00" class="mt-1 w-full border rounded px-2 py-1.5 text-xs font-bold bg-white"></label>
                        <label class="text-[10px] font-bold text-slate-600 uppercase">Fim - dia
                            <select id="per-df" class="mt-1 w-full border rounded px-2 py-1.5 text-xs font-bold bg-white"><option value="0">Mesmo dia</option><option value="1" selected>+1 dia</option><option value="2">+2 dias</option></select></label>
                        <label class="text-[10px] font-bold text-slate-600 uppercase">Fim - hora
                            <input type="time" id="per-hf" value="06:00" class="mt-1 w-full border rounded px-2 py-1.5 text-xs font-bold bg-white"></label>
                        <button type="button" onclick="adicionarPeriodoSobreaviso()" class="px-3 py-2 bg-amber-500 hover:bg-amber-600 text-white rounded text-xs font-bold shadow-sm">Adicionar período</button>
                    </div>
                    <div class="mt-3 max-h-48 overflow-y-auto bg-white border rounded">
                        <table class="w-full text-xs"><thead class="bg-slate-50 text-[10px] uppercase text-slate-500"><tr><th class="text-left px-2 py-1">Tipo</th><th class="text-left px-2 py-1">Vigência</th><th class="text-left px-2 py-1">Período</th><th class="text-right px-2 py-1">Duração</th><th></th></tr></thead><tbody id="periodos-tabela"></tbody></table>
                    </div>
                    <p id="periodos-dica" class="mt-2 text-[10px] text-slate-500 font-semibold"></p>
                </div>
                <div class="bg-slate-50 p-4 rounded border flex flex-col lg:flex-row gap-6 shrink-0">'''
idx = idx.replace(a3, painel)

a4 = unico(idx, '</body>', '</body>')
idx = idx.replace(a4, '<script src="gestao.js?v=1"></script>\n' + a4)

# ---------- mensal.php ----------
lin3 = [l for l in men.split('\n') if 'let hasAnexoMarker = anexoData' in l]
if len(lin3) != 1: sys.exit(f'ABORTADO (nada foi alterado): hasAnexoMarker encontrado {len(lin3)}x em mensal.php.')
men = men.replace(lin3[0], lin3[0] + "\n                    if (typeof marcadorHoraExtra === 'function') hasAnexoMarker += marcadorHoraExtra(nome, d.dataStr); // HE_PATCH_V1")

# ---------- api_escala.php ----------
a5 = unico(api, "require_once 'conexao.php';", "require conexao")
api = api.replace(a5, a5 + "\nrequire_once __DIR__ . '/hora_extra.php'; // HE_PATCH_V1\nif ($pdo) { try { heGarantirTabelas($pdo); } catch (Exception $e) {} }", 1)

a6 = unico(api, "function inserirPlantao($pdo, $nome, $dataStr, $status, $anexoUrl, $feriadosDB) {\n    $stmtDel = $pdo->prepare(\"DELETE FROM escala_dados WHERE nome_colaborador = :nome AND data_escala = :data\");\n    $stmtDel->execute(['nome' => $nome, 'data' => $dataStr]);\n",
           'início de inserirPlantao')
api = api.replace(a6, a6 + """
    // HE_PATCH_V1: sem sobreaviso no dia => hora extra do dia deixa de existir
    if ($status === null || $status === 'limpar' || !str_starts_with($status, 'plantao')) {
        try { $pdo->prepare("DELETE FROM horas_extras WHERE nome_colaborador = :n AND data_escala = :d")->execute(['n' => $nome, 'd' => $dataStr]); } catch (Exception $e) {}
    }
""")

ini = api.index("    if (str_starts_with($status, 'plantao')) {\n        $timestamp = strtotime($dataStr);")
fim = api.index("    } else {\n        $stmtIns->execute([\n            'nome' => $nome, 'data' => $dataStr, 'status' => $status, \n            'horas' => 0.00")
if not (ini < fim): sys.exit('ABORTADO (nada foi alterado): bloco de plantão em inserirPlantao não reconhecido.')
novo_bloco = """    if (str_starts_with($status, 'plantao')) {
        // HE_PATCH_V1: horários vêm dos períodos de sobreaviso configurados (Painel de Administração)
        $segmentos = pgSegmentosPlantao($pdo, $dataStr, $feriadosDB);
        foreach ($segmentos as $sg) {
            $stmtIns->execute([
                'nome' => $nome, 'data' => $dataStr, 'status' => $status,
                'horas' => $sg['horas'], 'anexo' => $anexoUrl,
                'd_ini' => $sg['d_ini'], 'h_ini' => $sg['h_ini'],
                'd_fim' => $sg['d_fim'], 'h_fim' => $sg['h_fim']
            ]);
        }
"""
api = api[:ini] + novo_bloco + api[fim:]

a7 = unico(api, "if ($action === 'get_config') {", 'get_config')
rotas = r"""// ===== HE_PATCH_V1: hora extra e períodos de sobreaviso =====
function heExigeGestor() {
    if (!heEhGestor()) { http_response_code(403); echo json_encode(['sucesso' => false, 'erro' => 'Acesso restrito a gestores.']); exit; }
}
function heEntrada() { return json_decode(file_get_contents('php://input'), true) ?: []; }

if ($action === 'get_horas_extras') {
    heExigeGestor();
    try { echo json_encode(['sucesso' => true, 'itens' => heListar($pdo)]); }
    catch (Exception $e) { echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]); }
    exit;
}
if ($action === 'adicionar_hora_extra' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    heExigeGestor();
    $in = heEntrada();
    try {
        $r = heAdicionar($pdo, $in['nome'] ?? '', $in['data'] ?? '', $in['hora_inicio'] ?? '', $in['hora_fim'] ?? '', $in['vidpol'] ?? '', $usuarioLogado, $in['dia_inicio'] ?? 0);
        if (!$r['ok']) { echo json_encode(['sucesso' => false, 'erro' => $r['erro']]); exit; }
        registrarAuditoria($pdo, $usuarioLogado, 'HORA EXTRA', "Registrou hora extra de {$in['nome']} em {$in['data']} ({$in['hora_inicio']}-{$in['hora_fim']}" . (!empty($in['dia_inicio']) ? ', madrugada seguinte' : '') . ", VIDPOL {$in['vidpol']})");
        echo json_encode(['sucesso' => true, 'id' => $r['id'], 'minutos' => $r['minutos']]);
    } catch (Exception $e) { echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]); }
    exit;
}
if ($action === 'remover_hora_extra' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    heExigeGestor();
    $in = heEntrada();
    try {
        $r = heRemover($pdo, $in['id'] ?? 0);
        if (!$r) { echo json_encode(['sucesso' => false, 'erro' => 'Registro não encontrado.']); exit; }
        registrarAuditoria($pdo, $usuarioLogado, 'HORA EXTRA', "Excluiu hora extra de {$r['nome_colaborador']} em {$r['d']} ({$r['hora_inicio']}-{$r['hora_fim']}, VIDPOL " . trim($r['vidpol']) . ")");
        echo json_encode(['sucesso' => true]);
    } catch (Exception $e) { echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]); }
    exit;
}
if ($action === 'get_periodos_sobreaviso') {
    heExigeGestor();
    try { echo json_encode(['sucesso' => true, 'itens' => pgListar($pdo)]); }
    catch (Exception $e) { echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]); }
    exit;
}
if ($action === 'adicionar_periodo_sobreaviso' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    heExigeGestor();
    $in = heEntrada();
    try {
        $r = pgAdicionar($pdo, $in['tipo'] ?? '', $in['vigencia'] ?? '', $in['dia_inicio'] ?? 0, $in['hora_inicio'] ?? '', $in['dia_fim'] ?? 0, $in['hora_fim'] ?? '', $usuarioLogado);
        if (!$r['ok']) { echo json_encode(['sucesso' => false, 'erro' => $r['erro']]); exit; }
        registrarAuditoria($pdo, $usuarioLogado, 'PERÍODO SOBREAVISO', "Adicionou período {$in['tipo']} a partir de {$in['vigencia']}: {$in['hora_inicio']} (+{$in['dia_inicio']}d) até {$in['hora_fim']} (+{$in['dia_fim']}d)");
        echo json_encode(['sucesso' => true, 'id' => $r['id']]);
    } catch (Exception $e) { echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]); }
    exit;
}
if ($action === 'remover_periodo_sobreaviso' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    heExigeGestor();
    $in = heEntrada();
    try {
        $r = pgRemover($pdo, $in['id'] ?? 0);
        if (!$r['ok']) { echo json_encode(['sucesso' => false, 'erro' => $r['erro']]); exit; }
        $x = $r['removido'];
        registrarAuditoria($pdo, $usuarioLogado, 'PERÍODO SOBREAVISO', "Removeu período {$x['tipo']} (vigência {$x['vigencia']}): {$x['hora_inicio']}-{$x['hora_fim']}");
        echo json_encode(['sucesso' => true]);
    } catch (Exception $e) { echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]); }
    exit;
}

"""
api = api.replace(a7, rotas + a7)

# ---------- grava (backups primeiro; se algo falhar, restaura tudo) ----------
novos = {'index.php': idx, 'mensal.php': men, 'api_escala.php': api}
backups = {}
for n in novos:
    p = os.path.join(BASE, n); k = 11
    while os.path.exists(f'{p}.bak{k}'): k += 1
    shutil.copy2(p, f'{p}.bak{k}'); backups[n] = f'{p}.bak{k}'
try:
    for n, t in novos.items():
        with open(os.path.join(BASE, n), 'w', encoding='utf-8') as f: f.write(t)
except Exception as e:
    for n, b in backups.items(): shutil.copy2(b, os.path.join(BASE, n))
    sys.exit(f'ERRO ao gravar ({e}); arquivos restaurados dos backups.')
for n, b in backups.items(): sys.stdout.write(f'ok  {n}  (backup: {os.path.basename(b)})\n')
sys.stdout.write('Patch aplicado. Valide com: php -l em index.php, mensal.php, api_escala.php e hora_extra.php; depois Ctrl+F5.\n')
