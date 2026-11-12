<?php
// hora_extra.php — Hora extra (VIDPOL), ticket extra e períodos de sobreaviso configuráveis.
// Incluído por api_escala.php. Não imprime nada por si só.

const HE_TICKET_MINUTOS = 120;                       // ticket extra: soma do dia ESTRITAMENTE maior que 2h
const PG_TIPOS = ['SEMANA', 'FIM_DE_SEMANA', 'FERIADO'];

function heEhGestor() {
    $p = $_SESSION['perfil'] ?? '';
    return ($p === 'admin' || $p === 'mestre');
}

function heGarantirTabelas($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS horas_extras (
        id SERIAL PRIMARY KEY,
        nome_colaborador VARCHAR(150) NOT NULL,
        data_escala DATE NOT NULL,
        hora_inicio VARCHAR(5) NOT NULL,
        hora_fim VARCHAR(5) NOT NULL,
        dia_inicio SMALLINT NOT NULL DEFAULT 0,
        vidpol CHAR(6) NOT NULL CHECK (vidpol ~ '^[0-9]{6}$'),
        minutos INT NOT NULL,
        criado_por VARCHAR(100),
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("ALTER TABLE horas_extras ADD COLUMN IF NOT EXISTS dia_inicio SMALLINT NOT NULL DEFAULT 0");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_he_colab_data ON horas_extras (nome_colaborador, data_escala)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS periodos_sobreaviso (
        id SERIAL PRIMARY KEY,
        tipo VARCHAR(20) NOT NULL,
        vigencia_inicio DATE NOT NULL,
        dia_inicio INT NOT NULL DEFAULT 0 CHECK (dia_inicio BETWEEN 0 AND 2),
        hora_inicio VARCHAR(5) NOT NULL,
        dia_fim INT NOT NULL DEFAULT 0 CHECK (dia_fim BETWEEN 0 AND 2),
        hora_fim VARCHAR(5) NOT NULL,
        criado_por VARCHAR(100),
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Vigência foi removida: se já havia mais de uma, mantém só a que está valendo hoje e unifica tudo em 2000-01-01
    $multi = $pdo->query("SELECT tipo FROM periodos_sobreaviso GROUP BY tipo HAVING COUNT(DISTINCT vigencia_inicio) > 1")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($multi as $tp) {
        $q = $pdo->prepare("SELECT COALESCE((SELECT MAX(vigencia_inicio) FROM periodos_sobreaviso WHERE tipo = :t AND vigencia_inicio <= CURRENT_DATE),
                                            (SELECT MIN(vigencia_inicio) FROM periodos_sobreaviso WHERE tipo = :t))");
        $q->execute(['t' => $tp]);
        $manter = $q->fetchColumn();
        $pdo->prepare("DELETE FROM periodos_sobreaviso WHERE tipo = :t AND vigencia_inicio <> :v")->execute(['t' => $tp, 'v' => $manter]);
    }
    if ($pdo->query("SELECT 1 FROM periodos_sobreaviso WHERE vigencia_inicio <> '2000-01-01' LIMIT 1")->fetchColumn()) {
        $pdo->exec("UPDATE periodos_sobreaviso SET vigencia_inicio = '2000-01-01'");
    }

    $qtd = (int)$pdo->query("SELECT COUNT(*) FROM periodos_sobreaviso")->fetchColumn();
    if ($qtd === 0) {
        $ins = $pdo->prepare("INSERT INTO periodos_sobreaviso (tipo, vigencia_inicio, dia_inicio, hora_inicio, dia_fim, hora_fim, criado_por)
                              VALUES (:t, '2000-01-01', :di, :hi, :df, :hf, 'sistema')");
        $padrao = [
            ['SEMANA',        0, '22:00', 1, '06:00'],
            ['FIM_DE_SEMANA', 0, '08:00', 0, '17:00'],
            ['FIM_DE_SEMANA', 0, '22:00', 1, '06:00'],
            ['FERIADO',       0, '08:00', 0, '17:00'],
            ['FERIADO',       0, '22:00', 1, '06:00'],
        ];
        foreach ($padrao as $p) $ins->execute(['t' => $p[0], 'di' => $p[1], 'hi' => $p[2], 'df' => $p[3], 'hf' => $p[4]]);
    }
}

// ---------- utilitários puros ----------

function heMin($hhmm) {            // "HH:MM" -> minutos, ou null se inválido
    if (!is_string($hhmm) || !preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $hhmm, $m)) return null;
    return (int)$m[1] * 60 + (int)$m[2];
}

function heDataValida($d) {
    if (!is_string($d) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return false;
    [$y, $mo, $da] = array_map('intval', explode('-', $d));
    return checkdate($mo, $da, $y);
}

// duração em minutos; se fim <= início o período termina no dia seguinte
function heDuracao($ini, $fim) {
    $a = heMin($ini); $b = heMin($fim);
    if ($a === null || $b === null) return null;
    return ($b > $a) ? ($b - $a) : ($b + 1440 - $a);
}

function heIntervalo($ini, $fim, $dia = 0) {  // [inicio, fim) em minutos desde 00:00 do dia da escala ($dia 1 = madrugada seguinte)
    $a = heMin($ini) + 1440 * (int)$dia; $d = heDuracao($ini, $fim);
    return [$a, $a + $d];
}

function heSobrepoe($a, $b) { return $a[0] < $b[1] && $b[0] < $a[1]; }

// ---------- hora extra ----------

function heListar($pdo, $inicio = null, $fim = null) {
    $sql = "SELECT id, nome_colaborador, to_char(data_escala,'YYYY-MM-DD') AS data, hora_inicio, hora_fim, dia_inicio, vidpol, minutos
            FROM horas_extras";
    $p = [];
    if ($inicio && $fim) { $sql .= " WHERE data_escala BETWEEN :i AND :f"; $p = ['i' => $inicio, 'f' => $fim]; }
    $sql .= " ORDER BY data_escala, hora_inicio, id";
    $st = $pdo->prepare($sql); $st->execute($p);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['id' => (int)$r['id'], 'nome' => $r['nome_colaborador'], 'data' => $r['data'],
                  'hora_inicio' => $r['hora_inicio'], 'hora_fim' => $r['hora_fim'], 'dia_inicio' => (int)$r['dia_inicio'],
                  'vidpol' => trim($r['vidpol']), 'minutos' => (int)$r['minutos']];
    }
    return $out;
}

// Períodos de hora extra batidos no Ponto (tabela ponto_hora_extra, mantida pelo Ponto) que o gestor já aprovou. Somente leitura aqui.
// Se o gestor já lançou à mão um período que se sobrepõe, vale o lançamento manual (evita contar duas vezes).
function heDoPonto($pdo, $manuais = []) {
    try {
        if (!$pdo->query("SELECT to_regclass('ponto_hora_extra')")->fetchColumn()) return [];
        $rows = $pdo->query("SELECT id, nome, to_char(data_ref,'YYYY-MM-DD') AS data, hora_inicio, hora_fim, dia_inicio, minutos, vidpols, origem
                             FROM ponto_hora_extra WHERE status = 'aprovada' ORDER BY data_ref, hora_inicio, id")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; }
    $abs = function ($data, $hi, $dia, $min) {
        $a = (int)floor(strtotime($data . ' UTC') / 86400) * 1440 + (int)$dia * 1440 + heMin($hi);
        return [$a, $a + $min];
    };
    $ocupado = [];
    foreach ($manuais as $m) $ocupado[$m['nome']][] = $abs($m['data'], $m['hora_inicio'], $m['dia_inicio'], (int)$m['minutos']);
    $out = [];
    foreach ($rows as $r) {
        if (heMin($r['hora_inicio']) === null) continue;
        $iv = $abs($r['data'], $r['hora_inicio'], $r['dia_inicio'], (int)$r['minutos']);
        $conflita = false;
        foreach ($ocupado[$r['nome']] ?? [] as $o) if (heSobrepoe($iv, $o)) { $conflita = true; break; }
        if ($conflita) continue;
        $vp = array_values(array_filter(explode(',', (string)$r['vidpols']), function ($v) { return preg_match('/^[0-9]{6}$/', $v); }));
        $out[] = ['id' => 'p' . (int)$r['id'], 'nome' => $r['nome'], 'data' => $r['data'], 'hora_inicio' => $r['hora_inicio'], 'hora_fim' => $r['hora_fim'],
                  'dia_inicio' => (int)$r['dia_inicio'], 'vidpol' => $vp ? $vp[0] : '', 'vidpols' => $vp, 'minutos' => (int)$r['minutos'],
                  'origem' => 'ponto', 'tipo_ponto' => $r['origem']];
    }
    return $out;
}

// Valida os dados; retorna string de erro ou null. $ignorarId: ao editar, ignora o próprio registro no teste de conflito.
function heValidar($pdo, $nome, $data, $hi, $hf, $vidpol, $dia, $ignorarId = 0) {
    if ($dia !== 0 && $dia !== 1) return 'Dia de início inválido.';
    if ($nome === '') return 'Colaborador não informado.';
    if (!preg_match('/^[0-9]{6}$/', $vidpol)) return 'O VIDPOL é obrigatório e deve ter exatamente 6 dígitos numéricos.';
    if (!heDataValida($data)) return 'Data inválida.';
    if (heMin($hi) === null || heMin($hf) === null) return 'Informe hora de início e de fim válidas (HH:MM).';
    if ($hi === $hf) return 'Hora de início e fim não podem ser iguais.';

    $st = $pdo->prepare("SELECT 1 FROM funcionarios WHERE nome = :n");
    $st->execute(['n' => $nome]);
    if (!$st->fetchColumn()) return 'Colaborador não encontrado.';

    // DASH_PATCH_V1: hora extra permitida mesmo sem sobreaviso no dia

    $novo = heIntervalo($hi, $hf, $dia);
    $st = $pdo->prepare("SELECT to_char(data_escala,'YYYY-MM-DD') AS d, hora_inicio, hora_fim, dia_inicio FROM horas_extras
                         WHERE nome_colaborador = :n AND id <> :x AND data_escala BETWEEN (CAST(:d AS date) - 1) AND (CAST(:d AS date) + 1)");
    $st->execute(['n' => $nome, 'd' => $data, 'x' => (int)$ignorarId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $off = (int)round((strtotime($r['d']) - strtotime($data)) / 86400) * 1440;
        $ex = heIntervalo($r['hora_inicio'], $r['hora_fim'], $r['dia_inicio']);
        $ex = [$ex[0] + $off, $ex[1] + $off];
        if (heSobrepoe($novo, $ex)) return 'Este período conflita com outra hora extra já registrada (' . $r['hora_inicio'] . '–' . $r['hora_fim'] . ').';
    }
    return null;
}

// retorna ['ok'=>true,'id'=>n,'minutos'=>m] ou ['ok'=>false,'erro'=>'...']
function heAdicionar($pdo, $nome, $data, $hi, $hf, $vidpol, $usuario, $dia = 0) {
    $nome = trim((string)$nome); $vidpol = (string)$vidpol; $dia = (int)$dia;
    $erro = heValidar($pdo, $nome, $data, $hi, $hf, $vidpol, $dia);
    if ($erro) return ['ok' => false, 'erro' => $erro];
    $min = heDuracao($hi, $hf);
    $ins = $pdo->prepare("INSERT INTO horas_extras (nome_colaborador, data_escala, hora_inicio, hora_fim, dia_inicio, vidpol, minutos, criado_por)
                          VALUES (:n, :d, :hi, :hf, :di, :v, :m, :u) RETURNING id");
    $ins->execute(['n' => $nome, 'd' => $data, 'hi' => $hi, 'hf' => $hf, 'di' => $dia, 'v' => $vidpol, 'm' => $min, 'u' => $usuario]);
    return ['ok' => true, 'id' => (int)$ins->fetchColumn(), 'minutos' => $min];
}

// Edita início, fim, dia de início e VIDPOL de um período já lançado (colaborador e dia não mudam).
function heEditar($pdo, $id, $hi, $hf, $vidpol, $dia, $usuario) {
    $id = (int)$id; $vidpol = (string)$vidpol; $dia = (int)$dia;
    $st = $pdo->prepare("SELECT nome_colaborador, to_char(data_escala,'YYYY-MM-DD') AS d, hora_inicio, hora_fim, dia_inicio, vidpol FROM horas_extras WHERE id = :id");
    $st->execute(['id' => $id]);
    $ant = $st->fetch(PDO::FETCH_ASSOC);
    if (!$ant) return ['ok' => false, 'erro' => 'Registro não encontrado.'];
    $erro = heValidar($pdo, $ant['nome_colaborador'], $ant['d'], $hi, $hf, $vidpol, $dia, $id);
    if ($erro) return ['ok' => false, 'erro' => $erro];
    $min = heDuracao($hi, $hf);
    $up = $pdo->prepare("UPDATE horas_extras SET hora_inicio = :hi, hora_fim = :hf, dia_inicio = :di, vidpol = :v, minutos = :m WHERE id = :id");
    $up->execute(['hi' => $hi, 'hf' => $hf, 'di' => $dia, 'v' => $vidpol, 'm' => $min, 'id' => $id]);
    return ['ok' => true, 'minutos' => $min, 'anterior' => $ant];
}

function heRemover($pdo, $id) {
    $st = $pdo->prepare("DELETE FROM horas_extras WHERE id = :id RETURNING nome_colaborador, to_char(data_escala,'YYYY-MM-DD') AS d, hora_inicio, hora_fim, vidpol");
    $st->execute(['id' => (int)$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ---------- períodos de sobreaviso ----------

// FER_MUNICIPAL_V2: feriado municipal vale só para quem tem a Localidade igual à cidade do feriado,
// e nesse dia o sobreaviso segue os mesmos horários (períodos) do fim de semana.
function escCidadeNorm($s) {
    $s = trim(function_exists('mb_strtoupper') ? mb_strtoupper((string)$s, 'UTF-8') : strtoupper((string)$s));
    $s = strtr($s, ['Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'É' => 'E', 'Ê' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ç' => 'C']);
    return trim(preg_replace('~\s*[/\-]\s*[A-Z]{2}$~', '', $s)); // "VITÓRIA DA CONQUISTA/BA" => "VITORIA DA CONQUISTA"
}
function escMunicipaisColab($pdo, $nome) { // ['MM-DD', ...] dos feriados municipais da cidade do colaborador
    static $c = [];
    if (array_key_exists($nome, $c)) return $c[$nome];
    $out = [];
    try {
        $st = $pdo->prepare("SELECT localidade FROM funcionarios WHERE nome = :n"); $st->execute(['n' => $nome]);
        $cid = escCidadeNorm($st->fetchColumn() ?: '');
        if ($cid !== '') foreach ($pdo->query("SELECT to_char(data_feriado,'MM-DD') AS md, localidade FROM feriados WHERE tipo = 'municipal' AND data_feriado IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $f)
            if (escCidadeNorm($f['localidade']) === $cid) $out[] = $f['md'];
    } catch (Exception $e) {}
    return $c[$nome] = $out;
}
function escComMunicipais($pdo, $nome, $fn) { // executa $fn considerando os feriados municipais do colaborador
    $ant = $GLOBALS['pgMunColab'] ?? null; $GLOBALS['pgMunColab'] = escMunicipaisColab($pdo, $nome);
    try { return $fn(); } finally { $GLOBALS['pgMunColab'] = $ant; }
}
// Recalcula os sobreavisos já lançados em dias de feriado municipal quando o cadastro de feriados ou as localidades mudam.
function escRecalcularMunicipaisSeMudou($pdo) {
    $hash = md5(json_encode([
        $pdo->query("SELECT string_agg(to_char(data_feriado,'MM-DD') || '|' || COALESCE(localidade, ''), ';' ORDER BY data_feriado, localidade) FROM feriados WHERE tipo = 'municipal'")->fetchColumn(),
        $pdo->query("SELECT string_agg(nome || '|' || COALESCE(localidade, ''), ';' ORDER BY nome) FROM funcionarios")->fetchColumn(), 'v2']));
    $q = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'esc_fer_mun_hash'"); $q->execute();
    if ($q->fetchColumn() === $hash) return 0;
    $fer = $pdo->query("SELECT data_feriado FROM feriados WHERE tipo = 'nacional'")->fetchAll(PDO::FETCH_COLUMN);
    $del = $pdo->prepare("DELETE FROM escala_dados WHERE nome_colaborador = :n AND data_escala = :d");
    $ins = $pdo->prepare("INSERT INTO escala_dados (nome_colaborador, data_escala, status, horas_total, arquivo_anexo, data_inicio, hora_inicio, data_fim, hora_fim) VALUES (:n, :d, :s, :h, :a, :di, :hi, :df, :hf)");
    $n = 0;
    $rows = $pdo->query("SELECT nome_colaborador AS nome, to_char(data_escala,'YYYY-MM-DD') AS d, max(status) AS status, max(arquivo_anexo) AS anexo FROM escala_dados WHERE status LIKE 'plantao%' AND status <> 'plantao_pontual' GROUP BY 1, 2")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $w = (int)date('w', strtotime($r['d']));
        if ($w === 0 || $w === 6 || in_array($r['d'], $fer)) continue; // fim de semana e feriado nacional já usam os períodos próprios
        $segs = escComMunicipais($pdo, $r['nome'], function () use ($pdo, $r, $fer) { return pgSegmentosPlantao($pdo, $r['d'], $fer); });
        $atual = $pdo->prepare( // reprocessa só o que mudou
            "SELECT to_char(data_inicio,'YYYY-MM-DD') || ' ' || hora_inicio || '-' || hora_fim FROM escala_dados WHERE nome_colaborador = :n AND data_escala = :d ORDER BY data_inicio, hora_inicio");
        $atual->execute(['n' => $r['nome'], 'd' => $r['d']]);
        $novo = array_map(function ($s) { return $s['d_ini'] . ' ' . $s['h_ini'] . '-' . $s['h_fim']; }, $segs);
        if ($atual->fetchAll(PDO::FETCH_COLUMN) === $novo) continue;
        $pdo->beginTransaction();
        try {
            $del->execute(['n' => $r['nome'], 'd' => $r['d']]);
            foreach ($segs as $s) $ins->execute(['n' => $r['nome'], 'd' => $r['d'], 's' => $r['status'], 'h' => $s['horas'], 'a' => $r['anexo'], 'di' => $s['d_ini'], 'hi' => $s['h_ini'], 'df' => $s['d_fim'], 'hf' => $s['h_fim']]);
            $pdo->commit(); $n++;
        } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); }
    }
    $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('esc_fer_mun_hash', :v) ON CONFLICT (chave) DO UPDATE SET valor = EXCLUDED.valor")->execute(['v' => $hash]);
    return $n;
}
function pgTipoDoDia($dataStr, $feriadosDB) {
    if (in_array($dataStr, $feriadosDB)) return 'FERIADO';
    if (!empty($GLOBALS['pgMunColab']) && in_array(substr($dataStr, 5, 5), $GLOBALS['pgMunColab'], true)) return 'FIM_DE_SEMANA'; // FER_MUNICIPAL_V2
    $w = (int)date('w', strtotime($dataStr));
    return ($w === 0 || $w === 6) ? 'FIM_DE_SEMANA' : 'SEMANA';
}

// intervalo absoluto do período, em minutos desde 00:00 do dia da escala
function pgIntervalo($dia_inicio, $hora_inicio, $dia_fim, $hora_fim) {
    $a = heMin($hora_inicio); $b = heMin($hora_fim);
    if ($a === null || $b === null) return null;
    $ini = $dia_inicio * 1440 + $a; $fim = $dia_fim * 1440 + $b;
    if ($fim <= $ini) return null;
    if ($fim - $ini > 48 * 60) return null;
    return [$ini, $fim];
}

// Sem vigência: os períodos do tipo valem para qualquer data lançada.
function pgPeriodosVigentes($pdo, $tipo, $dataStr = null) {
    $st = $pdo->prepare("SELECT dia_inicio, hora_inicio, dia_fim, hora_fim FROM periodos_sobreaviso WHERE tipo = :t ORDER BY dia_inicio, hora_inicio");
    $st->execute(['t' => $tipo]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// Fatia [ini,fim) (minutos desde 00:00 do dia base) em segmentos por dia.
// Retorna lista de [offsetDia, 'HH:MM:SS' ini, 'HH:MM:SS' fim, horas]. Fim 24:00 vira 23:59:00 (compatível com o legado).
function pgFatiar($ini, $fim) {
    $segs = [];
    $cur = $ini;
    while ($cur < $fim) {
        $dia = intdiv($cur, 1440);
        $fimDia = ($dia + 1) * 1440;
        $fimSeg = min($fim, $fimDia);
        $a = $cur - $dia * 1440; $b = $fimSeg - $dia * 1440;
        $fmt = function ($m) { return $m >= 1440 ? '23:59:00' : sprintf('%02d:%02d:00', intdiv($m, 60), $m % 60); };
        $segs[] = [$dia, $fmt($a), $fmt($b), round(($fimSeg - $cur) / 60, 2)];
        $cur = $fimSeg;
    }
    return $segs;
}

// Segmentos de sobreaviso do dia: [['d_ini','h_ini','d_fim','h_fim','horas'], ...]
function pgSegmentosPlantao($pdo, $dataStr, $feriadosDB) {
    $tipo = pgTipoDoDia($dataStr, $feriadosDB);
    $out = [];
    foreach (pgPeriodosVigentes($pdo, $tipo, $dataStr) as $p) {
        $iv = pgIntervalo((int)$p['dia_inicio'], $p['hora_inicio'], (int)$p['dia_fim'], $p['hora_fim']);
        if (!$iv) continue;
        foreach (pgFatiar($iv[0], $iv[1]) as $s) {
            $d = date('Y-m-d', strtotime($dataStr . ' +' . $s[0] . ' day'));
            $out[] = ['d_ini' => $d, 'h_ini' => $s[1], 'd_fim' => $d, 'h_fim' => $s[2], 'horas' => $s[3]];
        }
    }
    if (!$out) { // segurança: sem períodos válidos, usa o padrão histórico para o plantão não sumir
        $padrao = ($tipo === 'SEMANA') ? [[0, 1320, 1800]] : [[0, 480, 1020], [0, 1320, 1800]];
        foreach ($padrao as $p) {
            foreach (pgFatiar($p[1], $p[2]) as $s) {
                $d = date('Y-m-d', strtotime($dataStr . ' +' . $s[0] . ' day'));
                $out[] = ['d_ini' => $d, 'h_ini' => $s[1], 'd_fim' => $d, 'h_fim' => $s[2], 'horas' => $s[3]];
            }
        }
    }
    return $out;
}

function pgListar($pdo) {
    $st = $pdo->query("SELECT id, tipo, to_char(vigencia_inicio,'YYYY-MM-DD') AS vigencia, dia_inicio, hora_inicio, dia_fim, hora_fim
                       FROM periodos_sobreaviso ORDER BY tipo, vigencia_inicio DESC, dia_inicio, hora_inicio, id");
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $iv = pgIntervalo((int)$r['dia_inicio'], $r['hora_inicio'], (int)$r['dia_fim'], $r['hora_fim']);
        $out[] = ['id' => (int)$r['id'], 'tipo' => $r['tipo'], 'vigencia' => $r['vigencia'],
                  'dia_inicio' => (int)$r['dia_inicio'], 'hora_inicio' => $r['hora_inicio'],
                  'dia_fim' => (int)$r['dia_fim'], 'hora_fim' => $r['hora_fim'],
                  'minutos' => $iv ? $iv[1] - $iv[0] : 0];
    }
    return $out;
}

function pgValidar($pdo, $tipo, $di, $hi, $df, $hf, $ignorarId = 0) {
    if (!in_array($tipo, PG_TIPOS, true)) return 'Tipo inválido.';
    if ($di < 0 || $di > 2 || $df < 0 || $df > 2) return 'Dia de início/fim inválido.';
    $novo = pgIntervalo($di, $hi, $df, $hf);
    if (!$novo) return 'Período inválido: o fim deve ser posterior ao início e a duração não pode passar de 48h.';
    $st = $pdo->prepare("SELECT dia_inicio, hora_inicio, dia_fim, hora_fim FROM periodos_sobreaviso WHERE tipo = :t AND id <> :x");
    $st->execute(['t' => $tipo, 'x' => (int)$ignorarId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ex = pgIntervalo((int)$r['dia_inicio'], $r['hora_inicio'], (int)$r['dia_fim'], $r['hora_fim']);
        if ($ex && heSobrepoe($novo, $ex)) return 'Este período sobrepõe outro já cadastrado para o mesmo tipo de dia.';
    }
    return null;
}

function pgAdicionar($pdo, $tipo, $di, $hi, $df, $hf, $usuario) {
    $di = (int)$di; $df = (int)$df;
    $erro = pgValidar($pdo, $tipo, $di, $hi, $df, $hf);
    if ($erro) return ['ok' => false, 'erro' => $erro];
    $ins = $pdo->prepare("INSERT INTO periodos_sobreaviso (tipo, vigencia_inicio, dia_inicio, hora_inicio, dia_fim, hora_fim, criado_por)
                          VALUES (:t, '2000-01-01', :di, :hi, :df, :hf, :u) RETURNING id");
    $ins->execute(['t' => $tipo, 'di' => $di, 'hi' => $hi, 'df' => $df, 'hf' => $hf, 'u' => $usuario]);
    return ['ok' => true, 'id' => (int)$ins->fetchColumn()];
}

function pgEditar($pdo, $id, $tipo, $di, $hi, $df, $hf, $usuario) {
    $id = (int)$id; $di = (int)$di; $df = (int)$df;
    $st = $pdo->prepare("SELECT tipo, hora_inicio, hora_fim FROM periodos_sobreaviso WHERE id = :id");
    $st->execute(['id' => $id]);
    $ant = $st->fetch(PDO::FETCH_ASSOC);
    if (!$ant) return ['ok' => false, 'erro' => 'Período não encontrado.'];
    $erro = pgValidar($pdo, $tipo, $di, $hi, $df, $hf, $id);
    if ($erro) return ['ok' => false, 'erro' => $erro];
    if ($ant['tipo'] !== $tipo) { // mudou de tipo: o tipo de origem precisa continuar com ao menos um período
        $c = $pdo->prepare("SELECT COUNT(*) FROM periodos_sobreaviso WHERE tipo = :t");
        $c->execute(['t' => $ant['tipo']]);
        if ((int)$c->fetchColumn() <= 1) return ['ok' => false, 'erro' => 'Mantenha ao menos um período para o tipo de dia de origem.'];
    }
    $up = $pdo->prepare("UPDATE periodos_sobreaviso SET tipo = :t, dia_inicio = :di, hora_inicio = :hi, dia_fim = :df, hora_fim = :hf WHERE id = :id");
    $up->execute(['t' => $tipo, 'di' => $di, 'hi' => $hi, 'df' => $df, 'hf' => $hf, 'id' => $id]);
    return ['ok' => true, 'anterior' => $ant];
}

function pgRemover($pdo, $id) {
    $st = $pdo->prepare("SELECT tipo, to_char(vigencia_inicio,'YYYY-MM-DD') AS vigencia, hora_inicio, hora_fim FROM periodos_sobreaviso WHERE id = :id");
    $st->execute(['id' => (int)$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return ['ok' => false, 'erro' => 'Período não encontrado.'];
    $c = $pdo->prepare("SELECT COUNT(*) FROM periodos_sobreaviso WHERE tipo = :t");
    $c->execute(['t' => $r['tipo']]);
    if ((int)$c->fetchColumn() <= 1) return ['ok' => false, 'erro' => 'Mantenha ao menos um período para este tipo de dia.'];
    $pdo->prepare("DELETE FROM periodos_sobreaviso WHERE id = :id")->execute(['id' => (int)$id]);
    return ['ok' => true, 'removido' => $r];
}
