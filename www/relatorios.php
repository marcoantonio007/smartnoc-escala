<?php
// relatorios.php — RELATORIOS_V1: Relatório de Horas (sobreaviso) e Formulário de VR Extra (ticket extra) em PDF,
// gerados no servidor, para baixar na Escala e para envio automático por e-mail (Configurações → Relatórios).
// Incluído por api_escala.php (ações rel_*) e por relatorios_cron.php (envio agendado, rodado pelo cron do servidor).
date_default_timezone_set('America/Sao_Paulo'); // dia/hora dos envios e datas dos relatórios no horário de Brasília
require_once __DIR__ . '/hora_extra.php';
require_once __DIR__ . '/smtp_mail.php';

const REL_TICKET_MIN = 120; // ticket extra: soma do dia (calendário) ESTRITAMENTE maior que 2h — mesma regra da Escala
const REL_MESES = ['JANEIRO', 'FEVEREIRO', 'MARÇO', 'ABRIL', 'MAIO', 'JUNHO', 'JULHO', 'AGOSTO', 'SETEMBRO', 'OUTUBRO', 'NOVEMBRO', 'DEZEMBRO'];
const REL_TIPOS = ['horas' => 'Relatório de Horas (sobreaviso)', 'vr' => 'Formulário VR Extra (ticket extra)'];

function relGarantir($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS relatorios_envios (id SERIAL PRIMARY KEY, tipo VARCHAR(10) NOT NULL, ativo BOOLEAN NOT NULL DEFAULT TRUE,
        destinatarios TEXT NOT NULL DEFAULT '', dia SMALLINT NOT NULL DEFAULT 15, hora CHAR(5) NOT NULL DEFAULT '08:00', setores TEXT NOT NULL DEFAULT '',
        ultimo_ref VARCHAR(7), ultimo_em TIMESTAMP, ultimo_status TEXT, criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
}
function relCfg($pdo) {
    $c = ['empresa' => 'AVANTIA TECNOLOGIA E ENGENHARIA S/A – CNPJ 02.543.302/0001-31', 'centro_custo' => '210300406 - OI BA - POLICIA', 'cc_horas' => '210300406', 'responsavel' => '', 'resp_horas' => '', 'objetivo_vr' => 'Horas Extras Realizadas']; // RELATORIOS_CAB_V1
    try { $q = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'relatorios_cfg'"); $q->execute(); $v = json_decode((string)$q->fetchColumn(), true);
          if (is_array($v)) { foreach ($c as $k => $x) if (isset($v[$k])) $c[$k] = (string)$v[$k]; if (!isset($v['resp_horas'])) $c['resp_horas'] = $c['responsavel']; } } catch (Exception $e) {}
    return $c;
}
function relCfgSalvar($pdo, $in) {
    $c = relCfg($pdo);
    foreach ($c as $k => $x) if (isset($in[$k])) $c[$k] = mb_substr(trim((string)$in[$k]), 0, 200);
    $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('relatorios_cfg', :v) ON CONFLICT (chave) DO UPDATE SET valor = EXCLUDED.valor")->execute(['v' => json_encode($c, JSON_UNESCAPED_UNICODE)]);
    return $c;
}

/* ---------------- competência (15 do mês anterior a 14) ---------------- */
function relCompetencia($fim) { // $fim = AAAA-MM-14
    $t = strtotime($fim); $ini = date('Y-m-15', strtotime(date('Y-m-01', $t) . ' -1 month'));
    return ['ini' => $ini, 'fim' => $fim, 'mes' => REL_MESES[(int)date('n', $t) - 1], 'ano' => date('Y', $t)];
}
function relCompetenciaFechadaEm($ts) { // última competência já encerrada no momento $ts (fim no dia 14 anterior)
    $d = (int)date('j', $ts);
    $fim = $d >= 15 ? date('Y-m-14', $ts) : date('Y-m-14', strtotime(date('Y-m-01', $ts) . ' -1 month'));
    return relCompetencia($fim);
}

/* ---------------- dados ---------------- */
function relHm($s) { return preg_match('/^(\d{1,2}):(\d{2})/', (string)$s, $m) ? (int)$m[1] * 60 + (int)$m[2] : null; }
function relDia($s) { return (int)floor(strtotime(substr((string)$s, 0, 10) . ' UTC') / 86400); }
function relIvHE($e) {
    $a = relDia($e['data']) * 1440 + ((int)$e['dia_inicio'] ? 1440 : 0) + relHm($e['hora_inicio']);
    $hi = relHm($e['hora_inicio']); $hf = relHm($e['hora_fim']);
    return [$a, $a + ($hf > $hi ? $hf - $hi : $hf + 1440 - $hi)];
}
function relIvFracao($f) {
    $a = relDia($f['data_inicio']) * 1440 + relHm($f['hora_inicio']);
    $b = relDia($f['data_fim']) * 1440 + (substr((string)$f['hora_fim'], 0, 5) === '23:59' ? 1440 : relHm($f['hora_fim']));
    return [$a, $b];
}
// Linhas dos relatórios. $filtro: ['nomes' => [...]] (o que está visível na tela) ou ['setores' => [...]] (envio por e-mail); vazio = todos
function relDados($pdo, $ini, $fim, $filtro = []) {
    $func = $pdo->query("SELECT id, nome, COALESCE(NULLIF(nome_completo, ''), nome) AS nc, COALESCE(setor, '') AS setor FROM funcionarios")->fetchAll(PDO::FETCH_ASSOC);
    $nomes = isset($filtro['nomes']) && is_array($filtro['nomes']) ? array_flip(array_map('strval', $filtro['nomes'])) : null;
    $setores = !empty($filtro['setores']) ? array_flip(array_map(function ($s) { return mb_strtoupper(trim($s), 'UTF-8'); }, $filtro['setores'])) : null;
    $func = array_values(array_filter($func, function ($f) use ($nomes, $setores) {
        if ($nomes !== null && !isset($nomes[$f['nome']])) return false;
        if ($setores !== null && !isset($setores[mb_strtoupper(trim($f['setor']), 'UTF-8')])) return false;
        return true;
    }));
    $a = date('Y-m-d', strtotime("$ini -1 day")); $b = date('Y-m-d', strtotime("$fim +1 day"));
    $st = $pdo->prepare("SELECT nome_colaborador AS nome, to_char(data_escala,'YYYY-MM-DD') AS d, status, horas_total, to_char(data_inicio,'YYYY-MM-DD') AS data_inicio,
                                hora_inicio, to_char(data_fim,'YYYY-MM-DD') AS data_fim, hora_fim
                         FROM escala_dados WHERE status LIKE 'plantao%' AND data_escala BETWEEN :a AND :b");
    $st->execute(['a' => $a, 'b' => $b]);
    $pl = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $pl[$r['nome']][$r['d']][] = $r;
    $man = heListar($pdo); $he = array_merge($man, heDoPonto($pdo, $man));
    $heCol = [];
    foreach ($he as $e) if ($e['data'] >= $ini && $e['data'] <= $fim && relHm($e['hora_inicio']) !== null && relHm($e['hora_fim']) !== null) $heCol[$e['nome']][] = $e;
    $out = [];
    foreach ($func as $f) {
        $n = $f['nome']; $dias = 0; $horas = 0.0;
        foreach ($pl[$n] ?? [] as $d => $rows) if ($d >= $ini && $d <= $fim) { $dias++; foreach ($rows as $r) $horas += (float)$r['horas_total']; }
        $desc = 0; $porDia = [];
        foreach ($heCol[$n] ?? [] as $e) {
            $iv = relIvHE($e);
            foreach ([-1, 0, 1] as $o) foreach ($pl[$n][date('Y-m-d', strtotime($e['data'] . " $o day"))] ?? [] as $fr) {
                $x = relIvFracao($fr); $desc += max(0, min($iv[1], $x[1]) - max($iv[0], $x[0]));
            }
            for ($cur = $iv[0]; $cur < $iv[1];) { $dn = intdiv($cur, 1440); $fimD = min($iv[1], ($dn + 1) * 1440); $porDia[$dn] = ($porDia[$dn] ?? 0) + ($fimD - $cur); $cur = $fimD; }
        }
        $horas = max(0, $horas - min($desc, round($horas * 60)) / 60);
        $tk = array_keys(array_filter($porDia, function ($m) { return $m > REL_TICKET_MIN; })); sort($tk);
        $out[] = ['matricula' => str_pad((string)$f['id'], 6, '0', STR_PAD_LEFT), 'nome' => $n, 'nc' => $f['nc'], 'dias' => $dias, 'horas' => $horas,
                  'tickets' => array_map(function ($dn) { return gmdate('d/m', $dn * 86400); }, $tk)];
    }
    usort($out, function ($x, $y) { return strcoll(mb_strtoupper($x['nc'], 'UTF-8'), mb_strtoupper($y['nc'], 'UTF-8')); });
    return $out;
}
function relFmtHoras($h) { $hi = (int)floor($h); $m = (int)round(($h - $hi) * 60); if ($m === 60) { $hi++; $m = 0; } return $hi . ' h' . ($m > 0 ? ' ' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) . 'm' : ''); }

/* ---------------- PDF (sem bibliotecas; A4, várias páginas, Helvetica) ---------------- */
class RelPdf {
    public $W = 595.28; public $H = 841.89; public $titulo = 'Relatório';
    private $pags = []; private $c = ''; private $img = null; public $aoNovaPagina = null; // RELATORIOS_V2
    private static $w = [32=>278,33=>278,34=>355,35=>556,36=>556,37=>889,38=>667,39=>191,40=>333,41=>333,42=>389,43=>584,44=>278,45=>333,46=>278,47=>278,
        48=>556,49=>556,50=>556,51=>556,52=>556,53=>556,54=>556,55=>556,56=>556,57=>556,58=>278,59=>278,60=>584,61=>584,62=>584,63=>556,64=>1015,
        65=>667,66=>667,67=>722,68=>722,69=>667,70=>611,71=>778,72=>722,73=>278,74=>500,75=>667,76=>556,77=>833,78=>722,79=>778,80=>667,81=>778,82=>722,
        83=>667,84=>611,85=>722,86=>667,87=>944,88=>667,89=>667,90=>611,91=>278,92=>278,93=>278,94=>469,95=>556,96=>333,97=>556,98=>556,99=>500,100=>556,
        101=>556,102=>278,103=>556,104=>556,105=>222,106=>222,107=>500,108=>222,109=>833,110=>556,111=>556,112=>556,113=>556,114=>333,115=>500,116=>278,
        117=>556,118=>500,119=>722,120=>500,121=>500,122=>500,123=>334,124=>260,125=>334,126=>584];
    static function cp($s) { $r = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', (string)$s); return $r === false ? '' : $r; }
    static function larg($s, $size, $bold = false) {
        $t = 0; foreach (str_split(self::cp($s)) as $ch) { $o = ord($ch); $t += self::$w[$o] ?? 556; }
        return $t / 1000 * $size * ($bold ? 1.07 : 1);
    }
    static function mm($v) { return $v * 72 / 25.4; }
    function novaPagina() { if ($this->c !== '' || $this->pags) $this->pags[] = $this->c; $this->c = ''; if ($this->aoNovaPagina) call_user_func($this->aoNovaPagina, $this); }
    function texto($x, $y, $s, $size, $bold = false, $align = 'l') { // x, y em mm (y = linha de base, a partir do topo)
        $w = self::larg($s, $size, $bold); $px = self::mm($x);
        if ($align === 'r') $px -= $w; elseif ($align === 'c') $px -= $w / 2;
        $e = str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], self::cp($s));
        $this->c .= sprintf("BT /%s %.2f Tf 0 g %.2f %.2f Td (%s) Tj ET\n", $bold ? 'F2' : 'F1', $size, $px, $this->H - self::mm($y), $e);
    }
    function quebrar($s, $size, $larg, $bold = false) { // quebra o texto em linhas que caibam em $larg mm
        $lin = []; $cur = '';
        foreach (preg_split('/\s+/', trim((string)$s)) as $p) {
            $t = $cur === '' ? $p : "$cur $p";
            if ($cur !== '' && self::larg($t, $size, $bold) > self::mm($larg)) { $lin[] = $cur; $cur = $p; } else $cur = $t;
        }
        if ($cur !== '') $lin[] = $cur;
        return $lin ?: [''];
    }
    function linha($x1, $y1, $x2, $y2, $esp = 0.5) { $this->c .= sprintf("0 G %.2f w %.2f %.2f m %.2f %.2f l S\n", $esp, self::mm($x1), $this->H - self::mm($y1), self::mm($x2), $this->H - self::mm($y2)); }
    function retang($x, $y, $w, $h, $esp = 0.5) { $this->c .= sprintf("0 G %.2f w %.2f %.2f %.2f %.2f re S\n", $esp, self::mm($x), $this->H - self::mm($y + $h), self::mm($w), self::mm($h)); }
    // Logo: JPEG direto; PNG pelo GD ou, sem GD, decodificado aqui (8 bits, sem entrelaçamento). Transparência vira fundo branco.
    function carregaLogo($bin) {
        $inf = @getimagesizefromstring($bin); if (!$inf) return null;
        if ($inf[2] === IMAGETYPE_JPEG && ($inf['channels'] ?? 3) !== 4) {
            $this->img = ['w' => $inf[0], 'h' => $inf[1], 'cs' => ($inf['channels'] ?? 3) == 1 ? '/DeviceGray' : '/DeviceRGB', 'f' => '/DCTDecode', 'd' => $bin];
            return [$inf[0], $inf[1]];
        }
        $rgb = null; $w = $inf[0]; $h = $inf[1];
        if (function_exists('imagecreatefromstring') && ($g = @imagecreatefromstring($bin))) {
            $fundo = imagecreatetruecolor($w, $h); imagefill($fundo, 0, 0, imagecolorallocate($fundo, 255, 255, 255)); imagealphablending($fundo, true); imagecopy($fundo, $g, 0, 0, 0, 0, $w, $h);
            $rgb = ''; for ($y = 0; $y < $h; $y++) for ($x = 0; $x < $w; $x++) { $c = imagecolorat($fundo, $x, $y); $rgb .= chr(($c >> 16) & 255) . chr(($c >> 8) & 255) . chr($c & 255); }
        } elseif ($inf[2] === IMAGETYPE_PNG) $rgb = self::pngRgb($bin, $w, $h);
        if ($rgb === null) return null;
        $this->img = ['w' => $w, 'h' => $h, 'cs' => '/DeviceRGB', 'f' => '/FlateDecode', 'd' => gzcompress($rgb, 6)];
        return [$w, $h];
    }
    private static function pngRgb($bin, &$w, &$h) {
        $p = 8; $idat = ''; $ct = null; $bd = 0; $il = 0; $pal = ''; $trns = '';
        while ($p + 8 <= strlen($bin)) {
            $len = unpack('N', substr($bin, $p, 4))[1]; $tipo = substr($bin, $p + 4, 4); $dado = substr($bin, $p + 8, $len); $p += 12 + $len;
            if ($tipo === 'IHDR') { $hd = unpack('Nw/Nh/Cbd/Cct/Ccm/Cfm/Cil', $dado); $w = $hd['w']; $h = $hd['h']; $bd = $hd['bd']; $ct = $hd['ct']; $il = $hd['il']; }
            elseif ($tipo === 'PLTE') $pal = $dado; elseif ($tipo === 'tRNS') $trns = $dado; elseif ($tipo === 'IDAT') $idat .= $dado; elseif ($tipo === 'IEND') break;
        }
        if ($bd !== 8 || $il || !in_array($ct, [0, 2, 3, 4, 6], true)) return null;
        $raw = @gzuncompress($idat); if ($raw === false) return null;
        $bpp = [0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4][$ct]; $lb = $w * $bpp; $prev = str_repeat("\0", $lb); $out = ''; $o = 0;
        for ($y = 0; $y < $h; $y++) {
            $f = ord($raw[$o]); $lin = substr($raw, $o + 1, $lb); $o += $lb + 1; $cur = '';
            for ($i = 0; $i < $lb; $i++) {
                $x = ord($lin[$i]); $a = $i >= $bpp ? ord($cur[$i - $bpp]) : 0; $b = ord($prev[$i]); $c = $i >= $bpp ? ord($prev[$i - $bpp]) : 0;
                if ($f === 1) $x += $a; elseif ($f === 2) $x += $b; elseif ($f === 3) $x += ($a + $b) >> 1;
                elseif ($f === 4) { $pp = $a + $b - $c; $pa = abs($pp - $a); $pb = abs($pp - $b); $pc = abs($pp - $c); $x += ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c); }
                $cur .= chr($x & 255);
            }
            $prev = $cur;
            for ($i = 0; $i < $w; $i++) {
                if ($ct === 6) { $r = ord($cur[$i*4]); $g = ord($cur[$i*4+1]); $bb = ord($cur[$i*4+2]); $al = ord($cur[$i*4+3]); }
                elseif ($ct === 2) { $r = ord($cur[$i*3]); $g = ord($cur[$i*3+1]); $bb = ord($cur[$i*3+2]); $al = 255; }
                elseif ($ct === 3) { $k = ord($cur[$i]); $r = ord($pal[$k*3] ?? "\0"); $g = ord($pal[$k*3+1] ?? "\0"); $bb = ord($pal[$k*3+2] ?? "\0"); $al = isset($trns[$k]) ? ord($trns[$k]) : 255; }
                elseif ($ct === 4) { $r = $g = $bb = ord($cur[$i*2]); $al = ord($cur[$i*2+1]); }
                else { $r = $g = $bb = ord($cur[$i]); $al = 255; }
                $out .= chr(intdiv($r * $al + 255 * (255 - $al), 255)) . chr(intdiv($g * $al + 255 * (255 - $al), 255)) . chr(intdiv($bb * $al + 255 * (255 - $al), 255));
            }
        }
        return $out;
    }
    function logo($x, $y, $wMax, $hMax, $dim) { // encaixa a logo em $wMax x $hMax mm, alinhada à direita da caixa
        if (!$this->img) return;
        $r = min($wMax / $dim[0], $hMax / $dim[1]); $w = $dim[0] * $r; $h = $dim[1] * $r;
        $this->c .= sprintf("q %.2f 0 0 %.2f %.2f %.2f cm /Im1 Do Q\n", self::mm($w), self::mm($h), self::mm($x + $wMax - $w), $this->H - self::mm($y + $h));
    }
    function saida() {
        $this->pags[] = $this->c; $this->c = '';
        $n = count($this->pags); $o = []; $kids = [];
        $o[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $o[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $o[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $o[5] = '<< /Title (' . str_replace(['(', ')', '\\'], '', self::cp($this->titulo)) . ') /Producer (SmartNOC Escala) >>';
        if ($this->img) { $i = $this->img; $o[6] = sprintf("<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace %s /BitsPerComponent 8 /Filter %s /Length %d >>\nstream\n", $i['w'], $i['h'], $i['cs'], $i['f'], strlen($i['d'])) . $i['d'] . "\nendstream"; }
        $k = 7;
        foreach ($this->pags as $pg) {
            $z = gzcompress($pg, 6);
            $o[$k + 1] = "<< /Length " . strlen($z) . " /Filter /FlateDecode >>\nstream\n" . $z . "\nendstream";
            $o[$k] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] /Contents %d 0 R /Resources << /Font << /F1 3 0 R /F2 4 0 R >>' . ($this->img ? ' /XObject << /Im1 6 0 R >>' : '') . ' >> >>', $this->W, $this->H, $k + 1);
            $kids[] = "$k 0 R"; $k += 2;
        }
        $o[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $n . ' >>';
        ksort($o);
        $pdf = "%PDF-1.4\n"; $off = [];
        foreach ($o as $num => $corpo) { $off[$num] = strlen($pdf); $pdf .= "$num 0 obj\n$corpo\nendobj\n"; }
        $xref = strlen($pdf); $max = max(array_keys($o));
        $pdf .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) $pdf .= isset($off[$i]) ? sprintf("%010d 00000 n \n", $off[$i]) : "0000000000 65535 f \n";
        return $pdf . "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R /Info 5 0 R >>\nstartxref\n$xref\n%%EOF\n";
    }
}
function relLogoBin($pdo) {
    try { $q = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'logo_pdf_base64'"); $q->execute(); $v = $q->fetchColumn();
          if (is_string($v) && preg_match('#^data:image/[a-z+.-]+;base64,(.+)$#s', trim($v), $m)) return base64_decode($m[1], true) ?: null; } catch (Exception $e) {}
    return null;
}
// Tabela com cabeçalho repetido nas páginas seguintes. $cols: [[largura mm, título, alinhamento]]; $linhas: [[células], negrito?]
function relTabela($pdf, $x, &$y, $cols, $linhas, $cab = true, $tam = 9) {
    $lh = $tam * 0.42;
    $desenha = function ($cel, $bold, $minH, $centro = false) use ($pdf, $x, &$y, $cols, $tam, $lh) {
        $ls = []; $alt = $minH;
        foreach ($cols as $i => $c) { $ls[$i] = []; foreach (explode("\n", (string)$cel[$i]) as $parte) $ls[$i] = array_merge($ls[$i], $pdf->quebrar($parte, $tam, $c[0] - 3, $bold)); $alt = max($alt, count($ls[$i]) * $lh + 3.6); }
        if ($y + $alt > 280) { $pdf->novaPagina(); $y = 31; return false; }
        $cx = $x;
        foreach ($cols as $i => $c) {
            $pdf->retang($cx, $y, $c[0], $alt, 0.3);
            $ty = $y + ($alt - count($ls[$i]) * $lh) / 2 + $lh - 0.6;
            $cc = $centro || $c[2] === 'c';
            foreach ($ls[$i] as $t) { $pdf->texto($cc ? $cx + $c[0] / 2 : $cx + 1.6, $ty, $t, $tam, $bold, $cc ? 'c' : 'l'); $ty += $lh; }
            $cx += $c[0];
        }
        $y += $alt; return true;
    };
    $titulos = array_map(function ($c) { return $c[1]; }, $cols);
    if ($cab) $desenha($titulos, true, 8, true);
    foreach ($linhas as $l) { if (!$desenha($l[0], $l[1], 6.5)) { if ($cab) $desenha($titulos, true, 8, true); $desenha($l[0], $l[1], 6.5); } }
}
function relRodapeAssinatura($pdf, &$y, $obsAltura) {
    if ($y + 95 > 287) { $pdf->novaPagina(); $y = 31; }
    $pdf->texto(14, $y, 'A ser preenchido pela Direção/Gerência', 9);
    $pdf->retang(14, $y + 1, 182, $obsAltura, 0.3);
    for ($k = 5; $k < $obsAltura; $k += 5) $pdf->linha(14, $y + 1 + $k, 196, $y + 1 + $k, 0.3);
    $pdf->texto(16, $y + 4.6, 'Observações:', 9);
    $pdf->texto(14, $y + 40, 'Atenciosamente,', 9);
    $pdf->texto(14, $y + 60, '________________________, ____ de ________ de ________', 9);
    $pdf->texto(105, $y + 75, '__________________________________', 9, false, 'c');
    $pdf->texto(105, $y + 80, 'Gestor Imediato', 9, false, 'c');
}
function relCabecalho($pdf, $pdo) {
    $bin = relLogoBin($pdo);
    if ($bin && ($d = $pdf->carregaLogo($bin))) { // a logo cadastrada em Configurações → Geral → Logo Relatório PDF, em todas as páginas
        $pdf->logo(155, 8, 40, 16, $d);
        $pdf->aoNovaPagina = function ($p) use ($d) { $p->logo(155, 8, 40, 16, $d); };
    }
}

function relPdfHoras($pdo, $comp, $filtro = []) {
    $cfg = relCfg($pdo);
    $linhas = [];
    foreach (relDados($pdo, $comp['ini'], $comp['fim'], $filtro) as $r) if ($r['dias'] > 0)
        $linhas[] = [[$r['matricula'], $r['nc'], (string)$r['dias'], relFmtHoras($r['horas'])], true];
    $pdf = new RelPdf(); $pdf->titulo = 'Relatório de Sobreaviso ' . $comp['mes'] . '/' . $comp['ano'];
    relCabecalho($pdf, $pdo);
    $pdf->texto(105, 15, 'SOLICITAÇÃO', 14, true, 'c');
    $pdf->retang(14, 25, 182, 20, 0.3); $pdf->linha(14, 31.6, 196, 31.6, 0.3); $pdf->linha(14, 38.3, 196, 38.3, 0.3);
    $pdf->texto(16, 30, 'CENTRO DE CUSTO: ' . $cfg['cc_horas'], 10, true);
    $pdf->texto(16, 37, 'RESPONSÁVEL: ' . $cfg['resp_horas'], 10, true);
    $pdf->texto(16, 43, 'COMPETÊNCIA: ' . $comp['mes'] . '/' . $comp['ano'], 10, true);
    $pdf->texto(20, 52, 'Solicitação de: SOBREAVISO', 10, true);
    $y = 55;
    relTabela($pdf, 14, $y, [[25, 'Matrícula', 'c'], [75, 'Nome Completo', 'l'], [40, "Dias\nSobreaviso", 'c'], [42, "TOTAL\nHoras\nSobreaviso", 'c']],
        $linhas ?: [[['', 'Nenhum sobreaviso na competência.', '', ''], false]]);
    $y += 10;
    relRodapeAssinatura($pdf, $y, 20);
    return ['bin' => $pdf->saida(), 'arquivo' => 'Relatorio_Sobreaviso_' . $comp['mes'] . '_' . $comp['ano'] . '.pdf', 'linhas' => count($linhas)];
}
function relPdfVr($pdo, $comp, $filtro = []) {
    $cfg = relCfg($pdo);
    $linhas = []; $total = 0;
    foreach (relDados($pdo, $comp['ini'], $comp['fim'], $filtro) as $r) if ($r['tickets']) {
        $linhas[] = [[$r['matricula'], mb_strtoupper($r['nc'], 'UTF-8'), implode(', ', $r['tickets']), (string)count($r['tickets'])], false];
        $total += count($r['tickets']);
    }
    if (!$linhas) $linhas[] = [['', 'Nenhum vale extra na competência.', '', ''], false];
    $pdf = new RelPdf(); $pdf->titulo = 'Formulário VR Extra ' . $comp['mes'] . '/' . $comp['ano'];
    relCabecalho($pdf, $pdo);
    $pdf->texto(105, 17, 'AUTORIZAÇÃO', 13, true, 'c');
    $pdf->texto(105, 26, 'VALE REFEIÇÃO – EXTRA', 13, true, 'c');
    $y = 34;
    $pdf->retang(14, $y, 182, 28, 0.3);
    $campos = [['EMPRESA:', $cfg['empresa']], ['CENTRO DE CUSTO:', $cfg['centro_custo']], ['RESPONSÁVEL:', $cfg['responsavel']], ['OBJETIVO:', $cfg['objetivo_vr'] ?? 'Horas Extras Realizadas']];
    foreach ($campos as $i => $c) {
        if ($i) $pdf->linha(14, $y + $i * 7, 196, $y + $i * 7, 0.3);
        $pdf->texto(16, $y + $i * 7 + 4.9, $c[0], 9.5, true);
        $pdf->texto(16 + RelPdf::larg($c[0] . ' ', 9.5, true) * 25.4 / 72, $y + $i * 7 + 4.9, $c[1], 9.5);
    }
    $y += 38;
    $pdf->texto(20, $y, 'Solicito a compra de', 9.5);
    $x2 = 20 + RelPdf::larg('Solicito a compra de ', 9.5) * 25.4 / 72;
    $pdf->texto($x2, $y, 'VALE REFEIÇÃO', 9.5, true);
    $pdf->texto($x2 + RelPdf::larg('VALE REFEIÇÃO ', 9.5, true) * 25.4 / 72, $y, 'para trabalho conforme programação abaixo:', 9.5);
    $y += 8; // VR_SEM_COMPETENCIA_V1
    relTabela($pdf, 20, $y, [[26, 'Matr.', 'c'], [76, 'Nome Completo', 'c'], [50, 'Dia', 'c'], [18, 'Qtde', 'c']], $linhas, true, 8);
    $y += 14;
    relRodapeAssinatura($pdf, $y, 20);
    return ['bin' => $pdf->saida(), 'arquivo' => 'Formulario_VR_Extra_' . $comp['mes'] . '_' . $comp['ano'] . '.pdf', 'linhas' => $total ? count($linhas) : 0, 'total' => $total];
}
function relGerar($pdo, $tipo, $comp, $filtro = []) { return $tipo === 'vr' ? relPdfVr($pdo, $comp, $filtro) : relPdfHoras($pdo, $comp, $filtro); }

/* ---------------- envio por e-mail ---------------- */
function relEmails($s) { return array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string)$s)), function ($e) { return filter_var($e, FILTER_VALIDATE_EMAIL); }))); }
function relSetores($s) { return array_values(array_filter(array_map('trim', explode(',', (string)$s)))); }
function relEnviar($pdo, $env, $comp) {
    $smtp = smtpConfigLer($pdo);
    if (!smtpConfigurado($smtp)) return 'SMTP não configurado (Configurações → E-mail).';
    $dest = relEmails($env['destinatarios']); if (!$dest) return 'Nenhum destinatário válido.';
    $set = relSetores($env['setores']);
    $arq = relGerar($pdo, $env['tipo'], $comp, $set ? ['setores' => $set] : []);
    $nomeTipo = REL_TIPOS[$env['tipo']] ?? $env['tipo'];
    $assunto = $nomeTipo . ' — ' . $comp['mes'] . '/' . $comp['ano'];
    $periodo = date('d/m/Y', strtotime($comp['ini'])) . ' a ' . date('d/m/Y', strtotime($comp['fim']));
    $html = '<p>Olá,</p><p>Segue em anexo o <b>' . htmlspecialchars($nomeTipo) . '</b> da competência <b>' . $comp['mes'] . '/' . $comp['ano'] . '</b> (' . $periodo . ')' .
            ($set ? ', setores: ' . htmlspecialchars(implode(', ', $set)) : '') . '.</p>' .
            ($env['tipo'] === 'vr' ? '<p>Total de vales: <b>' . (int)($arq['total'] ?? 0) . '</b> (' . (int)$arq['linhas'] . ' colaborador(es)).</p>' : '<p>Colaboradores com sobreaviso: <b>' . (int)$arq['linhas'] . '</b>.</p>') .
            '<p style="color:#64748b;font-size:12px">Envio automático da Escala (Plantão Técnico). Para alterar, acesse Configurações → Relatórios.</p>';
    $erros = [];
    foreach ($dest as $d) { $r = smtpEnviar($smtp, $d, $assunto, $html, strip_tags(str_replace('</p>', "\n", $html)), [['nome' => $arq['arquivo'], 'tipo' => 'application/pdf', 'dados' => $arq['bin']]]); if (!$r['ok']) $erros[] = "$d: " . $r['erro']; }
    return $erros ? implode(' | ', $erros) : null;
}
function relRegistrar($pdo, $id, $ref, $status) {
    $pdo->prepare("UPDATE relatorios_envios SET ultimo_ref = COALESCE(:r, ultimo_ref), ultimo_em = now(), ultimo_status = :s WHERE id = :id")->execute(['r' => $ref, 's' => mb_substr($status, 0, 900), 'id' => $id]);
}
// Envios agendados que já passaram do dia/hora deste mês e ainda não saíram. Chamado pelo cron (relatorios_cron.php).
function relEnviarAgendados($pdo, $agora = null) {
    $agora = $agora ?: time(); $n = 0; relGarantir($pdo);
    foreach ($pdo->query("SELECT * FROM relatorios_envios WHERE ativo ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $env) {
        $ref = date('Y-m', $agora);
        $quando = strtotime(sprintf('%s-%02d %s', $ref, min(28, max(1, (int)$env['dia'])), $env['hora']));
        if ($agora < $quando || $env['ultimo_ref'] === $ref) continue;
        $comp = relCompetenciaFechadaEm($quando);
        $pdo->prepare("UPDATE relatorios_envios SET ultimo_ref = :r WHERE id = :id")->execute(['r' => $ref, 'id' => $env['id']]); // marca antes: nunca envia duas vezes
        try { $e = relEnviar($pdo, $env, $comp); } catch (Throwable $t) { $e = $t->getMessage(); }
        relRegistrar($pdo, $env['id'], $ref, ($e ? 'ERRO: ' . $e : 'Enviado') . ' (' . $comp['mes'] . '/' . $comp['ano'] . ')');
        if (!$e) $n++;
    }
    return $n;
}
function relMarcarSeJaPassou($pdo, $id) { // ao criar/editar: se o horário deste mês já passou, o primeiro envio fica para o mês seguinte
    $st = $pdo->prepare("SELECT dia, hora FROM relatorios_envios WHERE id = :id"); $st->execute(['id' => $id]); $e = $st->fetch(PDO::FETCH_ASSOC); if (!$e) return;
    $ref = date('Y-m'); if (time() >= strtotime(sprintf('%s-%02d %s', $ref, (int)$e['dia'], $e['hora'])))
        $pdo->prepare("UPDATE relatorios_envios SET ultimo_ref = :r WHERE id = :id AND (ultimo_ref IS NULL OR ultimo_ref < :r2)")->execute(['r' => $ref, 'r2' => $ref, 'id' => $id]);
}

/* ---------------- API (ações rel_*, só gestor) ---------------- */
function relJson($x) { header('Content-Type: application/json; charset=utf-8'); echo json_encode($x, JSON_UNESCAPED_UNICODE); exit; }
function relRotear($pdo, $action, $usuario) {
    if (!heEhGestor()) relJson(['sucesso' => false, 'erro' => 'Acesso restrito a gestores.']);
    relGarantir($pdo);
    $in = json_decode(file_get_contents('php://input'), true); if (!is_array($in)) $in = [];
    $aud = function ($t) use ($pdo, $usuario) { if (function_exists('registrarAuditoria')) registrarAuditoria($pdo, $usuario, 'RELATÓRIOS', $t); };
    try {
        if ($action === 'rel_pdf') { // download: competência da tela (ini/fim) e colaboradores visíveis
            $ini = (string)($in['ini'] ?? ''); $fim = (string)($in['fim'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-15$/', $ini) || !preg_match('/^\d{4}-\d{2}-14$/', $fim)) relJson(['sucesso' => false, 'erro' => 'Competência inválida.']);
            $comp = relCompetencia($fim); $tipo = ($in['tipo'] ?? '') === 'vr' ? 'vr' : 'horas';
            $filtro = isset($in['nomes']) && is_array($in['nomes']) ? ['nomes' => $in['nomes']] : [];
            $word = ($in['formato'] ?? '') === 'docx'; // RELATORIOS_WORD_V1
            if ($word) { require_once __DIR__ . '/relatorios_docx.php'; $arq = $tipo === 'vr' ? relDocxVr($pdo, $comp, $filtro) : relDocxHoras($pdo, $comp, $filtro); }
            else $arq = relGerar($pdo, $tipo, $comp, $filtro);
            $aud('Baixou o ' . REL_TIPOS[$tipo] . ' de ' . $comp['mes'] . '/' . $comp['ano']);
            header_remove('Content-Type'); header('Content-Type: ' . ($word ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' : 'application/pdf')); header('Content-Disposition: attachment; filename="' . $arq['arquivo'] . '"');
            header('Content-Length: ' . strlen($arq['bin'])); echo $arq['bin']; exit;
        }
        if ($action === 'rel_listar') {
            $env = $pdo->query("SELECT id, tipo, ativo, destinatarios, dia, hora, setores, ultimo_ref, to_char((ultimo_em AT TIME ZONE current_setting('TimeZone')) AT TIME ZONE 'America/Sao_Paulo','DD/MM/YYYY HH24:MI') AS ultimo_em, ultimo_status FROM relatorios_envios ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
            $setores = $pdo->query("SELECT DISTINCT upper(trim(setor)) FROM funcionarios WHERE COALESCE(trim(setor), '') <> '' ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
            $c = relCompetenciaFechadaEm(time());
            relJson(['sucesso' => true, 'envios' => $env, 'cfg' => relCfg($pdo), 'setores' => $setores, 'smtp' => smtpConfigurado(smtpConfigLer($pdo)), 'ultima_comp' => $c['mes'] . '/' . $c['ano']]);
        }
        if ($action === 'rel_cfg') { $c = relCfgSalvar($pdo, $in); $aud('Atualizou os dados dos relatórios (empresa/centro de custo/responsável)'); relJson(['sucesso' => true, 'cfg' => $c]); }
        if ($action === 'rel_salvar') {
            $tipo = ($in['tipo'] ?? '') === 'vr' ? 'vr' : 'horas'; $dia = (int)($in['dia'] ?? 15); $hora = (string)($in['hora'] ?? '');
            if ($dia < 1 || $dia > 28) relJson(['sucesso' => false, 'erro' => 'Escolha um dia entre 1 e 28.']);
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) relJson(['sucesso' => false, 'erro' => 'Hora inválida.']);
            $dest = relEmails($in['destinatarios'] ?? ''); if (!$dest) relJson(['sucesso' => false, 'erro' => 'Informe ao menos um e-mail válido.']);
            $set = implode(', ', relSetores($in['setores'] ?? ''));
            $p = ['t' => $tipo, 'a' => !empty($in['ativo']) ? 'true' : 'false', 'd' => implode(', ', $dest), 'dia' => $dia, 'h' => $hora, 's' => $set];
            if (!empty($in['id'])) { $p['id'] = (int)$in['id']; $pdo->prepare("UPDATE relatorios_envios SET tipo = :t, ativo = :a, destinatarios = :d, dia = :dia, hora = :h, setores = :s WHERE id = :id")->execute($p); $id = (int)$in['id']; }
            else { $st = $pdo->prepare("INSERT INTO relatorios_envios (tipo, ativo, destinatarios, dia, hora, setores) VALUES (:t, :a, :d, :dia, :h, :s) RETURNING id"); $st->execute($p); $id = (int)$st->fetchColumn(); }
            relMarcarSeJaPassou($pdo, $id);
            $aud((empty($in['id']) ? 'Criou' : 'Alterou') . ' o envio automático #' . $id . ' (' . REL_TIPOS[$tipo] . ', dia ' . $dia . ' às ' . $hora . ', para ' . implode(', ', $dest) . ')');
            relJson(['sucesso' => true, 'id' => $id]);
        }
        if ($action === 'rel_ativo') {
            $pdo->prepare("UPDATE relatorios_envios SET ativo = :a WHERE id = :id")->execute(['a' => !empty($in['ativo']) ? 'true' : 'false', 'id' => (int)($in['id'] ?? 0)]);
            if (!empty($in['ativo'])) relMarcarSeJaPassou($pdo, (int)$in['id']);
            $aud((!empty($in['ativo']) ? 'Habilitou' : 'Desabilitou') . ' o envio automático #' . (int)$in['id']); relJson(['sucesso' => true]);
        }
        if ($action === 'rel_remover') { $pdo->prepare("DELETE FROM relatorios_envios WHERE id = :id")->execute(['id' => (int)($in['id'] ?? 0)]); $aud('Removeu o envio automático #' . (int)$in['id']); relJson(['sucesso' => true]); }
        if ($action === 'rel_enviar_agora') { // envia já a última competência fechada (não altera o agendamento)
            $st = $pdo->prepare("SELECT * FROM relatorios_envios WHERE id = :id"); $st->execute(['id' => (int)($in['id'] ?? 0)]); $env = $st->fetch(PDO::FETCH_ASSOC);
            if (!$env) relJson(['sucesso' => false, 'erro' => 'Envio não encontrado.']);
            $comp = relCompetenciaFechadaEm(time()); $e = relEnviar($pdo, $env, $comp);
            relRegistrar($pdo, $env['id'], null, ($e ? 'ERRO: ' . $e : 'Enviado agora') . ' (' . $comp['mes'] . '/' . $comp['ano'] . ')');
            $aud('Enviou agora o ' . REL_TIPOS[$env['tipo']] . ' de ' . $comp['mes'] . '/' . $comp['ano'] . ($e ? ' — erro: ' . $e : ''));
            relJson($e ? ['sucesso' => false, 'erro' => $e] : ['sucesso' => true, 'comp' => $comp['mes'] . '/' . $comp['ano']]);
        }
    } catch (Throwable $t) { relJson(['sucesso' => false, 'erro' => $t->getMessage()]); }
    relJson(['sucesso' => false, 'erro' => 'Ação inválida.']);
}
