<?php
// SENHA_RESET_V1 — "Esqueci minha senha" da Escala: código de 6 dígitos para o e-mail do colaborador (válido 15 min, 5 tentativas).
// O e-mail vem do cadastro do colaborador (Configurações → Colaboradores), ligado ao usuário pelo nome completo.
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/smtp_mail.php';
require_once __DIR__ . '/email_colab.php';
const ESC_SENHA_MIN = 15;
const ESC_SENHA_TENT = 5;
function rsResp($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
function rsErro($m) { rsResp(['sucesso' => false, 'erro' => $m]); }
function rsIp() { $x = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['HTTP_X_REAL_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? '')); return substr(trim(explode(',', $x)[0]), 0, 45); }
function rsTabela($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS escala_reset_senha (
        id SERIAL PRIMARY KEY, usuario VARCHAR(100) NOT NULL, salt VARCHAR(32), codigo_hash VARCHAR(64), expira_em TIMESTAMP,
        tentativas INT NOT NULL DEFAULT 0, usado BOOLEAN NOT NULL DEFAULT TRUE, ip VARCHAR(45), criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ers_usuario ON escala_reset_senha (usuario, criado_em)");
}
function rsHash($salt, $u, $cod) { return hash('sha256', $salt . '|' . $u . '|' . $cod); }
function rsFalhas($s) {
    if (!mb_check_encoding($s, 'UTF-8')) return ['caracteres inválidos'];
    $f = [];
    if (mb_strlen($s) < 8) $f[] = 'mínimo de 8 caracteres';
    if (mb_strlen($s) > 64) $f[] = 'no máximo 64 caracteres';
    if (!preg_match('/\p{L}/u', $s)) $f[] = 'pelo menos 1 letra';
    if (!preg_match('/\p{Nd}/u', $s)) $f[] = 'pelo menos 1 número';
    if (!preg_match('/[^\p{L}\p{N}\s]/u', $s)) $f[] = 'pelo menos 1 caractere especial';
    if (preg_match('/\s/u', $s)) $f[] = 'sem espaços';
    return $f;
}
function rsAuditar($pdo, $u, $txt) {
    try { if ($pdo->query("SELECT to_regclass('trilha_auditoria')")->fetchColumn())
        $pdo->prepare("INSERT INTO trilha_auditoria (usuario, acao, detalhes) VALUES (:u, 'SENHA', :d)")->execute(['u' => $u, 'd' => $txt]); } catch (Exception $e) {}
}
function rsEmailDoUsuario($pdo, $nomeCompleto) { // mesma regra do Ponto: nome completo do login = funcionarios.nome_completo ou nome
    escEmailGarantir($pdo);
    $st = $pdo->prepare("SELECT email FROM funcionarios WHERE upper(nome_completo) = upper(:a) OR upper(nome) = upper(:b) ORDER BY (nome_completo = :c) DESC, nome LIMIT 1");
    $nc = trim((string)$nomeCompleto); $st->execute(['a' => $nc, 'b' => $nc, 'c' => $nc]);
    $e = trim((string)$st->fetchColumn());
    return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : null;
}
function rsLayout($titulo, $corpo) {
    return '<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;color:#1f2937">' .
        '<div style="background:#1e3a8a;color:#fff;padding:14px 20px;font-weight:bold;font-size:16px;border-radius:8px 8px 0 0">SmartNOC — Plantão Técnico</div>' .
        '<div style="border:1px solid #e5e7eb;border-top:0;padding:20px;border-radius:0 0 8px 8px"><h2 style="margin:0 0 12px;font-size:18px;color:#1e3a8a">' . $titulo . '</h2>' . $corpo . '</div></div>';
}
function rsPrimeiro($n) { $p = preg_split('/\s+/', trim((string)$n)); return $p[0] !== '' ? mb_convert_case($p[0], MB_CASE_TITLE, 'UTF-8') : ''; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') rsErro('Requisição inválida.');
$in = json_decode(file_get_contents('php://input'), true); if (!is_array($in)) rsErro('Requisição inválida.');
$acao = $_GET['acao'] ?? '';
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

if ($acao === 'solicitar') {
    $u = trim((string)($in['usuario'] ?? ''));
    if ($u === '' || mb_strlen($u) > 100) rsErro('Informe o usuário.');
    $cfg = smtpConfigLer($pdo);
    if (!smtpConfigurado($cfg)) rsErro('O envio de e-mail ainda não está configurado. Fale com o gestor.');
    rsTabela($pdo); $ip = rsIp();
    $q = $pdo->prepare("SELECT count(*) FROM escala_reset_senha WHERE ip = :ip AND criado_em > now() - interval '1 hour'"); $q->execute(['ip' => $ip]);
    if ((int)$q->fetchColumn() >= 10) rsErro('Muitas solicitações deste aparelho. Tente novamente mais tarde.');
    $q = $pdo->prepare("SELECT count(*) AS n, COALESCE(bool_or(criado_em > now() - interval '60 seconds'), false) AS recente FROM escala_reset_senha WHERE lower(usuario) = lower(:u) AND criado_em > now() - interval '15 minutes'");
    $q->execute(['u' => $u]); $lim = $q->fetch(PDO::FETCH_ASSOC);
    if ((int)$lim['n'] >= 3) rsErro('Foram pedidos códigos demais. Aguarde alguns minutos e tente de novo.');
    if ($lim['recente']) rsErro('Aguarde 1 minuto para pedir um novo código.');
    $st = $pdo->prepare("INSERT INTO escala_reset_senha (usuario, ip) VALUES (:u, :ip) RETURNING id"); $st->execute(['u' => $u, 'ip' => $ip]); $idR = (int)$st->fetchColumn();
    $st = $pdo->prepare("SELECT usuario, nome_completo FROM usuarios WHERE usuario = :u"); $st->execute(['u' => $u]);
    $row = $st->fetch(PDO::FETCH_ASSOC); $email = $row ? rsEmailDoUsuario($pdo, $row['nome_completo']) : null;
    if ($row && $email) {
        $cod = sprintf('%06d', random_int(0, 999999)); $salt = bin2hex(random_bytes(8)); $prim = rsPrimeiro($row['nome_completo']);
        $html = rsLayout('Código para redefinir sua senha', '<p>Olá, <b>' . $h($prim) . '</b>.</p><p>Recebemos um pedido para redefinir a senha do seu acesso à Escala e ao Ponto. Use o código abaixo (válido por ' . ESC_SENHA_MIN . ' minutos):</p>' .
            '<p style="margin:18px 0;text-align:center"><span style="display:inline-block;background:#eff6ff;border:1px solid #bfdbfe;padding:14px 26px;font-size:32px;font-weight:bold;letter-spacing:8px;color:#1d4ed8;font-family:Consolas,Menlo,monospace">' . $h($cod) . '</span></p>' .
            '<p style="font-size:13px;color:#4b5563">Digite o código na tela de recuperação de senha e escolha a nova senha. Se você <b>não</b> fez esse pedido, ignore este e-mail: a sua senha continua a mesma. Nunca compartilhe este código.</p>' .
            '<p style="font-size:11px;color:#6b7280">Pedido feito em ' . date('d/m/Y H:i') . '.</p>');
        $r = smtpEnviar($cfg, $email, 'Seu código para redefinir a senha — SmartNOC', $html, "Olá, $prim.\n\nSeu código para redefinir a senha: $cod\nVálido por " . ESC_SENHA_MIN . " minutos. Se você não fez esse pedido, ignore este e-mail.\n");
        if (!$r['ok']) { error_log('escala: e-mail de recuperação de senha: ' . $r['erro']); rsErro('Não foi possível enviar o e-mail agora. Tente novamente em instantes ou fale com o gestor.'); }
        $pdo->prepare("UPDATE escala_reset_senha SET usado = TRUE WHERE usuario = :u AND NOT usado")->execute(['u' => $u]);
        $pdo->prepare("UPDATE escala_reset_senha SET salt = :s, codigo_hash = :c, expira_em = now() + interval '" . ESC_SENHA_MIN . " minutes', usado = FALSE WHERE id = :id")
            ->execute(['s' => $salt, 'c' => rsHash($salt, $u, $cod), 'id' => $idR]);
    }
    rsAuditar($pdo, $u, 'Pediu código para redefinir a senha (tela de login da Escala)' . ($row && $email ? '' : ' — nada enviado: usuário inexistente ou sem e-mail cadastrado') . ' · IP ' . $ip);
    rsResp(['sucesso' => true, 'mensagem' => 'Se este usuário tiver um e-mail cadastrado, enviamos um código de 6 dígitos (válido por ' . ESC_SENHA_MIN . ' minutos). Confira também a caixa de spam.']);
}
if ($acao === 'redefinir') {
    $u = trim((string)($in['usuario'] ?? '')); $cod = preg_replace('/\D/', '', (string)($in['codigo'] ?? '')); $nova = (string)($in['senha'] ?? '');
    if ($u === '' || mb_strlen($u) > 100 || strlen($cod) !== 6) rsErro('Informe o usuário e o código de 6 dígitos.');
    if ($f = rsFalhas($nova)) rsErro('A senha precisa ter: ' . implode('; ', $f) . '.');
    rsTabela($pdo);
    $st = $pdo->prepare("SELECT * FROM escala_reset_senha WHERE usuario = :u AND NOT usado AND codigo_hash IS NOT NULL AND expira_em > now() ORDER BY id DESC LIMIT 1");
    $st->execute(['u' => $u]); $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) rsErro('Código inválido ou expirado. Solicite um novo código.');
    if (!hash_equals($r['codigo_hash'], rsHash($r['salt'], $u, $cod))) {
        $pdo->prepare("UPDATE escala_reset_senha SET tentativas = tentativas + 1, usado = (tentativas + 1 >= " . ESC_SENHA_TENT . ") WHERE id = :id")->execute(['id' => $r['id']]);
        rsErro((int)$r['tentativas'] + 1 >= ESC_SENHA_TENT ? 'Código incorreto. Foram muitas tentativas: solicite um novo código.' : 'Código incorreto. Confira o e-mail recebido.');
    }
    $st = $pdo->prepare("SELECT usuario, senha, nome_completo FROM usuarios WHERE usuario = :u"); $st->execute(['u' => $u]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) rsErro('Código inválido ou expirado. Solicite um novo código.');
    if (hash_equals((string)$row['senha'], $nova)) rsErro('A nova senha deve ser diferente da atual.');
    $uso = $pdo->prepare("UPDATE escala_reset_senha SET usado = TRUE WHERE id = :id AND NOT usado"); $uso->execute(['id' => $r['id']]);
    if ($uso->rowCount() !== 1) rsErro('Código inválido ou expirado. Solicite um novo código.');
    // a Escala e o Ponto comparam a senha no mesmo formato do cadastro de usuários da Escala
    $pdo->prepare("UPDATE usuarios SET senha = :s WHERE usuario = :u")->execute(['s' => $nova, 'u' => $u]);
    $pdo->prepare("UPDATE escala_reset_senha SET usado = TRUE WHERE usuario = :u")->execute(['u' => $u]);
    try { if ($pdo->query("SELECT to_regclass('ponto_login_falhas')")->fetchColumn()) $pdo->prepare("DELETE FROM ponto_login_falhas WHERE chave = :k")->execute(['k' => 'u:' . mb_strtolower(mb_substr($u, 0, 60))]); } catch (Exception $e) {}
    rsAuditar($pdo, $u, 'Redefiniu a própria senha pelo "Esqueci minha senha" da Escala · IP ' . rsIp());
    $email = rsEmailDoUsuario($pdo, $row['nome_completo']);
    if ($email) {
        $prim = rsPrimeiro($row['nome_completo']);
        $x = smtpEnviar(smtpConfigLer($pdo), $email, 'Sua senha foi alterada — SmartNOC', rsLayout('Senha alterada', '<p>Olá, <b>' . $h($prim) . '</b>.</p><p>A senha do seu acesso à Escala e ao Ponto foi alterada em <b>' . date('d/m/Y H:i') . '</b>.</p><p style="font-size:13px;color:#b91c1c"><b>Não foi você?</b> Fale com o seu supervisor imediatamente.</p>'),
            "Olá, $prim.\n\nA senha do seu acesso foi alterada em " . date('d/m/Y H:i') . ". Se não foi você, fale com o seu supervisor imediatamente.\n");
        if (!$x['ok']) error_log('escala: aviso de senha alterada: ' . $x['erro']);
    }
    rsResp(['sucesso' => true]);
}
rsErro('Ação inválida.');
