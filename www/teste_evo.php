<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/whatsapp_helper.php';
if (!$pdo) { echo "Sem conexão com o banco: $msgErro\n"; exit(1); }

$wa = new WhatsAppManager($pdo);

if (($argv[1] ?? '') === 'grupos') {
    $r = $wa->listarGrupos();
    if (!$r['success'] || !is_array($r['response'])) {
        echo "Falha ao listar grupos. HTTP {$r['http_code']} {$r['curl_error']}\n";
        echo json_encode($r['response'], JSON_UNESCAPED_UNICODE) . "\n";
        exit(1);
    }
    foreach ($r['response'] as $g) {
        echo ($g['id'] ?? '?') . ' | ' . ($g['subject'] ?? '?') . "\n";
    }
    exit;
}

$r = $wa->testarConexao();
echo "URL tentada : " . $r['url_tentada'] . "\n";
echo "HTTP        : " . $r['http_code'] . "\n";
echo "Erro cURL   : " . ($r['curl_error'] ?: '-') . "\n";
echo "Estado      : " . ($r['state'] ?? '-') . "\n";
echo "Resposta    : " . (is_string($r['response']) ? $r['response'] : json_encode($r['response'], JSON_UNESCAPED_UNICODE)) . "\n";
echo $r['success'] ? "\nRESULTADO: CONEXÃO OK\n" : "\nRESULTADO: FALHA\n";
