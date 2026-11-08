#!/bin/bash
# Uso: bash instalar_hora_extra.sh   (rode como usuário que acessa /opt/escala/www)
set -e
cd /opt/escala/www
cat << 'EOF_HE' > /opt/escala/www/hora_extra.php
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

// retorna ['ok'=>true,'id'=>n,'minutos'=>m] ou ['ok'=>false,'erro'=>'...']
function heAdicionar($pdo, $nome, $data, $hi, $hf, $vidpol, $usuario, $dia = 0) {
    $nome = trim((string)$nome); $vidpol = (string)$vidpol; $dia = (int)$dia;
    if ($dia !== 0 && $dia !== 1) return ['ok' => false, 'erro' => 'Dia de início inválido.'];
    if ($nome === '') return ['ok' => false, 'erro' => 'Colaborador não informado.'];
    if (!preg_match('/^[0-9]{6}$/', $vidpol)) return ['ok' => false, 'erro' => 'O VIDPOL é obrigatório e deve ter exatamente 6 dígitos numéricos.'];
    if (!heDataValida($data)) return ['ok' => false, 'erro' => 'Data inválida.'];
    if (heMin($hi) === null || heMin($hf) === null) return ['ok' => false, 'erro' => 'Informe hora de início e de fim válidas (HH:MM).'];
    if ($hi === $hf) return ['ok' => false, 'erro' => 'Hora de início e fim não podem ser iguais.'];

    $st = $pdo->prepare("SELECT 1 FROM funcionarios WHERE nome = :n");
    $st->execute(['n' => $nome]);
    if (!$st->fetchColumn()) return ['ok' => false, 'erro' => 'Colaborador não encontrado.'];

    $st = $pdo->prepare("SELECT 1 FROM escala_dados WHERE nome_colaborador = :n AND data_escala = :d AND status LIKE 'plantao%' LIMIT 1");
    $st->execute(['n' => $nome, 'd' => $data]);
    if (!$st->fetchColumn()) return ['ok' => false, 'erro' => 'O colaborador não possui sobreaviso neste dia.'];

    $novo = heIntervalo($hi, $hf, $dia);
    $st = $pdo->prepare("SELECT to_char(data_escala,'YYYY-MM-DD') AS d, hora_inicio, hora_fim, dia_inicio FROM horas_extras
                         WHERE nome_colaborador = :n AND data_escala BETWEEN (CAST(:d AS date) - 1) AND (CAST(:d AS date) + 1)");
    $st->execute(['n' => $nome, 'd' => $data]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $off = (int)round((strtotime($r['d']) - strtotime($data)) / 86400) * 1440;
        $ex = heIntervalo($r['hora_inicio'], $r['hora_fim'], $r['dia_inicio']);
        $ex = [$ex[0] + $off, $ex[1] + $off];
        if (heSobrepoe($novo, $ex)) return ['ok' => false, 'erro' => 'Este período conflita com outra hora extra já registrada (' . $r['hora_inicio'] . '–' . $r['hora_fim'] . ').'];
    }

    $min = heDuracao($hi, $hf);
    $ins = $pdo->prepare("INSERT INTO horas_extras (nome_colaborador, data_escala, hora_inicio, hora_fim, dia_inicio, vidpol, minutos, criado_por)
                          VALUES (:n, :d, :hi, :hf, :di, :v, :m, :u) RETURNING id");
    $ins->execute(['n' => $nome, 'd' => $data, 'hi' => $hi, 'hf' => $hf, 'di' => $dia, 'v' => $vidpol, 'm' => $min, 'u' => $usuario]);
    return ['ok' => true, 'id' => (int)$ins->fetchColumn(), 'minutos' => $min];
}

function heRemover($pdo, $id) {
    $st = $pdo->prepare("DELETE FROM horas_extras WHERE id = :id RETURNING nome_colaborador, to_char(data_escala,'YYYY-MM-DD') AS d, hora_inicio, hora_fim, vidpol");
    $st->execute(['id' => (int)$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ---------- períodos de sobreaviso ----------

function pgTipoDoDia($dataStr, $feriadosDB) {
    if (in_array($dataStr, $feriadosDB)) return 'FERIADO';
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

function pgPeriodosVigentes($pdo, $tipo, $dataStr) {
    $st = $pdo->prepare("SELECT MAX(vigencia_inicio) FROM periodos_sobreaviso WHERE tipo = :t AND vigencia_inicio <= :d");
    $st->execute(['t' => $tipo, 'd' => $dataStr]);
    $vig = $st->fetchColumn();
    if (!$vig) {
        $st = $pdo->prepare("SELECT MIN(vigencia_inicio) FROM periodos_sobreaviso WHERE tipo = :t");
        $st->execute(['t' => $tipo]);
        $vig = $st->fetchColumn();
    }
    if (!$vig) return [];
    $st = $pdo->prepare("SELECT dia_inicio, hora_inicio, dia_fim, hora_fim FROM periodos_sobreaviso WHERE tipo = :t AND vigencia_inicio = :v ORDER BY dia_inicio, hora_inicio");
    $st->execute(['t' => $tipo, 'v' => $vig]);
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

function pgAdicionar($pdo, $tipo, $vigencia, $di, $hi, $df, $hf, $usuario) {
    if (!in_array($tipo, PG_TIPOS, true)) return ['ok' => false, 'erro' => 'Tipo inválido.'];
    if (!heDataValida($vigencia)) return ['ok' => false, 'erro' => 'Data de vigência inválida.'];
    $di = (int)$di; $df = (int)$df;
    if ($di < 0 || $di > 2 || $df < 0 || $df > 2) return ['ok' => false, 'erro' => 'Dia de início/fim inválido.'];
    $novo = pgIntervalo($di, $hi, $df, $hf);
    if (!$novo) return ['ok' => false, 'erro' => 'Período inválido: o fim deve ser posterior ao início e a duração não pode passar de 48h.'];

    $st = $pdo->prepare("SELECT dia_inicio, hora_inicio, dia_fim, hora_fim FROM periodos_sobreaviso WHERE tipo = :t AND vigencia_inicio = :v");
    $st->execute(['t' => $tipo, 'v' => $vigencia]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ex = pgIntervalo((int)$r['dia_inicio'], $r['hora_inicio'], (int)$r['dia_fim'], $r['hora_fim']);
        if ($ex && heSobrepoe($novo, $ex)) return ['ok' => false, 'erro' => 'Este período sobrepõe outro já cadastrado para o mesmo tipo e vigência.'];
    }
    $ins = $pdo->prepare("INSERT INTO periodos_sobreaviso (tipo, vigencia_inicio, dia_inicio, hora_inicio, dia_fim, hora_fim, criado_por)
                          VALUES (:t, :v, :di, :hi, :df, :hf, :u) RETURNING id");
    $ins->execute(['t' => $tipo, 'v' => $vigencia, 'di' => $di, 'hi' => $hi, 'df' => $df, 'hf' => $hf, 'u' => $usuario]);
    return ['ok' => true, 'id' => (int)$ins->fetchColumn()];
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
EOF_HE
cat << 'EOF_HE' > /opt/escala/www/gestao.js
/* gestao.js — Hora extra (VIDPOL), ticket extra, dashboard gerencial e períodos de sobreaviso.
   Carregado no fim do index.php. Só age para perfis admin/mestre (gestor ou superior). */
(function (root) {
  'use strict';

  var TICKET_MIN = 120; // ticket extra: soma do dia ESTRITAMENTE maior que 2h
  var TIPOS = { SEMANA: 'Semana (seg–sex)', FIM_DE_SEMANA: 'Final de semana', FERIADO: 'Feriado' };
  var DIA_LABEL = ['mesmo dia', '+1 dia', '+2 dias'];
  var CORES = ['#2563eb', '#f59e0b', '#059669', '#7c3aed', '#dc2626', '#0891b2', '#db2777', '#65a30d'];

  /* ======================= NÚCLEO PURO (testável) ======================= */
  function pad(n) { return String(n).padStart(2, '0'); }
  function hm(s) {
    if (typeof s !== 'string') return null;
    var m = /^([01]\d|2[0-3]):([0-5]\d)(?::\d\d)?$/.exec(s);
    return m ? (+m[1]) * 60 + (+m[2]) : null;
  }
  function dur(hi, hf) {
    var a = hm(hi), b = hm(hf);
    if (a === null || b === null) return null;
    return b > a ? b - a : b + 1440 - a;
  }
  function fmtMin(m) {
    m = Math.round(m || 0);
    var h = Math.floor(m / 60), r = m % 60;
    return h + 'h' + (r ? pad(r) + 'm' : '');
  }
  function fmtH(h) { return fmtMin(Math.round((h || 0) * 60)); }
  function diaNum(s) { var p = s.split('-'); return Date.UTC(+p[0], +p[1] - 1, +p[2]) / 86400000; }
  function addDias(s, n) {
    var d = new Date(diaNum(s) * 86400000 + n * 86400000);
    return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate());
  }
  function ivHE(e) { var a = diaNum(e.data) * 1440 + (e.dia_inicio ? 1440 : 0) + hm(e.hora_inicio); return [a, a + dur(e.hora_inicio, e.hora_fim)]; }
  function ivFracao(f) {
    var a = diaNum(String(f.data_inicio).substring(0, 10)) * 1440 + hm(String(f.hora_inicio));
    var fimStr = String(f.hora_fim);
    var b = diaNum(String(f.data_fim).substring(0, 10)) * 1440 + (fimStr.substring(0, 5) === '23:59' ? 1440 : hm(fimStr));
    return [a, b];
  }
  function overlap(a, b) { return Math.max(0, Math.min(a[1], b[1]) - Math.max(a[0], b[0])); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function statusDe(c) { return c == null ? null : (typeof c === 'string' ? c : c.status); }
  function ehPlantao(c) { var s = statusDe(c); return !!s && String(s).indexOf('plantao') === 0; }

  // Competência: do dia 15 do mês de referência ao dia 14 do mês seguinte
  function competencia(ano, mes0) {
    var fa = ano, fm = mes0 + 1;
    if (fm > 11) { fm = 0; fa++; }
    return { ini: ano + '-' + pad(mes0 + 1) + '-15', fim: fa + '-' + pad(fm + 1) + '-14', anoFim: fa, mesFim0: fm };
  }

  // Resumo de hora extra de um colaborador em um dia (apenas dados já carregados)
  function resumoDia(extras, nome, data) {
    var itens = extras.filter(function (e) { return e.nome === nome && e.data === data; });
    if (!itens.length) return null;
    var total = itens.reduce(function (s, e) { return s + e.minutos; }, 0);
    return { minutos: total, periodos: itens, ticket: total > TICKET_MIN };
  }

  /* params: {plantao, tecnicos, extras, ini, fim, setor}  */
  function calcularGestao(p) {
    var linhas = [], porSetor = {}, porDia = {};
    var d0 = diaNum(p.ini), d1 = diaNum(p.fim);
    for (var d = d0; d <= d1; d++) {
      var s = addDias(p.ini, d - d0);
      porDia[s] = { data: s, heMin: 0, plantoes: 0 };
    }
    (p.tecnicos || []).forEach(function (t) {
      if (p.setor && String(t.setor || '').toUpperCase() !== String(p.setor).toUpperCase()) return;
      var dados = (p.plantao && p.plantao[t.nome]) || {};
      var dias = 0, brutas = 0, diasPlantao = {};
      Object.keys(dados).forEach(function (data) {
        var c = dados[data];
        if (data < p.ini || data > p.fim || !ehPlantao(c)) return;
        dias++; diasPlantao[data] = c;
        (c.fracoes || []).forEach(function (f) { brutas += parseFloat(f.horas) || 0; });
        if (porDia[data]) porDia[data].plantoes++;
      });
      var heMin = 0, periodos = 0, descMin = 0, somaDia = {};
      (p.extras || []).forEach(function (e) {
        if (e.nome !== t.nome || e.data < p.ini || e.data > p.fim || !diasPlantao[e.data]) return;
        heMin += e.minutos; periodos++;
        somaDia[e.data] = (somaDia[e.data] || 0) + e.minutos;
        if (porDia[e.data]) porDia[e.data].heMin += e.minutos;
        var iv = ivHE(e);
        (diasPlantao[e.data].fracoes || []).forEach(function (f) { descMin += overlap(iv, ivFracao(f)); });
      });
      var tickets = Object.keys(somaDia).filter(function (k) { return somaDia[k] > TICKET_MIN; }).length;
      if (!dias && !heMin) return;
      var liquidas = Math.max(0, brutas - descMin / 60);
      linhas.push({ nome: t.nome, nomeCompleto: t.nome_completo || t.nome, setor: t.setor || '—', dias: dias,
        horasBrutas: brutas, descontoMin: descMin, horasLiquidas: liquidas, heMin: heMin, periodos: periodos, tickets: tickets });
      var st = t.setor || '—';
      var o = porSetor[st] || (porSetor[st] = { setor: st, heMin: 0, tickets: 0, horas: 0, colaboradores: 0 });
      o.heMin += heMin; o.tickets += tickets; o.horas += liquidas; o.colaboradores++;
    });
    var tot = linhas.reduce(function (a, l) {
      a.horasLiquidas += l.horasLiquidas; a.horasBrutas += l.horasBrutas; a.heMin += l.heMin;
      a.tickets += l.tickets; a.descontoMin += l.descontoMin; if (l.heMin) a.comHE++; return a;
    }, { horasLiquidas: 0, horasBrutas: 0, heMin: 0, tickets: 0, descontoMin: 0, comHE: 0 });
    return {
      linhas: linhas, totais: tot,
      porSetor: Object.keys(porSetor).map(function (k) { return porSetor[k]; }).sort(function (a, b) { return b.heMin - a.heMin || b.horas - a.horas; }),
      porDia: Object.keys(porDia).sort().map(function (k) { return porDia[k]; })
    };
  }

  // Validação do formulário de hora extra (cliente). extrasDoDia: itens já registrados do dia
  function validarHE(hi, hf, vidpol, extrasDoDia, extrasVizinhos, dia) {
    if (!/^[0-9]{6}$/.test(vidpol || '')) return 'O VIDPOL é obrigatório e deve ter exatamente 6 dígitos numéricos.';
    if (hm(hi) === null || hm(hf) === null) return 'Informe a hora de início e a hora de fim.';
    if (hi === hf) return 'Hora de início e fim não podem ser iguais.';
    var a = hm(hi) + (dia ? 1440 : 0), novo = [a, a + dur(hi, hf)];
    var conflito = null;
    (extrasDoDia || []).forEach(function (e) {
      var b = hm(e.hora_inicio) + (e.dia_inicio ? 1440 : 0), ex = [b, b + dur(e.hora_inicio, e.hora_fim)];
      if (overlap(novo, ex) > 0) conflito = e;
    });
    (extrasVizinhos || []).forEach(function (v) { // {off: -1440|1440, e}
      var b = hm(v.e.hora_inicio) + (v.e.dia_inicio ? 1440 : 0) + v.off, ex = [b, b + dur(v.e.hora_inicio, v.e.hora_fim)];
      if (overlap(novo, ex) > 0) conflito = v.e;
    });
    if (conflito) return 'Este período conflita com outra hora extra já registrada (' + conflito.hora_inicio + '–' + conflito.hora_fim + ').';
    return null;
  }

  root.GestaoCore = { hm: hm, dur: dur, fmtMin: fmtMin, fmtH: fmtH, competencia: competencia, calcularGestao: calcularGestao,
    resumoDia: resumoDia, validarHE: validarHE, ivHE: ivHE, ivFracao: ivFracao, addDias: addDias, TICKET_MIN: TICKET_MIN };

  /* ============================== UI ============================== */
  if (!root.document || !root.document.body) return;
  var doc = root.document;
  function el(id) { return doc.getElementById(id); }
  // let/const de outros <script> ficam no escopo global léxico (não em window): acesso por nome puro
  function gAccess() { return typeof currentAccess !== 'undefined' ? currentAccess : ''; }
  function gPlantao() { return typeof plantaoMensalDados !== 'undefined' ? plantaoMensalDados : {}; }
  function gTecnicos() { return typeof tecnicosDB !== 'undefined' ? tecnicosDB : []; }
  function gSetoresGer() { return typeof setoresGerenciaveisLogin !== 'undefined' ? setoresGerenciaveisLogin : ''; }
  function gMes() { return (typeof currentMonthDate !== 'undefined' && currentMonthDate instanceof Date) ? currentMonthDate : new Date(); }
  function gNomesMeses() { return typeof nomesMeses !== 'undefined' ? nomesMeses : ['JANEIRO', 'FEVEREIRO', 'MARÇO', 'ABRIL', 'MAIO', 'JUNHO', 'JULHO', 'AGOSTO', 'SETEMBRO', 'OUTUBRO', 'NOVEMBRO', 'DEZEMBRO']; }
  function gView() { return typeof currentView !== 'undefined' ? currentView : ''; }
  function ehGestor() { var a = gAccess(); return a === 'admin' || a === 'mestre'; }
  function toast(m, t) { if (typeof root.showToast === 'function') root.showToast(m, t || 'success'); else if (t === 'error') alert(m); }
  function icones() { if (root.lucide && root.lucide.createIcons) root.lucide.createIcons(); }

  var horasExtrasDB = [];
  var periodosDB = [];
  var alvoHE = null; // {nome, data}

  async function api(action, opts) {
    var r = await fetch('api_escala.php?action=' + action, opts);
    var txt = await r.text(), j;
    try { j = JSON.parse(txt); } catch (e) { throw new Error('Resposta inválida do servidor.'); }
    return j;
  }
  function post(action, body) {
    return api(action, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  }

  async function carregarHorasExtras() {
    if (!ehGestor()) return;
    try {
      var j = await api('get_horas_extras');
      if (j.sucesso) horasExtrasDB = j.itens || [];
    } catch (e) { /* silencioso */ }
  }
  function rerenderTudo() {
    try { if (typeof root.renderAll === 'function') root.renderAll(); } catch (e) {}
    if (gView() === 'gestao') renderGestao();
  }

  // Usado pelo mensal.php (somente gestor): dados da hora extra de um dia
  function infoHoraExtra(nome, data) {
    if (!ehGestor()) return null;
    var c = gPlantao()[nome] && gPlantao()[nome][data];
    if (!ehPlantao(c)) return null;
    return resumoDia(horasExtrasDB, nome, data);
  }
  function marcadorHoraExtra(nome, data) {
    var i = infoHoraExtra(nome, data);
    if (!i) return '';
    var det = i.periodos.map(function (e) { return e.hora_inicio + (e.dia_inicio ? '(+1d)' : '') + '–' + e.hora_fim + ' (VIDPOL ' + e.vidpol + ')'; }).join(' | ');
    var tip = 'Hora extra: ' + fmtMin(i.minutos) + ' — ' + det + (i.ticket ? ' — direito a ticket extra' : '');
    var barra = '<div class="absolute bottom-0 left-0 right-0 h-[3px] bg-yellow-400 rounded-b pointer-events-none" title="' + esc(tip) + '"></div>';
    var tk = i.ticket ? '<div class="absolute -top-1.5 -left-1 w-3 h-3 bg-white rounded-full border border-violet-500 flex items-center justify-center pointer-events-none" title="Direito a ticket extra no cartão">' +
      '<svg viewBox="0 0 24 24" width="8" height="8" fill="none" stroke="#7c3aed" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9a2 2 0 0 0 0 6v3h18v-3a2 2 0 0 1 0-6V6H3z"/><path d="M13 6v12" stroke-dasharray="2 3"/></svg></div>' : '';
    return barra + tk;
  }
  root.infoHoraExtra = infoHoraExtra;
  root.marcadorHoraExtra = marcadorHoraExtra;

  /* ------------------------- Modal de hora extra ------------------------- */
  function garantirModalHE() {
    if (el('he-modal')) return;
    var d = doc.createElement('div');
    d.id = 'he-modal';
    d.className = 'hidden fixed inset-0 bg-slate-900/70 z-[400] flex items-center justify-center p-4';
    d.innerHTML =
      '<div class="bg-white rounded-xl shadow-2xl w-full max-w-md max-h-[90vh] overflow-y-auto">' +
        '<div class="flex justify-between items-center px-5 py-3 border-b">' +
          '<h3 class="text-sm font-black text-slate-800 uppercase flex items-center gap-2"><span class="inline-block w-3 h-1 bg-yellow-400 rounded"></span> Hora Extra</h3>' +
          '<button type="button" id="he-fechar" class="text-slate-400 hover:text-red-500 text-lg leading-none" aria-label="Fechar">&times;</button>' +
        '</div>' +
        '<div class="px-5 pt-3 text-[11px] font-bold text-slate-500 uppercase" id="he-titulo"></div>' +
        '<div class="px-5 pt-2"><div class="text-[10px] font-bold text-slate-500 uppercase mb-1">Períodos registrados</div><div id="he-lista" class="space-y-1"></div>' +
          '<div id="he-total" class="mt-2 text-xs font-black text-slate-700"></div></div>' +
        '<form id="he-form" class="px-5 py-4 mt-3 border-t bg-slate-50 rounded-b-xl" novalidate>' +
          '<div class="text-[10px] font-bold text-slate-500 uppercase mb-2">Adicionar período</div>' +
          '<div class="grid grid-cols-2 gap-3">' +
            '<label class="text-[10px] font-bold text-slate-600 uppercase">Hora início<input type="time" id="he-ini" class="mt-1 w-full border rounded px-2 py-1.5 text-sm font-bold text-slate-800" required></label>' +
            '<label class="text-[10px] font-bold text-slate-600 uppercase">Hora fim<input type="time" id="he-fim" class="mt-1 w-full border rounded px-2 py-1.5 text-sm font-bold text-slate-800" required></label>' +
            '<label class="col-span-2 text-[10px] font-bold text-slate-600 uppercase">Hora de início ocorre<select id="he-dia" class="mt-1 w-full border rounded px-2 py-1.5 text-sm font-bold text-slate-800 bg-white"><option value="0">No dia do plantão</option><option value="1">Na madrugada seguinte (+1 dia)</option></select></label>' +
            '<label class="col-span-2 text-[10px] font-bold text-slate-600 uppercase">VIDPOL (chamado) *<input type="text" id="he-vidpol" inputmode="numeric" autocomplete="off" maxlength="6" placeholder="6 dígitos" class="mt-1 w-full border rounded px-2 py-1.5 text-sm font-mono font-bold tracking-widest text-slate-800" required></label>' +
          '</div>' +
          '<div id="he-erro" class="hidden mt-2 text-[11px] font-bold text-red-600"></div>' +
          '<div class="flex justify-end gap-2 mt-3">' +
            '<button type="button" id="he-cancelar" class="px-3 py-1.5 text-xs font-bold text-slate-600 bg-white border rounded hover:bg-slate-100">Fechar</button>' +
            '<button type="submit" id="he-salvar" class="px-3 py-1.5 text-xs font-bold text-white bg-yellow-500 hover:bg-yellow-600 rounded shadow-sm">Adicionar período</button>' +
          '</div>' +
        '</form>' +
      '</div>';
    doc.body.appendChild(d);
    el('he-fechar').onclick = el('he-cancelar').onclick = fecharHE;
    d.addEventListener('mousedown', function (ev) { if (ev.target === d) fecharHE(); });
    doc.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && !d.classList.contains('hidden')) fecharHE(); });
    el('he-vidpol').addEventListener('input', function () { this.value = this.value.replace(/\D/g, '').substring(0, 6); });
    el('he-form').addEventListener('submit', function (ev) { ev.preventDefault(); salvarPeriodoHE(); });
  }
  function fecharHE() { var m = el('he-modal'); if (m) m.classList.add('hidden'); alvoHE = null; }
  function fmtDataBR(s) { var p = s.split('-'); return p[2] + '/' + p[1] + '/' + p[0]; }

  function abrirHoraExtra(nome, data) {
    if (!ehGestor()) return;
    garantirModalHE();
    alvoHE = { nome: nome, data: data };
    el('he-titulo').textContent = nome + ' — sobreaviso de ' + fmtDataBR(data);
    el('he-ini').value = ''; el('he-fim').value = ''; el('he-vidpol').value = ''; el('he-dia').value = '0';
    mostrarErroHE('');
    renderListaHE();
    el('he-modal').classList.remove('hidden');
    setTimeout(function () { el('he-ini').focus(); }, 30);
  }
  function mostrarErroHE(m) { var e = el('he-erro'); e.textContent = m; e.classList.toggle('hidden', !m); }
  function renderListaHE() {
    if (!alvoHE) return;
    var itens = horasExtrasDB.filter(function (e) { return e.nome === alvoHE.nome && e.data === alvoHE.data; });
    var lista = el('he-lista');
    lista.innerHTML = itens.length ? itens.map(function (e) {
      var vira = hm(e.hora_fim) <= hm(e.hora_inicio), seg = e.dia_inicio ? ' <span class="text-[9px] text-slate-400">(madrugada seguinte)</span>' : '';
      return '<div class="flex items-center justify-between bg-yellow-50 border border-yellow-200 rounded px-2 py-1.5 text-xs">' +
        '<div class="font-bold text-slate-700">' + esc(e.hora_inicio) + seg + ' → ' + esc(e.hora_fim) + (vira ? ' <span class="text-[9px] text-slate-400">(+1 dia)</span>' : '') +
        ' <span class="text-slate-400 font-semibold">· ' + fmtMin(e.minutos) + '</span> <span class="font-mono text-slate-500">· VIDPOL ' + esc(e.vidpol) + '</span></div>' +
        '<button type="button" data-id="' + e.id + '" class="he-del text-red-500 hover:text-red-700 font-black px-1" title="Excluir período">&times;</button></div>';
    }).join('') : '<div class="text-xs text-slate-400 font-semibold">Nenhum período registrado.</div>';
    Array.prototype.forEach.call(lista.querySelectorAll('.he-del'), function (b) {
      b.onclick = function () { excluirPeriodoHoraExtra(+b.getAttribute('data-id')); };
    });
    var i = resumoDia(horasExtrasDB, alvoHE.nome, alvoHE.data);
    var t = el('he-total');
    if (!i) { t.textContent = ''; return; }
    t.innerHTML = 'Total do dia: ' + fmtMin(i.minutos) + (i.ticket
      ? ' <span class="ml-1 px-1.5 py-0.5 rounded bg-violet-100 text-violet-700 border border-violet-300">🎫 Direito a ticket extra no cartão</span>'
      : ' <span class="text-slate-400 font-semibold">(ticket extra apenas acima de 2h)</span>');
  }
  async function salvarPeriodoHE() {
    if (!alvoHE) return;
    var hi = el('he-ini').value, hf = el('he-fim').value, v = el('he-vidpol').value.trim();
    var doDia = horasExtrasDB.filter(function (e) { return e.nome === alvoHE.nome && e.data === alvoHE.data; });
    var viz = [];
    horasExtrasDB.forEach(function (e) {
      if (e.nome !== alvoHE.nome) return;
      if (e.data === root.GestaoCore.addDias(alvoHE.data, -1)) viz.push({ off: -1440, e: e });
      if (e.data === root.GestaoCore.addDias(alvoHE.data, 1)) viz.push({ off: 1440, e: e });
    });
    var dia = +el('he-dia').value ? 1 : 0;
    var erro = validarHE(hi, hf, v, doDia, viz, dia);
    if (erro) { mostrarErroHE(erro); return; }
    mostrarErroHE('');
    var btn = el('he-salvar'); btn.disabled = true;
    try {
      var j = await post('adicionar_hora_extra', { nome: alvoHE.nome, data: alvoHE.data, hora_inicio: hi, hora_fim: hf, dia_inicio: dia, vidpol: v });
      if (!j.sucesso) { mostrarErroHE(j.erro || 'Falha ao salvar.'); return; }
      await carregarHorasExtras();
      el('he-ini').value = ''; el('he-fim').value = ''; el('he-vidpol').value = '';
      renderListaHE(); rerenderTudo();
      toast('Período de hora extra registrado!');
    } catch (e) { mostrarErroHE(e.message || 'Falha ao salvar.'); }
    finally { btn.disabled = false; }
  }
  async function excluirPeriodoHoraExtra(id) {
    if (!confirm('Excluir este período de hora extra?')) return;
    try {
      var j = await post('remover_hora_extra', { id: id });
      if (!j.sucesso) { mostrarErroHE(j.erro || 'Falha ao excluir.'); return; }
      await carregarHorasExtras();
      renderListaHE(); rerenderTudo();
      toast('Período excluído.');
    } catch (e) { mostrarErroHE(e.message || 'Falha ao excluir.'); }
  }
  root.abrirHoraExtra = abrirHoraExtra;
  root.excluirPeriodoHoraExtra = excluirPeriodoHoraExtra;

  /* ------------------------ Dashboard gerencial ------------------------ */
  var gestaoRef = null; // Date (1º dia do mês de início da competência)
  function refInicial() {
    var d = gMes();
    return new Date(d.getFullYear(), d.getMonth(), 1);
  }
  function garantirViewGestao() {
    if (el('view-gestao')) return;
    var v = doc.createElement('div');
    v.id = 'view-gestao';
    v.className = 'hidden w-full h-full overflow-y-auto custom-scrollbar bg-slate-50 p-4 sm:p-6';
    v.innerHTML =
      '<div class="max-w-6xl mx-auto pb-10">' +
        '<div class="flex flex-col md:flex-row md:justify-between md:items-center mb-5 gap-3 border-b pb-4">' +
          '<h2 class="text-xl font-black text-slate-800 uppercase flex items-center gap-2"><i data-lucide="layout-dashboard" class="w-6 h-6 text-blue-600"></i> Dashboard Gerencial</h2>' +
          '<div class="flex flex-wrap items-center gap-2">' +
            '<select id="gestao-setor" class="border border-slate-300 rounded-lg px-2 py-1.5 text-xs font-bold bg-white text-slate-700"></select>' +
            '<div class="flex items-center bg-white border border-slate-200 rounded-lg shadow-sm">' +
              '<button type="button" id="gestao-ant" class="px-2.5 py-1.5 text-slate-500 hover:text-blue-600 font-black" aria-label="Período anterior">&#8249;</button>' +
              '<span id="gestao-periodo" class="px-2 text-xs font-bold text-slate-600 whitespace-nowrap"></span>' +
              '<button type="button" id="gestao-prox" class="px-2.5 py-1.5 text-slate-500 hover:text-blue-600 font-black" aria-label="Próximo período">&#8250;</button>' +
            '</div>' +
          '</div>' +
        '</div>' +
        '<div id="gestao-corpo"></div>' +
      '</div>';
    var host = el('main-container') || doc.body;
    host.appendChild(v);
    el('gestao-ant').onclick = function () { gestaoRef = new Date(gestaoRef.getFullYear(), gestaoRef.getMonth() - 1, 1); renderGestao(); };
    el('gestao-prox').onclick = function () { gestaoRef = new Date(gestaoRef.getFullYear(), gestaoRef.getMonth() + 1, 1); renderGestao(); };
    el('gestao-setor').onchange = renderGestao;
  }
  function tecnicosVisiveis() {
    var lista = gTecnicos();
    if (gAccess() === 'admin' && gSetoresGer()) {
      var ok = gSetoresGer().split(',').map(function (s) { return s.trim().toUpperCase(); });
      lista = lista.filter(function (t) { return ok.indexOf(String(t.setor || '').toUpperCase()) >= 0; });
    }
    return lista;
  }
  function kpi(titulo, valor, sub, cor) {
    return '<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 border-l-4" style="border-left-color:' + cor + '">' +
      '<div class="text-[10px] font-bold text-slate-500 uppercase tracking-wide">' + titulo + '</div>' +
      '<div class="text-2xl font-black text-slate-800 mt-1">' + valor + '</div>' +
      '<div class="text-[10px] font-semibold text-slate-400 mt-0.5">' + sub + '</div></div>';
  }
  function barras(itens, cor, fmt, vazio) {
    if (!itens.length) return '<div class="text-xs text-slate-400 font-semibold py-4 text-center">' + vazio + '</div>';
    var max = Math.max.apply(null, itens.map(function (i) { return i.v; })) || 1;
    return itens.map(function (i) {
      var w = Math.max(2, Math.round(i.v / max * 100));
      return '<div class="flex items-center gap-2 text-[11px] mb-1.5"><div class="w-28 sm:w-36 truncate font-bold text-slate-600 uppercase" title="' + esc(i.nomeCompleto || i.nome) + '">' + esc(i.nome) + '</div>' +
        '<div class="flex-1 bg-slate-100 rounded h-4 overflow-hidden"><div class="h-4 rounded" style="width:' + w + '%;background:' + cor + '"></div></div>' +
        '<div class="w-16 text-right font-black text-slate-700">' + fmt(i.v) + '</div></div>';
    }).join('');
  }
  function donut(fatias, fmt) {
    var total = fatias.reduce(function (s, f) { return s + f.v; }, 0);
    if (!total) return '<div class="text-xs text-slate-400 font-semibold py-4 text-center">Sem hora extra no período.</div>';
    var R = 15.9155, acum = 0, arcos = '';
    fatias.forEach(function (f, i) {
      var pct = f.v / total * 100;
      arcos += '<circle r="' + R + '" cx="21" cy="21" fill="none" stroke="' + CORES[i % CORES.length] + '" stroke-width="6" stroke-dasharray="' + pct.toFixed(3) + ' ' + (100 - pct).toFixed(3) + '" stroke-dashoffset="' + (25 - acum).toFixed(3) + '"><title>' + esc(f.nome) + ': ' + fmt(f.v) + '</title></circle>';
      acum += pct;
    });
    var leg = fatias.map(function (f, i) {
      return '<div class="flex items-center gap-2 text-[11px] font-bold text-slate-600"><span class="inline-block w-2.5 h-2.5 rounded-sm" style="background:' + CORES[i % CORES.length] + '"></span><span class="truncate">' + esc(f.nome) + '</span><span class="ml-auto text-slate-800">' + fmt(f.v) + ' · ' + Math.round(f.v / total * 100) + '%</span></div>';
    }).join('');
    return '<div class="flex items-center gap-4"><svg viewBox="0 0 42 42" class="w-32 h-32 shrink-0" role="img" aria-label="Hora extra por setor">' +
      '<circle r="' + R + '" cx="21" cy="21" fill="none" stroke="#f1f5f9" stroke-width="6"/>' + arcos + '</svg><div class="flex-1 space-y-1 min-w-0">' + leg + '</div></div>';
  }
  function colunasDia(dias) {
    var max = Math.max.apply(null, dias.map(function (d) { return d.heMin; })) || 0;
    if (!max) return '<div class="text-xs text-slate-400 font-semibold py-4 text-center">Sem hora extra no período.</div>';
    return '<div class="flex items-end gap-[3px] h-32 pt-4">' + dias.map(function (d) {
      var h = d.heMin ? Math.max(4, Math.round(d.heMin / max * 100)) : 0;
      return '<div class="flex-1 flex flex-col items-center justify-end h-full min-w-0" title="' + fmtDataBR(d.data) + ': ' + fmtMin(d.heMin) + '">' +
        (d.heMin ? '<div class="text-[8px] font-black text-slate-600 leading-none mb-0.5">' + (d.heMin / 60).toFixed(d.heMin % 60 ? 1 : 0) + '</div>' : '') +
        '<div class="w-full rounded-t" style="height:' + h + '%;background:#f59e0b"></div>' +
        '<div class="text-[8px] text-slate-400 font-bold mt-0.5">' + d.data.substring(8) + '</div></div>';
    }).join('') + '</div>';
  }
  function card(titulo, corpo, extra) {
    return '<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 ' + (extra || '') + '"><div class="text-[11px] font-black text-slate-700 uppercase mb-3">' + titulo + '</div>' + corpo + '</div>';
  }

  function renderGestao() {
    if (!ehGestor()) return;
    garantirViewGestao();
    if (!gestaoRef) gestaoRef = refInicial();
    var comp = competencia(gestaoRef.getFullYear(), gestaoRef.getMonth());
    var nomes = gNomesMeses();
    el('gestao-periodo').textContent = nomes[comp.mesFim0] + ' / ' + comp.anoFim + ' (15/' + pad(gestaoRef.getMonth() + 1) + ' a 14/' + pad(comp.mesFim0 + 1) + ')';

    var tecs = tecnicosVisiveis();
    var sel = el('gestao-setor'), atual = sel.value;
    var setores = Array.from(new Set(tecs.map(function (t) { return t.setor; }).filter(Boolean))).sort();
    sel.innerHTML = '<option value="">Todos os setores</option>' + setores.map(function (s) { return '<option value="' + esc(s) + '">' + esc(s) + '</option>'; }).join('');
    sel.value = setores.indexOf(atual) >= 0 ? atual : '';

    var r = calcularGestao({ plantao: gPlantao(), tecnicos: tecs, extras: horasExtrasDB, ini: comp.ini, fim: comp.fim, setor: sel.value });
    var t = r.totais, L = r.linhas;
    var porSob = L.filter(function (l) { return l.horasLiquidas > 0; }).sort(function (a, b) { return b.horasLiquidas - a.horasLiquidas; })
      .map(function (l) { return { nome: l.nome, nomeCompleto: l.nomeCompleto, v: l.horasLiquidas }; });
    var porHE = L.filter(function (l) { return l.heMin > 0; }).sort(function (a, b) { return b.heMin - a.heMin; })
      .map(function (l) { return { nome: l.nome, nomeCompleto: l.nomeCompleto, v: l.heMin }; });
    var porTk = L.filter(function (l) { return l.tickets > 0; }).sort(function (a, b) { return b.tickets - a.tickets; })
      .map(function (l) { return { nome: l.nome, nomeCompleto: l.nomeCompleto, v: l.tickets }; });

    var html =
      '<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">' +
        kpi('Sobreaviso líquido', fmtH(t.horasLiquidas), 'Bruto ' + fmtH(t.horasBrutas) + (t.descontoMin ? ' − ' + fmtMin(t.descontoMin) + ' de hora extra' : ''), '#2563eb') +
        kpi('Hora extra', fmtMin(t.heMin), t.comHE + ' colaborador(es)', '#f59e0b') +
        kpi('Tickets extras', String(t.tickets), 'Dias com mais de 2h de hora extra', '#7c3aed') +
        kpi('Colaboradores em sobreaviso', String(L.filter(function (l) { return l.dias > 0; }).length), L.reduce(function (s, l) { return s + l.dias; }, 0) + ' dias de sobreaviso', '#059669') +
      '</div>' +
      '<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">' +
        card('Sobreaviso líquido por colaborador', barras(porSob, '#2563eb', fmtH, 'Sem sobreaviso no período.')) +
        card('Hora extra por colaborador', barras(porHE, '#f59e0b', fmtMin, 'Sem hora extra no período.')) +
        card('Tickets extras por colaborador', barras(porTk, '#7c3aed', function (v) { return v + ' 🎫'; }, 'Nenhum colaborador com direito a ticket extra.')) +
        card('Hora extra por setor', donut(r.porSetor.filter(function (s) { return s.heMin > 0; }).map(function (s) { return { nome: s.setor, v: s.heMin }; }), fmtMin)) +
      '</div>' +
      card('Hora extra por dia (horas)', colunasDia(r.porDia), 'mb-4') +
      '<div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-x-auto"><table class="w-full text-xs"><thead class="bg-slate-50 text-[10px] uppercase text-slate-500 font-bold"><tr>' +
        '<th class="text-left px-3 py-2">Colaborador</th><th class="text-left px-3 py-2">Setor</th><th class="text-right px-3 py-2">Dias</th><th class="text-right px-3 py-2">Sobreaviso bruto</th>' +
        '<th class="text-right px-3 py-2">Hora extra</th><th class="text-right px-3 py-2">Períodos</th><th class="text-right px-3 py-2">Sobreaviso líquido</th><th class="text-right px-3 py-2">Tickets extras</th></tr></thead><tbody class="divide-y divide-slate-100">' +
      (L.length ? L.slice().sort(function (a, b) { return b.horasLiquidas - a.horasLiquidas; }).map(function (l) {
        return '<tr class="hover:bg-slate-50"><td class="px-3 py-2 font-bold text-slate-700 uppercase">' + esc(l.nome) + '</td><td class="px-3 py-2 text-slate-500">' + esc(l.setor) + '</td>' +
          '<td class="px-3 py-2 text-right">' + l.dias + '</td><td class="px-3 py-2 text-right">' + fmtH(l.horasBrutas) + '</td>' +
          '<td class="px-3 py-2 text-right ' + (l.heMin ? 'font-black text-amber-600' : 'text-slate-300') + '">' + (l.heMin ? fmtMin(l.heMin) : '—') + '</td>' +
          '<td class="px-3 py-2 text-right">' + (l.periodos || '—') + '</td><td class="px-3 py-2 text-right font-black text-blue-700">' + fmtH(l.horasLiquidas) + '</td>' +
          '<td class="px-3 py-2 text-right ' + (l.tickets ? 'font-black text-violet-700' : 'text-slate-300') + '">' + (l.tickets ? l.tickets + ' 🎫' : '—') + '</td></tr>';
      }).join('') : '<tr><td colspan="8" class="px-3 py-8 text-center text-slate-400 font-bold">Nenhum dado no período.</td></tr>') +
      '</tbody></table></div>' +
      '<p class="text-[10px] text-slate-400 font-semibold mt-3">Sobreaviso líquido = sobreaviso bruto menos as horas extras que coincidem com a janela de sobreaviso (a empresa não paga as duas ao mesmo tempo). Ticket extra: um por dia em que a soma dos períodos de hora extra supera 2h.</p>';
    el('gestao-corpo').innerHTML = html;
    icones();
  }
  root.renderGestao = renderGestao;

  /* ------------------ Períodos de sobreaviso (Configurações) ------------------ */
  function vigentesPorTipo(hoje) {
    var out = {};
    Object.keys(TIPOS).forEach(function (tp) {
      var todos = periodosDB.filter(function (p) { return p.tipo === tp; });
      if (!todos.length) { out[tp] = []; return; }
      var ate = todos.filter(function (p) { return p.vigencia <= hoje; });
      var vig = ate.length ? ate.reduce(function (m, p) { return p.vigencia > m ? p.vigencia : m; }, '0000') : todos.reduce(function (m, p) { return p.vigencia < m ? p.vigencia : m; }, '9999');
      out[tp] = todos.filter(function (p) { return p.vigencia === vig; }).sort(function (a, b) { return (a.dia_inicio * 1440 + hm(a.hora_inicio)) - (b.dia_inicio * 1440 + hm(b.hora_inicio)); });
    });
    return out;
  }
  function hojeISO() { var d = new Date(); return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function txtPeriodo(p) {
    return p.hora_inicio + (p.dia_inicio ? ' (' + DIA_LABEL[p.dia_inicio] + ')' : '') + ' → ' + p.hora_fim + (p.dia_fim ? ' (' + DIA_LABEL[p.dia_fim] + ')' : '');
  }
  async function carregarPeriodosSobreaviso() {
    if (!ehGestor() || !el('periodos-tabela')) return;
    try {
      var j = await api('get_periodos_sobreaviso');
      if (j.sucesso) { periodosDB = j.itens || []; renderPeriodos(); }
    } catch (e) { toast('Não foi possível carregar os períodos de sobreaviso.', 'error'); }
  }
  function renderPeriodos() {
    var resumo = el('periodos-resumo'), tabela = el('periodos-tabela');
    if (!resumo || !tabela) return;
    var vig = vigentesPorTipo(hojeISO());
    resumo.innerHTML = Object.keys(TIPOS).map(function (tp) {
      var tot = vig[tp].reduce(function (s, p) { return s + p.minutos; }, 0);
      return '<div class="bg-white border rounded p-2"><div class="text-[10px] font-black text-slate-500 uppercase">' + TIPOS[tp] + ' <span class="text-blue-700">· ' + fmtMin(tot) + '</span></div>' +
        (vig[tp].map(function (p) { return '<div class="text-xs font-bold text-slate-700">' + esc(txtPeriodo(p)) + '</div>'; }).join('') || '<div class="text-xs text-slate-400">—</div>') + '</div>';
    }).join('');
    tabela.innerHTML = periodosDB.length ? periodosDB.map(function (p) {
      return '<tr class="border-b"><td class="px-2 py-1 font-bold">' + TIPOS[p.tipo] + '</td><td class="px-2 py-1">' + fmtDataBR(p.vigencia) + '</td><td class="px-2 py-1">' + esc(txtPeriodo(p)) + '</td><td class="px-2 py-1 text-right font-bold">' + fmtMin(p.minutos) + '</td>' +
        '<td class="px-2 py-1 text-right"><button type="button" class="per-del text-red-500 hover:text-red-700 font-black" data-id="' + p.id + '" title="Excluir">&times;</button></td></tr>';
    }).join('') : '<tr><td colspan="5" class="px-2 py-3 text-center text-slate-400">Nenhum período cadastrado.</td></tr>';
    Array.prototype.forEach.call(tabela.querySelectorAll('.per-del'), function (b) { b.onclick = function () { removerPeriodoSobreaviso(+b.getAttribute('data-id')); }; });
    var dica = el('periodos-dica');
    if (dica) dica.textContent = 'Cada período vale a partir da data de vigência e só afeta plantões lançados depois disso; plantões já lançados mantêm seus horários. Para aplicar a um plantão existente, limpe e lance novamente.';
  }
  async function adicionarPeriodoSobreaviso() {
    var body = { tipo: el('per-tipo').value, vigencia: el('per-vig').value, dia_inicio: +el('per-di').value, hora_inicio: el('per-hi').value, dia_fim: +el('per-df').value, hora_fim: el('per-hf').value };
    if (!body.vigencia) return toast('Informe a data de vigência.', 'error');
    if (hm(body.hora_inicio) === null || hm(body.hora_fim) === null) return toast('Informe hora de início e de fim.', 'error');
    if (body.dia_fim * 1440 + hm(body.hora_fim) <= body.dia_inicio * 1440 + hm(body.hora_inicio)) return toast('O fim deve ser posterior ao início.', 'error');
    try {
      var j = await post('adicionar_periodo_sobreaviso', body);
      if (!j.sucesso) return toast(j.erro || 'Falha ao adicionar.', 'error');
      await carregarPeriodosSobreaviso();
      ajustarHorasPagasAosPeriodos();
      toast('Período adicionado.');
    } catch (e) { toast(e.message, 'error'); }
  }
  async function removerPeriodoSobreaviso(id) {
    if (!confirm('Excluir este período de sobreaviso?')) return;
    try {
      var j = await post('remover_periodo_sobreaviso', { id: id });
      if (!j.sucesso) return toast(j.erro || 'Falha ao excluir.', 'error');
      await carregarPeriodosSobreaviso();
      ajustarHorasPagasAosPeriodos();
      toast('Período removido.');
    } catch (e) { toast(e.message, 'error'); }
  }
  // Preenche os campos "Horas Pagas" com a soma dos períodos vigentes (o gestor ainda precisa clicar em salvar)
  function ajustarHorasPagasAosPeriodos() {
    var v = vigentesPorTipo(hojeISO()), a = el('cfg-horas-uteis'), b = el('cfg-horas-fds');
    var soma = function (tp) { return v[tp].reduce(function (s, p) { return s + p.minutos; }, 0) / 60; };
    if (a && v.SEMANA.length) a.value = soma('SEMANA');
    if (b && v.FIM_DE_SEMANA.length) b.value = soma('FIM_DE_SEMANA');
    if (a || b) toast('Horas pagas atualizadas nos campos acima — clique em salvar as opções para confirmar.', 'info');
  }
  root.carregarPeriodosSobreaviso = carregarPeriodosSobreaviso;
  root.adicionarPeriodoSobreaviso = adicionarPeriodoSobreaviso;
  root.removerPeriodoSobreaviso = removerPeriodoSobreaviso;
  root.ajustarHorasPagasAosPeriodos = ajustarHorasPagasAosPeriodos;

  /* ------------------------------ Hooks ------------------------------ */
  function hook(nome, fabrica) {
    var orig = root[nome];
    if (typeof orig !== 'function' || orig.__gestao) return;
    var novo = fabrica(orig); novo.__gestao = true; root[nome] = novo;
  }
  function instalarHooks() {
    hook('switchView', function (orig) {
      return function (mode) {
        var v = el('view-gestao');
        if (mode === 'gestao') {
          if (!ehGestor()) return orig('week');
          garantirViewGestao();
          orig('gestao');
          el('view-gestao').classList.remove('hidden');
          var fb = el('filter-bar-container');
          if (fb) { fb.classList.add('hidden'); fb.classList.remove('flex'); }
          if (typeof root.adjustMainHeight === 'function') root.adjustMainHeight();
          renderGestao();
          return;
        }
        if (v) v.classList.add('hidden');
        return orig.apply(this, arguments);
      };
    });
    hook('openCellMenu', function (orig) {
      return function (ev, nome, data) {
        var b = el('btn-hora-extra');
        if (b) {
          var c = gPlantao()[nome] && gPlantao()[nome][data];
          b.classList.toggle('hidden', !(ehGestor() && ehPlantao(c)));
        }
        return orig.apply(this, arguments);
      };
    });
    hook('abrirConfiguracoes', function (orig) {
      return function () { var r = orig.apply(this, arguments); carregarPeriodosSobreaviso(); return r; };
    });
  }

  function iniciar() {
    instalarHooks(); // os hooks já checam o perfil; assim 'gestao' nunca abre para quem não é gestor
    if (!ehGestor()) return;
    var vig = el('per-vig'); if (vig && !vig.value) vig.value = hojeISO();
    carregarHorasExtras().then(rerenderTudo);
  }
  root.carregarHorasExtras = carregarHorasExtras;
  iniciar();
})(typeof window !== 'undefined' ? window : globalThis);
EOF_HE
cat << 'EOF_HE' > /opt/escala/www/patch_he.py
#!/usr/bin/env python3
# Patch: hora extra / ticket extra / dashboard gerencial / períodos de sobreaviso. Rode em /opt/escala/www
import sys, os, shutil

BASE = os.environ.get('ESCALA_DIR', '/opt/escala/www')
MARK = 'HE_PATCH_V1'

def ler(n):
    with open(os.path.join(BASE, n), encoding='utf-8') as f: return f.read()

def unico(txt, ancora, nome):
    c = txt.count(ancora)
    if c != 1:
        sys.exit(f'ABORTADO (nada foi alterado): âncora "{nome}" encontrada {c}x em vez de 1.')
    return ancora

idx, api, men = ler('index.php'), ler('api_escala.php'), ler('mensal.php')
for n, t in (('index.php', idx), ('api_escala.php', api), ('mensal.php', men)):
    if MARK in t: sys.exit(f'ABORTADO: {n} já está com o patch aplicado.')
for n in ('hora_extra.php', 'gestao.js'):
    if not os.path.exists(os.path.join(BASE, n)): sys.exit(f'ABORTADO: falta {n} em {BASE} (crie-o antes).')

# ---------- index.php ----------
a1 = unico(idx, '''        <div class="px-2 py-1 text-[8px] text-slate-400 font-bold uppercase bg-slate-50 border-y mt-1">Ausências</div>''', 'cabeçalho Ausências')
idx = idx.replace(a1, '''        <div id="btn-hora-extra" onclick="abrirHoraExtra(targetCellData.nome, targetCellData.dataStr)" class="hidden px-3 py-1.5 hover:bg-slate-100 cursor-pointer flex items-center gap-2 border-t border-slate-100 mt-1"><div class="w-2.5 h-1 rounded bg-yellow-400 shadow-sm"></div>Hora Extra</div>
''' + a1)

lin = [l for l in idx.split('\n') if "onclick=\"switchView('dashboard')\" class=\"p-1.5" in l]
if len(lin) != 1: sys.exit(f'ABORTADO (nada foi alterado): botão do ranking encontrado {len(lin)}x.')
idx = idx.replace(lin[0], lin[0] + '''
                    <button id="btn-gestao" onclick="switchView('gestao')" class="p-1.5 bg-amber-500 hover:bg-amber-400 text-white rounded transition-colors shadow-sm" title="Dashboard Gerencial (sobreaviso, hora extra e tickets)"><i data-lucide="layout-dashboard" class="w-4 h-4"></i></button>''')

lin2 = [l for l in idx.split('\n') if "onclick=\"switchView('dashboard'); toggleMobileMenu();\"" in l]
if len(lin2) != 1: sys.exit(f'ABORTADO (nada foi alterado): botão mobile do ranking encontrado {len(lin2)}x.')
idx = idx.replace(lin2[0], lin2[0] + '''
                <button onclick="switchView('gestao'); toggleMobileMenu();" class="flex items-center justify-center gap-2 p-2 bg-amber-500 hover:bg-amber-400 text-white rounded text-xs font-bold"><i data-lucide="layout-dashboard" class="w-3.5 h-3.5"></i> Gerencial</button>''')

a3 = unico(idx, '''            <div class="flex-1 overflow-y-auto custom-scrollbar pr-2 space-y-6">
                <div class="bg-slate-50 p-4 rounded border flex flex-col lg:flex-row gap-6 shrink-0">''', 'corpo do modal de config')
painel = '''            <div class="flex-1 overflow-y-auto custom-scrollbar pr-2 space-y-6">
                <!-- HE_PATCH_V1: períodos de sobreaviso -->
                <div class="bg-amber-50 p-4 rounded border border-amber-200 shrink-0">
                    <h3 class="text-sm font-bold text-slate-700 mb-3 border-b border-amber-200 pb-1"><i data-lucide="alarm-clock" class="inline w-4 h-4 mr-1"></i> Períodos de Sobreaviso</h3>
                    <div id="periodos-resumo" class="grid grid-cols-1 md:grid-cols-3 gap-2 mb-3"></div>
                    <div class="grid grid-cols-2 md:grid-cols-7 gap-2 items-end">
                        <label class="text-[10px] font-bold text-slate-600 uppercase">Tipo de dia
                            <select id="per-tipo" class="mt-1 w-full border rounded px-2 py-1.5 text-xs font-bold bg-white"><option value="SEMANA">Semana (seg–sex)</option><option value="FIM_DE_SEMANA">Final de semana</option><option value="FERIADO">Feriado</option></select></label>
                        <label class="text-[10px] font-bold text-slate-600 uppercase">Vigência (a partir de)
                            <input type="date" id="per-vig" class="mt-1 w-full border rounded px-2 py-1.5 text-xs font-bold bg-white"></label>
                        <label class="text-[10px] font-bold text-slate-600 uppercase">Início - dia
                            <select id="per-di" class="mt-1 w-full border rounded px-2 py-1.5 text-xs font-bold bg-white"><option value="0">Mesmo dia</option><option value="1">+1 dia</option><option value="2">+2 dias</option></select></label>
                        <label class="text-[10px] font-bold text-slate-600 uppercase">Início - hora
                            <input type="time" id="per-hi" value="22:00" class="mt-1 w-full border rounded px-2 py-1.5 text-xs font-bold bg-white"></label>
                        <label class="text-[10px] font-bold text-slate-600 uppercase">Fim - dia
                            <select id="per-df" class="mt-1 w-full border rounded px-2 py-1.5 text-xs font-bold bg-white"><option value="0">Mesmo dia</option><option value="1" selected>+1 dia</option><option value="2">+2 dias</option></select></label>
                        <label class="text-[10px] font-bold text-slate-600 uppercase">Fim - hora
                            <input type="time" id="per-hf" value="06:00" class="mt-1 w-full border rounded px-2 py-1.5 text-xs font-bold bg-white"></label>
                        <button type="button" onclick="adicionarPeriodoSobreaviso()" class="px-3 py-2 bg-amber-500 hover:bg-amber-600 text-white rounded text-xs font-bold shadow-sm">Adicionar período</button>
                    </div>
                    <div class="mt-3 max-h-48 overflow-y-auto bg-white border rounded">
                        <table class="w-full text-xs"><thead class="bg-slate-50 text-[10px] uppercase text-slate-500"><tr><th class="text-left px-2 py-1">Tipo</th><th class="text-left px-2 py-1">Vigência</th><th class="text-left px-2 py-1">Período</th><th class="text-right px-2 py-1">Duração</th><th></th></tr></thead><tbody id="periodos-tabela"></tbody></table>
                    </div>
                    <p id="periodos-dica" class="mt-2 text-[10px] text-slate-500 font-semibold"></p>
                </div>
                <div class="bg-slate-50 p-4 rounded border flex flex-col lg:flex-row gap-6 shrink-0">'''
idx = idx.replace(a3, painel)

a4 = unico(idx, '</body>', '</body>')
idx = idx.replace(a4, '<script src="gestao.js?v=1"></script>\n' + a4)

# ---------- mensal.php ----------
lin3 = [l for l in men.split('\n') if 'let hasAnexoMarker = anexoData' in l]
if len(lin3) != 1: sys.exit(f'ABORTADO (nada foi alterado): hasAnexoMarker encontrado {len(lin3)}x em mensal.php.')
men = men.replace(lin3[0], lin3[0] + "\n                    if (typeof marcadorHoraExtra === 'function') hasAnexoMarker += marcadorHoraExtra(nome, d.dataStr); // HE_PATCH_V1")

# ---------- api_escala.php ----------
a5 = unico(api, "require_once 'conexao.php';", "require conexao")
api = api.replace(a5, a5 + "\nrequire_once __DIR__ . '/hora_extra.php'; // HE_PATCH_V1\nif ($pdo) { try { heGarantirTabelas($pdo); } catch (Exception $e) {} }", 1)

a6 = unico(api, "function inserirPlantao($pdo, $nome, $dataStr, $status, $anexoUrl, $feriadosDB) {\n    $stmtDel = $pdo->prepare(\"DELETE FROM escala_dados WHERE nome_colaborador = :nome AND data_escala = :data\");\n    $stmtDel->execute(['nome' => $nome, 'data' => $dataStr]);\n",
           'início de inserirPlantao')
api = api.replace(a6, a6 + """
    // HE_PATCH_V1: sem sobreaviso no dia => hora extra do dia deixa de existir
    if ($status === null || $status === 'limpar' || !str_starts_with($status, 'plantao')) {
        try { $pdo->prepare("DELETE FROM horas_extras WHERE nome_colaborador = :n AND data_escala = :d")->execute(['n' => $nome, 'd' => $dataStr]); } catch (Exception $e) {}
    }
""")

ini = api.index("    if (str_starts_with($status, 'plantao')) {\n        $timestamp = strtotime($dataStr);")
fim = api.index("    } else {\n        $stmtIns->execute([\n            'nome' => $nome, 'data' => $dataStr, 'status' => $status, \n            'horas' => 0.00")
if not (ini < fim): sys.exit('ABORTADO (nada foi alterado): bloco de plantão em inserirPlantao não reconhecido.')
novo_bloco = """    if (str_starts_with($status, 'plantao')) {
        // HE_PATCH_V1: horários vêm dos períodos de sobreaviso configurados (Painel de Administração)
        $segmentos = pgSegmentosPlantao($pdo, $dataStr, $feriadosDB);
        foreach ($segmentos as $sg) {
            $stmtIns->execute([
                'nome' => $nome, 'data' => $dataStr, 'status' => $status,
                'horas' => $sg['horas'], 'anexo' => $anexoUrl,
                'd_ini' => $sg['d_ini'], 'h_ini' => $sg['h_ini'],
                'd_fim' => $sg['d_fim'], 'h_fim' => $sg['h_fim']
            ]);
        }
"""
api = api[:ini] + novo_bloco + api[fim:]

a7 = unico(api, "if ($action === 'get_config') {", 'get_config')
rotas = r"""// ===== HE_PATCH_V1: hora extra e períodos de sobreaviso =====
function heExigeGestor() {
    if (!heEhGestor()) { http_response_code(403); echo json_encode(['sucesso' => false, 'erro' => 'Acesso restrito a gestores.']); exit; }
}
function heEntrada() { return json_decode(file_get_contents('php://input'), true) ?: []; }

if ($action === 'get_horas_extras') {
    heExigeGestor();
    try { echo json_encode(['sucesso' => true, 'itens' => heListar($pdo)]); }
    catch (Exception $e) { echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]); }
    exit;
}
if ($action === 'adicionar_hora_extra' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    heExigeGestor();
    $in = heEntrada();
    try {
        $r = heAdicionar($pdo, $in['nome'] ?? '', $in['data'] ?? '', $in['hora_inicio'] ?? '', $in['hora_fim'] ?? '', $in['vidpol'] ?? '', $usuarioLogado, $in['dia_inicio'] ?? 0);
        if (!$r['ok']) { echo json_encode(['sucesso' => false, 'erro' => $r['erro']]); exit; }
        registrarAuditoria($pdo, $usuarioLogado, 'HORA EXTRA', "Registrou hora extra de {$in['nome']} em {$in['data']} ({$in['hora_inicio']}-{$in['hora_fim']}" . (!empty($in['dia_inicio']) ? ', madrugada seguinte' : '') . ", VIDPOL {$in['vidpol']})");
        echo json_encode(['sucesso' => true, 'id' => $r['id'], 'minutos' => $r['minutos']]);
    } catch (Exception $e) { echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]); }
    exit;
}
if ($action === 'remover_hora_extra' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    heExigeGestor();
    $in = heEntrada();
    try {
        $r = heRemover($pdo, $in['id'] ?? 0);
        if (!$r) { echo json_encode(['sucesso' => false, 'erro' => 'Registro não encontrado.']); exit; }
        registrarAuditoria($pdo, $usuarioLogado, 'HORA EXTRA', "Excluiu hora extra de {$r['nome_colaborador']} em {$r['d']} ({$r['hora_inicio']}-{$r['hora_fim']}, VIDPOL " . trim($r['vidpol']) . ")");
        echo json_encode(['sucesso' => true]);
    } catch (Exception $e) { echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]); }
    exit;
}
if ($action === 'get_periodos_sobreaviso') {
    heExigeGestor();
    try { echo json_encode(['sucesso' => true, 'itens' => pgListar($pdo)]); }
    catch (Exception $e) { echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]); }
    exit;
}
if ($action === 'adicionar_periodo_sobreaviso' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    heExigeGestor();
    $in = heEntrada();
    try {
        $r = pgAdicionar($pdo, $in['tipo'] ?? '', $in['vigencia'] ?? '', $in['dia_inicio'] ?? 0, $in['hora_inicio'] ?? '', $in['dia_fim'] ?? 0, $in['hora_fim'] ?? '', $usuarioLogado);
        if (!$r['ok']) { echo json_encode(['sucesso' => false, 'erro' => $r['erro']]); exit; }
        registrarAuditoria($pdo, $usuarioLogado, 'PERÍODO SOBREAVISO', "Adicionou período {$in['tipo']} a partir de {$in['vigencia']}: {$in['hora_inicio']} (+{$in['dia_inicio']}d) até {$in['hora_fim']} (+{$in['dia_fim']}d)");
        echo json_encode(['sucesso' => true, 'id' => $r['id']]);
    } catch (Exception $e) { echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]); }
    exit;
}
if ($action === 'remover_periodo_sobreaviso' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    heExigeGestor();
    $in = heEntrada();
    try {
        $r = pgRemover($pdo, $in['id'] ?? 0);
        if (!$r['ok']) { echo json_encode(['sucesso' => false, 'erro' => $r['erro']]); exit; }
        $x = $r['removido'];
        registrarAuditoria($pdo, $usuarioLogado, 'PERÍODO SOBREAVISO', "Removeu período {$x['tipo']} (vigência {$x['vigencia']}): {$x['hora_inicio']}-{$x['hora_fim']}");
        echo json_encode(['sucesso' => true]);
    } catch (Exception $e) { echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]); }
    exit;
}

"""
api = api.replace(a7, rotas + a7)

# ---------- grava (backups primeiro; se algo falhar, restaura tudo) ----------
novos = {'index.php': idx, 'mensal.php': men, 'api_escala.php': api}
backups = {}
for n in novos:
    p = os.path.join(BASE, n); k = 11
    while os.path.exists(f'{p}.bak{k}'): k += 1
    shutil.copy2(p, f'{p}.bak{k}'); backups[n] = f'{p}.bak{k}'
try:
    for n, t in novos.items():
        with open(os.path.join(BASE, n), 'w', encoding='utf-8') as f: f.write(t)
except Exception as e:
    for n, b in backups.items(): shutil.copy2(b, os.path.join(BASE, n))
    sys.exit(f'ERRO ao gravar ({e}); arquivos restaurados dos backups.')
for n, b in backups.items(): sys.stdout.write(f'ok  {n}  (backup: {os.path.basename(b)})\n')
sys.stdout.write('Patch aplicado. Valide com: php -l em index.php, mensal.php, api_escala.php e hora_extra.php; depois Ctrl+F5.\n')
EOF_HE
docker exec site-escala php -l /var/www/html/hora_extra.php || echo "(aviso: confira o caminho do site dentro do container)"
python3 /opt/escala/www/patch_he.py
for f in index.php mensal.php api_escala.php hora_extra.php; do docker exec site-escala php -l /var/www/html/$f || true; done
echo "Pronto. Ctrl+F5 no navegador."
