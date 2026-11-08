<?php
require_once 'whatsapp_helper.php';

// Ajuste aqui os dados de conexão com o seu banco PostgreSQL
$host = 'localhost';
$db   = 'escala_db';
$user = 'escala_user';
$pass = 'SUA_SENHA_DO_BANCO'; // Coloque a senha correta do banco

try {
    $pdo = new PDO("pgsql:host=$host;dbname=$db", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    die("Erro na conexão com o banco de dados: " . $e->getMessage());
}

$wa = new WhatsAppManager();

echo "[" . date('Y-m-d H:i:s') . "] Iniciando sincronização dos grupos de WhatsApp...\n";

// 1. Buscar quem está de férias ou licença (>= 5 dias) HOJE e precisa SAIR do grupo
$sqlAfastados = "
    SELECT f.id, f.telefone, gc.group_jid, gc.group_nome
    FROM funcionarios f
    JOIN grupos_colaborador gc ON gc.funcionario_id = f.id AND gc.removido = false
    JOIN afastamentos a ON a.funcionario_id = f.id 
        AND (NOW() AT TIME ZONE 'America/Bahia')::date BETWEEN a.data_inicio AND a.data_fim
        AND (a.tipo = 'ferias' OR (a.tipo = 'licenca' AND a.dias >= 5))
";

$stmtAfastados = $pdo->query($sqlAfastados);
while ($row = $stmtAfastados->fetch(PDO::FETCH_ASSOC)) {
    if (!empty($row['telefone']) && !empty($row['group_jid'])) {
        echo "Removendo {$row['telefone']} do grupo {$row['group_nome']} (Afastado/Férias)...\n";
        $wa->removerDoGrupo($row['group_jid'], $row['telefone']);
    }
}

// 2. Buscar quem está ATIVO hoje e precisa ENTRAR/PERMANECER no grupo
$sqlAtivos = "
    SELECT f.id, f.telefone, gc.group_jid, gc.group_nome
    FROM funcionarios f
    JOIN grupos_colaborador gc ON gc.funcionario_id = f.id AND gc.removido = false
    WHERE NOT EXISTS (
        SELECT 1 FROM afastamentos a
        WHERE a.funcionario_id = f.id 
          AND (NOW() AT TIME ZONE 'America/Bahia')::date BETWEEN a.data_inicio AND a.data_fim
          AND (a.tipo = 'ferias' OR (a.tipo = 'licenca' AND a.dias >= 5))
    )
";

$stmtAtivos = $pdo->query($sqlAtivos);
while ($row = $stmtAtivos->fetch(PDO::FETCH_ASSOC)) {
    if (!empty($row['telefone']) && !empty($row['group_jid'])) {
        echo "Adicionando/Mantendo {$row['telefone']} no grupo {$row['group_nome']} (Ativo)...\n";
        $wa->adicionarAoGrupo($row['group_jid'], $row['telefone']);
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Sincronização concluída com sucesso.\n";
