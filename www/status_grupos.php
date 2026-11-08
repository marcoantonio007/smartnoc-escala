<?php
// ============================================================================
// Regras de grupos do WhatsApp conforme o status do colaborador na escala mensal
// Status: SEM_ESCALA | SOBREAVISO | FOLGA | AFASTAMENTO | LICENCA | FERIAS
//   - FERIAS: remove dos grupos no 1º dia; inclui de volta quando o status mudar
//   - LICENCA / FOLGA / AFASTAMENTO (atestado): mesma regra quando o período
//     contínuo passa do limite de dias úteis (padrão: mais de 5)
// ============================================================================

const SG_TZ = 'America/Bahia';
const SG_AUSENCIAS = ['FERIAS', 'LICENCA', 'AFASTAMENTO', 'FOLGA'];

function sgGarantirTabelas($pdo) {
    static $ok = false;
    if ($ok) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS status_colaborador (
        funcionario_id INT PRIMARY KEY,
        status VARCHAR(20) NOT NULL,
        desde DATE,
        afastado SMALLINT NOT NULL DEFAULT 0,
        dias_uteis INT NOT NULL DEFAULT 0,
        verificado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS status_colaborador_log (
        id SERIAL PRIMARY KEY,
        funcionario_id INT,
        nome VARCHAR(150),
        status_anterior VARCHAR(20),
        status_novo VARCHAR(20),
        acao VARCHAR(40),
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS grupos_removidos_auto (
        funcionario_id INT NOT NULL,
        grupo_id INT NOT NULL,
        removido_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (funcionario_id, grupo_id)
    )");
    $ok = true;
}

// ---------------------------------------------------------------- configuração
function sgConfigGet($pdo, $chave, $padrao) {
    try {
        $st = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = ?");
        $st->execute([$chave]);
        $v = $st->fetchColumn();
        return ($v === false || $v === null || $v === '') ? $padrao : $v;
    } catch (Exception $e) { return $padrao; }
}

function sgConfigSet($pdo, $chave, $valor) {
    $st = $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?, ?) ON CONFLICT (chave) DO UPDATE SET valor = EXCLUDED.valor");
    $st->execute([$chave, (string)$valor]);
}

// remove quando o período passa DESTE número de dias úteis (padrão 5 => "mais de 5")
function sgLimiteDiasUteis($pdo) {
    $n = (int)sgConfigGet($pdo, 'grupos_dias_uteis_limite', 5);
    return ($n < 1 || $n > 60) ? 5 : $n;
}

function sgHoje() {
    return (new DateTime('now', new DateTimeZone(SG_TZ)))->format('Y-m-d');
}

// ---------------------------------------------------------------- dias úteis
function sgPascoa($ano) {
    $a = $ano % 19; $b = intdiv($ano, 100); $c = $ano % 100;
    $d = intdiv($b, 4); $e = $b % 4; $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3); $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $mes = intdiv($h + $l - 7 * $m + 114, 31);
    $dia = (($h + $l - 7 * $m + 114) % 31) + 1;
    return new DateTime(sprintf('%04d-%02d-%02d', $ano, $mes, $dia));
}

function sgMoveis($ano) {
    static $c = [];
    if (!isset($c[$ano])) {
        $p = sgPascoa($ano);
        $c[$ano] = [
            (clone $p)->modify('-47 days')->format('Y-m-d') => true,   // Carnaval
            (clone $p)->modify('-2 days')->format('Y-m-d') => true,    // Sexta-feira Santa
            (clone $p)->modify('+60 days')->format('Y-m-d') => true    // Corpus Christi
        ];
    }
    return $c[$ano];
}

// feriados fixos (nacionais e estaduais) cadastrados no painel, repetidos todo ano (MM-DD)
function sgFeriados($pdo) {
    static $fixos = null;
    if ($fixos !== null) return $fixos;
    $fixos = [];
    try {
        $rs = $pdo->query("SELECT nome, tipo, data_feriado FROM feriados");
        foreach ($rs->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $tipo = strtolower((string)$r['tipo']);
            if ($tipo !== 'nacional' && $tipo !== 'estadual') continue;
            if (preg_match('/carnaval|sexta.feira santa|corpus christi/i', (string)$r['nome'])) continue; // móveis: calculados
            if (!empty($r['data_feriado'])) $fixos[substr((string)$r['data_feriado'], 5, 5)] = true;
        }
    } catch (Exception $e) {}
    return $fixos;
}

function sgDiaUtil($pdo, $ymd) {
    static $cache = [];
    if (isset($cache[$ymd])) return $cache[$ymd];
    $dt = new DateTime($ymd);
    $w = (int)$dt->format('w');
    $util = !($w === 0 || $w === 6);
    if ($util) {
        $fixos = sgFeriados($pdo);
        if (isset($fixos[$dt->format('m-d')])) {
            $util = false;
        } else {
            $mov = sgMoveis((int)$dt->format('Y'));
            if (isset($mov[$ymd])) $util = false;
        }
    }
    $cache[$ymd] = $util;
    return $util;
}

// ---------------------------------------------------------------- status
function sgCategoriaDoStatus($status) {
    $s = strtolower(trim((string)$status));
    if ($s === 'ferias') return 'FERIAS';
    if ($s === 'licenca') return 'LICENCA';
    if ($s === 'atestado') return 'AFASTAMENTO';
    if ($s === 'folga' || $s === 'folga_aniversario') return 'FOLGA';
    if (strpos($s, 'plantao') === 0) return 'SOBREAVISO';
    return 'SEM_ESCALA';
}

function sgRotuloStatus($s) {
    $r = ['SEM_ESCALA' => 'SEM_ESCALA', 'SOBREAVISO' => 'SOBREAVISO', 'FOLGA' => 'FOLGA',
          'AFASTAMENTO' => 'AFASTAMENTO', 'LICENCA' => 'LICENÇA', 'FERIAS' => 'FÉRIAS'];
    return $r[$s] ?? $s;
}

function sgMotivo($status, $diasUteis) {
    if ($status === 'FERIAS') return 'férias';
    if ($status === 'LICENCA') return 'licença de ' . $diasUteis . ' dias úteis';
    if ($status === 'AFASTAMENTO') return 'afastamento de ' . $diasUteis . ' dias úteis';
    if ($status === 'FOLGA') return 'folga de ' . $diasUteis . ' dias úteis';
    return '';
}

// marcações do colaborador na página mensal (uma categoria por dia; a mais "forte" vence)
function sgMapaDoColaborador($pdo, $nome, $de, $ate) {
    $prio = ['FERIAS' => 6, 'LICENCA' => 5, 'AFASTAMENTO' => 4, 'FOLGA' => 3, 'SOBREAVISO' => 2, 'SEM_ESCALA' => 1];
    $st = $pdo->prepare("SELECT data_escala, status FROM escala_dados WHERE nome_colaborador = ? AND data_escala BETWEEN ? AND ?");
    $st->execute([$nome, $de, $ate]);
    $mapa = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $c = sgCategoriaDoStatus($r['status']);
        $d = (string)$r['data_escala'];
        if (!isset($mapa[$d]) || $prio[$c] > $prio[$mapa[$d]]) $mapa[$d] = $c;
    }
    return $mapa;
}

// Status do colaborador em $hoje (Y-m-d) e se a regra manda ficar fora dos grupos.
// Fins de semana/feriados SEM marcação acompanham o status dos dois lados quando é o mesmo
// (lançamentos em lote costumam pular esses dias).
function sgCalcularStatus($pdo, $nome, $hoje, $limite) {
    $janela = 400;
    $dHoje = new DateTime($hoje);
    $de  = (clone $dHoje)->modify('-' . $janela . ' days')->format('Y-m-d');
    $ate = (clone $dHoje)->modify('+' . $janela . ' days')->format('Y-m-d');
    $mapa = sgMapaDoColaborador($pdo, $nome, $de, $ate);
    $memo = [];

    $efetiva = function ($ymd) use (&$memo, &$mapa, $pdo) {
        if (isset($memo[$ymd])) return $memo[$ymd];
        $c = $mapa[$ymd] ?? 'SEM_ESCALA';
        if ($c === 'SEM_ESCALA' && !sgDiaUtil($pdo, $ymd)) {
            $cp = 'SEM_ESCALA';
            $p = new DateTime($ymd);
            for ($i = 0; $i < 15; $i++) {
                $p->modify('-1 day');
                $y = $p->format('Y-m-d');
                if (isset($mapa[$y]) && $mapa[$y] !== 'SEM_ESCALA') { $cp = $mapa[$y]; break; }
                if (sgDiaUtil($pdo, $y)) break;
            }
            $cn = 'SEM_ESCALA';
            $n = new DateTime($ymd);
            for ($i = 0; $i < 15; $i++) {
                $n->modify('+1 day');
                $y = $n->format('Y-m-d');
                if (isset($mapa[$y]) && $mapa[$y] !== 'SEM_ESCALA') { $cn = $mapa[$y]; break; }
                if (sgDiaUtil($pdo, $y)) break;
            }
            if ($cp === $cn && in_array($cp, SG_AUSENCIAS, true)) $c = $cp;
        }
        $memo[$ymd] = $c;
        return $c;
    };

    $c = $efetiva($hoje);
    $afastado = false;
    $diasUteis = 0;
    $inicio = $hoje;
    $fim = $hoje;

    if (in_array($c, SG_AUSENCIAS, true)) {
        $s = new DateTime($hoje);
        for ($i = 0; $i < $janela; $i++) {
            $q = (clone $s)->modify('-1 day');
            if ($efetiva($q->format('Y-m-d')) !== $c) break;
            $s = $q;
        }
        $e = new DateTime($hoje);
        for ($i = 0; $i < $janela; $i++) {
            $q = (clone $e)->modify('+1 day');
            if ($efetiva($q->format('Y-m-d')) !== $c) break;
            $e = $q;
        }
        $inicio = $s->format('Y-m-d');
        $fim = $e->format('Y-m-d');
        $x = clone $s;
        while ($x <= $e) {
            if (sgDiaUtil($pdo, $x->format('Y-m-d'))) $diasUteis++;
            $x->modify('+1 day');
        }
        if ($c === 'FERIAS') $afastado = true;
        elseif ($diasUteis > $limite) $afastado = true;
    }

    return ['status' => $c, 'afastado' => $afastado, 'dias_uteis' => $diasUteis, 'inicio' => $inicio, 'fim' => $fim];
}

// ---------------------------------------------------------------- estado dos grupos
function sgEstaAfastado($pdo, $funcId) {
    try {
        sgGarantirTabelas($pdo);
        $st = $pdo->prepare("SELECT afastado::int FROM status_colaborador WHERE funcionario_id = ?");
        $st->execute([$funcId]);
        return ((int)$st->fetchColumn()) === 1;
    } catch (Exception $e) { return false; }
}

function sgMarcarRemovido($pdo, $funcId, $grupoId) {
    sgGarantirTabelas($pdo);
    $pdo->prepare("INSERT INTO grupos_removidos_auto (funcionario_id, grupo_id) VALUES (?, ?) ON CONFLICT DO NOTHING")->execute([$funcId, $grupoId]);
}

// true se o grupo estava marcado como "removido por afastamento" (e a marca foi apagada)
function sgDesmarcarRemovido($pdo, $funcId, $grupoId) {
    sgGarantirTabelas($pdo);
    $st = $pdo->prepare("DELETE FROM grupos_removidos_auto WHERE funcionario_id = ? AND grupo_id = ?");
    $st->execute([$funcId, $grupoId]);
    return $st->rowCount() > 0;
}

function sgPendente($pdo, $funcId, $grupoId, $acao) {
    try {
        $st = $pdo->prepare("SELECT 1 FROM fila_whatsapp WHERE func_id = ? AND grupo_local_id = ? AND acao = ? AND status IN ('pendente', 'processando') LIMIT 1");
        $st->execute([$funcId, $grupoId, $acao]);
        return (bool)$st->fetchColumn();
    } catch (Exception $e) { return false; }
}

// ---------------------------------------------------------------- verificação diária
// Compara o status de hoje com o último verificado e aplica a regra nos grupos de cada colaborador.
// Funciona por estado (não por evento): se algo falhou antes, a próxima verificação tenta de novo.
function sgSincronizar($pdo, $wa, $hoje = null) {
    sgGarantirTabelas($pdo);
    $wa->prepararFila();
    $hoje = $hoje ?: sgHoje();
    $limite = sgLimiteDiasUteis($pdo);
    $res = ['verificados' => 0, 'alteracoes' => 0, 'remocoes' => 0, 'adicoes' => 0];

    $funcs = $pdo->query("SELECT id, nome, telefone FROM funcionarios ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
    $selPrev = $pdo->prepare("SELECT status, afastado::int AS afastado FROM status_colaborador WHERE funcionario_id = ?");
    $upsert = $pdo->prepare("INSERT INTO status_colaborador (funcionario_id, status, desde, afastado, dias_uteis, verificado_em)
        VALUES (?, ?, ?, ?, ?, NOW())
        ON CONFLICT (funcionario_id) DO UPDATE SET
            desde = CASE WHEN status_colaborador.status <> EXCLUDED.status THEN EXCLUDED.desde ELSE status_colaborador.desde END,
            status = EXCLUDED.status, afastado = EXCLUDED.afastado, dias_uteis = EXCLUDED.dias_uteis, verificado_em = NOW()");
    $logIns = $pdo->prepare("INSERT INTO status_colaborador_log (funcionario_id, nome, status_anterior, status_novo, acao) VALUES (?, ?, ?, ?, ?)");

    $selFora = $pdo->prepare("SELECT g.id AS gid, g.grupo_id AS jid, g.nome AS gnome
        FROM funcionario_whatsapp fw
        JOIN whatsapp_grupos g ON g.id::text = fw.grupo_id::text
        WHERE fw.funcionario_id = ?
          AND NOT EXISTS (SELECT 1 FROM grupos_removidos_auto x WHERE x.funcionario_id = fw.funcionario_id AND x.grupo_id::text = fw.grupo_id::text)");
    $selVolta = $pdo->prepare("SELECT g.id AS gid, g.grupo_id AS jid, g.nome AS gnome
        FROM grupos_removidos_auto x
        JOIN whatsapp_grupos g ON g.id = x.grupo_id
        WHERE x.funcionario_id = ?");

    foreach ($funcs as $f) {
        $res['verificados']++;
        $r = sgCalcularStatus($pdo, $f['nome'], $hoje, $limite);
        $novoAfast = $r['afastado'] ? 1 : 0;

        $selPrev->execute([$f['id']]);
        $prev = $selPrev->fetch(PDO::FETCH_ASSOC);

        if ($prev && $prev['status'] !== $r['status']) {
            $acao = ($novoAfast === 1 && (int)$prev['afastado'] === 0) ? 'removido dos grupos'
                  : (($novoAfast === 0 && (int)$prev['afastado'] === 1) ? 'incluído nos grupos' : 'sem mudança nos grupos');
            $logIns->execute([$f['id'], $f['nome'], $prev['status'], $r['status'], $acao]);
            $res['alteracoes']++;
        } elseif (!$prev && $novoAfast === 1) {
            $logIns->execute([$f['id'], $f['nome'], null, $r['status'], 'removido dos grupos']);
            $res['alteracoes']++;
        }

        $desde = in_array($r['status'], SG_AUSENCIAS, true) ? $r['inicio'] : $hoje;
        $upsert->execute([$f['id'], $r['status'], $desde, $novoAfast, $r['dias_uteis']]);

        $tel = preg_replace('/\D/', '', (string)$f['telefone']);
        if ($tel === '') continue;   // sem telefone não há o que fazer nos grupos

        if ($novoAfast === 1) {
            $selFora->execute([$f['id']]);
            foreach ($selFora->fetchAll(PDO::FETCH_ASSOC) as $g) {
                if (sgPendente($pdo, $f['id'], $g['gid'], 'remove')) continue;
                $texto = "O colaborador " . $f['nome'] . " foi removido temporariamente do grupo.\nMotivo: " . sgMotivo($r['status'], $r['dias_uteis']) . ". Ele será incluído novamente no retorno.";
                $wa->enfileirarVinculado('remove', $g['jid'], $tel, $texto, $f['nome'], (int)$f['id'], (int)$g['gid']);
                $res['remocoes']++;
            }
        } else {
            $selVolta->execute([$f['id']]);
            foreach ($selVolta->fetchAll(PDO::FETCH_ASSOC) as $g) {
                if (sgPendente($pdo, $f['id'], $g['gid'], 'add')) continue;
                $wa->enfileirarVinculado('add', $g['jid'], $tel, "Seja bem vindo(a) de volta, " . $f['nome'], $f['nome'], (int)$f['id'], (int)$g['gid']);
                $res['adicoes']++;
            }
        }
    }
    return $res;
}

// ---------------------------------------------------------------- dados para a tela
function sgInfoColaborador($pdo, $funcId) {
    sgGarantirTabelas($pdo);
    $st = $pdo->prepare("SELECT id, nome FROM funcionarios WHERE id = ?");
    $st->execute([$funcId]);
    $f = $st->fetch(PDO::FETCH_ASSOC);
    if (!$f) return ['sucesso' => false, 'erro' => 'Colaborador não encontrado'];

    $r = sgCalcularStatus($pdo, $f['nome'], sgHoje(), sgLimiteDiasUteis($pdo));
    $q = $pdo->prepare("SELECT COUNT(*) FROM grupos_removidos_auto WHERE funcionario_id = ?");
    $q->execute([$funcId]);
    $ausencia = in_array($r['status'], SG_AUSENCIAS, true);

    return [
        'sucesso' => true,
        'status' => $r['status'],
        'afastado' => $r['afastado'],
        'dias_uteis' => $r['dias_uteis'],
        'desde' => $ausencia ? date('d/m/Y', strtotime($r['inicio'])) : null,
        'fora_dos_grupos' => (int)$q->fetchColumn(),
        'motivo' => sgMotivo($r['status'], $r['dias_uteis'])
    ];
}

function sgInfoRegra($pdo) {
    sgGarantirTabelas($pdo);
    $ult = $pdo->query("SELECT to_char(MAX(verificado_em), 'DD/MM/YYYY HH24:MI') FROM status_colaborador")->fetchColumn();
    $af = (int)$pdo->query("SELECT COUNT(*) FROM status_colaborador WHERE afastado = 1")->fetchColumn();
    $alts = $pdo->query("SELECT to_char(criado_em, 'DD/MM HH24:MI') AS quando, nome, status_anterior, status_novo, acao
        FROM status_colaborador_log ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    return ['sucesso' => true, 'limite' => sgLimiteDiasUteis($pdo), 'ultima' => $ult ?: null, 'afastados' => $af, 'alteracoes' => $alts];
}

// Histórico de mudanças de status, com filtro (colaborador / período) e paginação.
// $todos = true devolve até 5000 linhas (usado na exportação para Excel).
function sgListarLog($pdo, $busca, $de, $ate, $pagina, $porPagina, $todos = false) {
    sgGarantirTabelas($pdo);
    $where = [];
    $params = [];

    $busca = trim((string)$busca);
    if ($busca !== '') {
        $where[] = "nome ILIKE ?";
        $params[] = '%' . addcslashes($busca, '%_\\') . '%';
    }
    $dtDe = DateTime::createFromFormat('Y-m-d', (string)$de);
    if ($dtDe && $dtDe->format('Y-m-d') === $de) {
        $where[] = "criado_em >= ?";
        $params[] = $de;
    }
    $dtAte = DateTime::createFromFormat('Y-m-d', (string)$ate);
    if ($dtAte && $dtAte->format('Y-m-d') === $ate) {
        $where[] = "criado_em < ?";
        $params[] = (clone $dtAte)->modify('+1 day')->format('Y-m-d');   // inclui o dia final inteiro
    }
    $w = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $st = $pdo->prepare("SELECT COUNT(*) FROM status_colaborador_log $w");
    $st->execute($params);
    $total = (int)$st->fetchColumn();

    $porPagina = max(1, (int)$porPagina);
    $paginas = max(1, (int)ceil($total / $porPagina));
    $pagina = min(max(1, (int)$pagina), $paginas);
    $lim = $todos ? 'LIMIT 5000' : ('LIMIT ' . $porPagina . ' OFFSET ' . (($pagina - 1) * $porPagina));

    $st = $pdo->prepare("SELECT to_char(criado_em, 'DD/MM/YYYY HH24:MI') AS quando, nome, status_anterior, status_novo, acao
        FROM status_colaborador_log $w ORDER BY id DESC $lim");
    $st->execute($params);
    $itens = $st->fetchAll(PDO::FETCH_ASSOC);

    return ['sucesso' => true, 'total' => $total, 'pagina' => $pagina, 'paginas' => $paginas,
            'por_pagina' => $porPagina, 'itens' => $itens, 'limitado' => ($todos && $total > count($itens))];
}
