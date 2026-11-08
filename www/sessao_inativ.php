<?php
// sessao_inativ.php — SESSAO_INATIV_V1: encerra a sessão da Escala após X minutos sem uso.
// O tempo é o mesmo dos gestores no Ponto (Ponto → Configuração → Regra de horário → Permissões e avisos); padrão 30 min.
$sessaoLimMin = 30;
if ($pdo) { try { $q = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'sessao_gestor_min'"); $q->execute(); $v = $q->fetchColumn(); if ($v !== false && (int)$v > 0) $sessaoLimMin = (int)$v; } catch (Exception $e) {} }
if (isset($_GET['sair_inativo']) || (!empty($_SESSION['usuario']) && isset($_SESSION['ult_uso']) && time() - (int)$_SESSION['ult_uso'] > $sessaoLimMin * 60)) {
    if (!empty($_SESSION['usuario'])) { $_SESSION = []; session_regenerate_id(true); }
    $msgErro = 'Sua sessão foi encerrada por inatividade. Entre novamente.';
} elseif (!empty($_SESSION['usuario'])) $_SESSION['ult_uso'] = time();
if (($_GET['action'] ?? '') === 'sessao_ping') { // mantém a sessão enquanto a pessoa está usando a tela
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['sucesso' => !empty($_SESSION['usuario'])]); exit;
}
