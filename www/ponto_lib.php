<?php
// ponto_lib.php — Ponto eletrônico com reconhecimento facial (comparação no servidor), geolocalização,
// regra de horário configurável, alertas de batidas ímpares/faltantes e batidas manuais com aprovação.
// Incluído por api_ponto.php. Não imprime nada por si só.

date_default_timezone_set('America/Sao_Paulo');

const PT_TIPOS = ['ENTRADA', 'SAIDA_ALMOCO', 'RETORNO_ALMOCO', 'SAIDA'];
const PT_LABEL = ['ENTRADA' => 'Início da atividade', 'SAIDA_ALMOCO' => 'Saída para o almoço',
                  'RETORNO_ALMOCO' => 'Retorno do almoço', 'SAIDA' => 'Fim de expediente'];
const PT_DIST_MAX = 0.5;        // distância euclidiana máxima entre descritores faciais (menor = mais parecido)
const PT_JANELA_DIAS = 35;      // quantos dias para trás o colaborador pode corrigir/ver alertas
const PT_ATIVOS = "('valida','pendente','aprovada')";

function ptGarantirTabelas($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS configuracoes (chave VARCHAR(100) PRIMARY KEY, valor TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ponto_colaboradores (
        nome VARCHAR(150) PRIMARY KEY,
        pin_hash TEXT,
        pin_provisorio BOOLEAN NOT NULL DEFAULT TRUE,
        descritor TEXT,
        foto_cadastro TEXT,
        cadastrado_em TIMESTAMP,
        ativo BOOLEAN NOT NULL DEFAULT TRUE,
        tentativas INT NOT NULL DEFAULT 0,
        bloqueado_ate TIMESTAMP,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ponto_batidas (
        id SERIAL PRIMARY KEY,
        nome VARCHAR(150) NOT NULL,
        data_ref DATE NOT NULL,
        tipo VARCHAR(20) NOT NULL,
        momento TIMESTAMP NOT NULL,
        origem VARCHAR(10) NOT NULL,
        foto TEXT,
        lat DOUBLE PRECISION,
        lng DOUBLE PRECISION,
        precisao REAL,
        distancia REAL,
        justificativa TEXT,
        status VARCHAR(10) NOT NULL DEFAULT 'valida',
        decidido_por VARCHAR(100),
        decidido_em TIMESTAMP,
        motivo_decisao TEXT,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pb_nome_data ON ponto_batidas (nome, data_ref)");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_pb_ativa ON ponto_batidas (nome, data_ref, tipo) WHERE status IN ('valida','pendente','aprovada')");
}

// ---------- utilitários ----------

function ptMin($hhmm) {
    if (!is_string($hhmm) || !preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $hhmm, $m)) return null;
    return (int)$m[1] * 60 + (int)$m[2];
}
function ptHHMM($min) { $min = max(0, min(1439, (int)round($min))); return sprintf('%02d:%02d', intdiv($min, 60), $min % 60); }
function ptDataValida($d) {
    if (!is_string($d) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return false;
    [$y, $m, $dd] = array_map('intval', explode('-', $d));
    return checkdate($m, $dd, $y);
}
function ptMinDoMomento($ts) { return (int)date('G', strtotime($ts)) * 60 + (int)date('i', strtotime($ts)); }

// ---------- regra de horário ----------

function ptRegraPadrao() {
    $dia = function ($fim) { return ['ini' => '08:00', 'alm_ini' => '12:00', 'alm_fim' => '13:00', 'fim' => $fim]; };
    return ['tolerancia' => 10, 'dias' => [
        '0' => null, '1' => $dia('18:00'), '2' => $dia('18:00'), '3' => $dia('18:00'), '4' => $dia('18:00'),
        '5' => $dia('17:00'), '6' => null]];
}

function ptRegraValidar($r) {
    if (!is_array($r) || !isset($r['dias']) || !is_array($r['dias'])) return 'Regra inválida.';
    $tol = $r['tolerancia'] ?? 10;
    if (!is_numeric($tol) || $tol < 0 || $tol > 60) return 'A tolerância deve ficar entre 0 e 60 minutos.';
    $nomes = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
    for ($i = 0; $i < 7; $i++) {
        $d = $r['dias'][(string)$i] ?? null;
        if ($d === null) continue;
        $a = ptMin($d['ini'] ?? null); $b = ptMin($d['alm_ini'] ?? null); $c = ptMin($d['alm_fim'] ?? null); $e = ptMin($d['fim'] ?? null);
        if ($a === null || $b === null || $c === null || $e === null) return $nomes[$i] . ': informe os quatro horários (HH:MM).';
        if (!($a < $b && $b < $c && $c < $e)) return $nomes[$i] . ': os horários devem seguir a ordem início < saída almoço < retorno < fim.';
    }
    return null;
}

function ptRegra($pdo) {
    $st = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'ponto_regra'");
    $st->execute();
    $v = $st->fetchColumn();
    $r = $v ? json_decode($v, true) : null;
    if (!is_array($r) || ptRegraValidar($r)) return ptRegraPadrao();
    return $r;
}

function ptRegraSalvar($pdo, $r) {
    $limpa = ['tolerancia' => (int)($r['tolerancia'] ?? 10), 'dias' => []];
    for ($i = 0; $i < 7; $i++) {
        $d = $r['dias'][(string)$i] ?? null;
        $limpa['dias'][(string)$i] = $d === null ? null :
            ['ini' => $d['ini'], 'alm_ini' => $d['alm_ini'], 'alm_fim' => $d['alm_fim'], 'fim' => $d['fim']];
    }
    $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('ponto_regra', :v)
                   ON CONFLICT (chave) DO UPDATE SET valor = EXCLUDED.valor")->execute(['v' => json_encode($limpa)]);
}

// ---------- batidas do dia, sequência e sugestões ----------

function ptBatidasDoDia($pdo, $nome, $data) {
    $st = $pdo->prepare("SELECT id, tipo, to_char(momento,'YYYY-MM-DD HH24:MI:SS') AS momento, origem, status, justificativa,
                                lat, lng, precisao, distancia, (foto IS NOT NULL) AS tem_foto, motivo_decisao, decidido_por
                         FROM ponto_batidas WHERE nome = :n AND data_ref = :d ORDER BY momento, id");
    $st->execute(['n' => $nome, 'd' => $data]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
function ptAtivas($batidas) {
    return array_values(array_filter($batidas, function ($b) { return in_array($b['status'], ['valida', 'pendente', 'aprovada'], true); }));
}
function ptProximoTipo($batidas) {
    $tem = array_column(ptAtivas($batidas), 'tipo');
    foreach (PT_TIPOS as $t) if (!in_array($t, $tem, true)) return $t;
    return null;
}

// Verifica se $momento (Y-m-d H:i:s) respeita a ordem cronológica das demais batidas ativas do dia
function ptValidarOrdem($batidas, $tipo, $momento) {
    $idx = array_search($tipo, PT_TIPOS, true);
    $ts = strtotime($momento);
    foreach (ptAtivas($batidas) as $b) {
        $j = array_search($b['tipo'], PT_TIPOS, true);
        $tb = strtotime($b['momento']);
        if ($j < $idx && $tb >= $ts) return 'O horário deve ser posterior a "' . PT_LABEL[$b['tipo']] . '" (' . date('H:i', $tb) . ').';
        if ($j > $idx && $tb <= $ts) return 'O horário deve ser anterior a "' . PT_LABEL[$b['tipo']] . '" (' . date('H:i', $tb) . ').';
    }
    return null;
}

// Sugere horários para as batidas que faltam, a partir da regra e das batidas já feitas no dia.
// Ex.: entrou 08:20 e fez 1h de almoço => a saída sugerida é 18:20 (cumpre a jornada líquida da regra).
function ptSugestoes($regra, $data, $batidas) {
    $r = $regra['dias'][(string)date('w', strtotime($data))] ?? null;
    if (!$r) return [];
    $ini = ptMin($r['ini']); $ai = ptMin($r['alm_ini']); $af = ptMin($r['alm_fim']); $fim = ptMin($r['fim']);
    $jornada = ($fim - $ini) - ($af - $ai);
    $almDur = $af - $ai;
    $real = [];
    foreach (ptAtivas($batidas) as $b) $real[$b['tipo']] = ptMinDoMomento($b['momento']);

    $e = $real['ENTRADA'] ?? $ini;
    $sa = $real['SAIDA_ALMOCO'] ?? max($ai, $e + 1);
    $ra = $real['RETORNO_ALMOCO'] ?? ($sa + $almDur);
    $s = $real['SAIDA'] ?? ($ra + max(0, $jornada - ($sa - $e)));
    $calc = ['ENTRADA' => $e, 'SAIDA_ALMOCO' => $sa, 'RETORNO_ALMOCO' => $ra, 'SAIDA' => $s];
    $out = [];
    foreach (PT_TIPOS as $t) if (!isset($real[$t])) $out[$t] = ptHHMM($calc[$t]);
    return $out;
}

function ptPascoa($ano) { // algoritmo de Gauss/Meeus (não depende da extensão calendar do PHP)
    $a = $ano % 19; $b = intdiv($ano, 100); $c = $ano % 100; $d = intdiv($b, 4); $e = $b % 4; $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3); $h = (19 * $a + $b - $d - $g + 15) % 30; $i = intdiv($c, 4); $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7; $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $mes = intdiv($h + $l - 7 * $m + 114, 31); $dia = (($h + $l - 7 * $m + 114) % 31) + 1;
    return sprintf('%04d-%02d-%02d', $ano, $mes, $dia);
}
function ptLen($s) { return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s); }

// Feriados fixos cadastrados + móveis nacionais principais: dia sem obrigação de ponto
function ptEhFeriado($pdo, $data) {
    $st = $pdo->prepare("SELECT 1 FROM feriados WHERE tipo = 'nacional' AND to_char(data_feriado,'MM-DD') = :md LIMIT 1");
    try { $st->execute(['md' => substr($data, 5, 5)]); if ($st->fetchColumn()) return true; } catch (Exception $e) {}
    $pasc = ptPascoa((int)substr($data, 0, 4));
    foreach ([-47, -2, 60] as $off) if (date('Y-m-d', strtotime($pasc . ' ' . sprintf('%+d', $off) . ' days')) === $data) return true;
    return false;
}
function ptDiaSemObrigacao($pdo, $nome, $data) {
    if (ptEhFeriado($pdo, $data)) return true;
    try {
        $st = $pdo->prepare("SELECT 1 FROM escala_dados WHERE nome_colaborador = :n AND data_escala = :d AND status NOT LIKE 'plantao%' LIMIT 1");
        $st->execute(['n' => $nome, 'd' => $data]);
        return (bool)$st->fetchColumn();
    } catch (Exception $e) { return false; }
}

// Situação completa de um dia
function ptStatusDia($pdo, $regra, $nome, $data, $agora = null) {
    $agora = $agora ?: time();
    $hoje = date('Y-m-d', $agora);
    $batidas = ptBatidasDoDia($pdo, $nome, $data);
    $ativas = ptAtivas($batidas);
    $tem = array_column($ativas, 'tipo');
    $faltam = array_values(array_diff(PT_TIPOS, $tem));
    $r = $regra['dias'][(string)date('w', strtotime($data))] ?? null;
    $sem = ptDiaSemObrigacao($pdo, $nome, $data);
    $alerta = null; $atrasadas = [];

    if ($r && !$sem && $faltam) {
        if ($data < $hoje) {
            $alerta = count($ativas) === 0 ? 'Nenhuma batida registrada neste dia.'
                : 'Batidas incompletas — faltam: ' . implode(', ', array_map(function ($t) { return PT_LABEL[$t]; }, $faltam)) . '.';
        } elseif ($data === $hoje) {
            $min = (int)date('G', $agora) * 60 + (int)date('i', $agora);
            $prog = ['ENTRADA' => ptMin($r['ini']), 'SAIDA_ALMOCO' => ptMin($r['alm_ini']), 'RETORNO_ALMOCO' => ptMin($r['alm_fim']), 'SAIDA' => ptMin($r['fim'])];
            foreach ($faltam as $t) if ($min > $prog[$t] + (int)$regra['tolerancia']) $atrasadas[] = $t;
            if ($atrasadas) $alerta = 'Batida pendente: ' . implode(', ', array_map(function ($t) { return PT_LABEL[$t]; }, $atrasadas)) . '.';
        }
    }
    $trab = null; $saldo = null;
    if (!$faltam) {
        $m = []; foreach ($ativas as $b) $m[$b['tipo']] = strtotime($b['momento']);
        $trab = (int)round((($m['SAIDA_ALMOCO'] - $m['ENTRADA']) + ($m['SAIDA'] - $m['RETORNO_ALMOCO'])) / 60);
        if ($r) $saldo = $trab - ((ptMin($r['fim']) - ptMin($r['ini'])) - (ptMin($r['alm_fim']) - ptMin($r['alm_ini'])));
    }
    return ['data' => $data, 'dow' => (int)date('w', strtotime($data)), 'tem_regra' => (bool)$r, 'sem_obrigacao' => $sem,
            'batidas' => $batidas, 'faltam' => $faltam, 'proximo' => ptProximoTipo($batidas), 'alerta' => $alerta,
            'sugestoes' => ptSugestoes($regra, $data, $batidas), 'trabalhado_min' => $trab, 'saldo_min' => $saldo,
            'pendentes' => count(array_filter($batidas, function ($b) { return $b['status'] === 'pendente'; }))];
}

// ---------- reconhecimento facial (comparação no servidor; o modelo gera o descritor no navegador) ----------

function ptDescritorValido($d) {
    if (!is_array($d) || count($d) !== 128) return false;
    foreach ($d as $v) if (!is_int($v) && !is_float($v)) return false;
    foreach ($d as $v) if (!is_finite((float)$v) || abs($v) > 5) return false;
    return true;
}
function ptDistancia($a, $b) {
    $s = 0.0;
    for ($i = 0; $i < 128; $i++) { $x = (float)$a[$i] - (float)$b[$i]; $s += $x * $x; }
    return sqrt($s);
}
function ptFotoValida($f) {
    return is_string($f) && strlen($f) <= 200000 && preg_match('#^data:image/jpeg;base64,[A-Za-z0-9+/=]+$#', $f);
}
function ptCoordValida($lat, $lng, $prec) {
    return is_numeric($lat) && is_numeric($lng) && $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180
        && (!isset($prec) || is_numeric($prec));
}
