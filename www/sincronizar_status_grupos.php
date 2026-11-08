<?php
// Verificação diária: lê o status de cada colaborador na escala mensal e aplica a regra nos grupos de WhatsApp.
// Uso (cron do servidor): docker exec site-escala php /var/www/html/sincronizar_status_grupos.php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/whatsapp_helper.php';
require_once __DIR__ . '/status_grupos.php';
if (!$pdo) { fwrite(STDERR, "Sem conexão com o banco\n"); exit(1); }

$wa = new WhatsAppManager($pdo);
$r = sgSincronizar($pdo, $wa);
echo '[' . date('Y-m-d H:i:s') . '] Verificados: ' . $r['verificados'] . ' | Mudanças de status: ' . $r['alteracoes']
   . ' | Remoções na fila: ' . $r['remocoes'] . ' | Inclusões na fila: ' . $r['adicoes'] . "\n";

$n = $wa->processarFila();
echo '[' . date('Y-m-d H:i:s') . "] Fila WhatsApp: $n ação(ões) processada(s)\n";
