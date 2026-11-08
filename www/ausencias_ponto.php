<?php
// Abonos de dia inteiro APROVADOS no Ponto (tabela ponto_atestados) refletidos no mensal da escala (somente leitura).
// Um registro feito na própria escala (escala_dados) no mesmo dia tem prioridade.
function escalaCategoriaAbono($motivo, $tipoPadrao = 'Atestado Médico') {
    $m = mb_strtolower(trim((string)$motivo ?: $tipoPadrao), 'UTF-8');
    if ($m === 'feriado' || $m === 'troca de escala') return null;            // não são ausências
    if (strpos($m, 'aniversário') !== false || strpos($m, 'aniversario') !== false) return 'folga_aniversario';
    if (strpos($m, 'atestado médico') !== false || strpos($m, 'atestado medico') !== false || strpos($m, 'declara') !== false) return 'atestado';
    if (strpos($m, 'folga') !== false || strpos($m, 'abonado') !== false) return 'folga';
    return 'licenca';                                                          // licenças, óbito, TRE, vestibular, integração, etc.
}
function escalaMesclarAusenciasPonto($pdo, array &$dados) {
    try {
        if (!$pdo->query("SELECT to_regclass('ponto_atestados')")->fetchColumn()) return;
        $rows = $pdo->query("SELECT nome, motivo, to_char(data_ini,'YYYY-MM-DD') AS ini, to_char(data_fim,'YYYY-MM-DD') AS fim
                             FROM ponto_atestados
                             WHERE status = 'aprovada' AND tipo = 'dia' AND data_fim >= CURRENT_DATE - INTERVAL '400 days'
                             ORDER BY data_ini, id")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return; }
    foreach ($rows as $r) {
        $cat = escalaCategoriaAbono($r['motivo']);
        if ($cat === null) continue;
        $t = strtotime($r['ini'] . ' 12:00:00 UTC'); $f = strtotime($r['fim'] . ' 12:00:00 UTC');
        for ($n = 0; $t <= $f && $n < 120; $t += 86400, $n++) {
            $d = gmdate('Y-m-d', $t);
            if (isset($dados[$r['nome']][$d])) continue;
            $dados[$r['nome']][$d] = ['status' => $cat, 'anexo' => null, 'fracoes' => [], 'origem' => 'ponto', 'motivo' => $r['motivo'] ?: 'Atestado médico'];
        }
    }
}
