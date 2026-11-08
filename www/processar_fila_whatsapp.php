<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/whatsapp_helper.php';
if (!$pdo) { fwrite(STDERR, "Sem conexão com o banco\n"); exit(1); }

$wa = new WhatsAppManager($pdo);
$n = $wa->processarFila();
echo '[' . date('Y-m-d H:i:s') . "] Fila WhatsApp: $n ação(ões) processada(s)\n";
