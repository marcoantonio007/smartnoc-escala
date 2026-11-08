<?php
require_once 'conexao.php';

header('Content-Type: application/json');
$action = $_GET['action'] ?? '';

if ($action === 'get_dados') {
    // Busca colaboradores e suas escalas cadastradas
    $colaboradores = $pdo->query("SELECT * FROM colaboradores")->fetchAll();
    $escalas = $pdo->query("SELECT * FROM escalas")->fetchAll();
    $localidades = $pdo->query("SELECT * FROM localidades_setores")->fetchAll();

    echo json_encode([
        'colaboradores' => $colaboradores,
        'escalas' => $escalas,
        'localidades' => $localidades
    ]);
} elseif ($action === 'salvar_escala') {
    $data = json_decode(file_get_contents('php://input'), true);
    if ($data) {
        $stmt = $pdo->prepare("
            INSERT INTO escalas (colaborador_id, localidade_setor_id, status, data_plantao, dia, semana, mes, ano)
            VALUES (:colaborador_id, :localidade_setor_id, :status, :data_plantao, :dia, :semana, :mes, :ano)
            ON CONFLICT (colaborador_id, data_plantao) 
            DO UPDATE SET status = EXCLUDED.status, atualizado_em = CURRENT_TIMESTAMP
        ");
        $stmt->execute($data);
        echo json_encode(["sucesso" => true]);
    }
}
?>
