<?php
ini_set('session.gc_maxlifetime', '43200');
session_start();
require_once 'conexao.php';
require_once __DIR__ . '/sessao_inativ.php'; // SESSAO_INATIV_V1
require_once __DIR__ . '/hora_extra.php'; // HE_PATCH_V1
if ($pdo) { try { heGarantirTabelas($pdo); } catch (Exception $e) {} }
require_once __DIR__ . '/email_colab.php'; // EMAIL_COLAB_V1
if ($pdo && isset($_SESSION['usuario'])) { try { escEmailGarantir($pdo); } catch (Exception $e) {} }
if ($pdo && isset($_SESSION['usuario'])) { try { escRecalcularMunicipaisSeMudou($pdo); } catch (Exception $e) { error_log('escala: recálculo feriado municipal: ' . $e->getMessage()); } } // FER_MUNICIPAL_V2

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario'])) {
    echo json_encode(['sucesso' => false, 'erro' => 'Não autorizado']);
    exit;
}

$action = $_GET['action'] ?? '';
$usuarioLogado = $_SESSION['usuario'] ?? 'sistema';

function registrarAuditoria($pdo, $usuario, $acao, $detalhes) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS trilha_auditoria (
            id SERIAL PRIMARY KEY,
            data_hora TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            usuario VARCHAR(100),
            acao VARCHAR(100),
            detalhes TEXT
        )");
        $stmt = $pdo->prepare("INSERT INTO trilha_auditoria (usuario, acao, detalhes) VALUES (:u, :a, :d)");
        $stmt->execute(['u' => $usuario, 'a' => $acao, 'd' => $detalhes]);
    } catch(Exception $e) {}
}

try {
    $pdo->exec("ALTER TABLE escala_dados DROP CONSTRAINT IF EXISTS escala_dados_nome_colaborador_data_escala_key");
    $pdo->exec("DROP INDEX IF EXISTS idx_escala_colaborador_data");
    $pdo->exec("CREATE TABLE IF NOT EXISTS configuracoes (chave VARCHAR(100) PRIMARY KEY, valor TEXT)");
} catch (Exception $e) {}

// ROTA: Salvar tela inicial (Semanal/Mensal/...) do usuário logado
if ($action === 'salvar_view' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $view = $input['view'] ?? '';
    if (!in_array($view, ['week', 'month', 'dashboard', 'gestao'], true)) { echo json_encode(['sucesso' => false, 'erro' => 'Tela inválida.']); exit; }
    try {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS view_padrao VARCHAR(20)");
        $pdo->prepare("UPDATE usuarios SET view_padrao = :v WHERE usuario = :u")->execute(['v' => $view, 'u' => $usuarioLogado]);
        echo json_encode(['sucesso' => true]);
    } catch (Exception $e) { echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]); }
    exit;
}

// ROTA: Salvar Filtro Padrão do Usuário
if ($action === 'salvar_filtro' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $usuario = $input['usuario'] ?? '';
    $filtro = $input['filtro'] ?? [];

    if (!empty($usuario)) {
        try {
            $pdo->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS filtro_padrao TEXT");
            $stmt = $pdo->prepare("UPDATE usuarios SET filtro_padrao = :f WHERE usuario = :u");
            $stmt->execute(['f' => json_encode($filtro), 'u' => $usuario]);
            echo json_encode(['sucesso' => true]);
        } catch (Exception $e) {
            echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['sucesso' => false, 'erro' => 'Usuário inválido']);
    }
    exit;
}

function inserirPlantao($pdo, $nome, $dataStr, $status, $anexoUrl, $feriadosDB) {
    $stmtDel = $pdo->prepare("DELETE FROM escala_dados WHERE nome_colaborador = :nome AND data_escala = :data");
    $stmtDel->execute(['nome' => $nome, 'data' => $dataStr]);

    // DASH_PATCH_V1: a hora extra agora independe do sobreaviso (nao e apagada ao mudar o dia)

    if ($status === null || $status === 'limpar') return;

    $stmtIns = $pdo->prepare("INSERT INTO escala_dados (nome_colaborador, data_escala, status, horas_total, arquivo_anexo, data_inicio, hora_inicio, data_fim, hora_fim) 
            VALUES (:nome, :data, :status, :horas, :anexo, :d_ini, :h_ini, :d_fim, :h_fim)");

    if (str_starts_with($status, 'plantao')) {
        // HE_PATCH_V1: horários vêm dos períodos de sobreaviso configurados (Painel de Administração)
        $segmentos = escComMunicipais($pdo, $nome, function () use ($pdo, $dataStr, $feriadosDB) { return pgSegmentosPlantao($pdo, $dataStr, $feriadosDB); }); // FER_MUNICIPAL_V2
        foreach ($segmentos as $sg) {
            $stmtIns->execute([
                'nome' => $nome, 'data' => $dataStr, 'status' => $status,
                'horas' => $sg['horas'], 'anexo' => $anexoUrl,
                'd_ini' => $sg['d_ini'], 'h_ini' => $sg['h_ini'],
                'd_fim' => $sg['d_fim'], 'h_fim' => $sg['h_fim']
            ]);
        }
    } else {
        $stmtIns->execute([
            'nome' => $nome, 'data' => $dataStr, 'status' => $status, 
            'horas' => 0.00, 'anexo' => $anexoUrl, 
            'd_ini' => $dataStr, 'h_ini' => '00:00:00', 
            'd_fim' => $dataStr, 'h_fim' => '23:59:00'
        ]);
    }
}

// SOBREAVISO_REGRAS_V1: regras do sobreaviso (Painel de Administração → Sobreaviso), habilitáveis
function soRegras($pdo) {
    $r = ['limite_24h' => true, 'bloquear_ausencia' => true, 'avisar_compartilhado' => true];
    try {
        $v = $pdo->query("SELECT valor FROM configuracoes WHERE chave = 'sobreaviso_regras'")->fetchColumn();
        $s = $v ? json_decode($v, true) : null;
        if (is_array($s)) foreach ($r as $k => $x) if (array_key_exists($k, $s)) $r[$k] = (bool)$s[$k];
    } catch (Exception $e) {}
    return $r;
}
// intervalos do sobreaviso de um dia, em minutos absolutos (UTC), conforme os períodos configurados
function soIntervalosDia($pdo, $d, $feriadosDB) {
    $p = explode('-', $d); $base = intdiv(gmmktime(0, 0, 0, (int)$p[1], (int)$p[2], (int)$p[0]), 60);
    $tipo = pgTipoDoDia($d, $feriadosDB); $out = [];
    foreach (pgPeriodosVigentes($pdo, $tipo, $d) as $x) {
        $iv = pgIntervalo((int)$x['dia_inicio'], $x['hora_inicio'], (int)$x['dia_fim'], $x['hora_fim']);
        if ($iv) $out[] = [$base + $iv[0], $base + $iv[1]];
    }
    if (!$out) foreach (($tipo === 'SEMANA') ? [[1320, 1800]] : [[480, 1020], [1320, 1800]] as $iv) $out[] = [$base + $iv[0], $base + $iv[1]];
    return $out;
}
// CLT: no máximo 24h seguidas de sobreaviso. Períodos encostados (um termina quando o outro começa) somam;
// em dia útil o expediente normal interrompe o sobreaviso. Retorna a mensagem de bloqueio ou null.
function soChecar24h($pdo, $nome, $datas, $feriadosDB) {
    $datas = array_values(array_unique(array_filter((array)$datas))); if (!$datas) return null; sort($datas);
    $a = date('Y-m-d', strtotime($datas[0] . ' -3 day')); $b = date('Y-m-d', strtotime(end($datas) . ' +3 day'));
    $st = $pdo->prepare("SELECT DISTINCT to_char(data_escala,'YYYY-MM-DD') FROM escala_dados WHERE nome_colaborador = :n AND status LIKE 'plantao%' AND data_escala BETWEEN :a AND :b");
    $st->execute(['n' => $nome, 'a' => $a, 'b' => $b]);
    $iv = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $d) { $alvo = in_array($d, $datas, true); foreach (escComMunicipais($pdo, $nome, function () use ($pdo, $d, $feriadosDB) { return soIntervalosDia($pdo, $d, $feriadosDB); }) as $x) $iv[] = [$x[0], $x[1], $alvo]; }
    usort($iv, function ($x, $y) { return $x[0] <=> $y[0]; });
    $bl = [];
    foreach ($iv as $x) {
        $n = count($bl);
        if ($n && $x[0] <= $bl[$n - 1][1]) { $bl[$n - 1][1] = max($bl[$n - 1][1], $x[1]); $bl[$n - 1][2] = $bl[$n - 1][2] || $x[2]; }
        else $bl[] = $x;
    }
    $ds = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];
    $f = function ($m) use ($ds) { return $ds[(int)gmdate('w', $m * 60)] . ' ' . gmdate('d/m H:i', $m * 60); };
    foreach ($bl as $x) if ($x[2] && $x[1] - $x[0] > 1440) {
        $h = $x[1] - $x[0];
        return "LIMITE DE 24H DE SOBREAVISO (CLT)\n\n{$nome} ficaria " . intdiv($h, 60) . 'h' . ($h % 60 ? sprintf('%02d', $h % 60) : '') . ' seguidas de sobreaviso (de ' . $f($x[0]) . ' a ' . $f($x[1]) . ").\n\nNão é permitido passar de 24h seguidas.";
    }
    return null;
}

function checarConflitoPlantao($pdo, $data_escala, $nome_novo, $status_novo) {
    if (!str_starts_with($status_novo, 'plantao')) return null;

    $stmtFunc = $pdo->prepare("SELECT local_base, localidade, setor, sigla FROM funcionarios WHERE nome = :n");
    $stmtFunc->execute(['n' => $nome_novo]);
    $funcNovo = $stmtFunc->fetch(PDO::FETCH_ASSOC);
    if (!$funcNovo) return null;

    $stmtCheck = $pdo->prepare("
        SELECT e.nome_colaborador, f.localidade, f.setor, f.sigla 
        FROM escala_dados e
        JOIN funcionarios f ON e.nome_colaborador = f.nome
        WHERE e.data_escala = :d AND e.status = :s AND e.nome_colaborador != :n
    ");
    $stmtCheck->execute(['d' => $data_escala, 's' => $status_novo, 'n' => $nome_novo]);
    $escalados = $stmtCheck->fetchAll(PDO::FETCH_ASSOC);

    $timestamp = strtotime($data_escala);
    $dia_semana = date('N', $timestamp);
    $is_fds_sexta = in_array($dia_semana, [5, 6, 7]);

    $pares_sigla = [
        ['TAF', 'PGU'], ['FSA', 'SEH'], ['SNJ', 'ALH'], ['VCA', 'GNB'], 
        ['BES', 'SMV'], ['SBM', 'JUA'], ['ITB', 'JEE'], ['ECN', 'PAF'], ['IRE', 'IEB']
    ];

    $meu_par = [$funcNovo['sigla']];
    foreach ($pares_sigla as $p) {
        if (in_array($funcNovo['sigla'], $p)) { $meu_par = $p; break; }
    }

    $count_ele_ref_ssa = 0;
    foreach ($escalados as $esc) {
        if ($esc['setor'] === 'ELE/REF' && $esc['localidade'] === 'SALVADOR') $count_ele_ref_ssa++;
    }

    foreach ($escalados as $esc) {
        if ($funcNovo['setor'] === 'RECORRENCIA' && $esc['setor'] === 'RECORRENCIA') return $esc['nome_colaborador'];
        if ($funcNovo['setor'] === 'VIDEOWALL' && $esc['setor'] === 'VIDEOWALL') return $esc['nome_colaborador'];

        if ($funcNovo['setor'] === 'ELE/REF' && $esc['setor'] === 'ELE/REF' && in_array($esc['sigla'], $meu_par)) {
            return $esc['nome_colaborador'];
        }

        if ($funcNovo['setor'] === 'ELE/REF' && $funcNovo['localidade'] === 'SALVADOR') {
             if ($esc['setor'] === 'ELE/REF' && $esc['localidade'] === 'SALVADOR') {
                  if ($is_fds_sexta && $count_ele_ref_ssa >= 2) return "Limite de 2 técnicos atingido";
                  if (!$is_fds_sexta) return $esc['nome_colaborador'];
             }
        } else if ($funcNovo['setor'] === $esc['setor'] && $funcNovo['localidade'] === $esc['localidade'] && $funcNovo['setor'] !== 'ELE/REF') {
             return $esc['nome_colaborador'];
        }
    }
    return null;
}

if ($action === 'trocar_plantao_blocos' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $nomeAtual = $input['nome_atual'] ?? '';
    $diasAtual = $input['dias_atual'] ?? [];
    $novoNome = $input['novo_nome'] ?? '';
    $diasNovo = $input['dias_novo'] ?? [];
    $usuarioResp = $input['usuario_logado'] ?? $usuarioLogado;

    try {
        $pdo->beginTransaction();

        $stmtSelFeriados = $pdo->query("SELECT data_feriado FROM feriados WHERE tipo = 'nacional'");
        $feriadosDB = $stmtSelFeriados->fetchAll(PDO::FETCH_COLUMN);

        $inAtual = "'" . implode("','", $diasAtual) . "'";
        $stmtA = $pdo->prepare("SELECT DISTINCT data_escala, status, arquivo_anexo FROM escala_dados WHERE nome_colaborador = :nome AND data_escala IN ($inAtual)");
        $stmtA->execute(['nome' => $nomeAtual]);
        $registrosAtual = $stmtA->fetchAll(PDO::FETCH_ASSOC);

        $inNovo = "'" . implode("','", $diasNovo) . "'";
        $stmtN = $pdo->prepare("SELECT DISTINCT data_escala, status, arquivo_anexo FROM escala_dados WHERE nome_colaborador = :nome AND data_escala IN ($inNovo)");
        $stmtN->execute(['nome' => $novoNome]);
        $registrosNovo = $stmtN->fetchAll(PDO::FETCH_ASSOC);

        for ($i = 0; $i < count($diasNovo); $i++) {
            $novaData = $diasNovo[$i];
            $modelo = ['status' => 'plantao', 'arquivo_anexo' => null];
            foreach($registrosNovo as $rn) { if ($rn['data_escala'] === $novaData) { $modelo = $rn; break; } }
            
            $stmtDel = $pdo->prepare("DELETE FROM escala_dados WHERE nome_colaborador = :n AND data_escala = :d");
            $stmtDel->execute(['n' => $novoNome, 'd' => $novaData]);
            inserirPlantao($pdo, $nomeAtual, $novaData, $modelo['status'], $modelo['arquivo_anexo'], $feriadosDB);
        }

        for ($i = 0; $i < count($diasAtual); $i++) {
            $novaData = $diasAtual[$i];
            $modelo = ['status' => 'plantao', 'arquivo_anexo' => null];
            foreach($registrosAtual as $ra) { if ($ra['data_escala'] === $novaData) { $modelo = $ra; break; } }
            
            $stmtDel = $pdo->prepare("DELETE FROM escala_dados WHERE nome_colaborador = :n AND data_escala = :d");
            $stmtDel->execute(['n' => $nomeAtual, 'd' => $novaData]);
            inserirPlantao($pdo, $novoNome, $novaData, $modelo['status'], $modelo['arquivo_anexo'], $feriadosDB);
        }

        if (soRegras($pdo)['limite_24h'] && ($msg24 = (soChecar24h($pdo, $nomeAtual, $diasNovo, $feriadosDB) ?: soChecar24h($pdo, $novoNome, $diasAtual, $feriadosDB)))) {
            $pdo->rollBack(); echo json_encode(['sucesso' => false, 'erro' => $msg24, 'regra' => 'limite_24h']); exit;
        }
        $pdo->commit();
        registrarAuditoria($pdo, $usuarioResp, 'TROCA DE BLOCO', "Troca efetuada entre {$nomeAtual} e {$novoNome}");
        echo json_encode(['sucesso' => true]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'trocar_plantao' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $nome_atual = $input['nome_atual'];
    $novo_nome = $input['novo_nome'];
    $data = $input['data'];
    $usuarioResp = $input['usuario_logado'] ?? $usuarioLogado;

    $stmt = $pdo->prepare("SELECT status, horas_total FROM escala_dados WHERE nome_colaborador = ? AND data_escala = ?");
    $stmt->execute([$nome_atual, $data]);
    $escala = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($escala) {
        $avisoConflito = soRegras($pdo)['avisar_compartilhado'] ? checarConflitoPlantao($pdo, $data, $novo_nome, $escala['status']) : null;   // plantão compartilhado é permitido: só avisa

        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM escala_dados WHERE nome_colaborador = ? AND data_escala = ?")->execute([$nome_atual, $data]);
            $pdo->prepare("INSERT INTO escala_dados (nome_colaborador, data_escala, status, horas_total) VALUES (?, ?, ?, ?)")
                ->execute([$novo_nome, $data, $escala['status'], $escala['horas_total']]);
            if (str_starts_with((string)$escala['status'], 'plantao') && soRegras($pdo)['limite_24h']) {
                $fer24 = $pdo->query("SELECT data_feriado FROM feriados WHERE tipo = 'nacional'")->fetchAll(PDO::FETCH_COLUMN);
                if ($msg24 = soChecar24h($pdo, $novo_nome, [$data], $fer24)) { $pdo->rollBack(); echo json_encode(['sucesso' => false, 'erro' => $msg24, 'regra' => 'limite_24h']); exit; }
            }
            $pdo->commit();
            registrarAuditoria($pdo, $usuarioResp, 'TROCA DE PLANTÃO', "Substituição pontual de {$nome_atual} por {$novo_nome} no dia " . date('d/m/Y', strtotime($data)));
            echo json_encode(['sucesso' => true, 'aviso' => $avisoConflito ? "Plantão compartilhado: também de plantão no dia: $avisoConflito." : null]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['sucesso' => false, 'erro' => 'Erro ao processar troca.']);
        }
    } else {
        echo json_encode(['sucesso' => false, 'erro' => 'Plantão original não encontrado.']);
    }
    exit;
}

if ($action === 'get_auditoria') {
    if (($_SESSION['perfil'] ?? '') !== 'mestre') { echo json_encode(['sucesso' => false, 'erro' => 'Acesso restrito ao perfil Mestre.']); exit; } // AUDITORIA_MESTRE_V1
    $pagina = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
    $exportar = !empty($_GET['todos']); // AUDITORIA_EXPORT_V1
    $limite = $exportar ? 20000 : 15;
    $offset = $exportar ? 0 : ($pagina - 1) * $limite;

    $where = ["1=1"];
    $params = [];

    if (!empty($_GET['mes'])) {
        $where[] = "to_char(data_hora, 'YYYY-MM') = :mes";
        $params['mes'] = $_GET['mes'];
    }
    if (!empty($_GET['data'])) {
        $where[] = "to_char(data_hora, 'YYYY-MM-DD') = :data";
        $params['data'] = $_GET['data'];
    }
    if (!empty($_GET['usuario'])) {
        $where[] = "usuario ILIKE :usuario";
        $params['usuario'] = "%" . $_GET['usuario'] . "%";
    }
    if (!empty($_GET['acao'])) {
        $where[] = "acao ILIKE :acao";
        $params['acao'] = "%" . $_GET['acao'] . "%";
    }
    if (!empty($_GET['termo'])) {
        $where[] = "detalhes ILIKE :termo";
        $params['termo'] = "%" . $_GET['termo'] . "%";
    }

    $whereSql = implode(" AND ", $where);

    try {
        $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM trilha_auditoria WHERE $whereSql");
        $stmtCount->execute($params);
        $totalRegistros = $stmtCount->fetchColumn();
        $totalPaginas = ceil($totalRegistros / $limite) ?: 1;

        $stmt = $pdo->prepare("SELECT * FROM trilha_auditoria WHERE $whereSql ORDER BY data_hora DESC LIMIT $limite OFFSET $offset");
        $stmt->execute($params);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($exportar) { try { registrarAuditoria($pdo, $usuarioLogado, 'AUDITORIA', 'Exportou a trilha de auditoria (' . count($logs) . ' registro(s), ' . strtoupper(preg_replace('/[^a-z]/i', '', $_GET['exp'] ?? '')) . ')'); } catch (Exception $e) {} }

        $formattedLogs = array_map(function($l) {
            return [
                'data_hora_fmt' => date('d/m/Y H:i:s', strtotime($l['data_hora'])),
                'usuario' => $l['usuario'],
                'acao' => $l['acao'],
                'detalhes' => $l['detalhes']
            ];
        }, $logs);

        echo json_encode([
            'sucesso' => true,
            'logs' => $formattedLogs,
            'paginas_totais' => $totalPaginas,
            'pagina_atual' => $pagina
        ]);
    } catch (Exception $e) {
        echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'get_auditoria_sugestoes') {
    if (($_SESSION['perfil'] ?? '') !== 'mestre') { echo json_encode(['sucesso' => false, 'erro' => 'Acesso restrito ao perfil Mestre.']); exit; } // AUDITORIA_MESTRE_V1
    $campo = $_GET['campo'] ?? '';
    $termo = $_GET['termo'] ?? '';
    if ($campo !== 'usuario' && $campo !== 'acao') { echo json_encode(['sucesso' => true, 'dados' => []]); exit; }
    try {
        $stmt = $pdo->prepare("SELECT DISTINCT $campo FROM trilha_auditoria WHERE $campo ILIKE :termo LIMIT 10");
        $stmt->execute(['termo' => "%$termo%"]);
        echo json_encode(['sucesso' => true, 'dados' => $stmt->fetchAll(PDO::FETCH_COLUMN)]);
    } catch (Exception $e) { echo json_encode(['sucesso' => false, 'dados' => []]); }
    exit;
}

// ===== HE_PATCH_V1: hora extra e períodos de sobreaviso =====
function heExigeGestor() {
    if (!heEhGestor()) { http_response_code(403); echo json_encode(['sucesso' => false, 'erro' => 'Acesso restrito a gestores.']); exit; }
}
function heEntrada() { return json_decode(file_get_contents('php://input'), true) ?: []; }

if ($action === 'get_horas_extras') {
    heExigeGestor();
    try { $heMan = heListar($pdo); echo json_encode(['sucesso' => true, 'itens' => array_merge($heMan, heDoPonto($pdo, $heMan))]); } // inclui a hora extra batida no Ponto
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
// HE_PATCH_V2
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

// SMTP_PATCH_V1 — configuração do servidor de e-mail (usado pelo Ponto para enviar os relatórios). A senha nunca volta para a tela.
if ($action === 'get_regras_sobreaviso') { echo json_encode(['sucesso' => true, 'regras' => soRegras($pdo)]); exit; }
if ($action === 'salvar_regras_sobreaviso' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!heEhGestor()) { echo json_encode(['sucesso' => false, 'erro' => 'Apenas gestores podem alterar as regras do sobreaviso.']); exit; }
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $r = soRegras($pdo); $mud = [];
    foreach (array_keys($r) as $k) if (array_key_exists($k, $in) && (bool)$in[$k] !== $r[$k]) { $r[$k] = (bool)$in[$k]; $mud[] = $k . ' = ' . ($r[$k] ? 'habilitada' : 'desabilitada'); }
    try {
        $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('sobreaviso_regras', :v) ON CONFLICT (chave) DO UPDATE SET valor = EXCLUDED.valor")->execute(['v' => json_encode($r)]);
        if ($mud) registrarAuditoria($pdo, $usuarioLogado, 'REGRAS SOBREAVISO', 'Alterou as regras do sobreaviso: ' . implode(', ', $mud));
        echo json_encode(['sucesso' => true, 'regras' => $r]);
    } catch (Exception $e) { echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]); }
    exit;
}
if (strpos($action, 'so_') === 0) { require_once __DIR__ . '/sobreaviso_ferramentas.php'; soRotear($pdo, $action, $usuarioLogado ?? ($_SESSION['usuario'] ?? '')); exit; } // SO_FERRAMENTAS_V1
if (strpos($action, 'rel_') === 0) { require_once __DIR__ . '/relatorios.php'; relRotear($pdo, $action, $usuarioLogado ?? ($_SESSION['usuario'] ?? '')); exit; } // RELATORIOS_V1
if ($action === 'get_smtp' || $action === 'set_smtp' || $action === 'testar_smtp') {
    heExigeGestor();
    require_once __DIR__ . '/smtp_mail.php';
    try {
        if ($action === 'get_smtp') { echo json_encode(['sucesso' => true, 'dados' => smtpConfigPublica(smtpConfigLer($pdo))]); exit; }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['sucesso' => false, 'erro' => 'Método inválido.']); exit; }
        $in = json_decode(file_get_contents('php://input'), true);
        if (!is_array($in)) { echo json_encode(['sucesso' => false, 'erro' => 'Requisição inválida.']); exit; }
        if ($action === 'set_smtp') {
            if ($e = smtpConfigSalvar($pdo, $in)) { echo json_encode(['sucesso' => false, 'erro' => $e]); exit; }
            registrarAuditoria($pdo, $usuarioLogado, 'CONFIGURAÇÕES', 'Atualizou as configurações de e-mail (SMTP)');
            echo json_encode(['sucesso' => true, 'dados' => smtpConfigPublica(smtpConfigLer($pdo))]); exit;
        }
        $para = trim((string)($in['para'] ?? ''));
        if (!filter_var($para, FILTER_VALIDATE_EMAIL)) { echo json_encode(['sucesso' => false, 'erro' => 'Informe um e-mail válido para o teste.']); exit; }
        $r = smtpEnviar(smtpConfigLer($pdo), $para, 'Teste de e-mail — SmartNOC', '<p>Este é um e-mail de teste enviado pelo <b>SmartNOC</b>.</p><p>Se você o recebeu, o SMTP está configurado corretamente.</p>');
        registrarAuditoria($pdo, $usuarioLogado, 'CONFIGURAÇÕES', 'Teste de e-mail SMTP para ' . $para . ': ' . ($r['ok'] ? 'enviado' : 'falhou'));
        echo json_encode($r['ok'] ? ['sucesso' => true] : ['sucesso' => false, 'erro' => $r['erro']]);
    } catch (Exception $e) { echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]); }
    exit;
}

if ($action === 'get_config') {
    try {
        $stmt = $pdo->query("SELECT chave, valor FROM configuracoes");
        $configs = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { $configs[$row['chave']] = $row['valor']; }
        echo json_encode(['sucesso' => true, 'dados' => $configs]);
    } catch (Exception $e) { echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]); }
    exit;
}

if ($action === 'set_config' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    try {
        $stmt = $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES (:chave, :valor) ON CONFLICT (chave) DO UPDATE SET valor = EXCLUDED.valor");
        if (isset($input['horas_uteis'])) $stmt->execute(['chave' => 'sobreaviso_horas_uteis', 'valor' => $input['horas_uteis']]);
        if (isset($input['horas_fds'])) $stmt->execute(['chave' => 'sobreaviso_horas_fds', 'valor' => $input['horas_fds']]);
        if (isset($input['escala_texto'])) $stmt->execute(['chave' => 'escala_texto', 'valor' => $input['escala_texto']]);
        if (isset($input['logo_pdf_base64'])) $stmt->execute(['chave' => 'logo_pdf_base64', 'valor' => $input['logo_pdf_base64']]);
        if (isset($input['evo_url'])) $stmt->execute(['chave' => 'evo_url', 'valor' => $input['evo_url']]);
        if (isset($input['evo_key'])) $stmt->execute(['chave' => 'evo_key', 'valor' => $input['evo_key']]);
        if (isset($input['evo_inst'])) $stmt->execute(['chave' => 'evo_inst', 'valor' => $input['evo_inst']]);
        
        registrarAuditoria($pdo, $usuarioLogado, 'CONFIGURAÇÕES', 'Atualizou as configurações globais do sistema');
        echo json_encode(['sucesso' => true]);
    } catch (Exception $e) { echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]); }
    exit;
}

if ($action === 'get_wpp_grupos') {
    echo json_encode(['sucesso' => true, 'dados' => $pdo->query("SELECT * FROM whatsapp_grupos ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC)]); exit;
}
if ($action === 'salvar_wpp_grupo' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? ''; $nome = $_POST['nome'] ?? ''; $grupo_id = $_POST['grupo_id'] ?? '';
    if ($id) { $pdo->prepare("UPDATE whatsapp_grupos SET nome=?, grupo_id=? WHERE id=?")->execute([$nome, $grupo_id, $id]); } 
    else { $pdo->prepare("INSERT INTO whatsapp_grupos (nome, grupo_id) VALUES (?, ?)")->execute([$nome, $grupo_id]); }
    echo json_encode(['sucesso' => true]); exit;
}
if ($action === 'excluir_wpp_grupo' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = json_decode(file_get_contents('php://input'), true)['id'] ?? '';
    $pdo->prepare("DELETE FROM whatsapp_grupos WHERE id=?")->execute([$id]);
    echo json_encode(['sucesso' => true]); exit;
}
if ($action === 'get_usuarios') {
    try {
        $stmt = $pdo->query("SELECT id, usuario, perfil, nome_completo, setores_permitidos, setores_gerenciaveis FROM usuarios ORDER BY nome_completo ASC");
        echo json_encode(['sucesso' => true, 'dados' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]); }
    exit;
}

if ($action === 'salvar_usuario' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? '';
    $nome = $_POST['nome_completo'] ?? '';
    $user = $_POST['usuario'] ?? '';
    $senha = $_POST['senha'] ?? '';
    $perfil = $_POST['perfil'] ?? 'usuario';
    $setoresView = $_POST['setores_permitidos'] ?? '';
    $setoresEdit = $_POST['setores_gerenciaveis'] ?? '';

    try {
        if (!empty($id)) {
            if (!empty($senha)) {
                $stmt = $pdo->prepare("UPDATE usuarios SET nome_completo = :n, usuario = :u, senha = :s, perfil = :p, setores_permitidos = :sv, setores_gerenciaveis = :se WHERE id = :id");
                $stmt->execute(['n' => $nome, 'u' => $user, 's' => $senha, 'p' => $perfil, 'sv' => $setoresView, 'se' => $setoresEdit, 'id' => $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE usuarios SET nome_completo = :n, usuario = :u, perfil = :p, setores_permitidos = :sv, setores_gerenciaveis = :se WHERE id = :id");
                $stmt->execute(['n' => $nome, 'u' => $user, 'p' => $perfil, 'sv' => $setoresView, 'se' => $setoresEdit, 'id' => $id]);
            }
            registrarAuditoria($pdo, $usuarioLogado, 'ATUALIZAR USUÁRIO', "Atualizou dados do usuário: $user");
        } else {
            $stmt = $pdo->prepare("INSERT INTO usuarios (usuario, senha, perfil, nome_completo, setores_permitidos, setores_gerenciaveis) VALUES (:u, :s, :p, :n, :sv, :se)");
            $stmt->execute(['u' => $user, 's' => $senha, 'p' => $perfil, 'n' => $nome, 'sv' => $setoresView, 'se' => $setoresEdit]);
            registrarAuditoria($pdo, $usuarioLogado, 'CRIAR USUÁRIO', "Criou novo usuário: $user");
        }
        echo json_encode(['sucesso' => true]);
    } catch (Exception $e) { echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]); }
    exit;
}

if ($action === 'excluir_usuario' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? '';
    try {
        $stmt = $pdo->prepare("SELECT usuario FROM usuarios WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $uRow = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = :id");
        $stmt->execute(['id' => $id]);
        
        if ($uRow) registrarAuditoria($pdo, $usuarioLogado, 'EXCLUIR USUÁRIO', "Excluiu usuário: " . $uRow['usuario']);
        echo json_encode(['sucesso' => true]);
    } catch (Exception $e) { echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]); }
    exit;
}

if ($action === 'importar_funcionarios' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['file']['tmp_name'];
        $lines = file($fileTmpPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        try {
            $pdo->beginTransaction();
            foreach ($lines as $index => $line) {
                if ($index === 0) continue;
                $cols = explode(';', $line);
                if (count($cols) >= 1) {
                    $nome = trim($cols[0]);
                    $stmt = $pdo->prepare("INSERT INTO funcionarios (nome, nome_completo, telefone, setor, localidade, local_base, sigla, aniversario, supervisor) 
                        VALUES (:n, :nc, :t, :s, :l, :b, :sg, :an, :sup) ON CONFLICT DO NOTHING");
                    $stmt->execute([
                        'n' => $nome, 'nc' => trim($cols[1] ?? ''), 't' => trim($cols[2] ?? ''), 's' => trim($cols[3] ?? ''), 
                        'l' => trim($cols[4] ?? ''), 'b' => trim($cols[5] ?? ''), 'sg' => trim($cols[6] ?? ''), 
                        'an' => trim($cols[7] ?? ''), 'sup' => trim($cols[8] ?? '')
                    ]);
                }
            }
            $pdo->commit();
            registrarAuditoria($pdo, $usuarioLogado, 'IMPORTAR COLABORADORES', 'Importou colaboradores via CSV');
            echo json_encode(['sucesso' => true]);
        } catch (Exception $e) {
            $pdo->rollBack(); echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]);
        }
    } else { echo json_encode(['sucesso' => false, 'erro' => 'Arquivo inválido.']); }
    exit;
}

if ($action === 'get_funcionarios') {
    try {
        try { $pdo->exec("ALTER TABLE funcionarios ADD COLUMN IF NOT EXISTS data_admissao DATE, ADD COLUMN IF NOT EXISTS cpf VARCHAR(11)"); } catch (Exception $e) {}
        $stmt = $pdo->query("SELECT f.*, COALESCE((SELECT json_agg(grupo_id) FROM funcionario_whatsapp WHERE funcionario_id = f.id), '[]') as grupos_wpp FROM funcionarios f ORDER BY f.local_base ASC, f.localidade ASC, f.setor ASC, f.nome ASC");
        echo json_encode(['sucesso' => true, 'dados' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]); }
    exit;
}

if ($action === 'salvar_funcionario' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? '';
    $nome = $_POST['nome'] ?? '';
    $adm = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['data_admissao'] ?? '')) ? $_POST['data_admissao'] : '';
    $cpf = preg_replace('/\D/', '', (string)($_POST['cpf'] ?? '')); // CPF: usado nos arquivos fiscais do ponto (Portaria 671)
    if ($cpf !== '') {
        $okCpf = strlen($cpf) === 11 && !preg_match('/^(\d)\1{10}$/', $cpf);
        for ($t = 9; $okCpf && $t < 11; $t++) { $s = 0; for ($i = 0; $i < $t; $i++) $s += (int)$cpf[$i] * (($t + 1) - $i); if ((int)$cpf[$t] !== ((10 * $s) % 11) % 10) $okCpf = false; }
        if (!$okCpf) { echo json_encode(['sucesso' => false, 'erro' => 'CPF inválido.']); exit; }
    }
    $emailColab = null; // EMAIL_COLAB_V1: null = não veio na tela (mantém o atual); '' = sem e-mail
    if (isset($_POST['email'])) { $emailColab = escEmailValidar($_POST['email']); if ($emailColab === null) { echo json_encode(['sucesso' => false, 'erro' => 'E-mail inválido.']); exit; } }
    try {
        $pdo->exec("ALTER TABLE funcionarios ADD COLUMN IF NOT EXISTS data_admissao DATE, ADD COLUMN IF NOT EXISTS cpf VARCHAR(11)");
        if (!empty($id)) {
            $stmt = $pdo->prepare("UPDATE funcionarios SET nome = :n, nome_completo = :nc, local_base = :b, localidade = :l, setor = :s, sigla = :sg, supervisor = :sup, telefone = :t, aniversario = :an, data_admissao = NULLIF(:ad, '')::date, cpf = NULLIF(:cpf, '') WHERE id = :id");
            $stmt->execute(['n' => $nome, 'nc' => $_POST['nome_completo'] ?? '', 'b' => $_POST['local_base'] ?? '', 'l' => $_POST['localidade'] ?? '', 's' => $_POST['setor'] ?? '', 'sg' => $_POST['sigla'] ?? '', 'sup' => $_POST['supervisor'] ?? '', 't' => $_POST['telefone'] ?? '', 'an' => $_POST['aniversario'] ?? '', 'ad' => $adm, 'cpf' => $cpf, 'id' => $id]);
            registrarAuditoria($pdo, $usuarioLogado, 'ATUALIZAR COLABORADOR', "Atualizou colaborador: $nome");
        } else {
            $stmt = $pdo->prepare("INSERT INTO funcionarios (nome, nome_completo, local_base, localidade, setor, sigla, supervisor, telefone, aniversario, data_admissao, cpf) VALUES (:n, :nc, :b, :l, :s, :sg, :sup, :t, :an, NULLIF(:ad, '')::date, NULLIF(:cpf, ''))");
            $stmt->execute(['n' => $nome, 'nc' => $_POST['nome_completo'] ?? '', 'b' => $_POST['local_base'] ?? '', 'l' => $_POST['localidade'] ?? '', 's' => $_POST['setor'] ?? '', 'sg' => $_POST['sigla'] ?? '', 'sup' => $_POST['supervisor'] ?? '', 't' => $_POST['telefone'] ?? '', 'an' => $_POST['aniversario'] ?? '', 'ad' => $adm, 'cpf' => $cpf]);
            registrarAuditoria($pdo, $usuarioLogado, 'CRIAR COLABORADOR', "Cadastrou novo colaborador: $nome");
        }
        if ($emailColab !== null) escEmailSalvar($pdo, $nome, $emailColab); // EMAIL_COLAB_V1
                // --- INÍCIO SALVAR VÍNCULOS WHATSAPP ---
        $func_id = !empty($id) ? $id : $pdo->query("SELECT id FROM funcionarios WHERE nome = " . $pdo->quote($_POST['nome'] ?? '') . " ORDER BY id DESC LIMIT 1")->fetchColumn();
        $grupos_wpp = isset($_POST['grupos_wpp']) ? json_decode($_POST['grupos_wpp'], true) : [];
        $telefone_limpo = preg_replace('/\D/', '', $_POST['telefone'] ?? '');
        if (strlen($telefone_limpo) == 10 || strlen($telefone_limpo) == 11) $telefone_limpo = '55' . $telefone_limpo;
        
        $stmtG = $pdo->prepare("SELECT grupo_id FROM funcionario_whatsapp WHERE funcionario_id = ?");
        $stmtG->execute([$func_id]);
        $grupos_antigos = $stmtG->fetchAll(PDO::FETCH_COLUMN);
        
        $adicionados = array_diff($grupos_wpp, $grupos_antigos);
        $removidos = array_diff($grupos_antigos, $grupos_wpp);

        $pdo->prepare("DELETE FROM funcionario_whatsapp WHERE funcionario_id = ?")->execute([$func_id]);
        if (is_array($grupos_wpp) && count($grupos_wpp) > 0) {
            $stmtW = $pdo->prepare("INSERT INTO funcionario_whatsapp (funcionario_id, grupo_id) VALUES (?, ?)");
            foreach($grupos_wpp as $gid) { $stmtW->execute([$func_id, $gid]); }
        }

        $pendentesWpp = 0;
        $wa = null;
        if (!empty($telefone_limpo) && (count($adicionados) > 0 || count($removidos) > 0)) {
            require_once 'whatsapp_helper.php';
            require_once 'status_grupos.php';
            $wa = new WhatsAppManager($pdo);
            $nomeColab = $_POST['nome'] ?? '';
            $afastadoAgora = sgEstaAfastado($pdo, $func_id);
            foreach($adicionados as $gid) {
                if ($afastadoAgora) { sgMarcarRemovido($pdo, $func_id, (int)$gid); continue; }   // afastado: entra no grupo quando retornar
                $jid = $pdo->query("SELECT grupo_id FROM whatsapp_grupos WHERE id = " . (int)$gid)->fetchColumn();
                if ($jid) {
                    $wa->enfileirarComMensagem('add', $jid, $telefone_limpo, "Seja bem vindo(a), $nomeColab", $nomeColab);
                    $pendentesWpp += 2;
                }
            }
            foreach($removidos as $gid) {
                if (sgDesmarcarRemovido($pdo, $func_id, (int)$gid)) continue;   // já estava fora do grupo por afastamento
                $jid = $pdo->query("SELECT grupo_id FROM whatsapp_grupos WHERE id = " . (int)$gid)->fetchColumn();
                if ($jid) {
                    $wa->enfileirarComMensagem('remove', $jid, $telefone_limpo, "O colaborador $nomeColab foi removido do grupo.\nMotivo: Atualização de vínculos e perfis na base da plataforma SmartNoc.", $nomeColab);
                    $pendentesWpp += 2;
                }
            }
        }
        // --- FIM SALVAR VÍNCULOS WHATSAPP ---
        $respostaOk = ['sucesso' => true, 'whatsapp_pendentes' => $pendentesWpp];
        if ($wa && $pendentesWpp > 0) {
            // responde na hora e processa a fila em segundo plano, com intervalo entre cada ação
            $wa->responderEProcessarFila($respostaOk);
            exit;
        }
        echo json_encode($respostaOk);
    } catch (Exception $e) { echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]); }
    exit;
}

if ($action === 'excluir_funcionario' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? '';
    try {
        $stmt = $pdo->prepare("SELECT nome FROM funcionarios WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $fRow = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("DELETE FROM funcionarios WHERE id = :id");
        $stmt->execute(['id' => $id]);
        
        if ($fRow) registrarAuditoria($pdo, $usuarioLogado, 'EXCLUIR COLABORADOR', "Excluiu colaborador: " . $fRow['nome']);
        echo json_encode(['sucesso' => true]);
    } catch (Exception $e) { echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]); }
    exit;
}

if ($action === 'get_feriados') {
    try {
        $stmt = $pdo->query("SELECT id, nome, data_feriado, tipo, localidade FROM feriados ORDER BY data_feriado ASC");
        echo json_encode(['sucesso' => true, 'dados' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]); }
    exit;
}

if ($action === 'salvar_feriado' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? '';
    $nome = $_POST['nome'] ?? '';
    try {
        if (!empty($id)) {
            $stmt = $pdo->prepare("UPDATE feriados SET nome = :n, data_feriado = :d, tipo = :t, localidade = :l WHERE id = :id");
            $stmt->execute(['n' => $nome, 'd' => $_POST['data_feriado'] ?? '', 't' => $_POST['tipo'] ?? 'nacional', 'l' => $_POST['localidade'] ?? '', 'id' => $id]);
            registrarAuditoria($pdo, $usuarioLogado, 'ATUALIZAR FERIADO', "Atualizou feriado: $nome");
        } else {
            $stmt = $pdo->prepare("INSERT INTO feriados (nome, data_feriado, tipo, localidade) VALUES (:n, :d, :t, :l)");
            $stmt->execute(['n' => $nome, 'd' => $_POST['data_feriado'] ?? '', 't' => $_POST['tipo'] ?? 'nacional', 'l' => $_POST['localidade'] ?? '']);
            registrarAuditoria($pdo, $usuarioLogado, 'CRIAR FERIADO', "Cadastrou novo feriado: $nome");
        }
        echo json_encode(['sucesso' => true]);
    } catch (Exception $e) { echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]); }
    exit;
}

if ($action === 'excluir_feriado' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? '';
    try {
        $stmt = $pdo->prepare("SELECT nome FROM feriados WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $fRow = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("DELETE FROM feriados WHERE id = :id");
        $stmt->execute(['id' => $id]);

        if ($fRow) registrarAuditoria($pdo, $usuarioLogado, 'EXCLUIR FERIADO', "Excluiu feriado: " . $fRow['nome']);
        echo json_encode(['sucesso' => true]);
    } catch (Exception $e) { echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]); }
    exit;
}

if ($action === 'preencher_sobreaviso_lote' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $itens = $input['itens'] ?? [];
    $usuarioResp = $input['usuario_logado'] ?? $usuarioLogado;
    $origem = (($input['origem'] ?? '') === 'linha') ? 'linha' : 'mes';
    if (!is_array($itens) || count($itens) === 0 || count($itens) > 5000) {
        echo json_encode(['sucesso' => false, 'erro' => 'Nenhum item para preencher (máximo de 5000).']); exit;
    }
    $statusOk = ['plantao', 'plantao_rec2', 'plantao_reserva'];
    try {
        $pdo->beginTransaction();
        $feriadosDB = $pdo->query("SELECT data_feriado FROM feriados WHERE tipo = 'nacional'")->fetchAll(PDO::FETCH_COLUMN);
        $selFunc = $pdo->prepare("SELECT 1 FROM funcionarios WHERE nome = ? LIMIT 1");
        $selExiste = $pdo->prepare("SELECT 1 FROM escala_dados WHERE nome_colaborador = ? AND data_escala = ? LIMIT 1");
        $inseridos = 0; $ignorados = 0; $nomes = []; $rg24 = soRegras($pdo)['limite_24h']; $bloq24 = [];
        foreach ($itens as $it) {
            $nome = (string)($it['nome'] ?? '');
            $data = (string)($it['data'] ?? '');
            $status = (string)($it['status'] ?? '');
            $p = explode('-', $data);
            if ($nome === '' || count($p) !== 3 || !checkdate((int)$p[1], (int)$p[2], (int)$p[0]) || !in_array($status, $statusOk, true)) { $ignorados++; continue; }
            $selFunc->execute([$nome]);
            if (!$selFunc->fetchColumn()) { $ignorados++; continue; }
            $selExiste->execute([$nome, $data]);
            if ($selExiste->fetchColumn()) { $ignorados++; continue; }   // nunca sobrescreve um lançamento existente
            $pdo->exec('SAVEPOINT so_item');
            inserirPlantao($pdo, $nome, $data, $status, null, $feriadosDB);
            if ($rg24 && soChecar24h($pdo, $nome, [$data], $feriadosDB)) { $pdo->exec('ROLLBACK TO SAVEPOINT so_item'); $bloq24[] = $nome . ' ' . date('d/m', strtotime($data)); $ignorados++; continue; }
            $pdo->exec('RELEASE SAVEPOINT so_item');
            $inseridos++;
            $nomes[$nome] = true;
        }
        $pdo->commit();
        $quem = ($origem === 'linha') ? ('linha de ' . implode(', ', array_keys($nomes))) : (count($nomes) . ' colaborador(es)');
        registrarAuditoria($pdo, $usuarioResp, 'PREENCHER SOBREAVISO', "Preencheu {$inseridos} dia(s) de sobreaviso seguindo o mês anterior ({$quem})");
        echo json_encode(['sucesso' => true, 'inseridos' => $inseridos, 'ignorados' => $ignorados - count($bloq24), 'bloqueados_24h' => $bloq24]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]);
    }
    exit;
}
if ($action === 'bulk_update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = $_POST['nome'] ?? '';
    $status = $_POST['status'] ?? '';
    $dataInicio = $_POST['data_inicio'] ?? '';
    $dataFim = $_POST['data_fim'] ?? '';
    $usuarioResp = $_POST['usuario_logado'] ?? $usuarioLogado;

    $anexoUrl = null;
    if (isset($_FILES['anexo']) && $_FILES['anexo']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = 'uploads/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $fileName = time() . '_' . basename($_FILES['anexo']['name']);
        $uploadFile = $uploadDir . $fileName;
        if (move_uploaded_file($_FILES['anexo']['tmp_name'], $uploadFile)) {
            $anexoUrl = $uploadFile;
        }
    }

    try {
        $pdo->beginTransaction();
        $current = new DateTime($dataInicio);
        $end = new DateTime($dataFim);
        $end->modify('+1 day');
        $interval = new DateInterval('P1D');
        $daterange = new DatePeriod($current, $interval, $end);

        $stmtSelFeriados = $pdo->query("SELECT data_feriado FROM feriados WHERE tipo = 'nacional'");
        $feriadosDB = $stmtSelFeriados->fetchAll(PDO::FETCH_COLUMN);

        foreach ($daterange as $date) {
            $dateStr = $date->format("Y-m-d");
            inserirPlantao($pdo, $nome, $dateStr, $status, $anexoUrl, $feriadosDB);
            $datasLote[] = $dateStr;
        }
        if (str_starts_with((string)$status, 'plantao') && soRegras($pdo)['limite_24h'] && ($msg24 = soChecar24h($pdo, $nome, $datasLote ?? [], $feriadosDB))) {
            $pdo->rollBack(); echo json_encode(['sucesso' => false, 'erro' => $msg24 . "\n\nNada foi lançado neste período.", 'regra' => 'limite_24h']); exit;
        }
        $pdo->commit();
        registrarAuditoria($pdo, $usuarioResp, 'LANÇAMENTO EM LOTE', "Lote para {$nome} de {$dataInicio} até {$dataFim} (Ação: {$status})");
        echo json_encode(['sucesso' => true]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === '') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) { echo json_encode(['sucesso' => false, 'erro' => 'Dados inválidos']); exit; }

    $nome = $input['nome'] ?? '';
    $data = $input['data'] ?? '';
    $status = $input['status'] ?? null;
    $usuarioResp = $input['usuario_logado'] ?? $usuarioLogado;

    try {
        $stmtAnx = $pdo->prepare("SELECT arquivo_anexo FROM escala_dados WHERE nome_colaborador = :n AND data_escala = :d AND arquivo_anexo IS NOT NULL LIMIT 1");
        $stmtAnx->execute(['n' => $nome, 'd' => $data]);
        $anexoExistente = $stmtAnx->fetchColumn();

        $stmtSelFeriados = $pdo->query("SELECT data_feriado FROM feriados WHERE tipo = 'nacional'");
        $feriadosDB = $stmtSelFeriados->fetchAll(PDO::FETCH_COLUMN);

        $pdo->beginTransaction();
        inserirPlantao($pdo, $nome, $data, $status, $anexoExistente, $feriadosDB);
        if ($status && str_starts_with($status, 'plantao') && soRegras($pdo)['limite_24h'] && ($msg24 = soChecar24h($pdo, $nome, [$data], $feriadosDB))) {
            $pdo->rollBack(); echo json_encode(['sucesso' => false, 'erro' => $msg24, 'regra' => 'limite_24h']); exit;
        }
        $pdo->commit();

        if ($status === null) registrarAuditoria($pdo, $usuarioResp, 'REMOVER ESCALA', "Removeu escala de {$nome} no dia " . date('d/m/Y', strtotime($data)));
        else registrarAuditoria($pdo, $usuarioResp, 'ATUALIZAR ESCALA', "Definiu {$status} para {$nome} no dia " . date('d/m/Y', strtotime($data)));
        
        echo json_encode(['sucesso' => true]);
    } catch (Exception $e) { echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]); }
    exit;
}


if ($action === 'test_evo') {
    require_once 'whatsapp_helper.php';
    $wa = new WhatsAppManager($pdo);
    $res = $wa->testarConexao();
    echo json_encode(['sucesso' => $res['success'], 'detalhes' => $res]);
    exit;
}
if ($action === 'test_wpp' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    require_once 'whatsapp_helper.php';
    $wa = new WhatsAppManager($pdo);
    $res = $wa->enviarTexto($input['grupo_id'], "Mensagem teste de comunicaçao com SmartNoc");
    echo json_encode(['sucesso' => $res['success'], 'detalhes' => $res]);
    exit;
}
if ($action === 'salvar_config_fila' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once 'whatsapp_helper.php';
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $wa = new WhatsAppManager($pdo);
    echo json_encode($wa->salvarConfigFila($in['intervalo_min'] ?? 0, $in['intervalo_max'] ?? 0, $in['ordem'] ?? ''));
    exit;
}
if ($action === 'limpar_fila_erros_whatsapp' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once 'whatsapp_helper.php';
    $wa = new WhatsAppManager($pdo);
    echo json_encode($wa->limparErrosFila());
    exit;
}
if ($action === 'get_log_status_grupos') {
    require_once 'status_grupos.php';
    try {
        echo json_encode(sgListarLog($pdo, $_GET['busca'] ?? '', $_GET['de'] ?? '', $_GET['ate'] ?? '', (int)($_GET['pagina'] ?? 1), 10, !empty($_GET['todos'])));
    } catch (Exception $e) { echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]); }
    exit;
}
if ($action === 'get_status_colaborador') {
    require_once 'status_grupos.php';
    try { echo json_encode(sgInfoColaborador($pdo, (int)($_GET['id'] ?? 0))); }
    catch (Exception $e) { echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]); }
    exit;
}
if ($action === 'get_regra_grupos') {
    require_once 'status_grupos.php';
    try { echo json_encode(sgInfoRegra($pdo)); }
    catch (Exception $e) { echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]); }
    exit;
}
if ($action === 'salvar_regra_grupos' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once 'status_grupos.php';
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $lim = (int)($in['limite'] ?? 0);
    if ($lim < 1 || $lim > 60) { echo json_encode(['sucesso' => false, 'erro' => 'Informe um limite entre 1 e 60 dias úteis.']); exit; }
    sgConfigSet($pdo, 'grupos_dias_uteis_limite', $lim);
    echo json_encode(['sucesso' => true, 'limite' => $lim]);
    exit;
}
if ($action === 'sincronizar_status_grupos' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once 'whatsapp_helper.php';
    require_once 'status_grupos.php';
    try {
        $wa = new WhatsAppManager($pdo);
        $res = sgSincronizar($pdo, $wa);
        $res['sucesso'] = true;
    } catch (Exception $e) { echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]); exit; }
    $wa->responderEProcessarFila($res);   // responde na hora e executa a fila em segundo plano, com intervalo
    exit;
}
if ($action === 'get_fila_whatsapp') {
    require_once 'whatsapp_helper.php';
    try {
        $wa = new WhatsAppManager($pdo);
        echo json_encode($wa->statusFila());
    } catch (Exception $e) { echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]); }
    exit;
}
if ($action === 'processar_fila_whatsapp' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once 'whatsapp_helper.php';
    $wa = new WhatsAppManager($pdo);
    $wa->responderEProcessarFila(['sucesso' => true]);
    exit;
}
// RETORNO DE DADOS GERAIS
try { $pdo->exec("ALTER TABLE escala_dados ADD COLUMN IF NOT EXISTS observacao TEXT"); } catch (Exception $e) {} // SO_FERRAMENTAS_V1
try {
    $stmt = $pdo->query("SELECT nome_colaborador, data_escala, status, horas_total, arquivo_anexo, data_inicio, hora_inicio, data_fim, hora_fim, observacao FROM escala_dados ORDER BY data_inicio ASC, hora_inicio ASC");
    $dados = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!isset($dados[$row['nome_colaborador']])) {
            $dados[$row['nome_colaborador']] = [];
        }
        if (!isset($dados[$row['nome_colaborador']][$row['data_escala']])) {
            $dados[$row['nome_colaborador']][$row['data_escala']] = [
                'status' => $row['status'],
                'anexo' => $row['arquivo_anexo'], 'obs' => $row['observacao'] ?? null,
                'fracoes' => []
            ];
        }
        $dados[$row['nome_colaborador']][$row['data_escala']]['fracoes'][] = [
            'data_inicio' => $row['data_inicio'],
            'hora_inicio' => $row['hora_inicio'],
            'data_fim' => $row['data_fim'],
            'hora_fim' => $row['hora_fim'],
            'horas' => $row['horas_total']
        ];
    }
    require_once __DIR__ . '/ausencias_ponto.php'; escalaMesclarAusenciasPonto($pdo, $dados); // abonos aprovados no Ponto
    echo json_encode(['sucesso' => true, 'dados' => $dados]);
} catch (Exception $e) { echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]); }
?>
