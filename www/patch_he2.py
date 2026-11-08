#!/usr/bin/env python3
# Atualização 2 (hora extra): editar hora extra, detalhe no dashboard com link VIDPOL, períodos sem vigência + edição.
# Requer o patch anterior (HE_PATCH_V1) já aplicado e os novos hora_extra.php e gestao.js já gravados. Rode em /opt/escala/www
import sys, os, shutil

BASE = os.environ.get('ESCALA_DIR', '/opt/escala/www')

def ler(n):
    with open(os.path.join(BASE, n), encoding='utf-8') as f: return f.read()

idx, api = ler('index.php'), ler('api_escala.php')
if 'HE_PATCH_V1' not in api or 'HE_PATCH_V1' not in idx: sys.exit('ABORTADO: o patch anterior (HE_PATCH_V1) não está aplicado.')
if 'gestao.js?v=2' in idx or 'HE_PATCH_V2' in api: sys.exit('ABORTADO: esta atualização já foi aplicada.')
if 'function heEditar' not in ler('hora_extra.php') or 'editarPeriodoSobreaviso' not in ler('gestao.js'):
    sys.exit('ABORTADO: grave primeiro os novos hora_extra.php e gestao.js (heredocs do instalador).')

def unico(txt, ancora, nome):
    c = txt.count(ancora)
    if c != 1: sys.exit(f'ABORTADO (nada foi alterado): âncora "{nome}" encontrada {c}x em vez de 1.')
    return ancora

# ---------- api_escala.php ----------
ini = unico(api, "if ($action === 'adicionar_periodo_sobreaviso' && $_SERVER['REQUEST_METHOD'] === 'POST') {", 'rota adicionar_periodo')
fim = unico(api, "if ($action === 'remover_periodo_sobreaviso' && $_SERVER['REQUEST_METHOD'] === 'POST') {", 'rota remover_periodo')
i, f = api.index(ini), api.index(fim)
if not i < f: sys.exit('ABORTADO (nada foi alterado): ordem das rotas inesperada.')
novas = r"""// HE_PATCH_V2
if ($action === 'adicionar_periodo_sobreaviso' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    heExigeGestor();
    $in = heEntrada();
    try {
        $r = pgAdicionar($pdo, $in['tipo'] ?? '', $in['dia_inicio'] ?? 0, $in['hora_inicio'] ?? '', $in['dia_fim'] ?? 0, $in['hora_fim'] ?? '', $usuarioLogado);
        if (!$r['ok']) { echo json_encode(['sucesso' => false, 'erro' => $r['erro']]); exit; }
        registrarAuditoria($pdo, $usuarioLogado, 'PERÍODO SOBREAVISO', "Adicionou período {$in['tipo']}: {$in['hora_inicio']} (+{$in['dia_inicio']}d) até {$in['hora_fim']} (+{$in['dia_fim']}d)");
        echo json_encode(['sucesso' => true, 'id' => $r['id']]);
    } catch (Exception $e) { echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]); }
    exit;
}
if ($action === 'editar_periodo_sobreaviso' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    heExigeGestor();
    $in = heEntrada();
    try {
        $r = pgEditar($pdo, $in['id'] ?? 0, $in['tipo'] ?? '', $in['dia_inicio'] ?? 0, $in['hora_inicio'] ?? '', $in['dia_fim'] ?? 0, $in['hora_fim'] ?? '', $usuarioLogado);
        if (!$r['ok']) { echo json_encode(['sucesso' => false, 'erro' => $r['erro']]); exit; }
        $x = $r['anterior'];
        registrarAuditoria($pdo, $usuarioLogado, 'PERÍODO SOBREAVISO', "Editou período {$x['tipo']} {$x['hora_inicio']}-{$x['hora_fim']} para {$in['tipo']} {$in['hora_inicio']} (+{$in['dia_inicio']}d) até {$in['hora_fim']} (+{$in['dia_fim']}d)");
        echo json_encode(['sucesso' => true]);
    } catch (Exception $e) { echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]); }
    exit;
}
if ($action === 'editar_hora_extra' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    heExigeGestor();
    $in = heEntrada();
    try {
        $r = heEditar($pdo, $in['id'] ?? 0, $in['hora_inicio'] ?? '', $in['hora_fim'] ?? '', $in['vidpol'] ?? '', $in['dia_inicio'] ?? 0, $usuarioLogado);
        if (!$r['ok']) { echo json_encode(['sucesso' => false, 'erro' => $r['erro']]); exit; }
        $x = $r['anterior'];
        registrarAuditoria($pdo, $usuarioLogado, 'HORA EXTRA', "Editou hora extra de {$x['nome_colaborador']} em {$x['d']}: {$x['hora_inicio']}-{$x['hora_fim']} (VIDPOL " . trim($x['vidpol']) . ") para {$in['hora_inicio']}-{$in['hora_fim']} (VIDPOL {$in['vidpol']})");
        echo json_encode(['sucesso' => true, 'minutos' => $r['minutos']]);
    } catch (Exception $e) { echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]); }
    exit;
}
"""
api = api[:i] + novas + api[f:]

# ---------- index.php ----------
lbl = unico(idx, '''                        <label class="text-[10px] font-bold text-slate-600 uppercase">Vigência (a partir de)
                            <input type="date" id="per-vig" class="mt-1 w-full border rounded px-2 py-1.5 text-xs font-bold bg-white"></label>
''', 'campo Vigência')
idx = idx.replace(lbl, '')
idx = idx.replace(unico(idx, 'md:grid-cols-7', 'grade 7 colunas'), 'md:grid-cols-6')
btn = unico(idx, '''<button type="button" onclick="adicionarPeriodoSobreaviso()" class="px-3 py-2 bg-amber-500 hover:bg-amber-600 text-white rounded text-xs font-bold shadow-sm">Adicionar período</button>''', 'botão adicionar período')
idx = idx.replace(btn, '''<div class="flex gap-1"><button type="button" id="per-salvar" onclick="adicionarPeriodoSobreaviso()" class="flex-1 px-3 py-2 bg-amber-500 hover:bg-amber-600 text-white rounded text-xs font-bold shadow-sm">Adicionar período</button><button type="button" id="per-cancelar" onclick="cancelarEdicaoPeriodo()" class="hidden px-2 py-2 bg-white border border-amber-300 text-amber-700 hover:bg-amber-100 rounded text-xs font-bold">Cancelar</button></div>''')
idx = idx.replace(unico(idx, '<th class="text-left px-2 py-1">Vigência</th>', 'cabeçalho Vigência'), '')
idx = idx.replace(unico(idx, 'gestao.js?v=1', 'script gestao.js'), 'gestao.js?v=2')

# ---------- grava (backups primeiro; se algo falhar, restaura) ----------
novos = {'index.php': idx, 'api_escala.php': api}
backups = {}
for n in novos:
    p = os.path.join(BASE, n); k = 12
    while os.path.exists(f'{p}.bak{k}'): k += 1
    shutil.copy2(p, f'{p}.bak{k}'); backups[n] = f'{p}.bak{k}'
try:
    for n, t in novos.items():
        with open(os.path.join(BASE, n), 'w', encoding='utf-8') as f: f.write(t)
except Exception as e:
    for n, b in backups.items(): shutil.copy2(b, os.path.join(BASE, n))
    sys.exit(f'ERRO ao gravar ({e}); arquivos restaurados dos backups.')
for n, b in backups.items(): sys.stdout.write(f'ok  {n}  (backup: {os.path.basename(b)})\n')
sys.stdout.write('Atualização aplicada. Valide com php -l e dê Ctrl+F5.\n')
