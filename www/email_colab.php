<?php
// EMAIL_COLAB_V1 — e-mail do colaborador cadastrado na Escala (Configurações → Colaboradores).
// Fica em funcionarios.email e é copiado para ponto_colaboradores.email, que o Ponto usa (avisos, relatório da folha, "esqueci minha senha").
function escEmailGarantir($pdo) {
    static $ok = false; if ($ok) return; $ok = true;
    $pdo->exec("ALTER TABLE funcionarios ADD COLUMN IF NOT EXISTS email VARCHAR(200)");
    $q = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'email_colab_migrado'"); $q->execute();
    if ($q->fetchColumn()) return;
    if ($pdo->query("SELECT to_regclass('ponto_colaboradores')")->fetchColumn()) // traz os e-mails que já estavam cadastrados no Ponto
        $pdo->exec("UPDATE funcionarios f SET email = p.email FROM ponto_colaboradores p WHERE p.nome = f.nome AND COALESCE(f.email, '') = '' AND COALESCE(p.email, '') <> ''");
    $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('email_colab_migrado', :v) ON CONFLICT (chave) DO UPDATE SET valor = EXCLUDED.valor")->execute(['v' => date('c')]);
}
function escEmailValidar($email) { // '' = sem e-mail; null = inválido
    $email = strtolower(trim((string)$email));
    if ($email === '') return '';
    return (strlen($email) <= 200 && filter_var($email, FILTER_VALIDATE_EMAIL)) ? $email : null;
}
function escEmailSalvar($pdo, $nome, $email) {
    escEmailGarantir($pdo);
    $pdo->prepare("UPDATE funcionarios SET email = NULLIF(:e, '') WHERE nome = :n")->execute(['e' => $email, 'n' => $nome]);
    if ($pdo->query("SELECT to_regclass('ponto_colaboradores')")->fetchColumn())
        $pdo->prepare("INSERT INTO ponto_colaboradores (nome, email) VALUES (:n, NULLIF(:e, '')) ON CONFLICT (nome) DO UPDATE SET email = EXCLUDED.email")->execute(['n' => $nome, 'e' => $email]);
}
