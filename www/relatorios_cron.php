<?php
// relatorios_cron.php — RELATORIOS_V1: envia por e-mail os relatórios agendados em Configurações → Relatórios.
// Rodar pelo cron do servidor (a cada 10 min). Ex.: */10 * * * * root docker exec site-escala php /var/www/html/relatorios_cron.php
// Opcional: um horário de teste como argumento (ex.: php relatorios_cron.php "2026-12-15 08:05") simula a execução naquele momento.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(__DIR__);
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/relatorios.php';
if (empty($pdo)) { fwrite(STDERR, date('Y-m-d H:i:s') . " sem conexão com o banco\n"); exit(1); }
try {
    $n = relEnviarAgendados($pdo, isset($argv[1]) ? (strtotime($argv[1]) ?: null) : null);
    if ($n) echo date('Y-m-d H:i:s') . " relatórios enviados: $n\n";
} catch (Throwable $e) { fwrite(STDERR, date('Y-m-d H:i:s') . ' erro: ' . $e->getMessage() . "\n"); exit(1); }
