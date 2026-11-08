<?php
// api_ponto.php — API do ponto eletrônico. Colaborador: sessão própria (nome + PIN + rosto). Gestor: sessão do site (admin/mestre).
session_start();
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/ponto_lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function resp($arr, $code = 200) { http_response_code($code); echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }
function erro($msg, $code = 200) { resp(['sucesso' => false, 'erro' => $msg], $code); }
if (!$pdo) erro('Sem conexão com o banco de dados.', 500);
try { ptGarantirTabelas($pdo); } catch (Exception $e) { erro('Falha ao preparar o banco: ' . $e->getMessage(), 500); }

$action = $_GET['action'] ?? '';
$in = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) erro('Requisição inválida.', 400);
}

function ehGestor() { $p = $_SESSION['perfil'] ?? ''; return isset($_SESSION['usuario']) && ($p === 'admin' || $p === 'mestre'); }
function exigeGestor() { if (!ehGestor()) erro('Acesso restrito a gestores.', 403); }
function exigeColab() { if (empty($_SESSION['ponto_nome'])) erro('Sessão expirada. Entre novamente.', 401); return $_SESSION['ponto_nome']; }
function auditar($pdo, $usuario, $acao, $det) {
    try { $pdo->prepare("INSERT INTO trilha_auditoria (usuario, acao, detalhes) VALUES (:u, :a, :d)")->execute(['u' => $usuario, 'a' => $acao, 'd' => $det]); } catch (Exception $e) {}
}
function colab($pdo, $nome) {
    $st = $pdo->prepare("SELECT * FROM ponto_colaboradores WHERE nome = :n"); $st->execute(['n' => $nome]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
function falhar($pdo, $nome) { // contador de falhas (PIN ou rosto): 5 falhas => bloqueio de 10 min
    $pdo->prepare("UPDATE ponto_colaboradores SET
                   bloqueado_ate = CASE WHEN tentativas + 1 >= 5 THEN now() + interval '10 minutes' ELSE bloqueado_ate END,
                   tentativas = CASE WHEN tentativas + 1 >= 5 THEN 0 ELSE tentativas + 1 END WHERE nome = :n")->execute(['n' => $nome]);
}
function bloqueado($c) { return !empty($c['bloqueado_ate']) && strtotime($c['bloqueado_ate']) > time(); }

try {
switch ($action) {

// =================== COLABORADOR ===================
case 'ponto_buscar_nome': {
    $q = trim($_GET['q'] ?? '');
    if (ptLen($q) < 3) resp(['sucesso' => true, 'nomes' => []]);
    $st = $pdo->prepare("SELECT nome FROM ponto_colaboradores WHERE ativo AND pin_hash IS NOT NULL AND nome ILIKE :q ORDER BY nome LIMIT 8");
    $st->execute(['q' => '%' . str_replace(['%', '_'], '', $q) . '%']);
    resp(['sucesso' => true, 'nomes' => $st->fetchAll(PDO::FETCH_COLUMN)]);
}
case 'ponto_login': {
    $nome = trim($in['nome'] ?? ''); $pin = (string)($in['pin'] ?? '');
    $c = $nome !== '' ? colab($pdo, $nome) : null;
    if (!$c || !$c['ativo'] || !$c['pin_hash']) erro('Nome ou PIN incorretos.');
    if (bloqueado($c)) erro('Acesso bloqueado por tentativas incorretas. Tente novamente em alguns minutos.');
    if (!password_verify($pin, $c['pin_hash'])) { falhar($pdo, $nome); erro('Nome ou PIN incorretos.'); }
    $pdo->prepare("UPDATE ponto_colaboradores SET tentativas = 0, bloqueado_ate = NULL WHERE nome = :n")->execute(['n' => $nome]);
    session_regenerate_id(true);
    $_SESSION['ponto_nome'] = $nome;
    resp(['sucesso' => true, 'nome' => $nome, 'cadastrado' => !empty($c['descritor']), 'pin_provisorio' => (bool)$c['pin_provisorio']]);
}
case 'ponto_logout': { unset($_SESSION['ponto_nome']); resp(['sucesso' => true]); }
case 'ponto_trocar_pin': {
    $nome = exigeColab(); $novo = (string)($in['pin_novo'] ?? '');
    $c = colab($pdo, $nome);
    if (!password_verify((string)($in['pin_atual'] ?? ''), $c['pin_hash'])) erro('PIN atual incorreto.');
    if (!preg_match('/^\d{4,8}$/', $novo)) erro('O novo PIN deve ter de 4 a 8 dígitos.');
    $pdo->prepare("UPDATE ponto_colaboradores SET pin_hash = :h, pin_provisorio = FALSE WHERE nome = :n")->execute(['h' => password_hash($novo, PASSWORD_DEFAULT), 'n' => $nome]);
    resp(['sucesso' => true]);
}
case 'ponto_me': {
    $nome = exigeColab(); $c = colab($pdo, $nome);
    if (!$c || !$c['ativo']) { unset($_SESSION['ponto_nome']); erro('Acesso desativado.', 401); }
    $regra = ptRegra($pdo); $hoje = date('Y-m-d');
    $alertas = [];
    for ($i = 0; $i <= PT_JANELA_DIAS; $i++) {
        $d = date('Y-m-d', strtotime("$hoje -$i days"));
        if (!empty($c['cadastrado_em']) && $d < substr($c['cadastrado_em'], 0, 10)) break; // só cobra a partir do cadastro do rosto
        $s = ptStatusDia($pdo, $regra, $nome, $d);
        if ($s['alerta'] || $s['pendentes']) $alertas[] = $s;
    }
    resp(['sucesso' => true, 'nome' => $nome, 'cadastrado' => !empty($c['descritor']), 'pin_provisorio' => (bool)$c['pin_provisorio'],
          'agora' => date('Y-m-d H:i:s'), 'hoje' => ptStatusDia($pdo, $regra, $nome, $hoje),
          'alertas' => array_values(array_filter($alertas, function ($a) { return $a['alerta']; })),
          'rotulos' => PT_LABEL, 'tolerancia' => (int)$regra['tolerancia']]);
}
case 'ponto_historico': {
    $nome = exigeColab(); $regra = ptRegra($pdo); $out = [];
    for ($i = 0; $i <= PT_JANELA_DIAS; $i++) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $s = ptStatusDia($pdo, $regra, $nome, $d);
        if ($s['batidas'] || $s['alerta']) $out[] = $s;
    }
    resp(['sucesso' => true, 'dias' => $out, 'rotulos' => PT_LABEL]);
}
case 'ponto_cadastrar_rosto': {
    $nome = exigeColab(); $c = colab($pdo, $nome);
    if (!empty($c['descritor'])) erro('Rosto já cadastrado. Peça ao gestor para resetar o cadastro.');
    if (!ptDescritorValido($in['descritor'] ?? null)) erro('Leitura do rosto inválida. Tente novamente.');
    if (!ptFotoValida($in['foto'] ?? null)) erro('Foto inválida.');
    if (empty($in['consentimento'])) erro('É necessário aceitar o uso dos dados biométricos.');
    $pdo->prepare("UPDATE ponto_colaboradores SET descritor = :d, foto_cadastro = :f, cadastrado_em = now() WHERE nome = :n")
        ->execute(['d' => json_encode(array_map('floatval', $in['descritor'])), 'f' => $in['foto'], 'n' => $nome]);
    auditar($pdo, $nome, 'PONTO', 'Cadastrou o rosto para o ponto (com consentimento LGPD)');
    resp(['sucesso' => true]);
}
case 'ponto_bater': {
    $nome = exigeColab(); $c = colab($pdo, $nome);
    if (empty($c['descritor'])) erro('Cadastre seu rosto antes de bater o ponto.');
    if (bloqueado($c)) erro('Acesso bloqueado por tentativas incorretas. Tente novamente em alguns minutos.');
    if (!ptDescritorValido($in['descritor'] ?? null)) erro('Rosto não detectado. Posicione o rosto na câmera.');
    if (!ptFotoValida($in['foto'] ?? null)) erro('Foto inválida.');
    if (!ptCoordValida($in['lat'] ?? null, $in['lng'] ?? null, $in['precisao'] ?? null)) erro('Localização obrigatória: permita o acesso à localização do aparelho.');
    $dist = ptDistancia(json_decode($c['descritor'], true), $in['descritor']);
    if ($dist > PT_DIST_MAX) { falhar($pdo, $nome); erro('Rosto não reconhecido. Tente novamente com boa iluminação.'); }
    $pdo->prepare("UPDATE ponto_colaboradores SET tentativas = 0 WHERE nome = :n")->execute(['n' => $nome]);
    $agora = time(); $hoje = date('Y-m-d', $agora);
    $pdo->beginTransaction();
    $pdo->prepare("SELECT pg_advisory_xact_lock(hashtext(:n))")->execute(['n' => $nome]);
    $bat = ptBatidasDoDia($pdo, $nome, $hoje);
    $tipo = ptProximoTipo($bat);
    if (!$tipo) { $pdo->rollBack(); erro('As quatro batidas de hoje já foram registradas.'); }
    $mom = date('Y-m-d H:i:s', $agora);
    $ult = ptAtivas($bat); // evita duplo clique: mínimo de 1 minuto entre batidas
    if ($ult) { $maior = 0; foreach ($ult as $u) $maior = max($maior, strtotime($u['momento'])); if ($agora - $maior < 60 && $agora >= $maior) { $pdo->rollBack(); erro('Aguarde 1 minuto entre as batidas.'); } }
    if ($e = ptValidarOrdem($bat, $tipo, $mom)) { $pdo->rollBack(); erro($e . ' Corrija com uma batida manual justificada.'); }
    $ins = $pdo->prepare("INSERT INTO ponto_batidas (nome, data_ref, tipo, momento, origem, foto, lat, lng, precisao, distancia, status)
                          VALUES (:n, :d, :t, :m, 'facial', :f, :la, :ln, :p, :di, 'valida') RETURNING id");
    $ins->execute(['n' => $nome, 'd' => $hoje, 't' => $tipo, 'm' => $mom, 'f' => $in['foto'], 'la' => $in['lat'], 'ln' => $in['lng'],
                   'p' => $in['precisao'] ?? null, 'di' => round($dist, 4)]);
    $pdo->commit();
    resp(['sucesso' => true, 'tipo' => $tipo, 'rotulo' => PT_LABEL[$tipo], 'hora' => date('H:i:s', $agora), 'id' => (int)$ins->fetchColumn(), 'distancia' => round($dist, 3)]);
}
case 'ponto_manual': {
    $nome = exigeColab(); $data = $in['data'] ?? ''; $tipo = $in['tipo'] ?? ''; $hora = $in['hora'] ?? ''; $just = trim((string)($in['justificativa'] ?? ''));
    if (!ptDataValida($data)) erro('Data inválida.');
    $hoje = date('Y-m-d');
    if ($data > $hoje) erro('Não é possível lançar batida em data futura.');
    if ($data < date('Y-m-d', strtotime('-' . PT_JANELA_DIAS . ' days'))) erro('Só é possível corrigir os últimos ' . PT_JANELA_DIAS . ' dias. Fale com o gestor.');
    if (!in_array($tipo, PT_TIPOS, true)) erro('Tipo de batida inválido.');
    if (ptMin($hora) === null) erro('Informe o horário (HH:MM).');
    if (ptLen($just) < 10) erro('Informe a justificativa (mínimo de 10 caracteres).');
    if (ptLen($just) > 500) erro('Justificativa muito longa (máximo de 500 caracteres).');
    $mom = $data . ' ' . $hora . ':00';
    if ($data === $hoje && strtotime($mom) > time()) erro('O horário não pode ser futuro.');
    $pdo->beginTransaction();
    $pdo->prepare("SELECT pg_advisory_xact_lock(hashtext(:n))")->execute(['n' => $nome]);
    $bat = ptBatidasDoDia($pdo, $nome, $data);
    if (in_array($tipo, array_column(ptAtivas($bat), 'tipo'), true)) { $pdo->rollBack(); erro('Já existe "' . PT_LABEL[$tipo] . '" neste dia. Aguarde a decisão do gestor ou fale com ele.'); }
    if ($e = ptValidarOrdem($bat, $tipo, $mom)) { $pdo->rollBack(); erro($e); }
    $pdo->prepare("INSERT INTO ponto_batidas (nome, data_ref, tipo, momento, origem, justificativa, status)
                   VALUES (:n, :d, :t, :m, 'manual', :j, 'pendente')")->execute(['n' => $nome, 'd' => $data, 't' => $tipo, 'm' => $mom, 'j' => $just]);
    $pdo->commit();
    auditar($pdo, $nome, 'PONTO', "Solicitou batida manual $tipo $hora em $data: $just");
    resp(['sucesso' => true]);
}

// =================== GESTOR ===================
case 'ponto_admin_regra': {
    exigeGestor();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($e = ptRegraValidar($in)) erro($e);
        ptRegraSalvar($pdo, $in);
        auditar($pdo, $_SESSION['usuario'], 'PONTO', 'Alterou a regra de horário do ponto');
        resp(['sucesso' => true]);
    }
    resp(['sucesso' => true, 'regra' => ptRegra($pdo)]);
}
case 'ponto_admin_colaboradores': {
    exigeGestor();
    $st = $pdo->query("SELECT f.nome, f.nome_completo, f.setor, (p.pin_hash IS NOT NULL) AS tem_pin, (p.descritor IS NOT NULL) AS cadastrado,
                              COALESCE(p.ativo, TRUE) AS ativo, p.pin_provisorio
                       FROM funcionarios f LEFT JOIN ponto_colaboradores p ON p.nome = f.nome ORDER BY f.nome");
    $l = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($l as &$r) { foreach (['tem_pin', 'cadastrado', 'ativo', 'pin_provisorio'] as $k) $r[$k] = (bool)$r[$k]; }
    resp(['sucesso' => true, 'itens' => $l]);
}
case 'ponto_admin_definir_pin': {
    exigeGestor(); $nome = trim($in['nome'] ?? '');
    $st = $pdo->prepare("SELECT 1 FROM funcionarios WHERE nome = :n"); $st->execute(['n' => $nome]);
    if (!$st->fetchColumn()) erro('Colaborador não encontrado.');
    $pin = (string)($in['pin'] ?? '');
    if ($pin === '') $pin = (string)random_int(100000, 999999);
    elseif (!preg_match('/^\d{4,8}$/', $pin)) erro('O PIN deve ter de 4 a 8 dígitos.');
    $pdo->prepare("INSERT INTO ponto_colaboradores (nome, pin_hash, pin_provisorio) VALUES (:n, :h, TRUE)
                   ON CONFLICT (nome) DO UPDATE SET pin_hash = EXCLUDED.pin_hash, pin_provisorio = TRUE, tentativas = 0, bloqueado_ate = NULL")
        ->execute(['n' => $nome, 'h' => password_hash($pin, PASSWORD_DEFAULT)]);
    auditar($pdo, $_SESSION['usuario'], 'PONTO', "Definiu novo PIN de ponto para $nome");
    resp(['sucesso' => true, 'pin' => $pin]);
}
case 'ponto_admin_resetar_rosto': {
    exigeGestor(); $nome = trim($in['nome'] ?? '');
    $pdo->prepare("UPDATE ponto_colaboradores SET descritor = NULL, foto_cadastro = NULL, cadastrado_em = NULL WHERE nome = :n")->execute(['n' => $nome]);
    auditar($pdo, $_SESSION['usuario'], 'PONTO', "Resetou o cadastro facial de $nome");
    resp(['sucesso' => true]);
}
case 'ponto_admin_ativar': {
    exigeGestor(); $nome = trim($in['nome'] ?? '');
    $pdo->prepare("UPDATE ponto_colaboradores SET ativo = :a WHERE nome = :n")->execute(['a' => !empty($in['ativo']) ? 'true' : 'false', 'n' => $nome]);
    auditar($pdo, $_SESSION['usuario'], 'PONTO', (!empty($in['ativo']) ? 'Ativou' : 'Desativou') . " o ponto de $nome");
    resp(['sucesso' => true]);
}
case 'ponto_admin_pendencias': {
    exigeGestor(); $regra = ptRegra($pdo);
    $st = $pdo->query("SELECT id, nome, to_char(data_ref,'YYYY-MM-DD') AS data, tipo, to_char(momento,'HH24:MI') AS hora, justificativa, to_char(criado_em,'YYYY-MM-DD HH24:MI') AS criado_em
                       FROM ponto_batidas WHERE status = 'pendente' ORDER BY data_ref, nome, momento");
    $l = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($l as &$r) { $r['id'] = (int)$r['id']; $r['rotulo'] = PT_LABEL[$r['tipo']]; }
    resp(['sucesso' => true, 'itens' => $l]);
}
case 'ponto_admin_decidir': {
    exigeGestor(); $id = (int)($in['id'] ?? 0); $dec = $in['decisao'] ?? ''; $mot = trim((string)($in['motivo'] ?? ''));
    if (!in_array($dec, ['aprovar', 'recusar'], true)) erro('Decisão inválida.');
    if ($dec === 'recusar' && ptLen($mot) < 5) erro('Informe o motivo da recusa.');
    $st = $pdo->prepare("UPDATE ponto_batidas SET status = :s, decidido_por = :u, decidido_em = now(), motivo_decisao = :m
                         WHERE id = :id AND status = 'pendente' RETURNING nome, tipo, to_char(data_ref,'YYYY-MM-DD') AS d, to_char(momento,'HH24:MI') AS h");
    $st->execute(['s' => $dec === 'aprovar' ? 'aprovada' : 'recusada', 'u' => $_SESSION['usuario'], 'm' => $mot ?: null, 'id' => $id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) erro('Solicitação não encontrada ou já decidida.');
    auditar($pdo, $_SESSION['usuario'], 'PONTO', ($dec === 'aprovar' ? 'Aprovou' : 'Recusou') . " batida manual de {$r['nome']} ({$r['tipo']} {$r['h']} em {$r['d']})" . ($mot ? ": $mot" : ''));
    resp(['sucesso' => true]);
}
case 'ponto_admin_registros': {
    exigeGestor(); $regra = ptRegra($pdo);
    $ini = $_GET['inicio'] ?? date('Y-m-d', strtotime('-7 days')); $fim = $_GET['fim'] ?? date('Y-m-d');
    if (!ptDataValida($ini) || !ptDataValida($fim) || $ini > $fim) erro('Período inválido.');
    if ((strtotime($fim) - strtotime($ini)) / 86400 > 62) erro('Escolha um período de até 62 dias.');
    $filtro = trim($_GET['nome'] ?? '');
    $st = $pdo->prepare("SELECT DISTINCT nome FROM ponto_colaboradores WHERE ativo AND (cadastrado_em IS NOT NULL) AND (:f = '' OR nome ILIKE :fl) ORDER BY nome");
    $st->execute(['f' => $filtro, 'fl' => '%' . $filtro . '%']);
    $nomes = $st->fetchAll(PDO::FETCH_COLUMN); $out = [];
    foreach ($nomes as $n) {
        for ($d = $fim; $d >= $ini; $d = date('Y-m-d', strtotime("$d -1 day"))) {
            $s = ptStatusDia($pdo, $regra, $n, $d);
            if ($s['batidas'] || $s['alerta']) { $s['nome'] = $n; $out[] = $s; }
        }
    }
    resp(['sucesso' => true, 'dias' => $out, 'rotulos' => PT_LABEL]);
}
case 'ponto_admin_foto': {
    exigeGestor();
    $st = $pdo->prepare("SELECT foto FROM ponto_batidas WHERE id = :id"); $st->execute(['id' => (int)($_GET['id'] ?? 0)]);
    $f = $st->fetchColumn();
    if (!$f) erro('Sem foto.');
    resp(['sucesso' => true, 'foto' => $f]);
}
default:
    erro('Ação inválida.', 404);
}
} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    erro('Erro no servidor: ' . $e->getMessage(), 500);
}
