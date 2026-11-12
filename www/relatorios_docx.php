<?php
// relatorios_docx.php — RELATORIOS_DOCX_V1: Relatório Sobreaviso e Formulário VR Extra em Word (.docx), no mesmo padrão dos PDFs.
// Gera o .docx sem bibliotecas (o ZIP é montado aqui). Incluído por relatorios.php quando o formato pedido é Word.

function relZip($arqs) { // [nome => conteúdo] -> binário .zip (deflate)
    $dados = ''; $central = ''; $n = 0;
    foreach ($arqs as $nome => $cont) {
        $crc = crc32($cont); $z = gzdeflate($cont, 6); $off = strlen($dados);
        $cab = pack('vvvvvVVVvv', 20, 0x0800, 8, 0, 0x21, $crc, strlen($z), strlen($cont), strlen($nome), 0);
        $dados .= "PK\x03\x04" . $cab . $nome . $z;
        $central .= "PK\x01\x02" . pack('vvvvvvVVVvvvvvVV', 20, 20, 0x0800, 8, 0, 0x21, $crc, strlen($z), strlen($cont), strlen($nome), 0, 0, 0, 0, 0, $off) . $nome;
        $n++;
    }
    return $dados . $central . "PK\x05\x06" . pack('vvvvVVv', 0, 0, $n, $n, strlen($central), strlen($dados), 0);
}
function dxE($s) { return htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }
// run: [texto, negrito, tamanho em pt]
function dxR($t, $b = false, $pt = null) {
    $pr = ($b ? '<w:b/>' : '') . ($pt ? '<w:sz w:val="' . (int)round($pt * 2) . '"/><w:szCs w:val="' . (int)round($pt * 2) . '"/>' : '');
    $partes = explode("\n", (string)$t); $x = '';
    foreach ($partes as $i => $p) $x .= ($i ? '<w:br/>' : '') . '<w:t xml:space="preserve">' . dxE($p) . '</w:t>';
    return '<w:r>' . ($pr ? "<w:rPr>$pr</w:rPr>" : '') . $x . '</w:r>';
}
function dxP($runs, $al = 'left', $antes = 0, $depois = 0) {
    return '<w:p><w:pPr><w:spacing w:before="' . $antes . '" w:after="' . $depois . '" w:line="240" w:lineRule="auto"/><w:jc w:val="' . ($al === 'c' ? 'center' : ($al === 'r' ? 'right' : 'left')) . '"/></w:pPr>' . (is_array($runs) ? implode('', $runs) : $runs) . '</w:p>';
}
// tabela: $larg em twips; $linhas = [[células...], negrito?, cabeçalho?]; célula = texto | [texto, alinhamento]
function dxTab($larg, $linhas, $pt, $alinh) {
    $x = '<w:tbl><w:tblPr><w:tblW w:w="' . array_sum($larg) . '" w:type="dxa"/><w:jc w:val="center"/><w:tblBorders>';
    foreach (['top', 'left', 'bottom', 'right', 'insideH', 'insideV'] as $b) $x .= '<w:' . $b . ' w:val="single" w:sz="4" w:space="0" w:color="000000"/>';
    $x .= '</w:tblBorders><w:tblLayout w:type="fixed"/><w:tblCellMar><w:left w:w="70" w:type="dxa"/><w:right w:w="70" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid>';
    foreach ($larg as $w) $x .= '<w:gridCol w:w="' . $w . '"/>';
    $x .= '</w:tblGrid>';
    foreach ($linhas as $l) {
        $x .= '<w:tr><w:trPr><w:cantSplit/>' . (!empty($l[2]) ? '<w:tblHeader/>' : '') . '<w:trHeight w:val="' . (!empty($l[2]) ? 560 : 300) . '"/></w:trPr>';
        foreach ($l[0] as $i => $c) {
            $al = !empty($l[2]) ? 'c' : $alinh[$i];
            $x .= '<w:tc><w:tcPr><w:tcW w:w="' . $larg[$i] . '" w:type="dxa"/><w:vAlign w:val="center"/></w:tcPr>' . dxP(dxR($c, !empty($l[1]), $pt), $al, 30, 30) . '</w:tc>';
        }
        $x .= '</w:tr>';
    }
    return $x . '</w:tbl>';
}
function dxCaixa($larg, $linhas, $pt) { // caixa de uma coluna: cada linha = [[rótulo, valor]]
    $x = '<w:tbl><w:tblPr><w:tblW w:w="' . $larg . '" w:type="dxa"/><w:jc w:val="center"/><w:tblBorders>';
    foreach (['top', 'left', 'bottom', 'right', 'insideH'] as $b) $x .= '<w:' . $b . ' w:val="single" w:sz="4" w:space="0" w:color="000000"/>';
    $x .= '</w:tblBorders><w:tblCellMar><w:left w:w="70" w:type="dxa"/><w:right w:w="70" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid><w:gridCol w:w="' . $larg . '"/></w:tblGrid>';
    foreach ($linhas as $l) $x .= '<w:tr><w:trPr><w:trHeight w:val="300"/></w:trPr><w:tc><w:tcPr><w:tcW w:w="' . $larg . '" w:type="dxa"/></w:tcPr>' .
        dxP([dxR($l[0], true, $pt), $l[1] !== '' ? dxR(' ' . $l[1], !empty($l[2]), $pt) : ''], 'l', 20, 20) . '</w:tc></w:tr>';
    return $x . '</w:tbl>';
}
function dxRodape($larg, $pt) {
    $obs = dxCaixa($larg, [['Observações:', ''], ['', ''], ['', ''], ['', '']], $pt);
    return dxP(dxR('A ser preenchido pela Direção/Gerência', false, $pt), 'l', 360, 0) . str_replace('<w:b/>', '', $obs) .
        dxP(dxR('Atenciosamente,', false, $pt), 'l', 900, 0) .
        dxP(dxR('__________________, ________ de___________ de ___________', false, $pt), 'l', 900, 0) .
        dxP(dxR('_______________________________', false, $pt), 'c', 1100, 0) . dxP(dxR('Gestor Imediato', false, $pt), 'c', 0, 0);
}
// Monta o .docx; a logo (Configurações → Geral → Logo Relatório PDF) vai no cabeçalho, à direita, em todas as páginas
function dxDocx($pdo, $corpo, $fonte, $ptPadrao, $titulo) {
    $ns = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" ' .
          'xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" ' .
          'xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"';
    $bin = function_exists('relLogoBin') ? relLogoBin($pdo) : null; $inf = $bin ? @getimagesizefromstring($bin) : null;
    $ext = $inf && $inf[2] === IMAGETYPE_PNG ? 'png' : ($inf && $inf[2] === IMAGETYPE_JPEG ? 'jpeg' : null);
    $arq = [];
    $hdr = '<w:p><w:pPr><w:jc w:val="right"/><w:spacing w:after="0"/></w:pPr>';
    if ($ext) {
        $r = min(40 / $inf[0], 16 / $inf[1]); $cx = (int)round($inf[0] * $r * 36000); $cy = (int)round($inf[1] * $r * 36000);
        $hdr .= '<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="' . $cx . '" cy="' . $cy . '"/><wp:docPr id="1" name="Logo"/>' .
            '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic><pic:nvPicPr><pic:cNvPr id="1" name="logo.' . $ext . '"/><pic:cNvPicPr/></pic:nvPicPr>' .
            '<pic:blipFill><a:blip r:embed="rLogo"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm>' .
            '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r>';
        $arq['word/media/logo.' . $ext] = $bin;
    }
    $hdr .= '</w:p>';
    $arq['[Content_Types].xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
        '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>' .
        '<Default Extension="png" ContentType="image/png"/><Default Extension="jpeg" ContentType="image/jpeg"/>' .
        '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>' .
        '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>' .
        '<Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/>' .
        '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>';
    $arq['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>' .
        '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>';
    $arq['docProps/core.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/">' .
        '<dc:title>' . dxE($titulo) . '</dc:title><dc:creator>SmartNOC Escala</dc:creator></cp:coreProperties>';
    $arq['word/_rels/document.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' .
        '<Relationship Id="rHdr" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header1.xml"/></Relationships>';
    $arq['word/_rels/header1.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        ($ext ? '<Relationship Id="rLogo" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/logo.' . $ext . '"/>' : '') . '</Relationships>';
    $arq['word/styles.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:docDefaults>' .
        '<w:rPrDefault><w:rPr><w:rFonts w:ascii="' . $fonte . '" w:hAnsi="' . $fonte . '" w:cs="' . $fonte . '" w:eastAsia="' . $fonte . '"/><w:sz w:val="' . ($ptPadrao * 2) . '"/><w:szCs w:val="' . ($ptPadrao * 2) . '"/><w:lang w:val="pt-BR"/></w:rPr></w:rPrDefault>' .
        '<w:pPrDefault><w:pPr><w:spacing w:after="0" w:line="240" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>' .
        '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style></w:styles>';
    $arq['word/header1.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:hdr ' . $ns . '>' . $hdr . '</w:hdr>';
    $arq['word/document.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document ' . $ns . '><w:body>' . $corpo .
        '<w:sectPr><w:headerReference w:type="default" r:id="rHdr"/><w:pgSz w:w="11906" w:h="16838"/>' .
        '<w:pgMar w:top="1418" w:right="1134" w:bottom="1134" w:left="1134" w:header="425" w:footer="709" w:gutter="0"/></w:sectPr></w:body></w:document>';
    return relZip($arq);
}

function relDocxHoras($pdo, $comp, $filtro = []) {
    $cfg = relCfg($pdo); $linhas = [[['Matrícula', 'Nome Completo', "Dias\nSobreaviso", "TOTAL\nHoras\nSobreaviso"], true, true]]; $n = 0;
    foreach (relDados($pdo, $comp['ini'], $comp['fim'], $filtro) as $r) if ($r['dias'] > 0) { $linhas[] = [[$r['matricula'], $r['nc'], (string)$r['dias'], relFmtHoras($r['horas'])], true]; $n++; }
    if (!$n) $linhas[] = [['', 'Nenhum sobreaviso na competência.', '', ''], false];
    $corpo = dxP(dxR('SOLICITAÇÃO', true, 14), 'c', 0, 240) .
        dxCaixa(10318, [['CENTRO DE CUSTO:', $cfg['cc_horas'] ?? $cfg['centro_custo'], true], ['RESPONSÁVEL:', $cfg['resp_horas'] ?? $cfg['responsavel'], true], ['COMPETÊNCIA:', $comp['mes'] . '/' . $comp['ano'], true]], 10) .
        dxP(dxR('Solicitação de: SOBREAVISO', true, 10), 'l', 200, 120) .
        dxTab([1417, 4252, 2268, 2381], $linhas, 9, ['c', 'l', 'c', 'c']) . dxRodape(10318, 9);
    return ['bin' => dxDocx($pdo, $corpo, 'Arial', 10, 'Relatório de Sobreaviso ' . $comp['mes'] . '/' . $comp['ano']),
            'arquivo' => 'Relatorio_Sobreaviso_' . $comp['mes'] . '_' . $comp['ano'] . '.docx', 'linhas' => $n];
}
function relDocxVr($pdo, $comp, $filtro = []) {
    $cfg = relCfg($pdo); $linhas = [[['Matr.', 'Nome Completo', 'Dia', 'Qtde'], true, true]]; $total = 0; $n = 0;
    foreach (relDados($pdo, $comp['ini'], $comp['fim'], $filtro) as $r) if ($r['tickets']) {
        $linhas[] = [[$r['matricula'], mb_strtoupper($r['nc'], 'UTF-8'), implode(', ', $r['tickets']), (string)count($r['tickets'])], false]; $total += count($r['tickets']); $n++;
    }
    for ($i = 0; $i < 3; $i++) $linhas[] = [['', '', '', ''], false];
    $corpo = dxP(dxR('AUTORIZAÇÃO', true, 14), 'c', 0, 200) . dxP(dxR('VALE REFEIÇÃO – EXTRA', true, 14), 'c', 0, 360) .
        dxCaixa(9570, [['EMPRESA:', $cfg['empresa']], ['CENTRO DE CUSTO:', $cfg['centro_custo']], ['RESPONSÁVEL:', $cfg['responsavel']], ['OBJETIVO:', $cfg['objetivo_vr'] ?? 'Horas Extras Realizadas']], 12) .
        dxP([dxR('Solicito a compra de ', false, 11), dxR('VALE REFEIÇÃO', true, 11), dxR(' para trabalho conforme programação abaixo:', false, 11)], 'l', 360, 240) . // VR_SEM_COMPETENCIA_V1
        dxTab([1129, 4536, 2229, 928], $linhas, 9, ['c', 'c', 'c', 'c']) . dxRodape(8669, 11);
    return ['bin' => dxDocx($pdo, $corpo, 'Times New Roman', 12, 'Formulário VR Extra ' . $comp['mes'] . '/' . $comp['ano']),
            'arquivo' => 'Formulario_VR_Extra_' . $comp['mes'] . '_' . $comp['ano'] . '.docx', 'linhas' => $n, 'total' => $total];
}
