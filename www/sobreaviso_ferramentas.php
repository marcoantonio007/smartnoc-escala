<?php
// SO_FERRAMENTAS_V1 — ferramentas do sobreaviso:
//  so_folha_atualizar: reaplica os períodos de sobreaviso atuais (Configurações → Sobreaviso) a todos os sobreavisos já lançados numa folha (15 a 14);
//  so_pontual: lança um sobreaviso pontual com horário escolhido no lançamento e observação opcional (status plantao_pontual, não é recalculado).
function soGarantirObs($pdo) {
    static $ok = false; if ($ok) return; $ok = true;
    $pdo->exec("ALTER TABLE escala_dados ADD COLUMN IF NOT EXISTS observacao TEXT");
}
function soFolhaPeriodo($comp) { // 'AAAA-MM' (mês da folha) => [ini, fim] = 15 do mês anterior a 14 do mês
    if (!preg_match('/^(\d{4})-(\d{2})$/', (string)$comp, $m) || (int)$m[2] < 1 || (int)$m[2] > 12) return null;
    $fim = sprintf('%04d-%02d-14', (int)$m[1], (int)$m[2]);
    return [date('Y-m-15', strtotime(sprintf('%04d-%02d-01', (int)$m[1], (int)$m[2]) . ' -1 month')), $fim];
}
function soRotear($pdo, $action, $usuario) {
    header('Content-Type: application/json; charset=utf-8');
    $resp = function ($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; };
    $perfil = $_SESSION['perfil'] ?? '';
    if ($perfil !== 'admin' && $perfil !== 'mestre') $resp(['sucesso' => false, 'erro' => 'Apenas gestores podem usar esta função.']);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') $resp(['sucesso' => false, 'erro' => 'Requisição inválida.']);
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    soGarantirObs($pdo);
    $fer = $pdo->query("SELECT data_feriado FROM feriados WHERE tipo = 'nacional'")->fetchAll(PDO::FETCH_COLUMN);

    if ($action === 'so_folha_atualizar') {
        $per = soFolhaPeriodo($in['folha'] ?? ''); if (!$per) $resp(['sucesso' => false, 'erro' => 'Folha inválida.']);
        $simular = !empty($in['simular']);
        $rows = $pdo->prepare("SELECT nome_colaborador AS nome, to_char(data_escala,'YYYY-MM-DD') AS d, max(status) AS status, max(arquivo_anexo) AS anexo, max(observacao) AS obs
                               FROM escala_dados WHERE status LIKE 'plantao%' AND status <> 'plantao_pontual' AND data_escala BETWEEN :a AND :b GROUP BY 1, 2 ORDER BY 2, 1");
        $rows->execute(['a' => $per[0], 'b' => $per[1]]); $rows = $rows->fetchAll(PDO::FETCH_ASSOC);
        $atual = $pdo->prepare("SELECT to_char(data_inicio,'YYYY-MM-DD') || ' ' || hora_inicio || '-' || hora_fim FROM escala_dados WHERE nome_colaborador = :n AND data_escala = :d ORDER BY data_inicio, hora_inicio");
        $del = $pdo->prepare("DELETE FROM escala_dados WHERE nome_colaborador = :n AND data_escala = :d");
        $ins = $pdo->prepare("INSERT INTO escala_dados (nome_colaborador, data_escala, status, horas_total, arquivo_anexo, data_inicio, hora_inicio, data_fim, hora_fim, observacao) VALUES (:n, :d, :s, :h, :a, :di, :hi, :df, :hf, :o)");
        $mudar = []; $pontuais = (int)$pdo->query("SELECT count(DISTINCT (nome_colaborador, data_escala)) FROM escala_dados WHERE status = 'plantao_pontual' AND data_escala BETWEEN '{$per[0]}' AND '{$per[1]}'")->fetchColumn();
        foreach ($rows as $r) {
            $segs = function_exists('escComMunicipais')
                ? escComMunicipais($pdo, $r['nome'], function () use ($pdo, $r, $fer) { return pgSegmentosPlantao($pdo, $r['d'], $fer); })
                : pgSegmentosPlantao($pdo, $r['d'], $fer);
            $atual->execute(['n' => $r['nome'], 'd' => $r['d']]);
            $novo = array_map(function ($s) { return $s['d_ini'] . ' ' . $s['h_ini'] . '-' . $s['h_fim']; }, $segs);
            if ($atual->fetchAll(PDO::FETCH_COLUMN) !== $novo) $mudar[] = [$r, $segs];
        }
        $colabs = count(array_unique(array_map(function ($x) { return $x[0]['nome']; }, $mudar)));
        if ($simular) $resp(['sucesso' => true, 'periodo' => $per, 'total' => count($rows), 'alterar' => count($mudar), 'colaboradores' => $colabs, 'pontuais' => $pontuais]);
        $pdo->beginTransaction();
        try {
            foreach ($mudar as $x) {
                list($r, $segs) = $x;
                $del->execute(['n' => $r['nome'], 'd' => $r['d']]);
                foreach ($segs as $s) $ins->execute(['n' => $r['nome'], 'd' => $r['d'], 's' => $r['status'], 'h' => $s['horas'], 'a' => $r['anexo'], 'di' => $s['d_ini'], 'hi' => $s['h_ini'], 'df' => $s['d_fim'], 'hf' => $s['h_fim'], 'o' => $r['obs']]);
            }
            $pdo->commit();
        } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $resp(['sucesso' => false, 'erro' => 'Nada foi alterado: ' . $e->getMessage()]); }
        if (function_exists('registrarAuditoria')) registrarAuditoria($pdo, $usuario, 'ATUALIZAR SOBREAVISO', 'Atualizou os horários de ' . count($mudar) . ' dia(s) de sobreaviso (' . $colabs . ' colaborador(es)) da folha ' . date('d/m/Y', strtotime($per[0])) . ' a ' . date('d/m/Y', strtotime($per[1])) . ' conforme os períodos atuais');
        $resp(['sucesso' => true, 'alterados' => count($mudar), 'total' => count($rows), 'colaboradores' => $colabs]);
    }

    if ($action === 'so_pontual') {
        $nome = trim((string)($in['nome'] ?? '')); $d = (string)($in['data'] ?? '');
        $hi = (string)($in['hora_inicio'] ?? ''); $hf = (string)($in['hora_fim'] ?? '');
        $obs = trim(preg_replace('/\s+/u', ' ', (string)($in['observacao'] ?? '')));
        $p = explode('-', $d);
        if ($nome === '' || count($p) !== 3 || !checkdate((int)$p[1], (int)$p[2], (int)$p[0])) $resp(['sucesso' => false, 'erro' => 'Colaborador ou dia inválido.']);
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hi) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hf)) $resp(['sucesso' => false, 'erro' => 'Informe o horário de início e de fim (HH:MM).']);
        if (mb_strlen($obs) > 300) $resp(['sucesso' => false, 'erro' => 'A observação pode ter no máximo 300 caracteres.']);
        $st = $pdo->prepare("SELECT 1 FROM funcionarios WHERE nome = :n"); $st->execute(['n' => $nome]);
        if (!$st->fetchColumn()) $resp(['sucesso' => false, 'erro' => 'Colaborador não encontrado.']);
        $a = (int)substr($hi, 0, 2) * 60 + (int)substr($hi, 3, 2); $b = (int)substr($hf, 0, 2) * 60 + (int)substr($hf, 3, 2);
        if ($b <= $a) $b += 1440; // fim no dia seguinte
        if ($b - $a > 1440) $resp(['sucesso' => false, 'erro' => 'O sobreaviso pontual pode ter no máximo 24h.']);
        $st = $pdo->prepare("SELECT max(status) FROM escala_dados WHERE nome_colaborador = :n AND data_escala = :d"); $st->execute(['n' => $nome, 'd' => $d]);
        $atualSt = (string)$st->fetchColumn();
        if (function_exists('soRegras') && soRegras($pdo)['bloquear_ausencia'] && in_array($atualSt, ['ferias', 'atestado', 'licenca', 'folga_aniversario'], true))
            $resp(['sucesso' => false, 'erro' => $nome . ' tem ' . strtoupper($atualSt) . ' neste dia. Remova a ausência primeiro.']);
        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM escala_dados WHERE nome_colaborador = :n AND data_escala = :d")->execute(['n' => $nome, 'd' => $d]);
            $ins = $pdo->prepare("INSERT INTO escala_dados (nome_colaborador, data_escala, status, horas_total, arquivo_anexo, data_inicio, hora_inicio, data_fim, hora_fim, observacao) VALUES (:n, :d, 'plantao_pontual', :h, NULL, :di, :hi, :df, :hf, :o)");
            foreach (pgFatiar($a, $b) as $s) {
                $dd = date('Y-m-d', strtotime($d . ' +' . $s[0] . ' day'));
                $ins->execute(['n' => $nome, 'd' => $d, 'h' => $s[3], 'di' => $dd, 'hi' => $s[1], 'df' => $dd, 'hf' => $s[2], 'o' => $obs !== '' ? $obs : null]);
            }
            $pdo->commit();
        } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $resp(['sucesso' => false, 'erro' => $e->getMessage()]); }
        $dur = $b - $a;
        if (function_exists('registrarAuditoria')) registrarAuditoria($pdo, $usuario, 'SOBREAVISO PONTUAL', "Lançou sobreaviso pontual de {$nome} em " . date('d/m/Y', strtotime($d)) . " das {$hi} às {$hf}" . ($b > 1440 ? ' (+1 dia)' : '') . ' — ' . sprintf('%dh%02d', intdiv($dur, 60), $dur % 60) . ($obs !== '' ? ' · Obs.: ' . $obs : ''));
        $resp(['sucesso' => true]);
    }
    if ($action === 'so_meu_email') { // DASH_MEU_EMAIL_V1: e-mail do colaborador ligado ao login (pelo nome completo)
        $st = $pdo->prepare("SELECT nome_completo FROM usuarios WHERE usuario = :u"); $st->execute(['u' => $_SESSION['usuario'] ?? '']);
        $nc = trim((string)$st->fetchColumn()); $email = '';
        if ($nc !== '') {
            try { $q = $pdo->prepare("SELECT email FROM funcionarios WHERE upper(nome_completo) = upper(:a) OR upper(nome) = upper(:b) ORDER BY (upper(nome_completo) = upper(:c)) DESC LIMIT 1"); $q->execute(['a' => $nc, 'b' => $nc, 'c' => $nc]); $email = trim((string)$q->fetchColumn()); } catch (Exception $e) {}
            if ($email === '') try { $q = $pdo->prepare("SELECT p.email FROM ponto_colaboradores p JOIN funcionarios f ON f.nome = p.nome WHERE upper(f.nome_completo) = upper(:a) OR upper(f.nome) = upper(:b) LIMIT 1"); $q->execute(['a' => $nc, 'b' => $nc]); $email = trim((string)$q->fetchColumn()); } catch (Exception $e) {}
        }
        $resp(['sucesso' => true, 'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null]);
    }
    if ($action === 'so_dash_email') { // DASH_PDF_V1: envia o PDF do Dashboard Gerencial por e-mail
        require_once __DIR__ . '/smtp_mail.php';
        $cfg = smtpConfigLer($pdo);
        if (!smtpConfigurado($cfg)) $resp(['sucesso' => false, 'erro' => 'Configure o e-mail (SMTP) em Configurações → E-mail.']);
        $para = array_values(array_unique(array_filter(array_map('trim', (array)($in['para'] ?? [])))));
        if (!$para || count($para) > 20) $resp(['sucesso' => false, 'erro' => 'Informe de 1 a 20 e-mails.']);
        foreach ($para as $e) if (!filter_var($e, FILTER_VALIDATE_EMAIL)) $resp(['sucesso' => false, 'erro' => 'E-mail inválido: ' . $e]);
        $pdf = base64_decode((string)($in['pdf'] ?? ''), true);
        if ($pdf === false || substr($pdf, 0, 5) !== '%PDF-' || strlen($pdf) > 8 * 1024 * 1024) $resp(['sucesso' => false, 'erro' => 'PDF inválido.']);
        $tit = mb_substr(trim((string)($in['titulo'] ?? '')), 0, 60);
        $assunto = mb_substr(trim((string)($in['assunto'] ?? '')), 0, 150); if ($assunto === '') $assunto = 'Dashboard Gerencial — ' . $tit;
        $arq = preg_replace('/[^A-Za-z0-9_.-]+/', '_', (string)($in['arquivo'] ?? 'Dashboard.pdf')); if (!preg_match('/\.pdf$/i', $arq)) $arq .= '.pdf';
        $msg = mb_substr(trim((string)($in['mensagem'] ?? '')), 0, 2000);
        $h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
        $html = '<div style="font-family:Arial,Helvetica,sans-serif;color:#1f2937;max-width:560px">' .
            '<div style="background:#1e3a8a;color:#fff;padding:12px 18px;font-weight:bold;border-radius:8px 8px 0 0">Plantão Técnico — Dashboard Gerencial</div>' .
            '<div style="border:1px solid #e5e7eb;border-top:0;padding:18px;border-radius:0 0 8px 8px">' .
            ($msg !== '' ? '<p style="white-space:pre-line">' . $h($msg) . '</p>' : '') .
            '<p>Segue em anexo o Dashboard Gerencial da folha <b>' . $h($tit) . '</b> (sobreaviso, hora extra e tickets extras).</p>' .
            '<p style="font-size:12px;color:#6b7280">Enviado por ' . $h($usuario) . ' em ' . date('d/m/Y H:i') . '.</p></div></div>';
        $ok = 0; $falhas = [];
        foreach ($para as $e) {
            $r = smtpEnviar($cfg, $e, $assunto, $html, ($msg !== '' ? $msg . "\n\n" : '') . "Segue em anexo o Dashboard Gerencial da folha $tit.", [['nome' => $arq, 'tipo' => 'application/pdf', 'dados' => $pdf]]);
            if ($r['ok']) $ok++; else { $falhas[] = $e; error_log('escala: e-mail do dashboard para ' . $e . ': ' . $r['erro']); }
        }
        if (!$ok) $resp(['sucesso' => false, 'erro' => 'Não foi possível enviar o e-mail. Verifique a configuração do SMTP.']);
        if (function_exists('registrarAuditoria')) registrarAuditoria($pdo, $usuario, 'RELATÓRIOS', 'Enviou o Dashboard Gerencial da folha ' . $tit . ' por e-mail para ' . implode(', ', array_diff($para, $falhas)));
        $resp(['sucesso' => true, 'enviados' => $ok, 'falhas' => $falhas]);
    }
    $resp(['sucesso' => false, 'erro' => 'Ação inválida.']);
}
