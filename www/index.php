<?php
ob_start();
ini_set('session.gc_maxlifetime', '43200');
session_start();
require_once 'conexao.php';
require_once __DIR__ . '/sessao_inativ.php'; // SESSAO_INATIV_V1

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'login') {
    $inputUser = trim($_POST['usuario']); $inputPass = trim($_POST['senha']);
    if ($pdo) {
        try { $pdo->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS filtro_padrao TEXT"); } catch (Exception $e) {}
        
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE usuario = :usuario");
        $stmt->execute(['usuario' => $inputUser]);
        $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($userRow && $inputPass === $userRow['senha']) {
            $_SESSION['usuario'] = $userRow['usuario']; 
            $_SESSION['perfil'] = $userRow['perfil']; 
            $_SESSION['nome'] = $userRow['nome_completo']; 
            $_SESSION['setores_permitidos'] = $userRow['setores_permitidos'] ?? '';
            $_SESSION['setores_gerenciaveis'] = $userRow['setores_gerenciaveis'] ?? '';
            session_write_close(); header("Location: index.php"); exit;
        } else { $msgErro = "Credenciais inválidas."; }
    }
}
if (isset($_GET['logout'])) { session_destroy(); header("Location: index.php"); exit; }

$logado = isset($_SESSION['usuario']); 
$perfilLogado = $logado ? $_SESSION['perfil'] : ''; 
$nomeLogado = $logado ? $_SESSION['nome'] : '';
$setoresPermitidos = $_SESSION['setores_permitidos'] ?? '';
$setoresGerenciaveis = $_SESSION['setores_gerenciaveis'] ?? '';

$funcionarios = []; $dadosIniciaisBanco = []; $filtroPadrao = 'null'; $feriadosIniciais = []; $viewPadrao = 'week';

if ($logado && $pdo) {
    try {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS filtro_padrao TEXT");
        $stmtUser = $pdo->prepare("SELECT filtro_padrao FROM usuarios WHERE usuario = :u");
        $stmtUser->execute(['u' => $_SESSION['usuario']]);
        $res = $stmtUser->fetch(PDO::FETCH_ASSOC);
        if ($res && !empty($res['filtro_padrao'])) {
            $filtroPadrao = $res['filtro_padrao'];
        }
        try { // tela inicial salva pelo usuário (Semanal / Mensal / ...)
            $pdo->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS view_padrao VARCHAR(20)");
            $sv = $pdo->prepare("SELECT view_padrao FROM usuarios WHERE usuario = :u"); $sv->execute(['u' => $_SESSION['usuario']]);
            $vv = $sv->fetchColumn(); if (in_array($vv, ['week', 'month', 'dashboard', 'gestao'], true)) $viewPadrao = $vv;
        } catch (Exception $e) {}
        $pdo->exec("ALTER TABLE feriados ADD COLUMN IF NOT EXISTS localidade VARCHAR(100)");
        $pdo->exec("ALTER TABLE escala_dados ADD COLUMN IF NOT EXISTS data_inicio DATE");
        $pdo->exec("ALTER TABLE escala_dados ADD COLUMN IF NOT EXISTS hora_inicio VARCHAR(10)");
        $pdo->exec("ALTER TABLE escala_dados ADD COLUMN IF NOT EXISTS data_fim DATE");
        $pdo->exec("ALTER TABLE escala_dados ADD COLUMN IF NOT EXISTS hora_fim VARCHAR(10)");
    } catch(Exception $e) {}

    $stmt = $pdo->query("SELECT * FROM funcionarios ORDER BY local_base ASC, localidade ASC, setor ASC, nome ASC");
    $funcionarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmtEscala = $pdo->query("SELECT nome_colaborador, data_escala, status, horas_total, arquivo_anexo, data_inicio, hora_inicio, data_fim, hora_fim FROM escala_dados ORDER BY data_inicio ASC, hora_inicio ASC");
    while ($row = $stmtEscala->fetch(PDO::FETCH_ASSOC)) {
        if (!isset($dadosIniciaisBanco[$row['nome_colaborador']])) $dadosIniciaisBanco[$row['nome_colaborador']] = [];
        if (!isset($dadosIniciaisBanco[$row['nome_colaborador']][$row['data_escala']])) {
            $dadosIniciaisBanco[$row['nome_colaborador']][$row['data_escala']] = [
                'status' => $row['status'],
                'anexo' => $row['arquivo_anexo'],
                'fracoes' => []
            ];
        }
        $dadosIniciaisBanco[$row['nome_colaborador']][$row['data_escala']]['fracoes'][] = [
            'data_inicio' => $row['data_inicio'],
            'hora_inicio' => $row['hora_inicio'],
            'data_fim' => $row['data_fim'],
            'hora_fim' => $row['hora_fim'],
            'horas' => $row['horas_total']
        ];
    }

    require_once __DIR__ . '/ausencias_ponto.php'; escalaMesclarAusenciasPonto($pdo, $dadosIniciaisBanco); // abonos aprovados no Ponto

    $stmtFeriados = $pdo->query("SELECT data_feriado FROM feriados WHERE tipo = 'nacional'");
    while ($row = $stmtFeriados->fetch(PDO::FETCH_ASSOC)) {
        $feriadosIniciais[] = substr($row['data_feriado'], 5, 5);
    }
}

$jsonFuncionarios = json_encode($funcionarios ?: []); 
$jsonDadosBanco = json_encode($dadosIniciaisBanco ?: new stdClass());
$jsonFeriadosDB = json_encode($feriadosIniciais ?: []);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestão de Sobreaviso AVANTIA</title>
    <script src="https://cdn.tailwindcss.com">
    function showToast(msg, type = 'success') {
        const container = document.getElementById('toast-container');
        const toast = document.createElement('div');
        const bgColor = type === 'success' ? 'bg-emerald-500' : (type === 'error' ? 'bg-red-500' : 'bg-blue-500');
        toast.className = `${bgColor} text-white px-4 py-3 rounded shadow-lg transition-all duration-300 transform translate-x-full opacity-0 flex items-center gap-2 font-bold text-sm pointer-events-auto`;
        toast.innerHTML = `<i data-lucide="${type === 'success' ? 'check-circle' : 'alert-circle'}" class="w-5 h-5"></i> ${msg}`;
        container.appendChild(toast);
        if(typeof lucide !== 'undefined') lucide.createIcons();
        setTimeout(() => toast.classList.remove('translate-x-full', 'opacity-0'), 10);
        setTimeout(() => { toast.classList.add('translate-x-full', 'opacity-0'); setTimeout(() => toast.remove(), 300); }, 3500);
    }
    async function testarEvoConnection() {
        showToast('Testando conexão...', 'success');
        const res = await fetch('api_escala.php?action=test_evo'); const data = await res.json();
        if(data.sucesso) showToast('Conexão Evolution OK!', 'success'); else showToast('Falha com Evolution.', 'error');
    }
    async function testarMensagemWpp(grupoJid) {
        if(!confirm("Enviar mensagem de teste real para o grupo agora?")) return;
        const res = await fetch('api_escala.php?action=test_wpp', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({grupo_id: grupoJid}) });
        const data = await res.json();
        if(data.sucesso) showToast('Mensagem enviada com sucesso.', 'success'); else showToast('Falha ao enviar mensagem.', 'error');
    }
    
    const _origCarregarConfiguracoes2 = carregarConfiguracoes;
    carregarConfiguracoes = async function() {
        await _origCarregarConfiguracoes2();
        try { const res = await fetch('api_escala.php?action=get_config'); const result = await res.json(); if(result.dados) { configuracoes.evo_url = result.dados.evo_url; configuracoes.evo_key = result.dados.evo_key; configuracoes.evo_inst = result.dados.evo_inst; } } catch(e) {}
    }
    const _origAbrirConfigs2 = abrirConfiguracoes;
    abrirConfiguracoes = function() {
        _origAbrirConfigs2();
        if(document.getElementById('cfg-evo-url')) document.getElementById('cfg-evo-url').value = configuracoes.evo_url || '';
        if(document.getElementById('cfg-evo-key')) document.getElementById('cfg-evo-key').value = configuracoes.evo_key || '';
        if(document.getElementById('cfg-evo-inst')) document.getElementById('cfg-evo-inst').value = configuracoes.evo_inst || '';
    }
    const _origSalvarConfigs2 = salvarConfiguracoes;
    salvarConfiguracoes = async function() {
        const hu = document.getElementById('cfg-horas-uteis').value; const hf = document.getElementById('cfg-horas-fds').value; 
        const reqBody = {horas_uteis: hu, horas_fds: hf, escala_texto: configuracoes.escala_texto};
        if (configuracoes.logo_pdf_base64) reqBody.logo_pdf_base64 = configuracoes.logo_pdf_base64;
        if (document.getElementById('cfg-evo-url')) reqBody.evo_url = document.getElementById('cfg-evo-url').value;
        if (document.getElementById('cfg-evo-key')) reqBody.evo_key = document.getElementById('cfg-evo-key').value;
        if (document.getElementById('cfg-evo-inst')) reqBody.evo_inst = document.getElementById('cfg-evo-inst').value;
        await fetch('api_escala.php?action=set_config', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(reqBody) }); 
        configuracoes.horas_uteis = parseFloat(hu); configuracoes.horas_fds = parseFloat(hf); 
        if(reqBody.evo_url) configuracoes.evo_url = reqBody.evo_url; if(reqBody.evo_key) configuracoes.evo_key = reqBody.evo_key; if(reqBody.evo_inst) configuracoes.evo_inst = reqBody.evo_inst;
        showToast("Opções globais atualizadas!", "success"); renderAll(); 
    }
</script>
    <script src="https://unpkg.com/lucide@latest">
    function showToast(msg, type = 'success') {
        const container = document.getElementById('toast-container');
        const toast = document.createElement('div');
        const bgColor = type === 'success' ? 'bg-emerald-500' : (type === 'error' ? 'bg-red-500' : 'bg-blue-500');
        toast.className = `${bgColor} text-white px-4 py-3 rounded shadow-lg transition-all duration-300 transform translate-x-full opacity-0 flex items-center gap-2 font-bold text-sm pointer-events-auto`;
        toast.innerHTML = `<i data-lucide="${type === 'success' ? 'check-circle' : 'alert-circle'}" class="w-5 h-5"></i> ${msg}`;
        container.appendChild(toast);
        if(typeof lucide !== 'undefined') lucide.createIcons();
        setTimeout(() => toast.classList.remove('translate-x-full', 'opacity-0'), 10);
        setTimeout(() => { toast.classList.add('translate-x-full', 'opacity-0'); setTimeout(() => toast.remove(), 300); }, 3500);
    }
    async function testarEvoConnection() {
        showToast('Testando conexão...', 'success');
        const res = await fetch('api_escala.php?action=test_evo'); const data = await res.json();
        if(data.sucesso) showToast('Conexão Evolution OK!', 'success'); else showToast('Falha com Evolution.', 'error');
    }
    async function testarMensagemWpp(grupoJid) {
        if(!confirm("Enviar mensagem de teste real para o grupo agora?")) return;
        const res = await fetch('api_escala.php?action=test_wpp', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({grupo_id: grupoJid}) });
        const data = await res.json();
        if(data.sucesso) showToast('Mensagem enviada com sucesso.', 'success'); else showToast('Falha ao enviar mensagem.', 'error');
    }
    
    const _origCarregarConfiguracoes2 = carregarConfiguracoes;
    carregarConfiguracoes = async function() {
        await _origCarregarConfiguracoes2();
        try { const res = await fetch('api_escala.php?action=get_config'); const result = await res.json(); if(result.dados) { configuracoes.evo_url = result.dados.evo_url; configuracoes.evo_key = result.dados.evo_key; configuracoes.evo_inst = result.dados.evo_inst; } } catch(e) {}
    }
    const _origAbrirConfigs2 = abrirConfiguracoes;
    abrirConfiguracoes = function() {
        _origAbrirConfigs2();
        if(document.getElementById('cfg-evo-url')) document.getElementById('cfg-evo-url').value = configuracoes.evo_url || '';
        if(document.getElementById('cfg-evo-key')) document.getElementById('cfg-evo-key').value = configuracoes.evo_key || '';
        if(document.getElementById('cfg-evo-inst')) document.getElementById('cfg-evo-inst').value = configuracoes.evo_inst || '';
    }
    const _origSalvarConfigs2 = salvarConfiguracoes;
    salvarConfiguracoes = async function() {
        const hu = document.getElementById('cfg-horas-uteis').value; const hf = document.getElementById('cfg-horas-fds').value; 
        const reqBody = {horas_uteis: hu, horas_fds: hf, escala_texto: configuracoes.escala_texto};
        if (configuracoes.logo_pdf_base64) reqBody.logo_pdf_base64 = configuracoes.logo_pdf_base64;
        if (document.getElementById('cfg-evo-url')) reqBody.evo_url = document.getElementById('cfg-evo-url').value;
        if (document.getElementById('cfg-evo-key')) reqBody.evo_key = document.getElementById('cfg-evo-key').value;
        if (document.getElementById('cfg-evo-inst')) reqBody.evo_inst = document.getElementById('cfg-evo-inst').value;
        await fetch('api_escala.php?action=set_config', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(reqBody) }); 
        configuracoes.horas_uteis = parseFloat(hu); configuracoes.horas_fds = parseFloat(hf); 
        if(reqBody.evo_url) configuracoes.evo_url = reqBody.evo_url; if(reqBody.evo_key) configuracoes.evo_key = reqBody.evo_key; if(reqBody.evo_inst) configuracoes.evo_inst = reqBody.evo_inst;
        showToast("Opções globais atualizadas!", "success"); renderAll(); 
    }
</script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js">
    function showToast(msg, type = 'success') {
        const container = document.getElementById('toast-container');
        const toast = document.createElement('div');
        const bgColor = type === 'success' ? 'bg-emerald-500' : (type === 'error' ? 'bg-red-500' : 'bg-blue-500');
        toast.className = `${bgColor} text-white px-4 py-3 rounded shadow-lg transition-all duration-300 transform translate-x-full opacity-0 flex items-center gap-2 font-bold text-sm pointer-events-auto`;
        toast.innerHTML = `<i data-lucide="${type === 'success' ? 'check-circle' : 'alert-circle'}" class="w-5 h-5"></i> ${msg}`;
        container.appendChild(toast);
        if(typeof lucide !== 'undefined') lucide.createIcons();
        setTimeout(() => toast.classList.remove('translate-x-full', 'opacity-0'), 10);
        setTimeout(() => { toast.classList.add('translate-x-full', 'opacity-0'); setTimeout(() => toast.remove(), 300); }, 3500);
    }
    async function testarEvoConnection() {
        showToast('Testando conexão...', 'success');
        const res = await fetch('api_escala.php?action=test_evo'); const data = await res.json();
        if(data.sucesso) showToast('Conexão Evolution OK!', 'success'); else showToast('Falha com Evolution.', 'error');
    }
    async function testarMensagemWpp(grupoJid) {
        if(!confirm("Enviar mensagem de teste real para o grupo agora?")) return;
        const res = await fetch('api_escala.php?action=test_wpp', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({grupo_id: grupoJid}) });
        const data = await res.json();
        if(data.sucesso) showToast('Mensagem enviada com sucesso.', 'success'); else showToast('Falha ao enviar mensagem.', 'error');
    }
    
    const _origCarregarConfiguracoes2 = carregarConfiguracoes;
    carregarConfiguracoes = async function() {
        await _origCarregarConfiguracoes2();
        try { const res = await fetch('api_escala.php?action=get_config'); const result = await res.json(); if(result.dados) { configuracoes.evo_url = result.dados.evo_url; configuracoes.evo_key = result.dados.evo_key; configuracoes.evo_inst = result.dados.evo_inst; } } catch(e) {}
    }
    const _origAbrirConfigs2 = abrirConfiguracoes;
    abrirConfiguracoes = function() {
        _origAbrirConfigs2();
        if(document.getElementById('cfg-evo-url')) document.getElementById('cfg-evo-url').value = configuracoes.evo_url || '';
        if(document.getElementById('cfg-evo-key')) document.getElementById('cfg-evo-key').value = configuracoes.evo_key || '';
        if(document.getElementById('cfg-evo-inst')) document.getElementById('cfg-evo-inst').value = configuracoes.evo_inst || '';
    }
    const _origSalvarConfigs2 = salvarConfiguracoes;
    salvarConfiguracoes = async function() {
        const hu = document.getElementById('cfg-horas-uteis').value; const hf = document.getElementById('cfg-horas-fds').value; 
        const reqBody = {horas_uteis: hu, horas_fds: hf, escala_texto: configuracoes.escala_texto};
        if (configuracoes.logo_pdf_base64) reqBody.logo_pdf_base64 = configuracoes.logo_pdf_base64;
        if (document.getElementById('cfg-evo-url')) reqBody.evo_url = document.getElementById('cfg-evo-url').value;
        if (document.getElementById('cfg-evo-key')) reqBody.evo_key = document.getElementById('cfg-evo-key').value;
        if (document.getElementById('cfg-evo-inst')) reqBody.evo_inst = document.getElementById('cfg-evo-inst').value;
        await fetch('api_escala.php?action=set_config', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(reqBody) }); 
        configuracoes.horas_uteis = parseFloat(hu); configuracoes.horas_fds = parseFloat(hf); 
        if(reqBody.evo_url) configuracoes.evo_url = reqBody.evo_url; if(reqBody.evo_key) configuracoes.evo_key = reqBody.evo_key; if(reqBody.evo_inst) configuracoes.evo_inst = reqBody.evo_inst;
        showToast("Opções globais atualizadas!", "success"); renderAll(); 
    }
</script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js">
    function showToast(msg, type = 'success') {
        const container = document.getElementById('toast-container');
        const toast = document.createElement('div');
        const bgColor = type === 'success' ? 'bg-emerald-500' : (type === 'error' ? 'bg-red-500' : 'bg-blue-500');
        toast.className = `${bgColor} text-white px-4 py-3 rounded shadow-lg transition-all duration-300 transform translate-x-full opacity-0 flex items-center gap-2 font-bold text-sm pointer-events-auto`;
        toast.innerHTML = `<i data-lucide="${type === 'success' ? 'check-circle' : 'alert-circle'}" class="w-5 h-5"></i> ${msg}`;
        container.appendChild(toast);
        if(typeof lucide !== 'undefined') lucide.createIcons();
        setTimeout(() => toast.classList.remove('translate-x-full', 'opacity-0'), 10);
        setTimeout(() => { toast.classList.add('translate-x-full', 'opacity-0'); setTimeout(() => toast.remove(), 300); }, 3500);
    }
    async function testarEvoConnection() {
        showToast('Testando conexão...', 'success');
        const res = await fetch('api_escala.php?action=test_evo'); const data = await res.json();
        if(data.sucesso) showToast('Conexão Evolution OK!', 'success'); else showToast('Falha com Evolution.', 'error');
    }
    async function testarMensagemWpp(grupoJid) {
        if(!confirm("Enviar mensagem de teste real para o grupo agora?")) return;
        const res = await fetch('api_escala.php?action=test_wpp', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({grupo_id: grupoJid}) });
        const data = await res.json();
        if(data.sucesso) showToast('Mensagem enviada com sucesso.', 'success'); else showToast('Falha ao enviar mensagem.', 'error');
    }
    
    const _origCarregarConfiguracoes2 = carregarConfiguracoes;
    carregarConfiguracoes = async function() {
        await _origCarregarConfiguracoes2();
        try { const res = await fetch('api_escala.php?action=get_config'); const result = await res.json(); if(result.dados) { configuracoes.evo_url = result.dados.evo_url; configuracoes.evo_key = result.dados.evo_key; configuracoes.evo_inst = result.dados.evo_inst; } } catch(e) {}
    }
    const _origAbrirConfigs2 = abrirConfiguracoes;
    abrirConfiguracoes = function() {
        _origAbrirConfigs2();
        if(document.getElementById('cfg-evo-url')) document.getElementById('cfg-evo-url').value = configuracoes.evo_url || '';
        if(document.getElementById('cfg-evo-key')) document.getElementById('cfg-evo-key').value = configuracoes.evo_key || '';
        if(document.getElementById('cfg-evo-inst')) document.getElementById('cfg-evo-inst').value = configuracoes.evo_inst || '';
    }
    const _origSalvarConfigs2 = salvarConfiguracoes;
    salvarConfiguracoes = async function() {
        const hu = document.getElementById('cfg-horas-uteis').value; const hf = document.getElementById('cfg-horas-fds').value; 
        const reqBody = {horas_uteis: hu, horas_fds: hf, escala_texto: configuracoes.escala_texto};
        if (configuracoes.logo_pdf_base64) reqBody.logo_pdf_base64 = configuracoes.logo_pdf_base64;
        if (document.getElementById('cfg-evo-url')) reqBody.evo_url = document.getElementById('cfg-evo-url').value;
        if (document.getElementById('cfg-evo-key')) reqBody.evo_key = document.getElementById('cfg-evo-key').value;
        if (document.getElementById('cfg-evo-inst')) reqBody.evo_inst = document.getElementById('cfg-evo-inst').value;
        await fetch('api_escala.php?action=set_config', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(reqBody) }); 
        configuracoes.horas_uteis = parseFloat(hu); configuracoes.horas_fds = parseFloat(hf); 
        if(reqBody.evo_url) configuracoes.evo_url = reqBody.evo_url; if(reqBody.evo_key) configuracoes.evo_key = reqBody.evo_key; if(reqBody.evo_inst) configuracoes.evo_inst = reqBody.evo_inst;
        showToast("Opções globais atualizadas!", "success"); renderAll(); 
    }
</script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/exceljs/4.3.0/exceljs.min.js">
    function showToast(msg, type = 'success') {
        const container = document.getElementById('toast-container');
        const toast = document.createElement('div');
        const bgColor = type === 'success' ? 'bg-emerald-500' : (type === 'error' ? 'bg-red-500' : 'bg-blue-500');
        toast.className = `${bgColor} text-white px-4 py-3 rounded shadow-lg transition-all duration-300 transform translate-x-full opacity-0 flex items-center gap-2 font-bold text-sm pointer-events-auto`;
        toast.innerHTML = `<i data-lucide="${type === 'success' ? 'check-circle' : 'alert-circle'}" class="w-5 h-5"></i> ${msg}`;
        container.appendChild(toast);
        if(typeof lucide !== 'undefined') lucide.createIcons();
        setTimeout(() => toast.classList.remove('translate-x-full', 'opacity-0'), 10);
        setTimeout(() => { toast.classList.add('translate-x-full', 'opacity-0'); setTimeout(() => toast.remove(), 300); }, 3500);
    }
    async function testarEvoConnection() {
        showToast('Testando conexão...', 'success');
        const res = await fetch('api_escala.php?action=test_evo'); const data = await res.json();
        if(data.sucesso) showToast('Conexão Evolution OK!', 'success'); else showToast('Falha com Evolution.', 'error');
    }
    async function testarMensagemWpp(grupoJid) {
        if(!confirm("Enviar mensagem de teste real para o grupo agora?")) return;
        const res = await fetch('api_escala.php?action=test_wpp', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({grupo_id: grupoJid}) });
        const data = await res.json();
        if(data.sucesso) showToast('Mensagem enviada com sucesso.', 'success'); else showToast('Falha ao enviar mensagem.', 'error');
    }
    
    const _origCarregarConfiguracoes2 = carregarConfiguracoes;
    carregarConfiguracoes = async function() {
        await _origCarregarConfiguracoes2();
        try { const res = await fetch('api_escala.php?action=get_config'); const result = await res.json(); if(result.dados) { configuracoes.evo_url = result.dados.evo_url; configuracoes.evo_key = result.dados.evo_key; configuracoes.evo_inst = result.dados.evo_inst; } } catch(e) {}
    }
    const _origAbrirConfigs2 = abrirConfiguracoes;
    abrirConfiguracoes = function() {
        _origAbrirConfigs2();
        if(document.getElementById('cfg-evo-url')) document.getElementById('cfg-evo-url').value = configuracoes.evo_url || '';
        if(document.getElementById('cfg-evo-key')) document.getElementById('cfg-evo-key').value = configuracoes.evo_key || '';
        if(document.getElementById('cfg-evo-inst')) document.getElementById('cfg-evo-inst').value = configuracoes.evo_inst || '';
    }
    const _origSalvarConfigs2 = salvarConfiguracoes;
    salvarConfiguracoes = async function() {
        const hu = document.getElementById('cfg-horas-uteis').value; const hf = document.getElementById('cfg-horas-fds').value; 
        const reqBody = {horas_uteis: hu, horas_fds: hf, escala_texto: configuracoes.escala_texto};
        if (configuracoes.logo_pdf_base64) reqBody.logo_pdf_base64 = configuracoes.logo_pdf_base64;
        if (document.getElementById('cfg-evo-url')) reqBody.evo_url = document.getElementById('cfg-evo-url').value;
        if (document.getElementById('cfg-evo-key')) reqBody.evo_key = document.getElementById('cfg-evo-key').value;
        if (document.getElementById('cfg-evo-inst')) reqBody.evo_inst = document.getElementById('cfg-evo-inst').value;
        await fetch('api_escala.php?action=set_config', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(reqBody) }); 
        configuracoes.horas_uteis = parseFloat(hu); configuracoes.horas_fds = parseFloat(hf); 
        if(reqBody.evo_url) configuracoes.evo_url = reqBody.evo_url; if(reqBody.evo_key) configuracoes.evo_key = reqBody.evo_key; if(reqBody.evo_inst) configuracoes.evo_inst = reqBody.evo_inst;
        showToast("Opções globais atualizadas!", "success"); renderAll(); 
    }
</script>
    <style>
        :root { --tbl-scale: 1; }
        .text-scale-nome { font-size: calc(10px * var(--tbl-scale)); line-height: 1.1; }
        .text-scale-detalhe { font-size: calc(8px * var(--tbl-scale)); line-height: 1.2; }
        .text-scale-semanal-tit { font-size: calc(9.5px * var(--tbl-scale)); }
        .text-scale-semanal-sub { font-size: calc(7.5px * var(--tbl-scale)); }
        .text-scale-semanal-cell { font-size: calc(10px * var(--tbl-scale)); }
        @media (min-width: 640px) {
            .text-scale-nome { font-size: calc(11px * var(--tbl-scale)); }
            .text-scale-detalhe { font-size: calc(9px * var(--tbl-scale)); }
            .text-scale-semanal-tit { font-size: calc(12px * var(--tbl-scale)); }
            .text-scale-semanal-sub { font-size: calc(9px * var(--tbl-scale)); }
            .text-scale-semanal-cell { font-size: calc(13px * var(--tbl-scale)); }
        }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .hoje-col-header { background-color: #15803d !important; border-top: 2px solid #4ade80 !important; border-bottom: 2px solid #4ade80 !important; color: white !important; z-index: 30 !important; }
        .hoje-col-body { background-color: #f0fdf4 !important; border-left: 2px solid #4ade80 !important; border-right: 2px solid #4ade80 !important; border-bottom: 1px solid #bbf7d0 !important; }
        .custom-scrollbar::-webkit-scrollbar { width: 6px; height: 6px; } .custom-scrollbar::-webkit-scrollbar-track { background: #f1f5f9; } .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
    </style>
    <style>@keyframes fadeIn { from { opacity: 0; transform: translateY(-5px); } to { opacity: 1; transform: translateY(0); } } .animate-fade-in { animation: fadeIn 0.2s ease-out forwards; }</style>
</head>
<body class="bg-sky-50 text-slate-800 font-sans overflow-hidden flex flex-col h-[100dvh]">
    <div id="toast-container" class="fixed top-5 right-5 z-[9999] flex flex-col gap-2 pointer-events-none"></div>

    <?php if (!$logado): ?>
    <div class="fixed inset-0 bg-blue-900/90 z-[100] flex items-center justify-center p-4">
        <div class="bg-white p-6 rounded-xl shadow-2xl w-full max-w-sm"><h2 class="text-xl font-black text-center mb-4">LOGIN</h2>
            <form method="POST"><input type="hidden" name="acao" value="login"><input type="text" name="usuario" placeholder="Usuário" class="w-full border mb-3 p-2 rounded"><input type="password" name="senha" placeholder="Senha" class="w-full border mb-3 p-2 rounded"><button type="submit" class="w-full bg-blue-600 text-white font-bold py-2 rounded">Entrar</button></form>
            <?php if(!empty($msgErro)) echo "<div class='text-red-500 text-xs mt-2'>$msgErro</div>"; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- MODAL DE CONFIGURAÇÕES -->
    <div id="config-modal" class="hidden fixed inset-0 bg-blue-900/90 z-[300] flex items-center justify-center p-4">
        <div class="bg-white p-6 rounded-xl shadow-2xl w-full max-w-5xl max-h-[90vh] overflow-hidden flex flex-col">
            <div class="flex justify-between items-center mb-4 border-b pb-2 shrink-0">
                <h2 class="text-lg font-black text-blue-950 uppercase flex items-center gap-2"><i data-lucide="settings"></i> Painel de Administração</h2>
                <button onclick="fecharConfiguracoes()" class="text-slate-400 hover:text-red-500"><i data-lucide="x"></i></button>
            </div>
            
            <div class="flex-1 overflow-y-auto custom-scrollbar pr-2 space-y-6">
                <!-- HE_PATCH_V1: períodos de sobreaviso -->
                <div class="bg-amber-50 p-4 rounded border border-amber-200 shrink-0">
                    <h3 class="text-sm font-bold text-slate-700 mb-3 border-b border-amber-200 pb-1"><i data-lucide="alarm-clock" class="inline w-4 h-4 mr-1"></i> Períodos de Sobreaviso</h3>
                    <div id="periodos-resumo" class="grid grid-cols-1 md:grid-cols-3 gap-2 mb-3"></div>
                    <div class="grid grid-cols-2 md:grid-cols-6 gap-2 items-end">
                        <label class="text-[10px] font-bold text-slate-600 uppercase">Tipo de dia
                            <select id="per-tipo" class="mt-1 w-full border rounded px-2 py-1.5 text-xs font-bold bg-white"><option value="SEMANA">Semana (seg–sex)</option><option value="FIM_DE_SEMANA">Final de semana</option><option value="FERIADO">Feriado</option></select></label>
                        <label class="text-[10px] font-bold text-slate-600 uppercase">Início - dia
                            <select id="per-di" class="mt-1 w-full border rounded px-2 py-1.5 text-xs font-bold bg-white"><option value="0">Mesmo dia</option><option value="1">+1 dia</option><option value="2">+2 dias</option></select></label>
                        <label class="text-[10px] font-bold text-slate-600 uppercase">Início - hora
                            <input type="time" id="per-hi" value="22:00" class="mt-1 w-full border rounded px-2 py-1.5 text-xs font-bold bg-white"></label>
                        <label class="text-[10px] font-bold text-slate-600 uppercase">Fim - dia
                            <select id="per-df" class="mt-1 w-full border rounded px-2 py-1.5 text-xs font-bold bg-white"><option value="0">Mesmo dia</option><option value="1" selected>+1 dia</option><option value="2">+2 dias</option></select></label>
                        <label class="text-[10px] font-bold text-slate-600 uppercase">Fim - hora
                            <input type="time" id="per-hf" value="06:00" class="mt-1 w-full border rounded px-2 py-1.5 text-xs font-bold bg-white"></label>
                        <div class="flex gap-1"><button type="button" id="per-salvar" onclick="adicionarPeriodoSobreaviso()" class="flex-1 px-3 py-2 bg-amber-500 hover:bg-amber-600 text-white rounded text-xs font-bold shadow-sm">Adicionar período</button><button type="button" id="per-cancelar" onclick="cancelarEdicaoPeriodo()" class="hidden px-2 py-2 bg-white border border-amber-300 text-amber-700 hover:bg-amber-100 rounded text-xs font-bold">Cancelar</button></div>
                    </div>
                    <div class="mt-3 max-h-48 overflow-y-auto bg-white border rounded">
                        <table class="w-full text-xs"><thead class="bg-slate-50 text-[10px] uppercase text-slate-500"><tr><th class="text-left px-2 py-1">Tipo</th><th class="text-left px-2 py-1">Período</th><th class="text-right px-2 py-1">Duração</th><th></th></tr></thead><tbody id="periodos-tabela"></tbody></table>
                    </div>
                    <p id="periodos-dica" class="mt-2 text-[10px] text-slate-500 font-semibold"></p>
                    <div id="so-regras" class="mt-4 pt-3 border-t border-amber-200">
                        <h4 class="text-xs font-black text-slate-700 uppercase mb-2">Regras do sobreaviso</h4>
                        <label class="flex items-start gap-2 text-xs mb-2 cursor-pointer"><input type="checkbox" data-regra="limite_24h" class="mt-0.5 w-4 h-4 shrink-0"><span><b class="text-slate-800">Limite de 24h de sobreaviso seguidas (CLT)</b><br><span class="text-slate-500">Bloqueia o lançamento que deixe o colaborador mais de 24h seguidas de sobreaviso, como sexta + sábado, sábado + domingo ou feriado emendado no fim de semana. Em dias úteis não soma, porque o expediente normal interrompe o sobreaviso.</span></span></label>
                        <label class="flex items-start gap-2 text-xs mb-2 cursor-pointer"><input type="checkbox" data-regra="bloquear_ausencia" class="mt-0.5 w-4 h-4 shrink-0"><span><b class="text-slate-800">Bloquear sobreaviso em dia de ausência</b><br><span class="text-slate-500">Não deixa lançar sobreaviso em dia com férias, atestado, licença ou folga de aniversário.</span></span></label>
                        <label class="flex items-start gap-2 text-xs cursor-pointer"><input type="checkbox" data-regra="avisar_compartilhado" class="mt-0.5 w-4 h-4 shrink-0"><span><b class="text-slate-800">Avisar plantão compartilhado</b><br><span class="text-slate-500">Mostra um aviso quando outro colaborador do mesmo setor/cidade já está de sobreaviso no dia (o lançamento é permitido).</span></span></label>
                    </div>
                </div>
                <div class="bg-slate-50 p-4 rounded border flex flex-col lg:flex-row gap-6 shrink-0">
                    <div class="flex-1 border-r pr-4">
                        <h3 class="text-sm font-bold text-slate-700 mb-3 border-b pb-1"><i data-lucide="clock" class="inline w-4 h-4 mr-1"></i> Regras de Cálculo e Exibição</h3>
                        <div class="grid grid-cols-2 gap-4">
                            <div><label class="block text-[10px] font-bold text-slate-600 uppercase mb-1">Horas Pagas (Seg a Sex)</label><input type="number" step="0.5" id="cfg-horas-uteis" class="w-full border rounded px-3 py-2 text-sm font-bold text-blue-900"></div>
                            <div><label class="block text-[10px] font-bold text-slate-600 uppercase mb-1">Horas Pagas (FDS/Feriados)</label><input type="number" step="0.5" id="cfg-horas-fds" class="w-full border rounded px-3 py-2 text-sm font-bold text-blue-900"></div>
                            <div class="col-span-2 mt-2">
                                <label class="block text-[10px] font-bold text-slate-600 uppercase mb-1 flex justify-between">
                                    <span>Tamanho da Fonte (Colunas de Nomes)</span>
                                    <span id="label-scale-val" class="text-blue-600">1.0x</span>
                                </label>
                                <input type="range" id="cfg-escala-texto" min="0.7" max="1.5" step="0.05" value="1.0" class="w-full" oninput="aplicarEscalaTexto(this.value)">
                            </div>
                        </div>
                    </div>
                    <div class="flex-1 border-r pr-4">
                        <h3 class="text-sm font-bold text-slate-700 mb-3 border-b pb-1"><i data-lucide="message-square" class="inline w-4 h-4 mr-1 text-emerald-600"></i> Evolution API</h3>
                        <div class="space-y-2">
                            <div><label class="block text-[10px] font-bold text-slate-600 uppercase mb-1">URL (Base)</label><input type="text" id="cfg-evo-url" class="w-full border rounded px-3 py-1.5 text-xs"></div>
                            <div><label class="block text-[10px] font-bold text-slate-600 uppercase mb-1">Global API Key</label><input type="password" id="cfg-evo-key" class="w-full border rounded px-3 py-1.5 text-xs"></div>
                            <div><label class="block text-[10px] font-bold text-slate-600 uppercase mb-1">Instância</label><input type="text" id="cfg-evo-inst" class="w-full border rounded px-3 py-1.5 text-xs"></div>
                            <button onclick="testarEvoConnection()" class="w-full bg-slate-200 hover:bg-emerald-100 hover:text-emerald-700 text-slate-700 font-bold text-xs py-1.5 rounded transition-colors flex items-center justify-center gap-1"><i data-lucide="wifi" class="w-3.5 h-3.5"></i> Testar Conexão</button>
                        </div>
                    </div>
                    <div class="flex-1">
                        <h3 class="text-sm font-bold text-slate-700 mb-3 border-b pb-1"><i data-lucide="image" class="inline w-4 h-4 mr-1"></i> Logo Relatório PDF</h3>
                        <div class="space-y-2">
                            <label class="block text-[10px] font-bold text-slate-600 uppercase mb-1">Selecione uma imagem (PNG ou JPG)</label>
                            <input type="file" id="cfg-logo-file" accept="image/png, image/jpeg" class="w-full text-xs" onchange="previewLogoBase64(event)">
                            <div class="h-16 border rounded bg-white flex items-center justify-center overflow-hidden p-1">
                                <img id="cfg-logo-preview" class="h-full object-contain hidden" />
                                <span id="cfg-logo-placeholder" class="text-xs text-slate-400 italic">Nenhuma logo definida</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="flex justify-end shrink-0"><button onclick="salvarConfiguracoes()" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded font-bold text-sm shadow">Salvar Opções Globais</button></div>

                <div class="bg-slate-50 p-4 rounded border flex flex-col h-[380px] shrink-0">
                    <div class="flex justify-between items-center mb-3 border-b pb-1">
                        <h3 class="text-sm font-bold text-slate-700"><i data-lucide="calendar" class="inline w-4 h-4 mr-1"></i> Gerenciador de Feriados</h3>
                        <div class="flex gap-2">
                            <select id="filtro-feriado-tipo" class="border rounded px-2 py-1 text-xs" onchange="renderListaFeriados()">
                                <option value="">Todos os Tipos</option>
                                <option value="nacional">Nacional</option>
                                <option value="estadual">Estadual</option>
                                <option value="municipal">Municipal</option>
                            </select>
                            <input type="text" id="filtro-feriado-loc" placeholder="Filtrar Localidade..." class="border rounded px-2 py-1 text-xs w-32" oninput="renderListaFeriados()">
                            <button onclick="abrirFormFeriado()" class="bg-emerald-600 hover:bg-emerald-700 text-white px-2 py-1 rounded text-xs font-bold flex items-center gap-1"><i data-lucide="plus" class="w-3 h-3"></i> Novo Feriado</button>
                        </div>
                    </div>
                    <div class="flex-1 overflow-auto bg-white border rounded relative">
                        <table class="w-full text-xs text-left">
                            <thead class="bg-slate-100 text-slate-600 sticky top-0 z-10 shadow-sm"><tr><th class="p-2 border-b w-24">Data</th><th class="p-2 border-b">Nome do Feriado</th><th class="p-2 border-b w-24">Tipo</th><th class="p-2 border-b w-48">Localidade</th><th class="p-2 border-b text-center w-20">Ações</th></tr></thead>
                            <tbody id="lista-feriados-body" class="divide-y"></tbody>
                        </table>
                    </div>
                </div>

                <div class="bg-slate-50 p-4 rounded border flex flex-col h-[340px] shrink-0">
                    <div class="flex justify-between items-center mb-3 border-b pb-1">
                        <h3 class="text-sm font-bold text-slate-700"><i data-lucide="key" class="inline w-4 h-4 mr-1"></i> Contas de Acesso (Login)</h3>
                        <div class="flex gap-2">
                            <button onclick="document.getElementById('file-import-user').click()" class="bg-slate-300 hover:bg-slate-400 text-slate-700 px-2 py-1 rounded text-xs font-bold flex items-center gap-1"><i data-lucide="upload" class="w-3 h-3"></i> CSV</button>
                            <button onclick="exportarUsuariosCSV()" class="bg-slate-300 hover:bg-slate-400 text-slate-700 px-2 py-1 rounded text-xs font-bold flex items-center gap-1"><i data-lucide="download" class="w-3 h-3"></i> CSV</button>
                            <button onclick="abrirFormUsuario()" class="bg-emerald-600 hover:bg-emerald-700 text-white px-2 py-1 rounded text-xs font-bold flex items-center gap-1"><i data-lucide="plus" class="w-3 h-3"></i> Novo Usuário</button>
                            <input type="file" id="file-import-user" class="hidden" accept=".csv" onchange="importarUsuariosCSV(event)">
                        </div>
                    </div>
                    <div class="flex-1 overflow-auto bg-white border rounded">
                        <table class="w-full text-xs text-left">
                            <thead class="bg-slate-100 text-slate-600 sticky top-0"><tr><th class="p-2 border-b">Nome</th><th class="p-2 border-b">Login</th><th class="p-2 border-b">Perfil</th><th class="p-2 border-b">Pode Visualizar (Mensal)</th><th class="p-2 border-b">Pode Gerenciar</th><th class="p-2 border-b text-center">Ações</th></tr></thead>
                            <tbody id="lista-usuarios-body" class="divide-y"></tbody>
                        </table>
                    </div>
                </div>

                <div class="bg-slate-50 p-4 rounded border flex flex-col h-[380px] shrink-0">
                    <div class="flex justify-between items-center mb-3 border-b pb-1">
                        <h3 class="text-sm font-bold text-slate-700"><i data-lucide="users" class="inline w-4 h-4 mr-1"></i> Colaboradores da Escala</h3>
                        <div class="flex gap-2">
                            <button onclick="document.getElementById('file-import-func').click()" class="bg-slate-300 hover:bg-slate-400 text-slate-700 px-2 py-1 rounded text-xs font-bold flex items-center gap-1"><i data-lucide="upload" class="w-3 h-3"></i> CSV</button>
                            <button onclick="exportarFuncionariosCSV()" class="bg-slate-300 hover:bg-slate-400 text-slate-700 px-2 py-1 rounded text-xs font-bold flex items-center gap-1"><i data-lucide="download" class="w-3 h-3"></i> CSV</button>
                            <button onclick="abrirFormFuncionario()" class="bg-emerald-600 hover:bg-emerald-700 text-white px-2 py-1 rounded text-xs font-bold flex items-center gap-1"><i data-lucide="plus" class="w-3 h-3"></i> Novo Colaborador</button>
                            <input type="file" id="file-import-func" class="hidden" accept=".csv" onchange="importarFuncionariosCSV(event)">
                        </div>
                    </div>
                    <div class="flex-1 overflow-auto bg-white border rounded">
                        <table class="w-full text-xs text-left">
                            <thead class="bg-slate-100 text-slate-600 sticky top-0"><tr><th class="p-2 border-b">Nome Resumido</th><th class="p-2 border-b">Nome Completo</th><th class="p-2 border-b">Local/Setor</th><th class="p-2 border-b">Supervisor</th><th class="p-2 border-b text-center">Ações</th></tr></thead>
                            <tbody id="lista-funcionarios-body" class="divide-y"></tbody>
                        </table>
                    </div>
                </div>
                <!-- GESTÃO WHATSAPP -->
                <div class="bg-slate-50 p-4 rounded border flex flex-col h-[380px] shrink-0 mt-6">
                    <div class="flex justify-between items-center mb-3 border-b pb-1">
                        <h3 class="text-sm font-bold text-slate-700"><i data-lucide="message-circle" class="inline w-4 h-4 mr-1 text-emerald-600"></i> Gestão de WhatsApp</h3>
                        <button onclick="abrirFormWpp()" class="bg-emerald-600 hover:bg-emerald-700 text-white px-2 py-1 rounded text-xs font-bold flex items-center gap-1"><i data-lucide="plus" class="w-3 h-3"></i> Novo Grupo</button>
                    </div>
                    <div class="flex-1 overflow-auto bg-white border rounded">
                        <table class="w-full text-xs text-left">
                            <thead class="bg-slate-100 text-slate-600 sticky top-0 shadow-sm"><tr><th class="p-2 border-b">Nome do Grupo</th><th class="p-2 border-b">ID do Grupo / Link</th><th class="p-2 border-b text-center w-20">Ações</th></tr></thead>
                            <tbody id="lista-wpp-body" class="divide-y"></tbody>
                        </table>
                    </div>
                </div>

                <!-- REGRA AUTOMÁTICA DE GRUPOS POR STATUS -->
                <div id="regra-grupos-card" class="bg-slate-50 p-4 rounded border flex flex-col shrink-0 mt-6">
                    <div class="flex justify-between items-center mb-2 border-b pb-1 gap-2 flex-wrap">
                        <h3 class="text-sm font-bold text-slate-700"><i data-lucide="shield-check" class="inline w-4 h-4 mr-1 text-emerald-600"></i> Regra Automática de Grupos por Status</h3>
                        <button type="button" onclick="verificarGruposAgora()" class="bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1 rounded text-xs font-bold flex items-center gap-1"><i data-lucide="refresh-cw" class="w-3 h-3"></i> Verificar agora</button>
                    </div>
                    <ul class="text-[11px] text-slate-600 space-y-0.5 mb-3 list-disc pl-4">
                        <li><b>Férias:</b> remove o colaborador de todos os grupos no primeiro dia e inclui de volta quando o status mudar.</li>
                        <li><b>Licença, folga ou afastamento (atestado):</b> mesma regra quando o período contínuo passar do limite de dias úteis abaixo.</li>
                        <li>O servidor verifica todos os dias; o botão executa a verificação na hora.</li>
                    </ul>
                    <div class="flex flex-wrap items-end gap-3 mb-3 bg-white border rounded p-3">
                        <div>
                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Remover quando passar de (dias úteis)</label>
                            <input id="regra-limite" type="number" min="1" max="60" class="w-28 border rounded p-1.5 text-xs outline-none focus:border-blue-500">
                        </div>
                        <button type="button" onclick="salvarRegraGrupos()" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded text-xs font-bold">Salvar</button>
                        <span id="regra-ultima" class="text-[11px] text-slate-500"></span>
                    </div>
                    <div id="regra-log" class="bg-white border rounded">
                        <button type="button" onclick="alternarLogStatus()" class="w-full flex items-center justify-between gap-2 px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50">
                            <span class="flex items-center gap-2">
                                <span id="regra-log-seta" class="inline-block transition-transform duration-200"><i data-lucide="chevron-right" class="w-4 h-4"></i></span>
                                Histórico de mudanças de status
                                <span id="regra-log-total" class="text-slate-400 font-semibold"></span>
                            </span>
                            <span id="regra-log-dica" class="text-[10px] text-slate-400 font-semibold">clique para expandir</span>
                        </button>
                        <div id="regra-log-corpo" class="hidden border-t">
                            <div class="flex flex-wrap items-end gap-2 p-3 bg-slate-50 border-b">
                                <div class="flex-1 min-w-[170px]">
                                    <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Colaborador</label>
                                    <input id="regra-log-busca" type="text" placeholder="Pesquisar colaborador…" autocomplete="off" class="w-full border rounded p-1.5 text-xs bg-white outline-none focus:border-blue-500">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Data inicial</label>
                                    <input id="regra-log-de" type="date" class="border rounded p-1.5 text-xs bg-white outline-none focus:border-blue-500">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Data final</label>
                                    <input id="regra-log-ate" type="date" class="border rounded p-1.5 text-xs bg-white outline-none focus:border-blue-500">
                                </div>
                                <button type="button" onclick="limparFiltroLogStatus()" class="bg-slate-200 hover:bg-slate-300 text-slate-700 px-3 py-1.5 rounded text-xs font-bold">Limpar</button>
                                <button type="button" onclick="exportarLogStatusXlsx()" class="bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1.5 rounded text-xs font-bold flex items-center gap-1"><i data-lucide="download" class="w-3 h-3"></i> Exportar XLSX</button>
                            </div>
                            <div id="regra-log-lista"></div>
                            <div class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 border-t bg-slate-50">
                                <span id="regra-log-info" class="text-[11px] text-slate-500 font-semibold"></span>
                                <div id="regra-log-pag" class="flex items-center gap-1"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- FILA DE INTERVALO DO WHATSAPP -->
                <div id="fila-wpp-card" class="bg-slate-50 p-4 rounded border flex flex-col shrink-0 mt-6 mb-2">
                    <div class="flex justify-between items-center mb-3 border-b pb-1 gap-2 flex-wrap">
                        <h3 class="text-sm font-bold text-slate-700"><i data-lucide="timer" class="inline w-4 h-4 mr-1 text-amber-600"></i> Fila de Intervalo do WhatsApp</h3>
                        <div class="flex items-center gap-2">
                            <span id="fila-wpp-atualizado" class="text-[10px] text-slate-400"></span>
                            <button type="button" onclick="carregarFilaWpp()" class="bg-slate-200 hover:bg-slate-300 text-slate-700 px-2 py-1 rounded text-xs font-bold flex items-center gap-1"><i data-lucide="refresh-cw" class="w-3 h-3"></i> Atualizar</button>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 items-end mb-3 bg-white border rounded p-3">
                        <div>
                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Intervalo mínimo (seg)</label>
                            <input id="fila-cfg-min" type="number" min="1" max="600" class="w-full border rounded p-1.5 text-xs outline-none focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Intervalo máximo (seg)</label>
                            <input id="fila-cfg-max" type="number" min="1" max="600" class="w-full border rounded p-1.5 text-xs outline-none focus:border-blue-500">
                        </div>
                        <div class="col-span-2 md:col-span-1">
                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Ordem de envio</label>
                            <select id="fila-cfg-ordem" class="w-full border rounded p-1.5 text-xs bg-white outline-none focus:border-blue-500">
                                <option value="acao_primeiro">Ação primeiro, depois a mensagem</option>
                                <option value="mensagem_primeiro">Mensagem primeiro, depois a ação</option>
                            </select>
                        </div>
                        <div class="col-span-2 md:col-span-1">
                            <button type="button" onclick="salvarConfigFilaWpp()" class="w-full bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded text-xs font-bold">Salvar configuração</button>
                        </div>
                    </div>
                    <div id="fila-wpp-aviso" class="mb-2"></div>
                    <div id="fila-wpp-lista" class="bg-white border rounded max-h-[340px] overflow-auto"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL DE AUDITORIA -->
    <div id="audit-modal" class="hidden fixed inset-0 bg-blue-900/90 z-[300] flex items-center justify-center p-4">
        <div class="bg-white p-6 rounded-xl shadow-2xl w-full max-w-5xl max-h-[90vh] flex flex-col">
            <div class="flex justify-between items-center mb-4 border-b pb-2">
                <h2 class="text-lg font-black text-blue-950 uppercase flex items-center gap-2"><i data-lucide="shield-alert"></i> Trilha de Auditoria</h2>
                <button onclick="document.getElementById('audit-modal').classList.add('hidden')" class="text-slate-400 hover:text-red-500"><i data-lucide="x"></i></button>
            </div>
            
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-6 gap-2 mb-3 bg-slate-50 p-3 rounded border border-slate-200">
                <div>
                    <label class="block text-[9px] font-bold text-slate-600 uppercase mb-1">Mês Específico</label>
                    <input type="month" id="audit-filtro-mes" class="w-full border rounded px-2 py-1 text-xs bg-white" onchange="if(this.value) document.getElementById('audit-filtro-data').value=''">
                </div>
                <div>
                    <label class="block text-[9px] font-bold text-slate-600 uppercase mb-1">Data Específica</label>
                    <input type="date" id="audit-filtro-data" class="w-full border rounded px-2 py-1 text-xs bg-white" onchange="if(this.value) document.getElementById('audit-filtro-mes').value=''">
                </div>
                <div class="relative">
                    <label class="block text-[9px] font-bold text-slate-600 uppercase mb-1">Usuário Resp.</label>
                    <input type="text" id="audit-filtro-usuario" placeholder="Digite para buscar..." autocomplete="off" oninput="showAuditSuggestions('usuario', this.value)" onfocus="showAuditSuggestions('usuario', this.value)" class="w-full border rounded px-2 py-1 text-xs bg-white">
                    <div id="sugestoes-audit-usuario" class="absolute z-50 w-full bg-white border border-slate-200 shadow-xl mt-1 rounded max-h-32 overflow-y-auto hidden custom-scrollbar divide-y divide-slate-100"></div>
                </div>
                <div class="relative">
                    <label class="block text-[9px] font-bold text-slate-600 uppercase mb-1">Tipo de Ação</label>
                    <input type="text" id="audit-filtro-acao" placeholder="Digite para buscar..." autocomplete="off" oninput="showAuditSuggestions('acao', this.value)" onfocus="showAuditSuggestions('acao', this.value)" class="w-full border rounded px-2 py-1 text-xs bg-white">
                    <div id="sugestoes-audit-acao" class="absolute z-50 w-full bg-white border border-slate-200 shadow-xl mt-1 rounded max-h-32 overflow-y-auto hidden custom-scrollbar divide-y divide-slate-100"></div>
                </div>
                <div>
                    <label class="block text-[9px] font-bold text-slate-600 uppercase mb-1">Colaborador Afetado</label>
                    <select id="audit-filtro-termo" class="w-full border rounded px-2 py-1 text-xs bg-white focus:outline-none">
                        <option value="">Todos (Qualquer detalhe)</option>
                    </select>
                </div>
                <div class="flex items-end gap-1">
                    <button onclick="mudarPaginaAuditoria(1)" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-bold py-1 px-2 rounded text-xs shadow flex items-center justify-center gap-1"><i data-lucide="search" class="w-3 h-3"></i> Filtrar</button>
                    <button onclick="limparFiltrosAuditoria()" class="bg-slate-200 hover:bg-slate-300 text-slate-700 py-1 px-2 rounded text-xs font-bold" title="Limpar Filtros"><i data-lucide="rotate-ccw" class="w-3 h-3"></i></button>
                </div>
            </div>

            <div class="flex-1 overflow-y-auto custom-scrollbar border rounded">
                <table class="w-full text-xs text-left">
                    <thead class="bg-slate-100 text-slate-600 sticky top-0"><tr><th class="p-2 border-b">Data/Hora</th><th class="p-2 border-b">Usuário Responsável</th><th class="p-2 border-b">Ação / Tipo</th><th class="p-2 border-b">Detalhes da Alteração</th></tr></thead>
                    <tbody id="lista-auditoria-body" class="divide-y"><tr><td colspan="4" class="p-6 text-center text-slate-400 italic">Carregando registros...</td></tr></tbody>
                </table>
            </div>

            <div class="flex justify-between items-center mt-3 pt-2 border-t text-xs text-slate-600">
                <span id="audit-pagination-info">Página 1 de 1</span>
                <div class="flex gap-1">
                    <button onclick="mudarPaginaAuditoria(auditCurrentPage - 1)" id="btn-audit-prev" class="px-3 py-1 bg-slate-200 hover:bg-slate-300 rounded font-bold disabled:opacity-40" disabled>Anterior</button>
                    <button onclick="mudarPaginaAuditoria(auditCurrentPage + 1)" id="btn-audit-next" class="px-3 py-1 bg-slate-200 hover:bg-slate-300 rounded font-bold disabled:opacity-40" disabled>Próxima</button>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAIS DE USUÁRIO, FUNCIONÁRIO E FERIADO -->
    <div id="user-form-modal" class="hidden fixed inset-0 bg-black/60 z-[310] flex items-center justify-center p-4"><div class="bg-white p-5 rounded-xl shadow-xl w-full max-w-md"><h3 class="text-md font-bold mb-4" id="user-form-title">Novo Usuário</h3><form id="user-form" class="space-y-3"><input type="hidden" id="user-id"><div><label class="block text-[10px] font-bold uppercase mb-1">Nome Completo</label><input type="text" id="user-nome" required class="w-full border rounded px-2 py-1 text-sm"></div><div><label class="block text-[10px] font-bold uppercase mb-1">Login</label><input type="text" id="user-login" required class="w-full border rounded px-2 py-1 text-sm"></div><div><label class="block text-[10px] font-bold uppercase mb-1">Senha (vazio p/ manter)</label><input type="password" id="user-senha" class="w-full border rounded px-2 py-1 text-sm"></div><div><label class="block text-[10px] font-bold uppercase mb-1">Perfil</label><select id="user-perfil" class="w-full border rounded px-2 py-1 text-sm"><option value="usuario">Usuário Comum</option><option value="admin">Administrador</option><option value="mestre">Mestre (Super Admin)</option></select></div><div class="border-t pt-2 mt-2"><div class="relative"><label class="block text-[10px] font-bold text-blue-700 uppercase mb-1">Setores p/ VISUALIZAR</label><div class="flex flex-wrap gap-1 p-1 border border-blue-300 rounded bg-blue-50 min-h-[32px] items-center cursor-text" id="container-chips-view" onclick="document.getElementById('input-setor-view').focus()"><div id="chips-view" class="flex flex-wrap gap-1"></div><input type="text" id="input-setor-view" class="flex-1 min-w-[60px] bg-transparent outline-none text-xs px-1" placeholder="Buscar setor..." oninput="showSetorSuggestions('view')" onfocus="showSetorSuggestions('view')"></div><div id="sugestoes-setor-view" class="absolute z-50 w-full bg-white border border-slate-200 shadow-xl mt-1 rounded max-h-32 overflow-y-auto hidden custom-scrollbar"></div><input type="hidden" id="user-setores-view"></div><div class="relative mt-2"><label class="block text-[10px] font-bold text-emerald-700 uppercase mb-1">Setores p/ GERENCIAR</label><div class="flex flex-wrap gap-1 p-1 border border-emerald-300 rounded bg-emerald-50 min-h-[32px] items-center cursor-text" id="container-chips-edit" onclick="document.getElementById('input-setor-edit').focus()"><div id="chips-edit" class="flex flex-wrap gap-1"></div><input type="text" id="input-setor-edit" class="flex-1 min-w-[60px] bg-transparent outline-none text-xs px-1" placeholder="Buscar setor..." oninput="showSetorSuggestions('edit')" onfocus="showSetorSuggestions('edit')"></div><div id="sugestoes-setor-edit" class="absolute z-50 w-full bg-white border border-slate-200 shadow-xl mt-1 rounded max-h-32 overflow-y-auto hidden custom-scrollbar"></div><input type="hidden" id="user-setores-edit"></div></div><div class="flex gap-2 pt-3"><button type="button" onclick="document.getElementById('user-form-modal').classList.add('hidden')" class="flex-1 bg-slate-200 py-1.5 rounded font-bold">Cancelar</button><button type="button" onclick="salvarUsuario()" class="flex-1 bg-blue-600 text-white py-1.5 rounded font-bold">Salvar</button></div></form></div></div>
    
    <div id="func-form-modal" class="hidden fixed inset-0 bg-black/60 z-[310] flex items-center justify-center p-4"><div class="bg-white p-5 rounded-xl shadow-xl w-full max-w-md"><h3 class="text-md font-bold mb-4" id="func-form-title">Novo Colaborador</h3><form id="func-form" class="grid grid-cols-2 gap-3"><input type="hidden" id="func-id">
<div id="func-status-box" class="hidden col-span-2 rounded-lg border bg-slate-50 px-3 py-2 flex items-center justify-between gap-3">
    <div class="min-w-0">
        <div class="text-[9px] font-bold uppercase text-slate-500">Status atual (conforme a escala mensal)</div>
        <div id="func-status-detalhe" class="text-[10px] text-slate-500 truncate"></div>
    </div>
    <span id="func-status-badge" class="shrink-0 px-2 py-1 rounded font-black text-[11px] tracking-wide bg-slate-200 text-slate-700">—</span>
</div><div class="col-span-2"><label class="block text-[10px] font-bold uppercase mb-1">Nome Resumido (Tabela)*</label><input type="text" id="func-nome" required class="w-full border rounded px-2 py-1 text-sm bg-slate-50 uppercase"></div><div class="col-span-2"><label class="block text-[10px] font-bold uppercase mb-1">Nome Completo</label><input type="text" id="func-nomecomp" class="w-full border rounded px-2 py-1 text-sm"></div><div><label class="block text-[10px] font-bold uppercase mb-1">Base (Ex: CICOM)</label><input type="text" id="func-base" class="w-full border rounded px-2 py-1 text-sm uppercase"></div><div><label class="block text-[10px] font-bold uppercase mb-1">Cidade (Ex: SALVADOR)</label><input type="text" id="func-loc" class="w-full border rounded px-2 py-1 text-sm uppercase"></div><div><label class="block text-[10px] font-bold uppercase mb-1">Setor (Ex: ELE/REF)</label><input type="text" id="func-setor" class="w-full border rounded px-2 py-1 text-sm uppercase"></div><div><label class="block text-[10px] font-bold uppercase mb-1">Supervisor</label><input type="text" id="func-supervisor" class="w-full border rounded px-2 py-1 text-sm uppercase"></div><div><label class="block text-[10px] font-bold uppercase mb-1">Sigla</label><input type="text" id="func-sigla" class="w-full border rounded px-2 py-1 text-sm uppercase"></div><div><label class="block text-[10px] font-bold uppercase mb-1">Telefone / WhatsApp</label><input type="text" id="func-tel" class="w-full border rounded px-2 py-1 text-sm"></div><div><label class="block text-[10px] font-bold uppercase mb-1">Aniversário (DD/MM)</label><input type="text" id="func-niver" class="w-full border rounded px-2 py-1 text-sm" placeholder="DD/MM"></div><div><label class="block text-[10px] font-bold uppercase mb-1" title="O ponto considera apenas os dias a partir desta data">Data de admissão</label><input type="date" id="func-admissao" class="w-full border rounded px-2 py-1 text-sm"></div><div class="col-span-2"><label class="block text-[10px] font-bold uppercase mb-1" title="Usado nos arquivos fiscais do ponto (AFD/AEJ)">CPF</label><input type="text" id="func-cpf" inputmode="numeric" maxlength="14" class="w-full border rounded px-2 py-1 text-sm" placeholder="000.000.000-00"></div><div class="col-span-2 pt-3 border-t mt-2 relative w-full">
        <label class="block text-[10px] font-bold uppercase mb-1 text-emerald-700">
            <i data-lucide="message-circle" class="inline w-3 h-3 mr-1"></i> Participa dos Grupos de WhatsApp:
        </label>
        
        <div class="relative">
            <div class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none">
                <i data-lucide="search" class="w-4 h-4 text-slate-400"></i>
            </div>
            <input type="text" id="wpp-search-input" onkeyup="filtrarWppGrupos(this.value)" onfocus="mostrarWppDropdown()" onblur="esconderWppDropdown()" autocomplete="off" placeholder="Pesquisar e vincular grupo..." class="w-full border border-slate-300 rounded-md pl-8 pr-3 py-1.5 text-sm bg-slate-50 focus:bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 transition-all shadow-sm">
        </div>
        
        <!-- Dropdown de Opções -->
        <div id="wpp-dropdown" class="hidden absolute z-[400] w-full bg-white border border-slate-200 rounded-md shadow-xl max-h-40 overflow-y-auto custom-scrollbar mt-1">
        </div>

        <!-- Local onde ficam os grupos adicionados -->
        <div id="func-wpp-selected" class="flex flex-wrap gap-2 mt-3">
        </div>
    </div>
<div class="col-span-2 flex gap-2 pt-3"><button type="button" onclick="document.getElementById('func-form-modal').classList.add('hidden')" class="flex-1 bg-slate-200 py-1.5 rounded font-bold">Cancelar</button><button type="button" onclick="salvarFuncionario()" class="flex-1 bg-blue-600 text-white py-1.5 rounded font-bold">Salvar Colaborador</button></div></form></div></div>

    <div id="feriado-form-modal" class="hidden fixed inset-0 bg-black/60 z-[310] flex items-center justify-center p-4">
        <div class="bg-white p-5 rounded-xl shadow-xl w-full max-w-sm">
            <h3 class="text-md font-bold mb-4" id="feriado-form-title">Novo Feriado</h3>
            <form id="feriado-form" class="space-y-3">
                <input type="hidden" id="feriado-id">
                <div>
                    <label class="block text-[10px] font-bold uppercase mb-1">Nome do Feriado</label>
                    <input type="text" id="feriado-nome" required class="w-full border rounded px-2 py-1 text-sm">
                </div>
                <div class="flex gap-2">
                    <div class="flex-1">
                        <label class="block text-[10px] font-bold uppercase mb-1">Data</label>
                        <input type="date" id="feriado-data" required class="w-full border rounded px-2 py-1 text-sm">
                    </div>
                    <div class="flex-1">
                        <label class="block text-[10px] font-bold uppercase mb-1">Tipo</label>
                        <select id="feriado-tipo" required class="w-full border rounded px-2 py-1 text-sm" onchange="toggleFeriadoLoc()">
                            <option value="nacional">Nacional</option>
                            <option value="estadual">Estadual</option>
                            <option value="municipal">Municipal</option>
                        </select>
                    </div>
                </div>
                
                <div id="div-feriado-estado" class="hidden">
                    <label class="block text-[10px] font-bold uppercase mb-1">Estado (UF)</label>
                    <select id="feriado-estado" class="w-full border rounded px-2 py-1 text-sm uppercase">
                        <option value="">Selecione o estado...</option>
                        <option value="AC">Acre (AC)</option><option value="AL">Alagoas (AL)</option><option value="AP">Amapá (AP)</option><option value="AM">Amazonas (AM)</option><option value="BA">Bahia (BA)</option><option value="CE">Ceará (CE)</option><option value="DF">Distrito Federal (DF)</option><option value="ES">Espírito Santo (ES)</option><option value="GO">Goiás (GO)</option><option value="MA">Maranhão (MA)</option><option value="MT">Mato Grosso (MT)</option><option value="MS">Mato Grosso do Sul (MS)</option><option value="MG">Minas Gerais (MG)</option><option value="PA">Pará (PA)</option><option value="PB">Paraíba (PB)</option><option value="PR">Paraná (PR)</option><option value="PE">Pernambuco (PE)</option><option value="PI">Piauí (PI)</option><option value="RJ">Rio de Janeiro (RJ)</option><option value="RN">Rio Grande do Norte (RN)</option><option value="RS">Rio Grande do Sul (RS)</option><option value="RO">Rondônia (RO)</option><option value="RR">Roraima (RR)</option><option value="SC">Santa Catarina (SC)</option><option value="SP">São Paulo (SP)</option><option value="SE">Sergipe (SE)</option><option value="TO">Tocantins (TO)</option>
                    </select>
                </div>

                <div id="div-feriado-cidade" class="hidden">
                    <label class="block text-[10px] font-bold uppercase mb-1">Localidade (Cidade)</label>
                    <select id="feriado-cidade" class="w-full border rounded px-2 py-1 text-sm uppercase">
                        <option value="">Selecione uma localidade...</option>
                        <option value="ALAGOINHAS">ALAGOINHAS</option>
                        <option value="BARREIRAS">BARREIRAS</option>
                        <option value="EUCLIDES DA CUNHA">EUCLIDES DA CUNHA</option>
                        <option value="FEIRA DE SANTANA">FEIRA DE SANTANA</option>
                        <option value="GUANAMBI">GUANAMBI</option>
                        <option value="IRECÊ">IRECÊ</option>
                        <option value="ITABERABA">ITABERABA</option>
                        <option value="ITABUNA">ITABUNA</option>
                        <option value="JEQUIÉ">JEQUIÉ</option>
                        <option value="JUAZEIRO">JUAZEIRO</option>
                        <option value="PAULO AFONSO">PAULO AFONSO</option>
                        <option value="PORTO SEGURO">PORTO SEGURO</option>
                        <option value="SALVADOR">SALVADOR</option>
                        <option value="SANTA MARIA DA VITÓRIA">SANTA MARIA DA VITÓRIA</option>
                        <option value="SANTO ANTÔNIO DE JESUS">SANTO ANTÔNIO DE JESUS</option>
                        <option value="SENHOR DO BONFIM">SENHOR DO BONFIM</option>
                        <option value="SERRINHA">SERRINHA</option>
                        <option value="TEIXEIRA DE FREITAS">TEIXEIRA DE FREITAS</option>
                        <option value="VITÓRIA DA CONQUISTA">VITÓRIA DA CONQUISTA</option>
                    </select>
                </div>

                <div class="flex gap-2 pt-3">
                    <button type="button" onclick="document.getElementById('feriado-form-modal').classList.add('hidden')" class="flex-1 bg-slate-200 py-1.5 rounded font-bold">Cancelar</button>
                    <button type="button" onclick="salvarFeriado()" class="flex-1 bg-blue-600 text-white py-1.5 rounded font-bold">Salvar</button>
                </div>
            </form>
        </div>
    </div>

        <div id="wpp-form-modal" class="hidden fixed inset-0 bg-black/60 z-[310] flex items-center justify-center p-4">
        <div class="bg-white p-5 rounded-xl shadow-xl w-full max-w-sm">
            <h3 class="text-md font-bold mb-4 flex items-center gap-2 text-emerald-700"><i data-lucide="message-circle"></i> <span id="wpp-form-title">Novo Grupo WhatsApp</span></h3>
            <form id="wpp-form" class="space-y-3">
                <input type="hidden" id="wpp-id">
                <div><label class="block text-[10px] font-bold uppercase mb-1">Nome do Grupo</label><input type="text" id="wpp-nome" required class="w-full border rounded px-2 py-1 text-sm bg-slate-50"></div>
                <div><label class="block text-[10px] font-bold uppercase mb-1">ID do Grupo / Link Convite</label><input type="text" id="wpp-grupo-id" required class="w-full border rounded px-2 py-1 text-sm"></div>
                <div class="flex gap-2 pt-3">
                    <button type="button" onclick="document.getElementById('wpp-form-modal').classList.add('hidden')" class="flex-1 bg-slate-200 py-1.5 rounded font-bold">Cancelar</button>
                    <button type="button" onclick="salvarWpp()" class="flex-1 bg-emerald-600 hover:bg-emerald-700 text-white py-1.5 rounded font-bold">Salvar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL LOTE -->
    <div id="bulk-modal" class="hidden fixed inset-0 bg-blue-900/90 z-[300] flex items-center justify-center p-4">
        <div class="bg-white p-6 rounded-xl shadow-2xl w-full max-w-sm">
            <h2 class="text-lg font-black text-blue-950 uppercase mb-4 flex items-center gap-2"><i data-lucide="layers"></i> Lançamento em Lote</h2>
            <form id="form-bulk" class="space-y-4">
                <div><label class="block text-[10px] font-bold text-slate-600 uppercase mb-1">Colaborador</label><input type="text" id="bulk-nome" list="bulk-funcionarios-list" required class="w-full border rounded px-3 py-2 text-sm font-bold text-blue-900" placeholder="Digite para buscar..."><datalist id="bulk-funcionarios-list"></datalist></div>
                <div><label class="block text-[10px] font-bold text-slate-600 uppercase mb-1">Tipo de Lançamento</label><select id="bulk-status" class="w-full border rounded px-3 py-2 text-sm" onchange="toggleBulkAnexo()"><option value="plantao">Sobreaviso (Padrão)</option></select></div>
                <div class="flex gap-2"><div class="flex-1"><label class="block text-[10px] font-bold text-slate-600 uppercase mb-1">Início</label><input type="date" id="bulk-inicio" required class="w-full border rounded px-2 py-1.5 text-sm"></div><div class="flex-1"><label class="block text-[10px] font-bold text-slate-600 uppercase mb-1">Fim</label><input type="date" id="bulk-fim" required class="w-full border rounded px-2 py-1.5 text-sm"></div></div>
                <div id="bulk-anexo-container" class="hidden bg-slate-50 p-2 rounded border border-slate-200"><label class="block text-[10px] font-bold text-slate-600 uppercase mb-1">Anexar Documento</label><input type="file" id="bulk-anexo" class="w-full text-xs text-slate-600 file:mr-2 file:py-1 file:px-2 file:rounded file:border-0 file:text-xs file:font-semibold file:bg-blue-50 file:text-blue-700"></div>
                <div class="flex gap-2 mt-4 pt-2 border-t"><button type="button" onclick="fecharBulkModal()" class="flex-1 bg-slate-200 hover:bg-slate-300 py-2 rounded font-bold text-slate-700">Fechar</button><button type="button" onclick="executarBulkAction('limpar')" class="flex-1 bg-red-600 hover:bg-red-700 text-white py-2 rounded font-bold shadow-md">Remover</button><button type="button" onclick="executarBulkAction('incluir')" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white py-2 rounded font-bold shadow-md">Incluir</button></div>
            </form>
        </div>
    </div>

    <!-- MODAL DE TROCA DE PLANTÃO INTELIGENTE -->
    <div id="troca-modal" class="hidden fixed inset-0 bg-blue-900/90 z-[300] flex items-center justify-center p-4">
        <div class="bg-white p-5 rounded-xl shadow-2xl w-full max-w-sm">
            <h3 class="text-md font-bold mb-3 flex items-center gap-2 text-indigo-700"><i data-lucide="refresh-cw"></i> Trocar Bloco de Plantão</h3>
            
            <div class="bg-slate-50 border border-slate-200 rounded p-3 mb-4">
                <p class="text-[10px] font-bold text-slate-500 uppercase">Bloco Selecionado (Atual):</p>
                <p class="text-sm font-black text-slate-800" id="troca-desc-atual"></p>
            </div>
            
            <label class="block text-[10px] font-bold text-slate-600 uppercase mb-1">Selecione o parceiro para a troca (futuro até 30 dias):</label>
            <select id="troca-novo-bloco" class="w-full border border-indigo-300 bg-indigo-50 rounded px-3 py-2 text-sm font-bold text-indigo-900 mb-4 focus:outline-none focus:ring-2 focus:ring-indigo-500"></select>
            
            <div class="flex gap-2">
                <button onclick="document.getElementById('troca-modal').classList.add('hidden')" class="flex-1 bg-slate-200 hover:bg-slate-300 py-2 rounded font-bold text-slate-700">Cancelar</button>
                <button onclick="confirmarTrocaBloco()" class="flex-1 bg-indigo-600 hover:bg-indigo-700 text-white py-2 rounded font-bold shadow">Efetuar Troca</button>
            </div>
        </div>
    </div>

    <!-- MENU DE CONTEXTO (MENSAL) -->
    <div id="cell-context-menu" class="hidden fixed bg-white shadow-2xl border border-slate-200 rounded-lg py-1 z-[200] text-[11px] font-bold w-44 overflow-hidden">
        <div class="px-2 py-1 text-[8px] text-slate-400 font-bold uppercase bg-slate-50 border-b">Opções de Plantão</div>
        <div onclick="setCellStatus('plantao')" class="px-3 py-1.5 hover:bg-slate-100 cursor-pointer flex items-center gap-2"><div class="w-2.5 h-2.5 rounded bg-green-500 shadow-sm"></div>Sobreaviso</div>
        <div id="btn-view-anexo" onclick="visualizarAnexo()" class="hidden px-3 py-2 hover:bg-slate-100 cursor-pointer flex items-center gap-2 text-blue-600 font-bold border-t border-slate-200 bg-blue-50/50"><i data-lucide="file-text" class="w-3 h-3"></i> Ver Atestado</div>
        <div id="btn-trocar-plantao" onclick="prepararTrocaBloco()" class="hidden px-3 py-2 hover:bg-slate-100 cursor-pointer flex items-center gap-2 text-indigo-700 font-bold border-t border-slate-200 bg-indigo-50/50"><i data-lucide="refresh-cw" class="w-3 h-3"></i> Trocar Plantão</div>
        <div id="btn-preencher-linha" onclick="preencherLinhaSobreaviso()" class="hidden px-3 py-2 hover:bg-slate-100 cursor-pointer flex items-center gap-2 text-emerald-700 font-bold border-t border-slate-200 bg-emerald-50/50"><i data-lucide="repeat" class="w-3 h-3"></i> Preencher Sequência</div>
        <div onclick="setCellStatus(null)" class="px-3 py-2 hover:bg-slate-100 cursor-pointer flex items-center gap-2 text-slate-500 border-t border-slate-200"><i data-lucide="eraser" class="w-3 h-3"></i> Livre (Limpar)</div>
    </div>

    <!-- CABEÇALHO PRINCIPAL -->
    <header id="main-header" class="bg-blue-950 text-white shadow-sm shrink-0 relative z-50">
        <div class="w-full px-3 py-2 flex justify-between items-center gap-2">
            <div class="flex items-center gap-2"><div class="p-1 bg-blue-600 rounded"><i data-lucide="calendar-range" class="w-4 h-4"></i></div><h1 class="text-xs font-bold uppercase tracking-wide">Plantão Técnico</h1></div>
            <button onclick="toggleMobileMenu()" class="md:hidden p-1.5 text-blue-200 hover:text-white hover:bg-blue-900 rounded focus:outline-none"><i data-lucide="menu" class="w-5 h-5"></i></button>
            <div class="hidden md:flex items-center justify-end gap-2">
                
                <?php if ($perfilLogado === 'admin' || $perfilLogado === 'mestre'): ?>
                <div class="flex items-center gap-1.5">
                    <button onclick="confirmarExclusaoMesGlobal()" class="p-1.5 bg-red-700 hover:bg-red-600 text-white rounded transition-colors shadow-sm" title="Limpar Mês Atual (Gera Backup)"><i data-lucide="trash-2" class="w-4 h-4"></i></button>
                    <button onclick="abrirModalAcaoEmMassa()" class="p-1.5 bg-sky-600 hover:bg-sky-500 text-white rounded transition-colors shadow-sm" title="Lançamento em Lote"><i data-lucide="layers" class="w-4 h-4"></i></button>
                    <button onclick="document.getElementById('file-import-escala').click()" class="p-1.5 bg-blue-600 hover:bg-blue-500 text-white rounded transition-colors shadow-sm" title="Importar Escala CSV"><i data-lucide="upload" class="w-4 h-4"></i></button>
                    <input type="file" id="file-import-escala" class="hidden" accept=".csv, .xlsx" onchange="importarCSV(event)">
                    <button onclick="exportarCSV()" class="p-1.5 bg-blue-800 hover:bg-blue-700 text-white rounded transition-colors shadow-sm" title="Exportar Escala Mês (CSV)"><i data-lucide="download" class="w-4 h-4"></i></button>
                    <button onclick="menuRelatorio(event, 'horas')" class="p-1.5 bg-emerald-600 hover:bg-emerald-500 text-white rounded transition-colors shadow-sm" title="Relatório de Horas (PDF ou Word)"><i data-lucide="file-bar-chart" class="w-4 h-4"></i></button>
                    <button onclick="menuRelatorio(event, 'vr')" class="p-1.5 bg-violet-600 hover:bg-violet-500 text-white rounded transition-colors shadow-sm" title="Formulário VR Extra (PDF ou Word)"><i data-lucide="ticket" class="w-4 h-4"></i></button>
                    <button id="btn-gestao" onclick="switchView('gestao')" class="p-1.5 bg-amber-500 hover:bg-amber-400 text-white rounded transition-colors shadow-sm" title="Dashboard Gerencial (sobreaviso, hora extra e tickets)"><i data-lucide="layout-dashboard" class="w-4 h-4"></i></button>
                    <button onclick="abrirAuditoria()" class="p-1.5 bg-yellow-600 hover:bg-yellow-500 text-white rounded transition-colors shadow-sm" title="Trilha de Auditoria"><i data-lucide="shield-alert" class="w-4 h-4"></i></button>
                </div>
                <div class="w-px h-5 bg-blue-800 mx-1"></div>
                <?php endif; ?>
                
                <div class="flex items-center bg-blue-900 p-0.5 rounded border border-blue-800">
                    <button id="btn-view-week" onclick="switchView('week')" class="flex items-center gap-1 px-3 py-1 text-[10px] font-bold rounded bg-blue-600 text-white"><i data-lucide="layout-grid" class="w-3 h-3"></i> Semanal</button>
                    <button id="btn-view-month" onclick="switchView('month')" class="flex items-center gap-1 px-3 py-1 text-[10px] font-bold rounded text-blue-300 hover:text-white"><i data-lucide="calendar-days" class="w-3 h-3"></i> Mensal</button>
                    <button type="button" id="btn-save-view" onclick="salvarViewPadrao(this)" title="Salvar esta tela (Semanal/Mensal) como tela inicial" class="flex items-center px-2 py-1 ml-0.5 text-[10px] font-bold rounded text-blue-300 hover:text-white border-l border-blue-800"><i data-lucide="save" class="w-3.5 h-3.5"></i></button>
                </div>
                
                <div class="w-px h-5 bg-blue-800 mx-1"></div>
                
                <div class="flex items-center gap-1.5">
                    <?php if ($perfilLogado === 'mestre'): ?><button onclick="abrirConfiguracoes()" class="p-1.5 bg-slate-700 hover:bg-slate-600 text-white rounded transition-colors shadow-sm" title="Configurações e Administração"><i data-lucide="settings" class="w-4 h-4"></i></button><?php endif; ?>
                    <div class="px-2 py-1 bg-blue-900 border border-blue-700 rounded text-[9px] font-mono flex items-center gap-1 text-blue-300"><i data-lucide="user" class="w-3 h-3"></i> <span><?php echo htmlspecialchars($nomeLogado ?: $perfilLogado); ?></span></div>
                    <a href="?logout=1" class="p-1 ml-1 hover:bg-blue-800 rounded text-blue-300 hover:text-red-400 transition-colors" title="Sair do Sistema"><i data-lucide="power" class="w-4 h-4"></i></a>
                </div>
                
            </div>
        </div>

        <div id="mobile-menu-dropdown" class="hidden md:hidden bg-blue-900 border-t border-blue-800 px-4 py-3 space-y-3">
            <div class="flex items-center justify-between pb-2 border-b border-blue-800">
                <div class="text-xs font-bold text-blue-200 flex items-center gap-1.5"><i data-lucide="user" class="w-3.5 h-3.5"></i> <?php echo htmlspecialchars($nomeLogado ?: $perfilLogado); ?></div>
                <a href="?logout=1" class="text-xs text-red-300 hover:text-red-100 flex items-center gap-1 font-bold"><i data-lucide="power" class="w-3.5 h-3.5"></i> Sair</a>
            </div>
            <div class="flex items-center justify-center bg-blue-950 p-1 rounded border border-blue-800 gap-1 flex-wrap">
                <button id="btn-view-week-mobile" onclick="switchView('week'); toggleMobileMenu();" class="flex-1 flex items-center justify-center gap-1 py-1.5 text-xs font-bold rounded bg-blue-600 text-white"><i data-lucide="layout-grid" class="w-3.5 h-3.5"></i> Semanal</button>
                <button id="btn-view-month-mobile" onclick="switchView('month'); toggleMobileMenu();" class="flex-1 flex items-center justify-center gap-1 py-1.5 text-xs font-bold rounded text-blue-300 hover:text-white"><i data-lucide="calendar-days" class="w-3.5 h-3.5"></i> Mensal</button>
                <button type="button" onclick="salvarViewPadrao(this)" title="Salvar esta tela como tela inicial" class="flex items-center justify-center px-3 py-1.5 text-xs font-bold rounded text-blue-300 hover:text-white"><i data-lucide="save" class="w-3.5 h-3.5"></i></button>
            </div>
            <?php if ($perfilLogado === 'admin' || $perfilLogado === 'mestre'): ?>
            <div class="grid grid-cols-2 gap-2 pt-1 border-t border-blue-800">
                <button onclick="confirmarExclusaoMesGlobal(); toggleMobileMenu();" class="col-span-2 flex items-center justify-center gap-2 p-2 bg-red-700 hover:bg-red-600 text-white rounded text-xs font-bold"><i data-lucide="trash-2" class="w-4 h-4"></i> Limpar Mês</button>
                <button onclick="abrirModalAcaoEmMassa(); toggleMobileMenu();" class="flex items-center gap-2 p-2 bg-sky-700 hover:bg-sky-600 text-white rounded text-xs font-bold"><i data-lucide="layers" class="w-4 h-4"></i> Lançamento Lote</button>
                <button onclick="document.getElementById('file-import-escala').click(); toggleMobileMenu();" class="flex items-center gap-2 p-2 bg-blue-600 hover:bg-blue-500 text-white rounded text-xs font-bold"><i data-lucide="upload" class="w-4 h-4"></i> Importar CSV</button>
                <button onclick="exportarCSV(); toggleMobileMenu();" class="flex items-center gap-2 p-2 bg-blue-800 hover:bg-blue-700 text-white rounded text-xs font-bold"><i data-lucide="download" class="w-4 h-4"></i> Exportar Mês</button>
                <button onclick="gerarRelatorioPDF(); toggleMobileMenu();" class="flex items-center justify-center gap-2 p-2 bg-emerald-600 hover:bg-emerald-500 text-white rounded text-xs font-bold"><i data-lucide="file-bar-chart" class="w-4 h-4"></i> Relatório PDF</button>
                <button onclick="switchView('gestao'); toggleMobileMenu();" class="flex items-center justify-center gap-2 p-2 bg-amber-500 hover:bg-amber-400 text-white rounded text-xs font-bold"><i data-lucide="layout-dashboard" class="w-3.5 h-3.5"></i> Gerencial</button>
                <button onclick="abrirAuditoria(); toggleMobileMenu();" class="col-span-2 flex items-center justify-center gap-2 p-2 bg-yellow-600 hover:bg-yellow-500 text-white rounded text-xs font-bold"><i data-lucide="shield-alert" class="w-4 h-4"></i> Ver Auditoria</button>
                <?php if ($perfilLogado === 'mestre'): ?><button onclick="abrirConfiguracoes(); toggleMobileMenu();" class="col-span-2 flex items-center justify-center gap-2 p-2 bg-slate-700 hover:bg-slate-600 text-white rounded text-xs font-bold"><i data-lucide="settings" class="w-4 h-4"></i> Configurações</button><?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </header>

    <div id="filter-bar-container" class="hidden bg-white border-b border-slate-200 px-2 py-1.5 flex-col items-center gap-1.5 shrink-0 relative z-40 shadow-sm w-full">
        <div class="flex items-center justify-between w-full">
            <div class="flex items-center gap-1.5 flex-1">
                <button onclick="filtrarMinhaEscala()" class="bg-blue-50 hover:bg-blue-100 text-blue-700 border border-blue-200 font-bold text-[10px] px-2 py-1.5 rounded flex items-center gap-1.5 transition-colors whitespace-nowrap shrink-0" title="Minha Escala"><i data-lucide="user-check" class="w-3.5 h-3.5"></i> <span class="hidden sm:inline">Minha Escala</span></button>
                <div class="relative flex-1 w-full max-w-lg">
                    <div class="flex items-center border border-slate-300 rounded bg-slate-50 px-2 py-1"><i data-lucide="search" class="w-3.5 h-3.5 text-slate-400 shrink-0"></i><input type="text" id="filtro-input-global" oninput="showFilterSuggestions()" onfocus="showFilterSuggestions()" class="w-full bg-transparent px-2 text-[11px] text-slate-700 focus:outline-none" placeholder="Buscar..."></div>
                    <div id="sugestoes-filtro" class="absolute top-full left-0 w-full bg-white border border-slate-200 rounded-b shadow-xl mt-0 hidden max-h-64 overflow-y-auto custom-scrollbar z-50 divide-y divide-slate-100"></div>
                </div>
                <button onclick="limparFiltros()" class="text-slate-400 hover:text-red-500 bg-slate-50 border border-slate-200 rounded font-bold text-[10px] px-2 py-1.5 flex items-center gap-1 shrink-0" title="Limpar Filtros"><i data-lucide="rotate-ccw" class="w-3.5 h-3.5"></i> <span class="hidden sm:inline">Limpar</span></button>
                <button id="btn-save-filter" onclick="salvarFiltroPadrao()" class="text-blue-700 hover:text-blue-800 bg-blue-50 border border-blue-200 hover:bg-blue-100 rounded font-bold text-[10px] px-2 py-1.5 flex items-center gap-1 shrink-0 transition-colors duration-300" title="Salvar filtro atual como padrão no banco de dados"><i id="icon-save-filter" data-lucide="save" class="w-3.5 h-3.5 transition-transform"></i> <span id="text-save-filter" class="hidden sm:inline">Salvar Padrão</span></button>
            </div>
            
            <div class="flex items-center gap-2 ml-2">
                <button id="btn-preencher-mes" type="button" onclick="preencherMesSobreaviso()" class="hidden text-emerald-700 hover:text-emerald-800 bg-emerald-50 border border-emerald-200 hover:bg-emerald-100 rounded font-bold text-[10px] px-2 py-1.5 flex items-center gap-1 shrink-0 transition-colors duration-300" title="Preencher o sobreaviso de todos os colaboradores neste mês, seguindo a lógica do mês anterior"><i data-lucide="calendar-plus" class="w-3.5 h-3.5"></i> <span class="hidden sm:inline">Preencher Sobreaviso</span></button>
            </div>
        </div>
        <div id="active-filters-wrapper" class="hidden items-center gap-2 w-full overflow-x-auto custom-scrollbar pb-0.5">
            <div id="active-filters-container" class="flex items-center gap-1.5 flex-nowrap min-h-[24px]"></div>
        </div>
    </div>

    <div id="loading-screen" class="flex-1 flex flex-col items-center justify-center w-full h-full bg-slate-50 z-50">
        <div class="bg-white p-6 rounded-xl shadow-lg border border-slate-200 flex flex-col items-center w-72"><i data-lucide="loader-2" class="w-8 h-8 text-blue-600 animate-spin mb-4"></i><span id="loading-text" class="text-xs font-bold text-slate-700 mb-2">Processando...</span><div id="progress-container" class="w-full h-2 bg-slate-100 rounded-full overflow-hidden hidden"><div id="progress-bar" class="h-full bg-blue-500 transition-all duration-200 ease-out" style="width: 0%"></div></div><span id="progress-text" class="text-[10px] font-bold text-slate-400 mt-2 hidden">0%</span></div>
    </div>

    <main id="main-container" class="hidden flex-1 flex flex-col p-0.5 overflow-hidden bg-slate-200/50 w-full relative z-10">
        <?php include 'semanal.php'; include 'mensal.php'; ?>
        
        <!-- TELA DE DASHBOARD EM FORMATO DE TABELA -->
        <div id="view-dashboard" class="hidden w-full h-full overflow-y-auto custom-scrollbar bg-slate-50 p-6">
            <div class="max-w-4xl mx-auto pb-10">
                <div class="flex flex-col md:flex-row md:justify-between md:items-center mb-6 gap-3 border-b pb-4">
                    <h2 class="text-xl font-black text-slate-800 uppercase flex items-center gap-2"><i data-lucide="bar-chart-2" class="w-6 h-6 text-blue-600"></i> Ranking de Horas de Sobreaviso</h2>
                    <div class="text-xs font-bold text-slate-600 bg-white px-3 py-1.5 rounded-lg border border-slate-200 shadow-sm" id="dash-periodo-label">Período: --</div>
                </div>
                
                <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                    <table class="w-full text-left border-collapse">
                        <thead class="bg-slate-100 text-slate-600 text-xs uppercase font-black border-b border-slate-200">
                            <tr>
                                <th class="p-3 text-center w-16">Pos</th>
                                <th class="p-3">Colaborador</th>
                                <th class="p-3 text-center">Dias Ativos</th>
                                <th class="p-3 text-right">Total Horas</th>
                            </tr>
                        </thead>
                        <tbody id="dashboard-table-body" class="divide-y divide-slate-100 text-sm">
                            <!-- Linhas inseridas via JS -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <script>
        const currentAccess = "<?php echo $perfilLogado; ?>";
        const currentLogin = "<?php echo addslashes($_SESSION['usuario'] ?? ''); ?>";
        const loggedUserNomeCompleto = "<?php echo addslashes($nomeLogado); ?>";
        const setoresPermitidosLogin = "<?php echo addslashes($setoresPermitidos); ?>";
        const setoresGerenciaveisLogin = "<?php echo addslashes($setoresGerenciaveis); ?>";

        let tecnicosDB = <?php echo $jsonFuncionarios; ?>;
        let plantaoMensalDados = <?php echo $jsonDadosBanco; ?>;
        let feriadosFixo = <?php echo $jsonFeriadosDB; ?>;
        const nomesMeses = ['JANEIRO', 'FEVEREIRO', 'MARÇO', 'ABRIL', 'MAIO', 'JUNHO', 'JULHO', 'AGOSTO', 'SETEMBRO', 'OUTUBRO', 'NOVEMBRO', 'DEZEMBRO'];
        const simulatedTodayStr = new Date().toDateString();
        let configuracoes = { horas_uteis: 8, horas_fds: 17, escala_texto: 1.0, logo_pdf_base64: null };
        let currentView = 'week';
        
        let activeFilters = { bases: [], areas: [], localidades: [], pessoas: [], siglas: [], supervisores: [] };
        let loggedUserNomeResumido = '';
        
        const userMatched = tecnicosDB.find(t => t.nome === loggedUserNomeCompleto || (t.nome_completo && t.nome_completo === loggedUserNomeCompleto));
        if(userMatched) loggedUserNomeResumido = userMatched.nome;

        const PARES_SIGLA = [
            ['TAF', 'PGU'], ['FSA', 'SEH'], ['SNJ', 'ALH'], ['VCA', 'GNB'], 
            ['BES', 'SMV'], ['SBM', 'JUA'], ['ITB', 'JEE'], ['ECN', 'PAF'], ['IRE', 'IEB']
        ];

        window.addEventListener('DOMContentLoaded', async () => {
            adjustMainHeight(); window.addEventListener('resize', adjustMainHeight);
            if (currentAccess) { 
                carregarFiltroPadrao();
                renderActiveChips(); 
                await carregarConfiguracoes(); await carregarUsuariosEFuncionariosEFeriados();
                if (!tecnicosDB || !plantaoMensalDados || Object.keys(plantaoMensalDados).length === 0) await recarregarDadosBanco();
                else liberarInterface();
                if (typeof aplicarViewPadrao === 'function') aplicarViewPadrao();
            }
        });

            let gruposWppCache = [];
    async function carregarWppGrupos() {
        try {
            const res = await fetch('api_escala.php?action=get_wpp_grupos');
            const data = await res.json();
            if(data.sucesso) { gruposWppCache = data.dados; renderListaWpp(); }
        } catch(e) { console.error(e); }
    }
    function renderListaWpp() {
        const tbody = document.getElementById('lista-wpp-body');
        if(!tbody) return;
        tbody.innerHTML = gruposWppCache.map(g => `<tr class="hover:bg-slate-50"><td class="p-2 font-bold">${g.nome}</td><td class="p-2 text-[10px] text-slate-500 truncate">${g.grupo_id}</td><td class="p-2 text-center flex justify-center gap-1"><button onclick="testarMensagemWpp('${g.grupo_id}')" class="p-1 bg-slate-200 hover:bg-emerald-300 hover:text-emerald-700 rounded text-emerald-600" title="Testar Disparo"><i data-lucide="send" class="w-3.5 h-3.5"></i></button><button onclick='editarWpp(${JSON.stringify(g)})' class="p-1 bg-slate-200 hover:bg-slate-300 rounded text-blue-600"><i data-lucide="edit-2" class="w-3.5 h-3.5"></i></button><button onclick="excluirWpp(${g.id})" class="p-1 bg-slate-200 hover:bg-slate-300 rounded text-red-600"><i data-lucide="trash" class="w-3.5 h-3.5"></i></button></td></tr>`).join('');
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }
    function abrirFormWpp() { document.getElementById('wpp-id').value = ''; document.getElementById('wpp-nome').value = ''; document.getElementById('wpp-grupo-id').value = ''; document.getElementById('wpp-form-title').innerText = 'Novo Grupo WhatsApp'; document.getElementById('wpp-form-modal').classList.remove('hidden'); }
    function editarWpp(g) { document.getElementById('wpp-id').value = g.id; document.getElementById('wpp-nome').value = g.nome; document.getElementById('wpp-grupo-id').value = g.grupo_id; document.getElementById('wpp-form-title').innerText = 'Editar Grupo WhatsApp'; document.getElementById('wpp-form-modal').classList.remove('hidden'); }
    async function salvarWpp() {
        const form = document.getElementById('wpp-form'); if(!form.checkValidity()) { form.reportValidity(); return; }
        const fd = new FormData(); fd.append('id', document.getElementById('wpp-id').value); fd.append('nome', document.getElementById('wpp-nome').value); fd.append('grupo_id', document.getElementById('wpp-grupo-id').value);
        await fetch('api_escala.php?action=salvar_wpp_grupo', {method:'POST', body: fd});
        document.getElementById('wpp-form-modal').classList.add('hidden'); carregarWppGrupos();
    }
    async function excluirWpp(id) {
        if(!confirm("Certeza que deseja excluir este grupo de WhatsApp? (Os vínculos serão removidos)")) return;
        await fetch('api_escala.php?action=excluir_wpp_grupo', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({id})});
        carregarWppGrupos();
    }
    
    // --- INÍCIO GESTÃO DINÂMICA WHATSAPP ---
    let selectedWppGroups = [];

    // Renderiza a lista de opções filtrada
    function renderWppDropdown(filtro = '') {
        const dropdown = document.getElementById('wpp-dropdown');
        if(!dropdown) return;
        
        // Pega os grupos que ainda não foram escolhidos e batem com o texto
        const disponiveis = gruposWppCache.filter(g => 
            !selectedWppGroups.includes(g.id) && 
            g.nome.toLowerCase().includes(filtro.toLowerCase())
        );

        if (disponiveis.length === 0) {
            dropdown.innerHTML = '<div class="p-3 text-xs text-slate-500 text-center font-medium">Nenhum grupo disponível ou encontrado.</div>';
        } else {
            // onmousedown é crucial aqui porque ele dispara antes do onblur do input esconder a janela
            dropdown.innerHTML = disponiveis.map(g => 
                `<div onmousedown="addWppGroup(${g.id})" class="p-2.5 text-xs cursor-pointer hover:bg-emerald-50 border-b border-slate-100 last:border-0 font-bold text-slate-700 flex items-center gap-2 transition-colors"><i data-lucide="plus-circle" class="w-3.5 h-3.5 text-emerald-600"></i> ${g.nome}</div>`
            ).join('');
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }
    }

    function mostrarWppDropdown() {
        document.getElementById('wpp-dropdown').classList.remove('hidden');
        renderWppDropdown(document.getElementById('wpp-search-input').value);
    }

    function esconderWppDropdown() {
        setTimeout(() => {
            const dp = document.getElementById('wpp-dropdown');
            if(dp) dp.classList.add('hidden');
        }, 150);
    }

    function filtrarWppGrupos(termo) {
        renderWppDropdown(termo);
    }

    function addWppGroup(id) {
        if (!selectedWppGroups.includes(id)) selectedWppGroups.push(id);
        document.getElementById('wpp-search-input').value = '';
        renderWppSelected();
        renderWppDropdown(); // Atualiza dropdown removendo a opção adicionada
    }

    function removeWppGroup(id) {
        selectedWppGroups = selectedWppGroups.filter(gid => gid !== id);
        renderWppSelected();
    }

    // Renderiza os "Badges" verdes logo abaixo do input
    function renderWppSelected() {
        const container = document.getElementById('func-wpp-selected');
        if(!container) return;
        
        container.innerHTML = selectedWppGroups.map(id => {
            const g = gruposWppCache.find(x => x.id === id);
            if(!g) return '';
            return `<div class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-md text-[10px] font-bold bg-emerald-100 text-emerald-800 border border-emerald-200 shadow-sm animate-fade-in">
                <i data-lucide="message-circle" class="w-3.5 h-3.5"></i>
                <span>${g.nome}</span>
                <button type="button" onclick="removeWppGroup(${g.id})" class="hover:bg-emerald-200 p-0.5 rounded-full text-red-500 hover:text-red-700 transition-colors ml-1" title="Remover grupo">
                    <i data-lucide="trash-2" class="w-3 h-3"></i>
                </button>
            </div>`;
        }).join('');
        
        if (selectedWppGroups.length === 0) {
            container.innerHTML = '<span class="text-xs text-slate-400 italic pt-1">Nenhum grupo vinculado ainda.</span>';
        }
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    // Redefinição das funções de modal para limpar e carregar corretamente os grupos
    const _origAbrirFormLocal = (typeof _origAbrirForm !== 'undefined') ? _origAbrirForm : abrirFormFuncionario;
    abrirFormFuncionario = function() { 
        _origAbrirFormLocal(); 
        selectedWppGroups = [];
        renderWppSelected();
        const input = document.getElementById('wpp-search-input');
        if(input) input.value = '';
    };
    
    const _origEditarFuncLocal = (typeof _origEditarFunc !== 'undefined') ? _origEditarFunc : editarFuncionario;
    editarFuncionario = function(f) { 
        _origEditarFuncLocal(f); 
        try { 
            selectedWppGroups = typeof f.grupos_wpp === 'string' ? JSON.parse(f.grupos_wpp) : (f.grupos_wpp||[]); 
        } catch(e){ selectedWppGroups = []; }
        renderWppSelected(); 
        const input = document.getElementById('wpp-search-input');
        if(input) input.value = '';
    };

    salvarFuncionario = async function() {
        const form = document.getElementById('func-form');
        if(!form.checkValidity()) { form.reportValidity(); return; }
        
        const fd = new FormData();
        // Os inputs originais não tem 'name', o append manual é OBRIGATÓRIO:
        fd.append('id', document.getElementById('func-id').value);
        fd.append('nome', document.getElementById('func-nome').value.toUpperCase());
        fd.append('nome_completo', document.getElementById('func-nomecomp').value);
        fd.append('local_base', document.getElementById('func-base').value.toUpperCase());
        fd.append('localidade', document.getElementById('func-loc').value.toUpperCase());
        fd.append('setor', document.getElementById('func-setor').value.toUpperCase());
        fd.append('sigla', document.getElementById('func-sigla').value.toUpperCase());
        fd.append('supervisor', document.getElementById('func-supervisor').value);
        fd.append('telefone', document.getElementById('func-tel').value);
        fd.append('aniversario', document.getElementById('func-niver').value); fd.append('data_admissao', document.getElementById('func-admissao').value); fd.append('cpf', document.getElementById('func-cpf').value);
        
        // Adiciona a seleção do WhatsApp à requisição
        fd.append('grupos_wpp', JSON.stringify(selectedWppGroups));

        try {
            const res = await fetch('api_escala.php?action=salvar_funcionario', { method: 'POST', body: fd });
            const result = await res.json();
            
            if(result.sucesso) { 
                document.getElementById('func-form-modal').classList.add('hidden'); 
                if (typeof carregarUsuariosEFuncionariosEFeriados === 'function') {
                    await carregarUsuariosEFuncionariosEFeriados();
                }
                // atualiza a escala na tela (sem recarregar a página) com os dados novos do colaborador
                if (typeof listaFuncionariosCache !== 'undefined' && Array.isArray(listaFuncionariosCache)) { tecnicosDB = listaFuncionariosCache; }
                if (typeof renderAll === 'function') renderAll();
                showToast('Colaborador salvo com sucesso!', 'success');
                if (result.whatsapp_pendentes > 0) showToast('WhatsApp: ' + result.whatsapp_pendentes + ' ação(ões) na fila, executando com intervalo de segurança.', 'info');
            } else { 
                showToast(result.erro || 'Erro ao salvar colaborador.', 'error'); 
            }
        } catch(e) {
            console.error('Erro:', e);
            alert('Erro de comunicação ao salvar.');
        }
    };
    window.addEventListener('DOMContentLoaded', () => { if(typeof currentAccess !== 'undefined' && currentAccess) carregarWppGrupos(); });
    // --- FIM GESTÃO DINÂMICA WHATSAPP ---

        let auditCurrentPage = 1;

        async function abrirAuditoria() {
            const selectTermo = document.getElementById('audit-filtro-termo');
            if (selectTermo && selectTermo.options.length <= 1) {
                let nomesUnicos = [...new Set(tecnicosDB.map(t => t.nome))].filter(Boolean).sort();
                nomesUnicos.forEach(nome => selectTermo.add(new Option(nome, nome)));
            }
            
            document.getElementById('audit-modal').classList.remove('hidden');
            limparFiltrosAuditoria();
        }

        function limparFiltrosAuditoria() {
            document.getElementById('audit-filtro-mes').value = '';
            document.getElementById('audit-filtro-data').value = '';
            document.getElementById('audit-filtro-usuario').value = '';
            document.getElementById('audit-filtro-acao').value = '';
            document.getElementById('audit-filtro-termo').value = '';
            
            auditCurrentPage = 1;
            carregarAuditoria();
        }

        async function mudarPaginaAuditoria(novaPagina) {
            auditCurrentPage = novaPagina;
            await carregarAuditoria();
        }

        async function carregarAuditoria() {
            const tbody = document.getElementById('lista-auditoria-body');
            tbody.innerHTML = '<tr><td colspan="4" class="p-4 text-center text-slate-400 italic">Carregando logs...</td></tr>';
            
            let mesFiltro = document.getElementById('audit-filtro-mes').value;
            const dataFiltro = document.getElementById('audit-filtro-data').value;
            const usuarioFiltro = document.getElementById('audit-filtro-usuario').value;
            const acaoFiltro = document.getElementById('audit-filtro-acao').value;
            const termoFiltro = document.getElementById('audit-filtro-termo').value;

            if (mesFiltro && !/^\d{4}-\d{2}$/.test(mesFiltro)) {
                const mapMesesInv = {
                    'janeiro': '01', 'fevereiro': '02', 'março': '03', 'marco': '03',
                    'abril': '04', 'maio': '05', 'junho': '06', 'julho': '07',
                    'agosto': '08', 'setembro': '09', 'outubro': '10', 'novembro': '11', 'dezembro': '12'
                };
                let mesLower = mesFiltro.toLowerCase();
                let mNum = '';
                for (let k in mapMesesInv) {
                    if (mesLower.includes(k)) { mNum = mapMesesInv[k]; break; }
                }
                let matchAno = mesFiltro.match(/\d{4}/);
                if (matchAno && mNum) {
                    mesFiltro = `${matchAno[0]}-${mNum}`;
                } else {
                    mesFiltro = '';
                }
            }

            let queryParams = new URLSearchParams();
            queryParams.append('action', 'get_auditoria');
            queryParams.append('pagina', auditCurrentPage);
            if (mesFiltro) queryParams.append('mes', mesFiltro);
            if (dataFiltro) queryParams.append('data', dataFiltro);
            if (usuarioFiltro) queryParams.append('usuario', usuarioFiltro);
            if (acaoFiltro) queryParams.append('acao', acaoFiltro);
            if (termoFiltro) queryParams.append('termo', termoFiltro);

            try {
                const res = await fetch(`api_escala.php?${queryParams.toString()}`);
                const data = await res.json();
                if (data.sucesso && data.logs && data.logs.length > 0) {
                    tbody.innerHTML = data.logs.map(l => {
                        let acaoUpper = l.acao.toUpperCase();
                        let badgeColor = 'bg-slate-100 text-slate-700'; 
                        
                        if (acaoUpper.includes('REMOV') || acaoUpper.includes('EXCLUS') || acaoUpper.includes('LIMPAR')) {
                            badgeColor = 'bg-red-100 text-red-700';
                        } else if (acaoUpper.includes('ATUALIZ') || acaoUpper.includes('ALTER')) {
                            badgeColor = 'bg-amber-100 text-amber-700';
                        } else if (acaoUpper.includes('INSER') || acaoUpper.includes('INCLUS')) {
                            badgeColor = 'bg-green-100 text-green-700';
                        } else if (acaoUpper.includes('TROC')) {
                            badgeColor = 'bg-indigo-100 text-indigo-700';
                        } else {
                            badgeColor = 'bg-blue-100 text-blue-700';
                        }

                        return `
                        <tr class="hover:bg-slate-50">
                            <td class="p-2 whitespace-nowrap">${l.data_hora_fmt}</td>
                            <td class="p-2 font-bold text-blue-700">${l.usuario}</td>
                            <td class="p-2"><span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase ${badgeColor}">${l.acao}</span></td>
                            <td class="p-2 text-slate-600 max-w-md truncate" title="${l.detalhes}">${l.detalhes}</td>
                        </tr>
                        `;
                    }).join('');
                } else {
                    tbody.innerHTML = '<tr><td colspan="4" class="p-4 text-center text-slate-400 italic">Nenhum registro encontrado.</td></tr>';
                }

                const totalPaginas = data.paginas_totais || 1;
                document.getElementById('audit-pagination-info').innerText = `Página ${auditCurrentPage} de ${totalPaginas}`;
                document.getElementById('btn-audit-prev').disabled = auditCurrentPage <= 1;
                document.getElementById('btn-audit-next').disabled = auditCurrentPage >= totalPaginas;

            } catch(e) {
                tbody.innerHTML = '<tr><td colspan="4" class="p-4 text-center text-red-500 italic">Falha ao carregar a trilha de auditoria.</td></tr>';
            }
        }

        async function showAuditSuggestions(campo, termo) {
            const container = document.getElementById(`sugestoes-audit-${campo}`);
            if (!termo || termo.trim() === '') {
                container.classList.add('hidden');
                return;
            }

            try {
                const res = await fetch(`api_escala.php?action=get_auditoria_sugestoes&campo=${campo}&termo=${encodeURIComponent(termo)}`);
                const data = await res.json();
                if (data.sucesso && data.dados && data.dados.length > 0) {
                    container.innerHTML = data.dados.map(item => `
                        <div class="px-3 py-2 text-xs text-slate-700 cursor-pointer hover:bg-slate-100 truncate flex items-center gap-2" onclick="selectAuditSuggestion('${campo}', '${item.replace(/'/g, "\\'")}')"><i data-lucide="check" class="w-3.5 h-3.5 text-slate-400"></i> ${item}</div>
                    `).join('');
                    container.classList.remove('hidden');
                    lucide.createIcons();
                } else {
                    container.classList.add('hidden');
                }
            } catch(e) {
                container.classList.add('hidden');
            }
        }

        function selectAuditSuggestion(campo, valor) {
            document.getElementById(`audit-filtro-${campo}`).value = valor;
            document.getElementById(`sugestoes-audit-${campo}`).classList.add('hidden');
        }

        document.addEventListener('click', (e) => {
            ['usuario', 'acao'].forEach(campo => {
                const input = document.getElementById(`audit-filtro-${campo}`);
                const sugg = document.getElementById(`sugestoes-audit-${campo}`);
                if (input && sugg && !input.contains(e.target) && !sugg.contains(e.target)) {
                    sugg.classList.add('hidden');
                }
            });
        });

        async function salvarFiltroPadrao() {
            const btn = document.getElementById('btn-save-filter');
            const icon = document.getElementById('icon-save-filter');
            const text = document.getElementById('text-save-filter');

            btn.disabled = true;
            btn.classList.add('opacity-70', 'cursor-not-allowed');

            try {
                const response = await fetch('api_escala.php?action=salvar_filtro', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ usuario: currentLogin, filtro: activeFilters })
                });
                
                const result = await response.json();

                if (result.sucesso) {
                    const originalClasses = btn.className;
                    const originalText = text.innerText;
                    const originalIcon = icon.getAttribute('data-lucide');

                    btn.className = "text-white bg-green-600 hover:bg-green-700 border border-green-700 rounded font-bold text-[10px] px-2 py-1.5 flex items-center gap-1 shrink-0 transition-colors duration-300 shadow-sm";
                    icon.setAttribute('data-lucide', 'check-circle');
                    text.innerText = "Filtro Salvo!";
                    lucide.createIcons(); 

                    setTimeout(() => {
                        btn.className = originalClasses;
                        btn.classList.remove('opacity-70', 'cursor-not-allowed');
                        icon.setAttribute('data-lucide', originalIcon);
                        text.innerText = originalText;
                        btn.disabled = false;
                        lucide.createIcons();
                    }, 2500);

                } else {
                    alert("Erro ao salvar filtro: " + (result.erro || "Falha no banco de dados."));
                    btn.classList.remove('opacity-70', 'cursor-not-allowed');
                    btn.disabled = false;
                }
            } catch(e) {
                console.error("Erro interno ao salvar filtro:", e);
                alert("Erro de conexão ao tentar salvar o filtro.");
                btn.classList.remove('opacity-70', 'cursor-not-allowed');
                btn.disabled = false;
            }
        }

        const VIEW_PADRAO = <?php echo json_encode($viewPadrao); ?>;
        function aplicarViewPadrao() {
            if (VIEW_PADRAO && VIEW_PADRAO !== currentView) { try { switchView(VIEW_PADRAO); } catch (e) {} }
        }
        async function salvarViewPadrao(btn) {
            if (!btn || btn.disabled) return;
            btn.disabled = true;
            const cls = btn.className, tit = btn.title;
            try {
                const r = await fetch('api_escala.php?action=salvar_view', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({ view: currentView }) });
                const j = await r.json();
                if (j.sucesso) { btn.className = cls + ' !bg-emerald-600 !text-white'; btn.title = 'Tela inicial salva!'; }
                else { btn.className = cls + ' !bg-red-600 !text-white'; btn.title = j.erro || 'Erro ao salvar'; }
            } catch (e) { btn.className = cls + ' !bg-red-600 !text-white'; btn.title = 'Erro de comunicação'; }
            setTimeout(() => { btn.className = cls; btn.title = tit; btn.disabled = false; }, 2000);
        }
        function carregarFiltroPadrao() {
            const savedDB = <?php echo $filtroPadrao; ?>;
            if (savedDB) {
                try {
                    let parsed = typeof savedDB === 'string' ? JSON.parse(savedDB) : savedDB;
                    activeFilters = {
                        bases: parsed.bases || [],
                        areas: parsed.areas || [],
                        localidades: parsed.localidades || [],
                        pessoas: parsed.pessoas || [],
                        siglas: parsed.siglas || [],
                        supervisores: parsed.supervisores || []
                    };
                } catch (e) {
                    console.error("Erro ao fazer parse do filtro salvo:", e);
                }
            }
        }

        function toggleMobileMenu() { document.getElementById('mobile-menu-dropdown').classList.toggle('hidden'); setTimeout(adjustMainHeight, 10); }

        function setProgress(percent, msg) {
            document.getElementById('loading-screen').classList.remove('hidden'); document.getElementById('main-container').classList.add('hidden');
            const cont = document.getElementById('progress-container'); const pbar = document.getElementById('progress-bar');
            const ptext = document.getElementById('progress-text'); const ltext = document.getElementById('loading-text');
            if (msg) ltext.innerText = msg;
            if (percent !== null) { cont.classList.remove('hidden'); ptext.classList.remove('hidden'); pbar.style.width = `${percent}%`; ptext.innerText = `${Math.round(percent)}%`; } 
            else { cont.classList.add('hidden'); ptext.classList.add('hidden'); }
        }

        let userSectorsView = []; let userSectorsEdit = [];
        function getUniqueSetores() { return [...new Set(tecnicosDB.map(f => f.setor).filter(s => s))].sort(); }
        function renderUserSectorChips(type) { const arr = type === 'view' ? userSectorsView : userSectorsEdit; const container = document.getElementById(`chips-${type}`); const hiddenInput = document.getElementById(`user-setores-${type}`); const colorClass = type === 'view' ? 'bg-blue-600 text-white' : 'bg-emerald-600 text-white'; container.innerHTML = arr.map(s => `<div class="flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-bold shadow-sm ${colorClass}"><span>${s}</span><button type="button" onclick="removeUserSector('${type}', '${s}')" class="hover:text-red-200 ml-0.5 focus:outline-none"><i data-lucide="x" class="w-3 h-3"></i></button></div>`).join(''); hiddenInput.value = arr.join(', '); lucide.createIcons(); }
        function removeUserSector(type, sector) { if (type === 'view') userSectorsView = userSectorsView.filter(s => s !== sector); else userSectorsEdit = userSectorsEdit.filter(s => s !== sector); renderUserSectorChips(type); document.getElementById(`input-setor-${type}`).focus(); }
        function addUserSector(type, sector) { if (type === 'view') { if (!userSectorsView.includes(sector)) userSectorsView.push(sector); } else { if (!userSectorsEdit.includes(sector)) userSectorsEdit.push(sector); } document.getElementById(`input-setor-${type}`).value = ''; document.getElementById(`sugestoes-setor-${type}`).classList.add('hidden'); renderUserSectorChips(type); document.getElementById(`input-setor-${type}`).focus(); }
        function showSetorSuggestions(type) { const input = document.getElementById(`input-setor-${type}`); const val = input.value.trim().toLowerCase(); const container = document.getElementById(`sugestoes-setor-${type}`); const allSetores = getUniqueSetores(); const selected = type === 'view' ? userSectorsView : userSectorsEdit; let filtered = allSetores.filter(s => !selected.includes(s)); if (val) filtered = filtered.filter(s => s.toLowerCase().includes(val)); if (filtered.length > 0) { container.innerHTML = filtered.map(s => `<div class="px-3 py-2 text-xs font-medium text-slate-700 cursor-pointer hover:bg-slate-100 border-b border-slate-50 flex items-center gap-2" onclick="addUserSector('${type}', '${s}')"><i data-lucide="plus-circle" class="w-3.5 h-3.5 text-slate-400"></i> ${s}</div>`).join(''); } else { container.innerHTML = `<div class="px-3 py-2 text-xs text-slate-400 font-medium text-center">Nenhum setor disponível</div>`; } container.classList.remove('hidden'); lucide.createIcons(); }

        let listaUsuariosCache = []; let listaFuncionariosCache = []; let listaFeriadosCache = [];
        
        async function carregarUsuariosEFuncionariosEFeriados() { 
            try { 
                const resU = await fetch('api_escala.php?action=get_usuarios'); 
                const rU = await resU.json(); 
                if(rU.sucesso) { listaUsuariosCache = rU.dados; renderListaUsuarios(); } 
            } catch(e) { console.error(e); } 
            try { 
                const resF = await fetch('api_escala.php?action=get_funcionarios'); 
                const rF = await resF.json(); 
                if(rF.sucesso) { listaFuncionariosCache = rF.dados; renderListaFuncionarios(); } 
            } catch(e) { console.error(e); } 
            try {
                const resFer = await fetch('api_escala.php?action=get_feriados');
                const rFer = await resFer.json();
                if(rFer.sucesso) { listaFeriadosCache = rFer.dados; renderListaFeriados(); }
            } catch(e) { console.error(e); }
        }
        function renderListaUsuarios() { const tbody = document.getElementById('lista-usuarios-body'); tbody.innerHTML = listaUsuariosCache.map(u => { let badgeClass = 'bg-blue-100 text-blue-700'; if(u.perfil === 'admin') badgeClass = 'bg-red-100 text-red-700'; if(u.perfil === 'mestre') badgeClass = 'bg-purple-100 text-purple-700'; return `<tr class="hover:bg-slate-50"><td class="p-2 font-bold truncate">${u.nome_completo||''}</td><td class="p-2 truncate">${u.usuario}</td><td class="p-2"><span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase ${badgeClass}">${u.perfil}</span></td><td class="p-2 text-[10px] text-slate-500 truncate max-w-[80px]" title="${u.setores_permitidos||'Todos'}">${u.setores_permitidos || 'Todos'}</td><td class="p-2 text-[10px] text-slate-500 truncate max-w-[80px]" title="${u.setores_gerenciaveis||'Todos'}">${u.setores_gerenciaveis || 'Todos'}</td><td class="p-2 text-center flex justify-center gap-1"><button onclick='editarUsuario(${JSON.stringify(u)})' class="p-1 bg-slate-200 hover:bg-slate-300 rounded text-blue-600"><i data-lucide="edit-2" class="w-3.5 h-3.5"></i></button><button onclick="excluirUsuario(${u.id})" class="p-1 bg-slate-200 hover:bg-slate-300 rounded text-red-600"><i data-lucide="trash" class="w-3.5 h-3.5"></i></button></td></tr>` }).join(''); lucide.createIcons(); }
        function abrirFormUsuario() { document.getElementById('user-id').value = ''; document.getElementById('user-nome').value = ''; document.getElementById('user-login').value = ''; document.getElementById('user-senha').value = ''; document.getElementById('user-perfil').value = 'usuario'; userSectorsView = []; userSectorsEdit = []; renderUserSectorChips('view'); renderUserSectorChips('edit'); document.getElementById('input-setor-view').value = ''; document.getElementById('input-setor-edit').value = ''; document.getElementById('user-form-title').innerText = 'Novo Usuário'; document.getElementById('user-form-modal').classList.remove('hidden'); }
        function editarUsuario(u) { document.getElementById('user-id').value = u.id; document.getElementById('user-nome').value = u.nome_completo; document.getElementById('user-login').value = u.usuario; document.getElementById('user-senha').value = ''; document.getElementById('user-perfil').value = u.perfil; userSectorsView = (u.setores_permitidos || '').split(',').map(s => s.trim()).filter(s => s); userSectorsEdit = (u.setores_gerenciaveis || '').split(',').map(s => s.trim()).filter(s => s); renderUserSectorChips('view'); renderUserSectorChips('edit'); document.getElementById('input-setor-view').value = ''; document.getElementById('input-setor-edit').value = ''; document.getElementById('user-form-title').innerText = 'Editar Usuário'; document.getElementById('user-form-modal').classList.remove('hidden'); }
        async function salvarUsuario() { const form = document.getElementById('user-form'); if(!form.checkValidity()) { form.reportValidity(); return; } const formData = new FormData(); formData.append('id', document.getElementById('user-id').value); formData.append('nome_completo', document.getElementById('user-nome').value); formData.append('usuario', document.getElementById('user-login').value); formData.append('senha', document.getElementById('user-senha').value); formData.append('perfil', document.getElementById('user-perfil').value); formData.append('setores_permitidos', document.getElementById('user-setores-view').value); formData.append('setores_gerenciaveis', document.getElementById('user-setores-edit').value); const res = await fetch('api_escala.php?action=salvar_usuario', { method: 'POST', body: formData }); const result = await res.json(); if(result.sucesso) { document.getElementById('user-form-modal').classList.add('hidden'); carregarUsuariosEFuncionariosEFeriados(); } else { alert(result.erro); } }
        async function excluirUsuario(id) { if(!confirm("Certeza que deseja excluir este usuário?")) return; await fetch('api_escala.php?action=excluir_usuario', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({id}) }); carregarUsuariosEFuncionariosEFeriados(); }
        function exportarUsuariosCSV() { let csv = "Nome Completo;Usuario;Senha(vazia para manter);Perfil;Setores_Permitidos;Setores_Gerenciaveis\n"; listaUsuariosCache.forEach(u => csv += `${u.nome_completo||''};${u.usuario};;${u.perfil};${u.setores_permitidos||''};${u.setores_gerenciaveis||''}\n`); const encodedUri = encodeURI("data:text/csv;charset=utf-8,\uFEFF" + csv); const link = document.createElement("a"); link.setAttribute("href", encodedUri); link.setAttribute("download", `usuarios_escala.csv`); document.body.appendChild(link); link.click(); document.body.removeChild(link); }
        async function importarUsuariosCSV(event) { const file = event.target.files[0]; if (!file) return; const formData = new FormData(); formData.append('file', file); await fetch('api_escala.php?action=importar_usuarios', { method: 'POST', body: formData }); document.getElementById('file-import-user').value = ''; carregarUsuariosEFuncionariosEFeriados(); alert('Usuários importados com sucesso!'); }
        
        function renderListaFuncionarios() { const tbody = document.getElementById('lista-funcionarios-body'); tbody.innerHTML = listaFuncionariosCache.map(f => `<tr class="hover:bg-slate-50"><td class="p-2 font-bold truncate">${f.nome}</td><td class="p-2 text-[10px] truncate max-w-[150px]">${f.nome_completo || '-'}</td><td class="p-2 text-[10px] text-slate-500 truncate max-w-[150px]">${f.local_base||''} - ${f.localidade||''} (${f.setor||''})</td><td class="p-2 text-[10px] text-slate-600 truncate">${f.supervisor||''}</td><td class="p-2 text-center flex justify-center gap-1"><button onclick='editarFuncionario(${JSON.stringify(f)})' class="p-1 bg-slate-200 hover:bg-slate-300 rounded text-blue-600"><i data-lucide="edit-2" class="w-3.5 h-3.5"></i></button><button onclick="excluirFuncionario(${f.id})" class="p-1 bg-slate-200 hover:bg-slate-300 rounded text-red-600"><i data-lucide="trash" class="w-3.5 h-3.5"></i></button></td></tr>`).join(''); lucide.createIcons(); }
        function abrirFormFuncionario() { document.getElementById('func-id').value = ''; document.getElementById('func-nome').value = ''; document.getElementById('func-nomecomp').value = ''; document.getElementById('func-base').value = ''; document.getElementById('func-loc').value = ''; document.getElementById('func-setor').value = ''; document.getElementById('func-sigla').value = ''; document.getElementById('func-supervisor').value = ''; document.getElementById('func-tel').value = ''; document.getElementById('func-niver').value = ''; document.getElementById('func-admissao').value = ''; document.getElementById('func-cpf').value = ''; document.getElementById('func-form-title').innerText = 'Novo Colaborador'; document.getElementById('func-form-modal').classList.remove('hidden'); }
        function editarFuncionario(f) { document.getElementById('func-id').value = f.id; document.getElementById('func-nome').value = f.nome; document.getElementById('func-nomecomp').value = f.nome_completo; document.getElementById('func-base').value = f.local_base; document.getElementById('func-loc').value = f.localidade; document.getElementById('func-setor').value = f.setor; document.getElementById('func-sigla').value = f.sigla; document.getElementById('func-supervisor').value = f.supervisor; document.getElementById('func-tel').value = f.telefone; document.getElementById('func-niver').value = f.aniversario; document.getElementById('func-admissao').value = f.data_admissao || ''; document.getElementById('func-cpf').value = (f.cpf || '').replace(/^(\d{3})(\d{3})(\d{3})(\d{2})$/, '$1.$2.$3-$4'); document.getElementById('func-form-title').innerText = 'Editar Colaborador'; document.getElementById('func-form-modal').classList.remove('hidden'); }
        async function salvarFuncionario() { const form = document.getElementById('func-form'); if(!form.checkValidity()) { form.reportValidity(); return; } const formData = new FormData(); formData.append('id', document.getElementById('func-id').value); formData.append('nome', document.getElementById('func-nome').value.toUpperCase()); formData.append('nome_completo', document.getElementById('func-nomecomp').value); formData.append('local_base', document.getElementById('func-base').value.toUpperCase()); formData.append('localidade', document.getElementById('func-loc').value.toUpperCase()); formData.append('setor', document.getElementById('func-setor').value.toUpperCase()); formData.append('sigla', document.getElementById('func-sigla').value.toUpperCase()); formData.append('supervisor', document.getElementById('func-supervisor').value); formData.append('telefone', document.getElementById('func-tel').value); formData.append('aniversario', document.getElementById('func-niver').value); formData.append('data_admissao', document.getElementById('func-admissao').value); formData.append('cpf', document.getElementById('func-cpf').value); const res = await fetch('api_escala.php?action=salvar_funcionario', { method: 'POST', body: formData }); const result = await res.json(); if(result.sucesso) { document.getElementById('func-form-modal').classList.add('hidden'); await carregarUsuariosEFuncionariosEFeriados(); renderAll(); if(typeof showToast === 'function') showToast('Colaborador salvo com sucesso!', 'success'); } else { if(typeof showToast === 'function') showToast(result.erro, 'error'); else alert(result.erro); } }
        async function excluirFuncionario(id) { if(!confirm("Certeza que deseja excluir este colaborador?\nIsso não apagará o histórico dele na escala já salva, apenas a exibição principal.")) return; await fetch('api_escala.php?action=excluir_funcionario', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({id}) }); await carregarUsuariosEFuncionariosEFeriados(); window.location.reload(); }
        function exportarFuncionariosCSV() { let csv = "NomeResumido;NomeCompleto;Telefone;Setor;Localidade;Base;Sigla;Aniversario;Supervisor\n"; listaFuncionariosCache.forEach(f => csv += `${f.nome};${f.nome_completo||''};${f.telefone||''};${f.setor||''};${f.localidade||''};${f.local_base||''};${f.sigla||''};${f.aniversario||''};${f.supervisor||''}\n`); const encodedUri = encodeURI("data:text/csv;charset=utf-8,\uFEFF" + csv); const link = document.createElement("a"); link.setAttribute("href", encodedUri); link.setAttribute("download", `colaboradores_escala.csv`); document.body.appendChild(link); link.click(); document.body.removeChild(link); }
        async function importarFuncionariosCSV(event) { const file = event.target.files[0]; if (!file) return; const formData = new FormData(); formData.append('file', file); await fetch('api_escala.php?action=importar_funcionarios', { method: 'POST', body: formData }); document.getElementById('file-import-func').value = ''; await carregarUsuariosEFuncionariosEFeriados(); alert('Colaboradores importados com sucesso!'); window.location.reload(); }

        function getFeriadosMoveisAdmin(ano) {
            let a = ano % 19, b = Math.floor(ano / 100), c = ano % 100;
            let d = Math.floor(b / 4), e = b % 4, f = Math.floor((b + 8) / 25);
            let g = Math.floor((b - f + 1) / 3), h = (19 * a + b - d - g + 15) % 30;
            let i = Math.floor(c / 4), k = c % 4, l = (32 + 2 * e + 2 * i - h - k) % 7;
            let m = Math.floor((a + 11 * h + 22 * l) / 451);
            let mesPascoa = Math.floor((h + l - 7 * m + 114) / 31) - 1;
            let diaPascoa = ((h + l - 7 * m + 114) % 31) + 1;
            let pascoa = new Date(ano, mesPascoa, diaPascoa);
            
            let addDias = (dataBase, dias) => {
                let dt = new Date(dataBase); dt.setDate(dt.getDate() + dias);
                return {
                    data_feriado: `${ano}-${String(dt.getMonth() + 1).padStart(2, '0')}-${String(dt.getDate()).padStart(2, '0')}`,
                    data_fmt: `${String(dt.getDate()).padStart(2, '0')}/${String(dt.getMonth() + 1).padStart(2, '0')}/${ano}`
                };
            };

            return [
                { id: 'movel_carnaval', nome: 'Carnaval (Data móvel)', tipo: 'nacional', localidade: '', ...addDias(pascoa, -47) },
                { id: 'movel_sexta', nome: 'Sexta-feira Santa (Data móvel)', tipo: 'nacional', localidade: '', ...addDias(pascoa, -2) },
                { id: 'movel_corpus', nome: 'Corpus Christi (Data móvel)', tipo: 'nacional', localidade: '', ...addDias(pascoa, 60) }
            ];
        }

        function renderListaFeriados() {
            const tbody = document.getElementById('lista-feriados-body');
            const filtroTipo = document.getElementById('filtro-feriado-tipo').value;
            const filtroLoc = document.getElementById('filtro-feriado-loc').value.toLowerCase();
            
            let html = '';
            
            let anoVigente = (typeof currentMonthDate !== 'undefined') ? currentMonthDate.getFullYear() : new Date().getFullYear();
            
            let feriadosMoveis = getFeriadosMoveisAdmin(anoVigente);
            let todosFeriados = [...feriadosMoveis, ...listaFeriadosCache];

            todosFeriados.sort((a, b) => {
                let dtA = a.data_feriado.substring(5, 10);
                let dtB = b.data_feriado.substring(5, 10);
                return dtA.localeCompare(dtB);
            });

            todosFeriados.forEach(f => {
                if (filtroTipo && f.tipo !== filtroTipo) return;
                if (filtroLoc && (!f.localidade || !f.localidade.toLowerCase().includes(filtroLoc))) return;

                let dtArr = f.data_feriado.split('-');
                let dataFmt = f.data_fmt || `${dtArr[2]}/${dtArr[1]}/${anoVigente}`; 
                
                let badgeClass = 'bg-slate-100 text-slate-700';
                if(f.tipo === 'nacional') badgeClass = 'bg-emerald-100 text-emerald-700';
                if(f.tipo === 'estadual') badgeClass = 'bg-blue-100 text-blue-700';
                if(f.tipo === 'municipal') badgeClass = 'bg-amber-100 text-amber-700';

                let isMovel = String(f.id).startsWith('movel');
                let acoesHtml = isMovel 
                    ? `<span class="text-[10px] text-slate-400 font-bold italic w-full text-center block">Automático</span>`
                    : `
                        <button onclick='editarFeriado(${JSON.stringify(f)})' class="p-1 bg-slate-200 hover:bg-slate-300 rounded text-blue-600"><i data-lucide="edit-2" class="w-3.5 h-3.5"></i></button>
                        <button onclick="excluirFeriado(${f.id})" class="p-1 bg-slate-200 hover:bg-slate-300 rounded text-red-600"><i data-lucide="trash" class="w-3.5 h-3.5"></i></button>
                    `;

                html += `
                <tr class="hover:bg-slate-50">
                    <td class="p-2 text-center font-mono">${dataFmt}</td>
                    <td class="p-2 font-bold truncate ${isMovel ? 'text-indigo-700' : ''}">${f.nome}</td>
                    <td class="p-2"><span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase ${badgeClass}">${f.tipo}</span></td>
                    <td class="p-2 text-[10px] text-slate-500 truncate">${f.localidade || '-'}</td>
                    <td class="p-2 text-center flex justify-center gap-1 items-center">
                        ${acoesHtml}
                    </td>
                </tr>`;
            });
            tbody.innerHTML = html || `<tr><td colspan="5" class="p-4 text-center text-slate-400 italic">Nenhum feriado encontrado.</td></tr>`;
            lucide.createIcons();
        }

        function toggleFeriadoLoc() {
            const tipo = document.getElementById('feriado-tipo').value;
            const divEstado = document.getElementById('div-feriado-estado');
            const divCidade = document.getElementById('div-feriado-cidade');
            
            if(tipo === 'estadual') {
                divEstado.classList.remove('hidden');
                divCidade.classList.add('hidden');
                document.getElementById('feriado-cidade').value = '';
            } else if(tipo === 'municipal') {
                divEstado.classList.add('hidden');
                divCidade.classList.remove('hidden');
                document.getElementById('feriado-estado').value = '';
            } else {
                divEstado.classList.add('hidden');
                divCidade.classList.add('hidden');
                document.getElementById('feriado-estado').value = '';
                document.getElementById('feriado-cidade').value = '';
            }
        }

        function abrirFormFeriado() {
            document.getElementById('feriado-id').value = '';
            document.getElementById('feriado-nome').value = '';
            document.getElementById('feriado-data').value = '';
            document.getElementById('feriado-tipo').value = 'nacional';
            document.getElementById('feriado-estado').value = '';
            document.getElementById('feriado-cidade').value = '';
            toggleFeriadoLoc();
            document.getElementById('feriado-form-title').innerText = 'Novo Feriado';
            document.getElementById('feriado-form-modal').classList.remove('hidden');
        }

        function editarFeriado(f) {
            document.getElementById('feriado-id').value = f.id;
            document.getElementById('feriado-nome').value = f.nome;
            document.getElementById('feriado-data').value = f.data_feriado;
            document.getElementById('feriado-tipo').value = f.tipo;
            
            if(f.tipo === 'estadual') {
                document.getElementById('feriado-estado').value = f.localidade || '';
                document.getElementById('feriado-cidade').value = '';
            } else if (f.tipo === 'municipal') {
                document.getElementById('feriado-cidade').value = f.localidade || '';
                document.getElementById('feriado-estado').value = '';
            } else {
                document.getElementById('feriado-estado').value = '';
                document.getElementById('feriado-cidade').value = '';
            }

            toggleFeriadoLoc();
            document.getElementById('feriado-form-title').innerText = 'Editar Feriado';
            document.getElementById('feriado-form-modal').classList.remove('hidden');
        }

        async function salvarFeriado() {
            const form = document.getElementById('feriado-form');
            if(!form.checkValidity()) { form.reportValidity(); return; }
            
            const formData = new FormData();
            formData.append('id', document.getElementById('feriado-id').value);
            formData.append('nome', document.getElementById('feriado-nome').value);
            formData.append('data_feriado', document.getElementById('feriado-data').value);
            
            const tipo = document.getElementById('feriado-tipo').value;
            formData.append('tipo', tipo);
            
            if (tipo === 'estadual') {
                formData.append('localidade', document.getElementById('feriado-estado').value);
            } else if (tipo === 'municipal') {
                formData.append('localidade', document.getElementById('feriado-cidade').value);
            } else {
                formData.append('localidade', '');
            }
            
            const res = await fetch('api_escala.php?action=salvar_feriado', { method: 'POST', body: formData });
            const result = await res.json();
            if(result.sucesso) {
                document.getElementById('feriado-form-modal').classList.add('hidden');
                await carregarUsuariosEFuncionariosEFeriados();
                feriadosFixo = listaFeriadosCache.filter(f => f.tipo === 'nacional').map(f => f.data_feriado.substring(5, 10));
            } else { alert(result.erro); }
        }

        async function excluirFeriado(id) {
            if(!confirm("Certeza que deseja excluir este feriado?")) return;
            await fetch('api_escala.php?action=excluir_feriado', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({id}) });
            await carregarUsuariosEFuncionariosEFeriados();
            feriadosFixo = listaFeriadosCache.filter(f => f.tipo === 'nacional').map(f => f.data_feriado.substring(5, 10));
        }

        function aplicarEscalaTexto(val) {
            document.documentElement.style.setProperty('--tbl-scale', val);
            document.getElementById('label-scale-val').innerText = parseFloat(val).toFixed(1) + 'x';
            configuracoes.escala_texto = val;
        }

        function previewLogoBase64(e) {
            const file = e.target.files[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onload = (ev) => {
                configuracoes.logo_pdf_base64 = ev.target.result;
                const img = document.getElementById('cfg-logo-preview');
                img.src = configuracoes.logo_pdf_base64;
                img.classList.remove('hidden');
                document.getElementById('cfg-logo-placeholder').classList.add('hidden');
            };
            reader.readAsDataURL(file);
        }

        async function carregarConfiguracoes() { 
            try { 
                const res = await fetch('api_escala.php?action=get_config'); const result = await res.json(); 
                if(result.dados) { 
                    if(result.dados.sobreaviso_horas_uteis) configuracoes.horas_uteis = parseFloat(result.dados.sobreaviso_horas_uteis); 
                    if(result.dados.sobreaviso_horas_fds) configuracoes.horas_fds = parseFloat(result.dados.sobreaviso_horas_fds); 
                    if(result.dados.escala_texto) {
                        configuracoes.escala_texto = parseFloat(result.dados.escala_texto);
                        aplicarEscalaTexto(configuracoes.escala_texto);
                    }
                    if(result.dados.logo_pdf_base64) configuracoes.logo_pdf_base64 = result.dados.logo_pdf_base64;
                } 
            } catch(e) {} 
        }

        async function salvarConfiguracoes() { 
            const hu = document.getElementById('cfg-horas-uteis').value; const hf = document.getElementById('cfg-horas-fds').value; 
            const reqBody = {horas_uteis: hu, horas_fds: hf, escala_texto: configuracoes.escala_texto};
            if (configuracoes.logo_pdf_base64) reqBody.logo_pdf_base64 = configuracoes.logo_pdf_base64;

            await fetch('api_escala.php?action=set_config', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(reqBody) }); 
            configuracoes.horas_uteis = parseFloat(hu); configuracoes.horas_fds = parseFloat(hf); 
            alert("Opções globais atualizadas!"); renderAll(); 
        }

        function abrirConfiguracoes() { 
            document.getElementById('cfg-horas-uteis').value = configuracoes.horas_uteis; 
            document.getElementById('cfg-horas-fds').value = configuracoes.horas_fds; 
            document.getElementById('cfg-escala-texto').value = configuracoes.escala_texto || 1.0;
            document.getElementById('label-scale-val').innerText = parseFloat(configuracoes.escala_texto || 1).toFixed(1) + 'x';
            
            if(configuracoes.logo_pdf_base64) {
                document.getElementById('cfg-logo-preview').src = configuracoes.logo_pdf_base64;
                document.getElementById('cfg-logo-preview').classList.remove('hidden');
                document.getElementById('cfg-logo-placeholder').classList.add('hidden');
            }

            document.getElementById('config-modal').classList.remove('hidden'); 
            carregarUsuariosEFuncionariosEFeriados();
        }
        function fecharConfiguracoes() { document.getElementById('config-modal').classList.add('hidden'); }

        function showFilterSuggestions() { 
            const input = document.getElementById('filtro-input-global'); 
            const container = document.getElementById('sugestoes-filtro'); 
            const val = input.value.trim().toLowerCase(); 
            
            if(!val) { container.classList.add('hidden'); return; } 
            
            let html = ''; 
            let funcFiltradosBase = tecnicosDB; 
            if (currentView !== 'dashboard' && currentAccess !== 'mestre' && setoresPermitidosLogin) { 
                const arrSetores = setoresPermitidosLogin.split(',').map(s => s.trim().toUpperCase()); 
                funcFiltradosBase = tecnicosDB.filter(f => arrSetores.includes(f.setor.toUpperCase())); 
            } 
            
            const basesUnicas = [...new Set(funcFiltradosBase.map(l => l.local_base).filter(b => b))]; 
            const basesFiltradas = basesUnicas.filter(b => b.toLowerCase().includes(val) && !activeFilters.bases.includes(b)); 
            if(basesFiltradas.length > 0) { 
                html += `<div class="bg-amber-50 text-[9px] font-black text-amber-700 uppercase px-3 py-1 border-b border-amber-100">Base Local</div>`; 
                basesFiltradas.forEach(b => html += `<div onclick="addFilter('bases', '${b}')" class="px-3 py-2 hover:bg-slate-50 cursor-pointer text-xs flex items-center gap-2"><i data-lucide="building-2" class="w-3 h-3"></i> ${b}</div>`); 
            } 
            
            const areasUnicas = [...new Set(funcFiltradosBase.map(l => l.setor).filter(a => a))]; 
            const areasFiltradas = areasUnicas.filter(a => a.toLowerCase().includes(val) && !activeFilters.areas.includes(a)); 
            if(areasFiltradas.length > 0) { 
                html += `<div class="bg-purple-50 text-[9px] font-black text-purple-700 uppercase px-3 py-1 border-b border-purple-100">Áreas / Setores</div>`; 
                areasFiltradas.forEach(a => html += `<div onclick="addFilter('areas', '${a}')" class="px-3 py-2 hover:bg-slate-50 cursor-pointer text-xs flex items-center gap-2"><i data-lucide="layout-template" class="w-3 h-3"></i> ${a}</div>`); 
            } 
            
            const locsUnicas = [...new Set(funcFiltradosBase.map(l => l.localidade).filter(l => l))]; 
            const locsFiltradas = locsUnicas.filter(l => l.toLowerCase().includes(val) && !activeFilters.localidades.includes(l)); 
            if(locsFiltradas.length > 0) { 
                html += `<div class="bg-blue-50 text-[9px] font-black text-blue-700 uppercase px-3 py-1 border-b border-blue-100">Localidades</div>`; 
                locsFiltradas.forEach(l => html += `<div onclick="addFilter('localidades', '${l}')" class="px-3 py-2 hover:bg-slate-50 cursor-pointer text-xs flex items-center gap-2"><i data-lucide="map-pin" class="w-3 h-3"></i> ${l}</div>`); 
            } 

            const supervisoresUnicos = [...new Set(funcFiltradosBase.map(l => l.supervisor).filter(s => s))];
            const supervisoresFiltrados = supervisoresUnicos.filter(s => s.toLowerCase().includes(val) && !activeFilters.supervisores.includes(s));
            if(supervisoresFiltrados.length > 0) {
                html += `<div class="bg-orange-50 text-[9px] font-black text-orange-700 uppercase px-3 py-1 border-b border-orange-100">Supervisores</div>`;
                supervisoresFiltrados.forEach(s => html += `<div onclick="addFilter('supervisores', '${s}')" class="px-3 py-2 hover:bg-slate-50 cursor-pointer text-xs flex items-center gap-2"><i data-lucide="briefcase" class="w-3 h-3"></i> ${s}</div>`);
            }

            const siglasUnicas = [...new Set(funcFiltradosBase.map(l => l.sigla).filter(s => s))];
            const siglasFiltradas = siglasUnicas.filter(s => s.toLowerCase().includes(val) && !activeFilters.siglas.includes(s));
            if(siglasFiltradas.length > 0) {
                html += `<div class="bg-indigo-50 text-[9px] font-black text-indigo-700 uppercase px-3 py-1 border-b border-indigo-100">Siglas</div>`;
                siglasFiltradas.forEach(s => html += `<div onclick="addFilter('siglas', '${s}')" class="px-3 py-2 hover:bg-slate-50 cursor-pointer text-xs flex items-center gap-2"><i data-lucide="tag" class="w-3 h-3"></i> ${s}</div>`);
            }
            
            const pessoasFiltradas = funcFiltradosBase.map(t => t.nome).filter(p => p && p.toLowerCase().includes(val) && !activeFilters.pessoas.includes(p)); 
            if(pessoasFiltradas.length > 0) { 
                html += `<div class="bg-emerald-50 text-[9px] font-black text-emerald-700 uppercase px-3 py-1 border-b border-emerald-100">Colaboradores</div>`; 
                pessoasFiltradas.forEach(p => html += `<div onclick="addFilter('pessoas', '${p}')" class="px-3 py-2 hover:bg-slate-50 cursor-pointer text-xs flex items-center gap-2"><i data-lucide="user" class="w-3 h-3"></i> ${p}</div>`); 
            } 
            
            if(html === '') html = `<div class="px-4 py-3 text-xs text-slate-400 text-center font-medium">Nenhuma opção encontrada</div>`; 
            container.innerHTML = html; 
            container.classList.remove('hidden'); 
            lucide.createIcons(); 
        }

        function addFilter(type, value) { if(!activeFilters[type].includes(value)) activeFilters[type].push(value); document.getElementById('filtro-input-global').value = ''; document.getElementById('sugestoes-filtro').classList.add('hidden'); renderActiveChips(); renderAll(); }
        function removeFilter(type, value) { activeFilters[type] = activeFilters[type].filter(item => item !== value); renderActiveChips(); renderAll(); }
        function limparFiltros() { activeFilters = { bases: [], areas: [], localidades: [], pessoas: [], siglas: [], supervisores: [] }; document.getElementById('filtro-input-global').value = ''; renderActiveChips(); renderAll(); }
        function filtrarMinhaEscala() { if(!loggedUserNomeResumido) { alert("Seu login não corresponde a um técnico cadastrado na base."); return; } activeFilters = { bases: [], areas: [], localidades: [], pessoas: [loggedUserNomeResumido], siglas: [], supervisores: [] }; renderActiveChips(); renderAll(); }
        
        function renderActiveChips() { 
            const wrapper = document.getElementById('active-filters-wrapper');
            const container = document.getElementById('active-filters-container'); 
            let html = ''; 
            const createChip = (type, val, colors) => `<div class="flex items-center gap-1 px-2.5 py-0.5 rounded-full ${colors} text-[9px] font-extrabold shadow-sm border border-black/5 whitespace-nowrap"><span>${val}</span><div class="p-0.5 hover:bg-black/10 rounded-full cursor-pointer ml-1 transition-colors" onclick="removeFilter('${type}', '${val}')"><i data-lucide="x" class="w-3 h-3"></i></div></div>`; 
            
            activeFilters.bases.forEach(b => html += createChip('bases', b, 'bg-amber-100 text-amber-800')); 
            activeFilters.areas.forEach(a => html += createChip('areas', a, 'bg-purple-100 text-purple-800')); 
            activeFilters.localidades.forEach(l => html += createChip('localidades', l, 'bg-blue-100 text-blue-800')); 
            activeFilters.pessoas.forEach(p => html += createChip('pessoas', p, 'bg-emerald-100 text-emerald-800')); 
            activeFilters.siglas.forEach(s => html += createChip('siglas', s, 'bg-indigo-100 text-indigo-800')); 
            activeFilters.supervisores.forEach(sup => html += createChip('supervisores', sup, 'bg-orange-100 text-orange-800')); 
            
            if(html === '') { wrapper.classList.remove('flex'); wrapper.classList.add('hidden'); } 
            else { wrapper.classList.remove('hidden'); wrapper.classList.add('flex'); }
            container.innerHTML = html; 
            lucide.createIcons(); 
            setTimeout(adjustMainHeight, 10);
        }

        function getOrderedAndFilteredTecnicos() { 
            let matched = []; 
            let baseFuncData = tecnicosDB; 
            
            if (currentView !== 'dashboard' && currentAccess !== 'mestre' && setoresPermitidosLogin) { 
                const arrSetores = setoresPermitidosLogin.split(',').map(s => s.trim().toUpperCase()); 
                baseFuncData = tecnicosDB.filter(f => arrSetores.includes(f.setor.toUpperCase())); 
            } 
            
            baseFuncData.forEach((func) => { 
                let matchBase = activeFilters.bases.length === 0 || activeFilters.bases.includes(func.local_base); 
                let matchPessoa = activeFilters.pessoas.length === 0 || activeFilters.pessoas.includes(func.nome); 
                let matchLoc = activeFilters.localidades.length === 0 || activeFilters.localidades.includes(func.localidade); 
                let matchArea = activeFilters.areas.length === 0 || activeFilters.areas.includes(func.setor); 
                let matchSigla = activeFilters.siglas.length === 0 || activeFilters.siglas.includes(func.sigla);
                let matchSupervisor = activeFilters.supervisores.length === 0 || activeFilters.supervisores.includes(func.supervisor);

                if(matchBase && matchPessoa && matchLoc && matchArea && matchSigla && matchSupervisor) {
                    matched.push(func);
                } 
            }); 

            matched.forEach(f => {
                let setorStr = (f.setor || "").toUpperCase().replace('Ê', 'E');
                let locStr = (f.localidade || "").toUpperCase();

                if (setorStr === 'RECORRENCIA') f._rank_setor = 1;
                else if (setorStr === 'VIDEOWALL') f._rank_setor = 2;
                else if (setorStr === 'LTE') f._rank_setor = 3;
                else if (setorStr === 'ELE/REF' && locStr === 'SALVADOR') f._rank_setor = 4;
                else if (setorStr === 'ELE/REF' && locStr !== 'SALVADOR') f._rank_setor = 5;
                else if (setorStr === 'TI') f._rank_setor = 6;
                else if (setorStr === 'ADM') f._rank_setor = 7;
                else f._rank_setor = 8;
                
                let grupoSigla = f.sigla || "";
                if (setorStr === 'ELE/REF') {
                    for (let p of PARES_SIGLA) {
                        if (p.includes(f.sigla)) {
                            grupoSigla = p[0] + '/' + p[1] + '_' + locStr; 
                            break;
                        }
                    }
                }
                
                f._grupo_ordenacao = setorStr + "_" + grupoSigla;
            });

            matched.sort((a, b) => {
                if (a._rank_setor !== b._rank_setor) return a._rank_setor - b._rank_setor;
                
                let grupoA = a._grupo_ordenacao || "";
                let grupoB = b._grupo_ordenacao || "";
                if (grupoA !== grupoB) return grupoA.localeCompare(grupoB);
                
                let nomeA = (a.nome || "").toUpperCase();
                let nomeB = (b.nome || "").toUpperCase();
                return nomeA.localeCompare(nomeB);
            });

            let nomesFinal = matched.map(f => f.nome); 
            nomesFinal.sort = function() { return this; };
            
            return nomesFinal;
        }

        document.addEventListener('click', (e) => { const inputGroup = document.getElementById('filtro-input-global'); if(inputGroup && !inputGroup.parentElement.parentElement.contains(e.target)) document.getElementById('sugestoes-filtro').classList.add('hidden'); const menu = document.getElementById('cell-context-menu'); if(menu && !menu.classList.contains('hidden') && !menu.contains(e.target)) menu.classList.add('hidden'); ['view', 'edit'].forEach(type => { const sugg = document.getElementById(`sugestoes-setor-${type}`); const inputWrapper = document.getElementById(`container-chips-${type}`); if (sugg && !sugg.classList.contains('hidden') && !sugg.contains(e.target) && !inputWrapper.contains(e.target)) { sugg.classList.add('hidden'); } }); });

        async function recarregarDadosBanco() { try { const response = await fetch('api_escala.php'); const result = await response.json(); if (result.sucesso && result.dados) plantaoMensalDados = result.dados; } catch (e) {} liberarInterface(); }
        
        function liberarInterface() { document.getElementById('loading-screen').classList.add('hidden'); document.getElementById('main-container').classList.remove('hidden'); document.getElementById('main-container').classList.add('flex'); switchView(currentView); }
        
        function confirmarExclusaoMesGlobal() {
            const startAno = currentMonthDate.getFullYear();
            const startMes = currentMonthDate.getMonth();
            let endAno = startAno;
            let endMes = startMes + 1;
            if (endMes > 11) { endMes = 0; endAno++; }
            
            const nomeCompetencia = nomesMeses[endMes];
            const anoCompetencia = endAno;
            
            const startDateStr = `${startAno}-${String(startMes+1).padStart(2,'0')}-15`;
            const endDateStr = `${endAno}-${String(endMes+1).padStart(2,'0')}-14`;

            if (!confirm(`ATENÇÃO: Apagar os registros APENAS da tela exibida (${nomeCompetencia}/${anoCompetencia})?\n\nPasso 1: Um arquivo de segurança (BACKUP) será baixado agora.\Deseja continuar?`)) return;
            exportarCSVMesAtual(startDateStr, endDateStr, nomeCompetencia, anoCompetencia);
            
            setTimeout(async () => {
                if (!confirm(`Passo 2: O download de backup foi iniciado.\n\nVocê confirma a EXCLUSÃO PERMANENTE dos dados de ${nomeCompetencia}/${anoCompetencia} no sistema?`)) return;
                setProgress(0, `Apagando ${nomeCompetencia} / ${anoCompetencia}...`);
                try { 
                    const itemsToDelete = [];
                    for (const [nome, datas] of Object.entries(plantaoMensalDados)) {
                        for (const [dataStr, dado] of Object.entries(datas)) {
                            if (dataStr >= startDateStr && dataStr <= endDateStr) itemsToDelete.push({ nome, data: dataStr, status: null, usuario_logado: currentLogin });
                        }
                    }
                    const total = itemsToDelete.length; if(total === 0) { alert("Nenhum dado encontrado."); liberarInterface(); return; }
                    const loteSize = 10; let processados = 0;
                    for (let i = 0; i < total; i += loteSize) {
                        const lote = itemsToDelete.slice(i, i + loteSize);
                        await Promise.all(lote.map(item => fetch('api_escala.php', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(item) })));
                        processados += lote.length; setProgress((processados / total) * 100);
                    }
                    await recarregarDadosBanco(); 
                } catch(e) { alert("Erro na rede."); liberarInterface(); }
            }, 1500);
        }

        // ====== ATUALIZADO: Exportação corrigida para Ordenação Real ======
        function exportarCSVMesAtual(startDateStr, endDateStr, nomeCompetencia, anoCompetencia) { 
            let csvHeader = "data:text/csv;charset=utf-8,\uFEFFNome;Localidade;Setor;Data Inicio;Hora Inicio;Data Fim;Hora Fim;Total_Horas_Calculadas\n"; 
            let linhasExportacao = [];

            const tecnicosVisiveis = getOrderedAndFilteredTecnicos();

            for (const [nome, datas] of Object.entries(plantaoMensalDados)) { 
                if (!tecnicosVisiveis.includes(nome)) continue;

                const func = tecnicosDB.find(t => t.nome === nome); 
                const loc = func ? func.localidade : ''; 
                const setor = func ? func.setor : ''; 
                
                for (const [dataStr, dado] of Object.entries(datas)) { 
                    const isPlantaoObj = typeof dado === 'object' ? dado.status : dado;
                    if (dataStr >= startDateStr && dataStr <= endDateStr && isPlantaoObj && isPlantaoObj.startsWith('plantao')) { 
                        
                        let fracoes = dado.fracoes || [];
                        fracoes.forEach(f => {
                            linhasExportacao.push({
                                nome, loc, setor, 
                                dtStrOrig: dataStr,
                                hrOrig: f.hora_inicio,
                                dataInicioRaw: f.data_inicio, // Guardando a data real do fracionamento
                                dataInicio: f.data_inicio.split('-').reverse().join('/'),
                                horaInicio: f.hora_inicio.substring(0, 5),
                                dataFim: f.data_fim.split('-').reverse().join('/'),
                                horaFim: f.hora_fim.substring(0, 5),
                                horas: f.horas
                            });
                        });
                    } 
                } 
            } 

            // Ordenando usando Data e Hora reais, não apenas a hora do começo do plantão original
            linhasExportacao.sort((a, b) => {
                if (a.nome !== b.nome) return a.nome.localeCompare(b.nome);
                if (a.dataInicioRaw !== b.dataInicioRaw) return a.dataInicioRaw.localeCompare(b.dataInicioRaw);
                return a.hrOrig.localeCompare(b.hrOrig);
            });

            // Usa o ExcelJS para criar um XLSX real com estilos (cores, bordas, largura)
        (async () => {
            const workbook = new ExcelJS.Workbook();
            const worksheet = workbook.addWorksheet(`SOBREAVISO_${nomeCompetencia}_${anoCompetencia}`);

            // Define as colunas e a largura ideal (ajuste do tamanho para leitura)
            worksheet.columns = [
                { header: 'Nome', key: 'nome', width: 22 },
                { header: 'Localidade', key: 'loc', width: 14 },
                { header: 'Setor', key: 'setor', width: 16 },
                { header: 'Data Inicio', key: 'dataInicio', width: 14 },
                { header: 'Hora Inicio', key: 'horaInicio', width: 13 },
                { header: 'Data Fim', key: 'dataFim', width: 14 },
                { header: 'Hora Fim', key: 'horaFim', width: 13 },
                { header: 'Total Horas', key: 'horas', width: 16 }
            ];

            // Estilo Profissional do Cabeçalho (Fundo Escuro, Texto Branco)
            worksheet.getRow(1).eachCell((cell) => {
                cell.font = { bold: true, color: { argb: 'FFFFFFFF' } };
                cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF1E3A8A' } };
                cell.border = { top: {style:'thin'}, left: {style:'thin'}, bottom: {style:'thin'}, right: {style:'thin'} };
                cell.alignment = { vertical: 'middle', horizontal: 'center' };
            });

            let ultimaData = '';
            let isGray = false;

            linhasExportacao.forEach(r => {
                if (r.dataInicio !== ultimaData) {
                    isGray = !isGray;
                    ultimaData = r.dataInicio;
                }
                
                const row = worksheet.addRow({
                    nome: r.nome,
                    loc: r.loc,
                    setor: r.setor,
                    dataInicio: r.dataInicio,
                    horaInicio: r.horaInicio,
                    dataFim: r.dataFim,
                    horaFim: r.horaFim,
                    horas: parseFloat(r.horas)
                });

                // Aplica zebrado (somente nas colunas e linhas onde tem dados)
                if (isGray) {
                    row.eachCell((cell) => {
                        cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFE2E8F0' } }; 
                    });
                }
                
                // Alinhamento e bordas leves em todas as células de dados
                row.eachCell((cell, colNumber) => {
                    cell.border = { top: {style:'thin', color:{argb:'FFCBD5E1'}}, left: {style:'thin', color:{argb:'FFCBD5E1'}}, bottom: {style:'thin', color:{argb:'FFCBD5E1'}}, right: {style:'thin', color:{argb:'FFCBD5E1'}} };
                    if (colNumber >= 4) {
                        cell.alignment = { vertical: 'middle', horizontal: 'center' };
                    } else {
                        cell.alignment = { vertical: 'middle', horizontal: 'left' };
                    }
                });
            });

            // Gerar o arquivo XLSX final
            const buffer = await workbook.xlsx.writeBuffer();
            const blob = new Blob([buffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement("a");
            link.setAttribute("href", url);
            link.setAttribute("download", `SOBREAVISO_${nomeCompetencia}_${anoCompetencia}.xlsx`);
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            setTimeout(() => URL.revokeObjectURL(url), 100);
        })(); 
        }

        function exportarCSV() { 
            const startAno = currentMonthDate.getFullYear();
            const startMes = currentMonthDate.getMonth();
            let endAno = startAno;
            let endMes = startMes + 1;
            if (endMes > 11) { endMes = 0; endAno++; }
            
            const nomeCompetencia = nomesMeses[endMes];
            const anoCompetencia = endAno;
            
            const startDateStr = `${startAno}-${String(startMes+1).padStart(2,'0')}-15`;
            const endDateStr = `${endAno}-${String(endMes+1).padStart(2,'0')}-14`;
            
            exportarCSVMesAtual(startDateStr, endDateStr, nomeCompetencia, anoCompetencia);
        }

        async function importarCSV(event) {
            const file = event.target.files[0]; if (!file) return;
            setProgress(0, "Lendo arquivo...");

            const mapParaSalvar = new Map();

            const processExcelJS = async (file) => {
                const arrayBuffer = await file.arrayBuffer();
                const workbook = new ExcelJS.Workbook();
                await workbook.xlsx.load(arrayBuffer);
                const worksheet = workbook.worksheets[0];
                let isBackup = false;

                worksheet.eachRow((row, rowNumber) => {
                    if (rowNumber === 1) {
                        row.eachCell((cell) => {
                            let val = cell.text || (cell.value ? cell.value.toString() : '');
                            if (val.includes("Total_Horas_Calculadas") || val.includes("Total Horas")) isBackup = true;
                        });
                        return;
                    }

                    const nomeRaw = row.getCell(1).text || (row.getCell(1).value ? row.getCell(1).value.toString() : '');
                    let dtInicioRaw = row.getCell(4).text || (row.getCell(4).value ? row.getCell(4).value.toString() : '');
                    let horaInicioRaw = row.getCell(5).text || (row.getCell(5).value ? row.getCell(5).value.toString() : '');

                    if (!nomeRaw) return;

                    if (isBackup) {
                        if (!horaInicioRaw || horaInicioRaw.trim().substring(0,5) !== "22:00") return;
                        dtInicioRaw = dtInicioRaw.trim().replace(/\//g, '-');
                        if (dtInicioRaw.length === 10 && dtInicioRaw.indexOf('-') === 2) {
                            dtInicioRaw = `${dtInicioRaw.substring(6,10)}-${dtInicioRaw.substring(3,5)}-${dtInicioRaw.substring(0,2)}`;
                        } else if (row.getCell(4).type === ExcelJS.ValueType.Date) {
                            const dtObj = row.getCell(4).value;
                            dtInicioRaw = dtObj.toISOString().split('T')[0];
                        }
                    } else {
                        horaInicioRaw = row.getCell(6).text || '';
                        if (!horaInicioRaw || horaInicioRaw.trim().substring(0,5) !== "22:00") return;
                        dtInicioRaw = row.getCell(5).text || '';
                        if(dtInicioRaw.length === 8 && !dtInicioRaw.includes('-')) {
                            dtInicioRaw = `${dtInicioRaw.substring(0,4)}-${dtInicioRaw.substring(4,6)}-${dtInicioRaw.substring(6,8)}`;
                        }
                    }

                    if (nomeRaw && dtInicioRaw && dtInicioRaw.includes('-')) {
                        let tMatched = tecnicosDB.find(t => t.nome === nomeRaw || nomeRaw.startsWith(t.nome));
                        let nomeSalvar = tMatched ? tMatched.nome : nomeRaw;
                        let key = nomeSalvar + '_' + dtInicioRaw;
                        mapParaSalvar.set(key, { nome: nomeSalvar, data: dtInicioRaw, status: 'plantao', usuario_logado: currentLogin });
                    }
                });
            };

            const processLegacyCSV = (file) => {
                return new Promise((resolve) => {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const rows = e.target.result.split("\n");
                        let isBackup = false; if(rows[0] && rows[0].includes("Total_Horas_Calculadas")) isBackup = true;
                        for (let i = 1; i < rows.length; i++) {
                            if(rows[i].trim() === '') continue;
                            const cols = rows[i].split(";");
                            if (cols.length >= 4) {
                                const nomeRaw = cols[0].trim();
                                let dtInicioRaw = ""; let horaInicioRaw = "";
                                if (isBackup) {
                                    horaInicioRaw = cols[4] ? cols[4].trim() : "";
                                    if (horaInicioRaw.substring(0,5) !== "22:00") continue;
                                    dtInicioRaw = cols[3] ? cols[3].trim().replace(/\//g, '-') : "";
                                    if (dtInicioRaw.length === 10 && dtInicioRaw.indexOf('-') === 2) {
                                        dtInicioRaw = `${dtInicioRaw.substring(6,10)}-${dtInicioRaw.substring(3,5)}-${dtInicioRaw.substring(0,2)}`;
                                    }
                                } else {
                                    horaInicioRaw = cols[5] ? cols[5].trim() : "";
                                    if (horaInicioRaw.substring(0,5) !== "22:00") continue;
                                    dtInicioRaw = cols[4] ? cols[4].trim() : "";
                                    if(dtInicioRaw.length === 8 && !dtInicioRaw.includes('-')) {
                                        dtInicioRaw = `${dtInicioRaw.substring(0,4)}-${dtInicioRaw.substring(4,6)}-${dtInicioRaw.substring(6,8)}`;
                                    }
                                }
                                if(nomeRaw && dtInicioRaw && dtInicioRaw.includes('-')) {
                                    let tMatched = tecnicosDB.find(t => t.nome === nomeRaw || nomeRaw.startsWith(t.nome));
                                    let nomeSalvar = tMatched ? tMatched.nome : nomeRaw;
                                    mapParaSalvar.set(nomeSalvar + '_' + dtInicioRaw, { nome: nomeSalvar, data: dtInicioRaw, status: 'plantao', usuario_logado: currentLogin });
                                }
                            }
                        }
                        resolve();
                    };
                    reader.readAsText(file);
                });
            };

            if (file.name.toLowerCase().endsWith('.xlsx') || file.type.includes('spreadsheetml')) {
                await processExcelJS(file);
            } else {
                await processLegacyCSV(file);
            }

            const itensParaSalvar = Array.from(mapParaSalvar.values());
            const total = itensParaSalvar.length;
            if(total === 0) { alert("Nenhum dado válido com horário de início 22:00 encontrado."); liberarInterface(); return; }
            setProgress(0, `Salvando ${total} registros no banco...`);
            const loteSize = 10; let processados = 0;
            for (let i = 0; i < total; i += loteSize) {
                const lote = itensParaSalvar.slice(i, i + loteSize);
                const rsImp = await Promise.all(lote.map(item => fetch('api_escala.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(item) }).then(r => r.json()).catch(() => ({}))));
                rsImp.forEach((r, k) => { if (r && r.regra === 'limite_24h') (window.__bloqImp = window.__bloqImp || []).push(lote[k].nome + ' ' + String(lote[k].data).split('-').reverse().join('/')); });
                processados += lote.length; setProgress((processados / total) * 100);
            }
            document.getElementById('file-import-escala').value = ""; await recarregarDadosBanco();
            if (window.__bloqImp && window.__bloqImp.length) { alert('LIMITE DE 24H DE SOBREAVISO (CLT)\n\nEstes dias não foram importados porque deixariam o colaborador mais de 24h seguidas de sobreaviso:\n\n' + window.__bloqImp.join(', ')); window.__bloqImp = []; }
        }

        function toggleBulkAnexo() { const status = document.getElementById('bulk-status').value; const container = document.getElementById('bulk-anexo-container'); if (status === 'atestado') container.classList.remove('hidden'); else { container.classList.add('hidden'); document.getElementById('bulk-anexo').value = ''; } }
        
        function abrirModalAcaoEmMassa() { 
            const dList = getOrderedAndFilteredTecnicos(); 
            const datalist = document.getElementById('bulk-funcionarios-list'); 
            datalist.innerHTML = dList.map(nome => `<option value="${nome}">`).join(''); 
            document.getElementById('bulk-nome').value = ''; 
            
            const startAno = currentMonthDate.getFullYear();
            const startMes = currentMonthDate.getMonth();
            let endAno = startAno;
            let endMes = startMes + 1;
            if (endMes > 11) { endMes = 0; endAno++; }

            const strDataInicio = `${startAno}-${String(startMes+1).padStart(2,'0')}-15`;
            const strDataFim = `${endAno}-${String(endMes+1).padStart(2,'0')}-14`;

            document.getElementById('bulk-inicio').value = strDataInicio; 
            document.getElementById('bulk-fim').value = strDataFim; 
            
            document.getElementById('bulk-status').value = 'plantao'; 
            toggleBulkAnexo(); 
            document.getElementById('bulk-modal').classList.remove('hidden'); 
        }
        
        function fecharBulkModal() { document.getElementById('bulk-modal').classList.add('hidden'); }
        
        function checarConflitoPlantaoJS(nome, dataStr, statusNovo) {
            if (!statusNovo || !statusNovo.startsWith('plantao')) return null;
            const myFunc = tecnicosDB.find(t => t.nome === nome);
            if (!myFunc) return null;

            let escalados = [];
            let countEleRefSsa = 0;
            
            for (const otherNome in plantaoMensalDados) {
                if (otherNome === nome) continue;
                const otherCell = plantaoMensalDados[otherNome][dataStr];
                const otherStatus = (typeof otherCell === 'string') ? otherCell : (otherCell ? otherCell.status : null);
                
                if (otherStatus && otherStatus.startsWith('plantao')) {
                    const otherFunc = tecnicosDB.find(t => t.nome === otherNome);
                    if (otherFunc) {
                        escalados.push({nome: otherNome, func: otherFunc, status: otherStatus});
                        if (otherFunc.setor === 'ELE/REF' && otherFunc.localidade === 'SALVADOR') {
                            countEleRefSsa++;
                        }
                    }
                }
            }

            if (escalados.length === 0) return null;

            const dtObj = new Date(dataStr + 'T00:00:00');
            const diaSemana = dtObj.getDay(); 
            const isFdsSexta = (diaSemana === 0 || diaSemana === 5 || diaSemana === 6);

            let meuPar = [myFunc.sigla];
            for (let p of PARES_SIGLA) {
                if (p.includes(myFunc.sigla)) { meuPar = p; break; }
            }

            for (let escObj of escalados) {
                const esc = escObj.func;
                if (myFunc.setor === 'RECORRENCIA' && esc.setor === 'RECORRENCIA') return esc.nome;
                if (myFunc.setor === 'VIDEOWALL' && esc.setor === 'VIDEOWALL') return esc.nome;

                if (myFunc.setor === 'ELE/REF') {
                    if (myFunc.localidade === 'SALVADOR') {
                        if (esc.setor === 'ELE/REF' && esc.localidade === 'SALVADOR') {
                            if (isFdsSexta && countEleRefSsa >= 2) return "Limite de 2 técnicos atingido";
                            if (!isFdsSexta) return esc.nome;
                        }
                    } else {
                        if (esc.setor === 'ELE/REF' && meuPar.includes(esc.sigla)) {
                            return esc.nome;
                        }
                    }
                } else if (myFunc.setor === esc.setor && myFunc.localidade === esc.localidade && myFunc.setor !== 'ELE/REF') {
                    return esc.nome;
                }
            }
            return null;
        }

        async function executarBulkAction(acaoStr) { 
            const form = document.getElementById('form-bulk'); 
            if (!form.checkValidity()) { form.reportValidity(); return; } 
            
            const inputNome = document.getElementById('bulk-nome').value; 
            const statusReal = acaoStr === 'limpar' ? 'limpar' : document.getElementById('bulk-status').value; 
            const dtInicio = document.getElementById('bulk-inicio').value;
            const dtFim = document.getElementById('bulk-fim').value;

            const funcCheck = tecnicosDB.find(t => t.nome === inputNome); 
            
            if(funcCheck && currentAccess === 'admin' && setoresGerenciaveisLogin) { 
                const arrGer = setoresGerenciaveisLogin.split(',').map(s=>s.trim().toUpperCase()); 
                if(!arrGer.includes(funcCheck.setor.toUpperCase())) { alert("Sem permissão para GERENCIAR: " + funcCheck.setor); return; } 
            } 
            
            if (statusReal.startsWith('plantao')) {
                let currentDt = new Date(dtInicio + 'T00:00:00');
                const endDt = new Date(dtFim + 'T00:00:00');
                let conflitosEncontrados = [];
                let conflitosSetor = [];
                
                while(currentDt <= endDt) {
                    let dStr = currentDt.toISOString().split('T')[0];
                    let cell = plantaoMensalDados[inputNome]?.[dStr];
                    let currStat = (typeof cell === 'string') ? cell : (cell ? cell.status : null);
                    
                    if (currStat === 'ferias' || currStat === 'atestado' || currStat === 'licenca' || currStat === 'folga_aniversario') {
                        conflitosEncontrados.push(dStr.split('-').reverse().join('/'));
                    }
                    
                    const conflito = checarConflitoPlantaoJS(inputNome, dStr, statusReal);
                    if (conflito) {
                        conflitosSetor.push(`${dStr.split('-').reverse().join('/')} (${conflito})`);
                    }
                    currentDt.setDate(currentDt.getDate() + 1);
                }
                
                if (conflitosEncontrados.length > 0 && (window.REGRAS_SO || {}).bloquear_ausencia !== false) {
                    alert(`⚠️ CONFLITO DE ESCALA!\n\nNão é possível alocar um Plantão para ${inputNome} nas seguintes datas, pois já existem Ausências Severas registradas:\n\n${conflitosEncontrados.join(', ')}\n\nRemova as ausências antes de alocar um plantão em lote.`);
                    return;
                }
                // plantão compartilhado é permitido: o aviso aparece depois de salvar (avisarSobreposicaoPlantaoLote)
            }

            setProgress(null, "Aplicando Lançamento..."); 
            fecharBulkModal(); 
            
            const formData = new FormData(); 
            formData.append('nome', inputNome); 
            formData.append('status', statusReal); 
            formData.append('data_inicio', dtInicio); 
            formData.append('data_fim', dtFim); 
            formData.append('usuario_logado', currentLogin); 
            
            if (acaoStr !== 'limpar') { 
                const fileInput = document.getElementById('bulk-anexo'); 
                if(fileInput.files.length > 0) formData.append('anexo', fileInput.files[0]); 
            } 
            
            let loteFalhou = false; try { const rb = await (await fetch('api_escala.php?action=bulk_update', { method: 'POST', body: formData })).json(); if (rb && rb.sucesso === false) { loteFalhou = true; alert(rb.erro || 'Não foi possível aplicar o lançamento.'); } } catch(e) { } 
            await recarregarDadosBanco();
            if (!loteFalhou && statusReal && statusReal.startsWith('plantao')) avisarSobreposicaoPlantaoLote(inputNome, dtInicio, dtFim); 
        }

        let targetCellData = null;
        
        function openCellMenu(event, nome, dataStr) { 
            if (currentAccess !== 'admin' && currentAccess !== 'mestre' && nome !== loggedUserNomeResumido) return; 
            
            if (currentAccess === 'admin' && setoresGerenciaveisLogin) { 
                const funcCheck = tecnicosDB.find(t => t.nome === nome); 
                if(funcCheck) { 
                    const arrGer = setoresGerenciaveisLogin.split(',').map(s=>s.trim().toUpperCase()); 
                    if(!arrGer.includes(funcCheck.setor.toUpperCase())) { alert("Você apenas visualiza o setor " + funcCheck.setor + "."); return; } 
                } 
            } 
            
            event.stopPropagation(); 
            targetCellData = { nome, dataStr }; 

            const cellObj = plantaoMensalDados[nome]?.[dataStr];
            const isPlantao = cellObj && (typeof cellObj === 'string' ? cellObj.startsWith('plantao') : cellObj.status.startsWith('plantao'));
            const btnAnexo = document.getElementById('btn-view-anexo');
            const btnTrocar = document.getElementById('btn-trocar-plantao');

            if (cellObj && cellObj.anexo) {
                targetCellData.anexoUrl = cellObj.anexo;
                btnAnexo.classList.remove('hidden');
            } else {
                btnAnexo.classList.add('hidden');
            }

            if (isPlantao && (currentAccess === 'admin' || currentAccess === 'mestre')) {
                btnTrocar.classList.remove('hidden');
            } else {
                btnTrocar.classList.add('hidden');
            }

            const menu = document.getElementById('cell-context-menu'); 
            let x = event.clientX; let y = event.clientY; menu.classList.remove('hidden'); 
            const rect = menu.getBoundingClientRect(); if (x + rect.width > window.innerWidth) x -= rect.width; if (y + rect.height > window.innerHeight) y -= rect.height; 
            menu.style.left = `${x}px`; menu.style.top = `${y}px`; 
        }

        function visualizarAnexo() {
            if(targetCellData && targetCellData.anexoUrl) {
                window.open(targetCellData.anexoUrl, '_blank');
            }
            document.getElementById('cell-context-menu').classList.add('hidden');
        }

        function getBlocoContiguo(nome, dataBaseStr) {
            let datasBloco = [dataBaseStr];
            let dateObj = new Date(dataBaseStr + 'T00:00:00');
            
            let current = new Date(dateObj);
            while(true) {
                current.setDate(current.getDate() - 1);
                let checkStr = current.toISOString().split('T')[0];
                let val = plantaoMensalDados[nome]?.[checkStr];
                if (val && (typeof val === 'string' ? val.startsWith('plantao') : val.status.startsWith('plantao'))) {
                    datasBloco.unshift(checkStr);
                } else { break; }
            }
            
            current = new Date(dateObj);
            while(true) {
                current.setDate(current.getDate() + 1);
                let checkStr = current.toISOString().split('T')[0];
                let val = plantaoMensalDados[nome]?.[checkStr];
                if (val && (typeof val === 'string' ? val.startsWith('plantao') : val.status.startsWith('plantao'))) {
                    datasBloco.push(checkStr);
                } else { break; }
            }
            return datasBloco;
        }

        let blocosTrocaDisponiveis = [];
        let blocoAtualAtivo = [];

        function prepararTrocaBloco() {
            if(!targetCellData) return;
            const funcData = tecnicosDB.find(t => t.nome === targetCellData.nome);
            if(!funcData) return;

            blocoAtualAtivo = getBlocoContiguo(targetCellData.nome, targetCellData.dataStr);
            let dtInicio = blocoAtualAtivo[0].split('-');
            let dtFim = blocoAtualAtivo[blocoAtualAtivo.length - 1].split('-');
            
            document.getElementById('troca-desc-atual').innerText = 
                `${targetCellData.nome} (${dtInicio[2]}/${dtInicio[1]} a ${dtFim[2]}/${dtFim[1]})`;

            let meuParSiglas = [funcData.sigla];
            for (let p of PARES_SIGLA) {
                if (p.includes(funcData.sigla)) { meuParSiglas = p; break; }
            }

            const parceiros = tecnicosDB.filter(t => {
                if (t.nome === funcData.nome) return false;
                if (funcData.setor === 'ELE/REF') {
                    if (funcData.localidade === 'SALVADOR') {
                        return t.setor === 'ELE/REF' && t.localidade === 'SALVADOR';
                    } else {
                        return t.setor === 'ELE/REF' && meuParSiglas.includes(t.sigla);
                    }
                } else {
                    return t.setor === funcData.setor && t.localidade === funcData.localidade;
                }
            });
            
            const refDate = new Date(targetCellData.dataStr + 'T00:00:00');
            const todayStrLimit = refDate.toISOString().split('T')[0];
            refDate.setDate(refDate.getDate() + 30);
            const futureStrLimit = refDate.toISOString().split('T')[0];

            blocosTrocaDisponiveis = [];

            parceiros.forEach(p => {
                if(plantaoMensalDados[p.nome]) {
                    let diasPlantaoParceiro = Object.keys(plantaoMensalDados[p.nome])
                        .filter(d => {
                            let val = plantaoMensalDados[p.nome][d];
                            let isP = (typeof val === 'string' ? val.startsWith('plantao') : val.status.startsWith('plantao'));
                            return isP && d >= todayStrLimit && d <= futureStrLimit;
                        })
                        .sort();

                    let blocosDoParceiro = [];
                    let blocoTemp = [];
                    for(let i=0; i<diasPlantaoParceiro.length; i++) {
                        if (blocoTemp.length === 0) {
                            blocoTemp.push(diasPlantaoParceiro[i]);
                        } else {
                            let lastDate = new Date(blocoTemp[blocoTemp.length-1] + 'T00:00:00');
                            lastDate.setDate(lastDate.getDate() + 1);
                            let expectedNext = lastDate.toISOString().split('T')[0];
                            
                            if(diasPlantaoParceiro[i] === expectedNext) {
                                blocoTemp.push(diasPlantaoParceiro[i]);
                            } else {
                                blocosDoParceiro.push([...blocoTemp]);
                                blocoTemp = [diasPlantaoParceiro[i]];
                            }
                        }
                    }
                    if(blocoTemp.length > 0) blocosDoParceiro.push(blocoTemp);

                    blocosDoParceiro.forEach(bloco => {
                        blocosTrocaDisponiveis.push({
                            nome: p.nome,
                            dias: bloco
                        });
                    });
                }
            });

            if (blocosTrocaDisponiveis.length === 0) {
                alert("Nenhum técnico que divide o plantão possui plantões futuros disponíveis (nos 30 dias a partir da data selecionada) para troca.");
                return;
            }

            const select = document.getElementById('troca-novo-bloco');
            select.innerHTML = blocosTrocaDisponiveis.map((b, index) => {
                let dIn = b.dias[0].split('-');
                let dFi = b.dias[b.dias.length-1].split('-');
                return `<option value="${index}">${b.nome} (Plantão: ${dIn[2]}/${dIn[1]} a ${dFi[2]}/${dFi[1]})</option>`;
            }).join('');
            
            document.getElementById('cell-context-menu').classList.add('hidden');
            document.getElementById('troca-modal').classList.remove('hidden');
        }

        async function confirmarTrocaBloco() {
            const indexBlocoNovo = document.getElementById('troca-novo-bloco').value;
            const blocoNovoData = blocosTrocaDisponiveis[indexBlocoNovo];

            const payload = {
                nome_atual: targetCellData.nome,
                dias_atual: blocoAtualAtivo,
                novo_nome: blocoNovoData.nome,
                dias_novo: blocoNovoData.dias,
                usuario_logado: currentLogin 
            };

            setProgress(null, "Efetivando troca de blocos...");
            document.getElementById('troca-modal').classList.add('hidden');

            try {
                const res = await fetch('api_escala.php?action=trocar_plantao_blocos', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const result = await res.json();
                if(result.sucesso) {
                    await recarregarDadosBanco();
                } else {
                    alert("Falha na troca: " + result.erro);
                    liberarInterface();
                }
            } catch(e) {
                alert("Erro na requisição.");
                liberarInterface();
            }
        }

        async function setCellStatus(status) { 
            if(!targetCellData) return; 
            const {nome, dataStr} = targetCellData; 
            
            const cellObj = plantaoMensalDados[nome]?.[dataStr];
            const currentStatus = (typeof cellObj === 'string') ? cellObj : (cellObj ? cellObj.status : null);

            if (status && status.startsWith('plantao')) {
                if ((window.REGRAS_SO || {}).bloquear_ausencia !== false && (currentStatus === 'ferias' || currentStatus === 'atestado' || currentStatus === 'licenca' || currentStatus === 'folga_aniversario')) {
                    alert(`⚠️ CONFLITO DE ESCALA!\n\n${nome} possui um registro de ${currentStatus.toUpperCase()} na data ${dataStr.split('-').reverse().join('/')}.\n\nRemova a ausência (Livre/Limpar) primeiro para poder alocar o plantão.`);
                    return;
                }
                // plantão compartilhado é permitido: o aviso aparece depois de salvar (avisarSobreposicaoPlantao)
            }

            let bodyData = { 
                nome, 
                data: dataStr, 
                status, 
                usuario_logado: currentLogin
            }; 
            
            try { 
                const response = await fetch('api_escala.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(bodyData) }); 
                const result = await response.json(); 
                if (!result.sucesso && result.erro) { 
                    alert(result.erro); 
                } else { 
                    await recarregarDadosBanco();
                    if (status) avisarSobreposicaoPlantao(nome, dataStr); 
                } 
            } catch(e) { } 
            
            document.getElementById('cell-context-menu').classList.add('hidden'); 
            targetCellData = null; 
        }
        
        function getStatusVisuals(statusObj, isWeekendOrHoliday) { 
            let status = (typeof statusObj === 'string') ? statusObj : (statusObj ? statusObj.status : null); 
            if (!status) return null; 
            if (status === 'plantao_rec2' || status === 'plantao_reserva') return isWeekendOrHoliday ? { bg: 'bg-sky-400/60', border: 'border-sky-500/30' } : { bg: 'bg-green-400/60', border: 'border-green-500/30' }; 
            if (status.startsWith('plantao')) return isWeekendOrHoliday ? { bg: 'bg-sky-500', border: 'border-sky-600/50' } : { bg: 'bg-green-500', border: 'border-green-600/50' }; 
            if (status === 'folga' || status === 'folga_aniversario') return { bg: 'bg-yellow-400', border: 'border-yellow-500/50' }; 
            if (status === 'ferias') return { bg: 'bg-orange-500', border: 'border-orange-600/50' }; 
            if (status === 'atestado') return { bg: 'bg-red-500', border: 'border-red-600/50' };
            if (status === 'licenca') return { bg: 'bg-purple-500', border: 'border-purple-600/50' }; 
            return null; 
        }

        function adjustMainHeight() { const headerHeight = document.getElementById('main-header').offsetHeight; const filterBar = document.getElementById('filter-bar-container'); const filterHeight = (filterBar && !filterBar.classList.contains('hidden')) ? filterBar.offsetHeight : 0; document.getElementById('main-container').style.height = `${window.innerHeight - (headerHeight + filterHeight)}px`; }
        
        function switchView(mode) { 
            currentView = mode; 
            const filterBar = document.getElementById('filter-bar-container'); 
            const btnW = document.getElementById('btn-view-week'); 
            const btnM = document.getElementById('btn-view-month'); 
            const btnWM = document.getElementById('btn-view-week-mobile'); 
            const btnMM = document.getElementById('btn-view-month-mobile'); 

            document.getElementById('view-semanal').classList.add('hidden');
            document.getElementById('view-mensal').classList.add('hidden');
            document.getElementById('view-dashboard').classList.add('hidden');

            const classActive = "flex items-center gap-1 px-3 py-1 text-[10px] font-bold rounded bg-blue-600 text-white";
            const classInactive = "flex items-center gap-1 px-3 py-1 text-[10px] font-bold rounded text-blue-300 hover:text-white";
            const classActiveMob = "flex-1 flex items-center justify-center gap-1 py-1.5 text-xs font-bold rounded bg-blue-600 text-white";
            const classInactiveMob = "flex-1 flex items-center justify-center gap-1 py-1.5 text-xs font-bold rounded text-blue-300 hover:text-white";

            if (btnW) btnW.className = classInactive;
            if (btnM) btnM.className = classInactive;
            if (btnWM) btnWM.className = classInactiveMob;
            if (btnMM) btnMM.className = classInactiveMob;

            if (mode === 'week') { 
                document.getElementById('view-semanal').classList.remove('hidden'); 
                if (btnW) btnW.className = classActive; 
                if (btnWM) btnWM.className = classActiveMob; 
                filterBar.classList.add('hidden'); filterBar.classList.remove('flex'); 
            } else if (mode === 'month') { 
                document.getElementById('view-mensal').classList.remove('hidden'); 
                if (btnM) btnM.className = classActive; 
                if (btnMM) btnMM.className = classActiveMob; 
                filterBar.classList.remove('hidden'); filterBar.classList.add('flex'); 
            } else if (mode === 'dashboard') {
                if (currentAccess !== 'admin' && currentAccess !== 'mestre') { switchView('week'); return; }
                document.getElementById('view-dashboard').classList.remove('hidden'); 
                filterBar.classList.remove('hidden'); filterBar.classList.add('flex'); 
            }
            setTimeout(adjustMainHeight, 10); 
            renderAll(); 
        }

        function renderDashboard() {
            if (currentAccess !== 'admin' && currentAccess !== 'mestre') return;
            
            const tbody = document.getElementById('dashboard-table-body');
            const labelPeriodo = document.getElementById('dash-periodo-label');
            
            const dtRef = (typeof currentMonthDate !== 'undefined') ? currentMonthDate : new Date();
            
            const startAno = dtRef.getFullYear();
            const startMes = dtRef.getMonth();
            let endAno = startAno;
            let endMes = startMes + 1;
            if (endMes > 11) { endMes = 0; endAno++; }
            
            const nomeCompetencia = nomesMeses[endMes];
            labelPeriodo.innerText = `Período: ${nomeCompetencia} / ${endAno} (15/${String(startMes+1).padStart(2,'0')} a 14/${String(endMes+1).padStart(2,'0')})`;

            const startDateStr = `${startAno}-${String(startMes+1).padStart(2,'0')}-15`;
            const endDateStr = `${endAno}-${String(endMes+1).padStart(2,'0')}-14`;
            
            const funcList = getOrderedAndFilteredTecnicos();
            let dashboardData = [];
            
            funcList.forEach(nome => { 
                const tecnico = tecnicosDB.find(t => t.nome === nome);
                if (!tecnico) return;
                
                let diasSobreaviso = 0; 
                let horasTotaisDecimais = 0; 
                
                if (plantaoMensalDados[nome]) { 
                    for (const [dataStr, dado] of Object.entries(plantaoMensalDados[nome])) { 
                        let st = typeof dado === 'object' ? dado.status : dado;
                        
                        if (dataStr >= startDateStr && dataStr <= endDateStr && st && st.startsWith('plantao')) { 
                            diasSobreaviso++; 
                            
                            let fracoes = dado.fracoes || [];
                            fracoes.forEach(f => {
                                horasTotaisDecimais += parseFloat(f.horas);
                            });
                        } 
                    } 
                } 
                const _abMin = (typeof window.descontoSobreavisoMin === 'function') ? window.descontoSobreavisoMin(tecnico.nome, startDateStr, endDateStr) : 0;
                const _brutoMin = Math.round(horasTotaisDecimais * 60);
                const _abEf = Math.min(_abMin, _brutoMin);
                horasTotaisDecimais = Math.max(0, horasTotaisDecimais - _abEf / 60);
                if (_brutoMin > 0) { 
                    let hInt = Math.floor(horasTotaisDecimais); 
                    let mInt = Math.round((horasTotaisDecimais - hInt) * 60); 
                    dashboardData.push({
                        nome: tecnico.nome,
                        nome_completo: tecnico.nome_completo || tecnico.nome,
                        totalDecimais: horasTotaisDecimais, abatidoMin: _abEf,
                        dias: diasSobreaviso,
                        formatado: `${hInt} h${mInt > 0 ? ' ' + String(mInt).padStart(2, '0') + 'm' : ''}`
                    });
                } 
            });
            
            dashboardData.sort((a, b) => b.totalDecimais - a.totalDecimais);
            
            if (dashboardData.length === 0) {
                tbody.innerHTML = `<tr><td colspan="4" class="p-8 text-center text-slate-400 italic">Nenhum sobreaviso registrado neste período para os filtros selecionados.</td></tr>`;
                return;
            }
            
            let html = '';
            dashboardData.forEach((item, index) => {
                let badgeStyle = "text-slate-600 font-bold";
                if (index === 0) badgeStyle = "bg-amber-100 text-amber-800 font-black rounded px-2 py-0.5";
                else if (index === 1) badgeStyle = "bg-slate-200 text-slate-800 font-black rounded px-2 py-0.5";
                else if (index === 2) badgeStyle = "bg-orange-100 text-orange-800 font-black rounded px-2 py-0.5";

                html += `
                <tr class="hover:bg-slate-50 transition-colors">
                    <td class="p-3 text-center font-mono"><span class="${badgeStyle}">#${index + 1}</span></td>
                    <td class="p-3">
                        <div class="font-black text-slate-900 uppercase">${item.nome}</div>
                        <div class="text-[10px] text-slate-500 uppercase">${item.nome_completo}</div>
                    </td>
                    <td class="p-3 text-center font-bold text-slate-700">${item.dias} dias</td>
                    <td class="p-3 text-right">
                        <span class="px-3 py-1 bg-blue-600 text-white font-black rounded text-xs shadow-inner">${item.formatado}</span>${item.abatidoMin ? `<div class="text-[9px] font-bold text-amber-600 mt-1">−${Math.floor(item.abatidoMin/60)}h${String(item.abatidoMin%60).padStart(2,'0')}m abatidos (hora extra)</div>` : ''}
                    </td>
                </tr>
                `;
            });
            
            tbody.innerHTML = html;
        }

        function gerarRelatorioPDF() { 
            const { jsPDF } = window.jspdf; const doc = new jsPDF(); 
            
            const startAno = currentMonthDate.getFullYear();
            const startMes = currentMonthDate.getMonth();
            let endAno = startAno;
            let endMes = startMes + 1;
            if (endMes > 11) { endMes = 0; endAno++; }
            
            const nomeCompetencia = nomesMeses[endMes];
            const anoCompetencia = endAno;
            
            const startDateStr = `${startAno}-${String(startMes+1).padStart(2,'0')}-15`;
            const endDateStr = `${endAno}-${String(endMes+1).padStart(2,'0')}-14`;

            let linhasTabela = []; 
            const tecnicosVisiveis = getOrderedAndFilteredTecnicos();
            let funcFiltradosBase = tecnicosDB.filter(f => tecnicosVisiveis.includes(f.nome));

            if (currentView !== 'dashboard' && currentAccess !== 'mestre' && setoresPermitidosLogin) { 
                const arrSetores = setoresPermitidosLogin.split(',').map(s => s.trim().toUpperCase()); 
                funcFiltradosBase = funcFiltradosBase.filter(f => arrSetores.includes(f.setor.toUpperCase())); 
            } 
            
            let listaOrdenada = [...funcFiltradosBase].sort((a, b) => (a.nome_completo || a.nome).localeCompare((b.nome_completo || b.nome))); 
            
            listaOrdenada.forEach(tecnico => { 
                let diasSobreaviso = 0; let horasTotaisDecimais = 0; 
                
                if (plantaoMensalDados[tecnico.nome]) { 
                    for (const [dataStr, dado] of Object.entries(plantaoMensalDados[tecnico.nome])) { 
                        let st = typeof dado === 'object' ? dado.status : dado;
                        
                        if (dataStr >= startDateStr && dataStr <= endDateStr && st && st.startsWith('plantao')) { 
                            diasSobreaviso++; 
                            
                            let fracoes = dado.fracoes || [];
                            fracoes.forEach(f => {
                                horasTotaisDecimais += parseFloat(f.horas);
                            });
                        } 
                    } 
                } 
                { const _ab = (typeof window.descontoSobreavisoMin === 'function') ? window.descontoSobreavisoMin(tecnico.nome, startDateStr, endDateStr) : 0;
                  horasTotaisDecimais = Math.max(0, horasTotaisDecimais - Math.min(_ab, Math.round(horasTotaisDecimais * 60)) / 60); }
                if (diasSobreaviso > 0) { 
                    let hInt = Math.floor(horasTotaisDecimais); let mInt = Math.round((horasTotaisDecimais - hInt) * 60); 
                    linhasTabela.push([tecnico.id ? String(tecnico.id).padStart(6, '0') : "000000", tecnico.nome_completo || tecnico.nome, diasSobreaviso.toString(), `${hInt} h${mInt > 0 ? ' ' + String(mInt).padStart(2, '0') + 'm' : ''}`]); 
                } 
            }); 

            doc.autoTable({ 
                startY: 55, 
                head: [['Matrícula', 'Nome Completo', 'Dias\nSobreaviso', 'TOTAL\nHoras\nSobreaviso']], 
                body: linhasTabela, 
                theme: 'grid', 
                styles: { halign: 'center', valign: 'middle', fontSize: 9, font: 'helvetica' }, 
                headStyles: { fillColor: [255, 255, 255], textColor: [0,0,0], fontStyle: 'bold', lineWidth: 0.1, lineColor: [0,0,0] }, 
                bodyStyles: { textColor: [0,0,0], fontStyle: 'bold', lineWidth: 0.1, lineColor: [0,0,0] }, 
                columnStyles: { 0: { cellWidth: 25 }, 1: { halign: 'left', cellWidth: 75 }, 2: { cellWidth: 40 }, 3: { cellWidth: 42 } },
                margin: { top: 30 }, 
                didDrawPage: function (data) {
                    if (configuracoes.logo_pdf_base64) {
                        doc.addImage(configuracoes.logo_pdf_base64, "JPEG", 155, 10, 40, 16);
                    }
                    
                    if (data.pageNumber === 1) {
                        doc.setFontSize(14); doc.setFont("helvetica", "bold"); doc.text("SOLICITAÇÃO", 105, 15, null, null, "center"); 
                        doc.setFontSize(10); doc.rect(14, 25, 182, 20); doc.line(14, 31.6, 196, 31.6); doc.line(14, 38.3, 196, 38.3); 
                        doc.text("CENTRO DE CUSTO: 210300406", 16, 30); doc.text("RESPONSÁVEL:", 16, 37); doc.text(`COMPETÊNCIA: ${nomeCompetencia}/${anoCompetencia}`, 16, 43); 
                        doc.text("Solicitação de: SOBREAVISO", 20, 52);
                    }
                }
            }); 

            let finalY = doc.lastAutoTable.finalY + 10; if (finalY > 230) { doc.addPage(); finalY = 20; } 
            doc.setFontSize(9); doc.setFont("helvetica", "normal"); doc.text("A ser preenchido pela Direção/Gerência", 14, finalY); doc.rect(14, finalY + 1, 182, 20); doc.line(14, finalY + 6, 196, finalY + 6); doc.line(14, finalY + 11, 196, finalY + 11); doc.line(14, finalY + 16, 196, finalY + 16); doc.text("Observações:", 16, finalY + 5); doc.text("Atenciosamente,", 14, finalY + 40); doc.text("________________________, ____ de ________ de ________", 14, finalY + 60); doc.text("__________________________________", 85, finalY + 75); doc.text("Gestor Imediato", 105, finalY + 80); 
            doc.save(`Relatorio_Sobreaviso_${nomeCompetencia}_${anoCompetencia}.pdf`); 
        }
        function renderAll() { 
            if (typeof renderWeekly === 'function' && currentView === 'week') renderWeekly(); 
            if (typeof renderMonthly === 'function' && currentView === 'month') renderMonthly(); 
            if (currentView === 'dashboard') renderDashboard();
        }
    
    function showToast(msg, type = 'success') {
        const container = document.getElementById('toast-container');
        const toast = document.createElement('div');
        const bgColor = type === 'success' ? 'bg-emerald-500' : (type === 'error' ? 'bg-red-500' : 'bg-blue-500');
        toast.className = `${bgColor} text-white px-4 py-3 rounded shadow-lg transition-all duration-300 transform translate-x-full opacity-0 flex items-center gap-2 font-bold text-sm pointer-events-auto`;
        toast.innerHTML = `<i data-lucide="${type === 'success' ? 'check-circle' : 'alert-circle'}" class="w-5 h-5"></i> ${msg}`;
        container.appendChild(toast);
        if(typeof lucide !== 'undefined') lucide.createIcons();
        setTimeout(() => toast.classList.remove('translate-x-full', 'opacity-0'), 10);
        setTimeout(() => { toast.classList.add('translate-x-full', 'opacity-0'); setTimeout(() => toast.remove(), 300); }, 3500);
    }
    async function testarEvoConnection() {
        showToast('Testando conexão...', 'success');
        const res = await fetch('api_escala.php?action=test_evo'); const data = await res.json();
        if(data.sucesso) showToast('Conexão Evolution OK!', 'success'); else showToast('Falha com Evolution.', 'error');
    }
    async function testarMensagemWpp(grupoJid) {
        if(!confirm("Enviar mensagem de teste real para o grupo agora?")) return;
        const res = await fetch('api_escala.php?action=test_wpp', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({grupo_id: grupoJid}) });
        const data = await res.json();
        if(data.sucesso) showToast('Mensagem enviada com sucesso.', 'success'); else showToast('Falha ao enviar mensagem.', 'error');
    }
    
    const _origCarregarConfiguracoes2 = carregarConfiguracoes;
    carregarConfiguracoes = async function() {
        await _origCarregarConfiguracoes2();
        try { const res = await fetch('api_escala.php?action=get_config'); const result = await res.json(); if(result.dados) { configuracoes.evo_url = result.dados.evo_url; configuracoes.evo_key = result.dados.evo_key; configuracoes.evo_inst = result.dados.evo_inst; } } catch(e) {}
    }
    const _origAbrirConfigs2 = abrirConfiguracoes;
    abrirConfiguracoes = function() {
        _origAbrirConfigs2();
        if(document.getElementById('cfg-evo-url')) document.getElementById('cfg-evo-url').value = configuracoes.evo_url || '';
        if(document.getElementById('cfg-evo-key')) document.getElementById('cfg-evo-key').value = configuracoes.evo_key || '';
        if(document.getElementById('cfg-evo-inst')) document.getElementById('cfg-evo-inst').value = configuracoes.evo_inst || '';
    }
    const _origSalvarConfigs2 = salvarConfiguracoes;
    salvarConfiguracoes = async function() {
        const hu = document.getElementById('cfg-horas-uteis').value; const hf = document.getElementById('cfg-horas-fds').value; 
        const reqBody = {horas_uteis: hu, horas_fds: hf, escala_texto: configuracoes.escala_texto};
        if (configuracoes.logo_pdf_base64) reqBody.logo_pdf_base64 = configuracoes.logo_pdf_base64;
        if (document.getElementById('cfg-evo-url')) reqBody.evo_url = document.getElementById('cfg-evo-url').value;
        if (document.getElementById('cfg-evo-key')) reqBody.evo_key = document.getElementById('cfg-evo-key').value;
        if (document.getElementById('cfg-evo-inst')) reqBody.evo_inst = document.getElementById('cfg-evo-inst').value;
        await fetch('api_escala.php?action=set_config', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(reqBody) }); 
        configuracoes.horas_uteis = parseFloat(hu); configuracoes.horas_fds = parseFloat(hf); 
        if(reqBody.evo_url) configuracoes.evo_url = reqBody.evo_url; if(reqBody.evo_key) configuracoes.evo_key = reqBody.evo_key; if(reqBody.evo_inst) configuracoes.evo_inst = reqBody.evo_inst;
        showToast("Opções globais atualizadas!", "success"); renderAll(); 
    }
</script>

<script>
// --- FILA DE INTERVALO DO WHATSAPP (painel de administração) ---
(function () {
    let filaDados = null;
    let filaRecebidoEm = 0;
    let cfgSujo = false;        // true enquanto o usuário está editando os campos de configuração
    let buscando = false;

    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const fmtTempo = s => {
        s = Math.max(0, Math.round(s));
        if (s < 60) return s + 's';
        const m = Math.floor(s / 60), r = s % 60;
        return m + 'min ' + String(r).padStart(2, '0') + 's';
    };
    const nomeAcao = a => a === 'add' ? 'Adicionar ao grupo' : (a === 'remove' ? 'Remover do grupo' : 'Mensagem no grupo');
    const iconeAcao = a => a === 'add' ? 'user-plus' : (a === 'remove' ? 'user-minus' : 'message-square');
    const modalAberto = () => { const m = document.getElementById('config-modal'); return !!m && !m.classList.contains('hidden'); };
    const toast = (m, t) => { if (typeof showToast === 'function') showToast(m, t); };

    function preencherConfig() {
        if (!filaDados || cfgSujo) return;
        const a = document.getElementById('fila-cfg-min'), b = document.getElementById('fila-cfg-max'), c = document.getElementById('fila-cfg-ordem');
        if (a) a.value = filaDados.intervalo_min;
        if (b) b.value = filaDados.intervalo_max;
        if (c && filaDados.ordem) c.value = filaDados.ordem;
    }

    window.carregarFilaWpp = async function () {
        if (buscando) return;
        buscando = true;
        try {
            const res = await fetch('api_escala.php?action=get_fila_whatsapp');
            const d = await res.json();
            if (d && d.sucesso) { filaDados = d; filaRecebidoEm = Date.now(); preencherConfig(); renderFilaWpp(); }
        } catch (e) { console.error(e); }
        buscando = false;
    };

    window.salvarConfigFilaWpp = async function () {
        const min = parseInt(document.getElementById('fila-cfg-min').value, 10);
        const max = parseInt(document.getElementById('fila-cfg-max').value, 10);
        const ordem = document.getElementById('fila-cfg-ordem').value;
        if (!(min >= 1) || !(max >= 1)) { toast('Informe intervalos de pelo menos 1 segundo.', 'error'); return; }
        if (max < min) { toast('O intervalo máximo não pode ser menor que o mínimo.', 'error'); return; }
        try {
            const res = await fetch('api_escala.php?action=salvar_config_fila', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ intervalo_min: min, intervalo_max: max, ordem })
            });
            const d = await res.json();
            if (d && d.sucesso) { cfgSujo = false; toast('Configuração da fila salva!', 'success'); window.carregarFilaWpp(); }
            else toast((d && d.erro) || 'Erro ao salvar a configuração.', 'error');
        } catch (e) { toast('Falha de comunicação ao salvar a configuração.', 'error'); }
    };

    window.processarFilaWppAgora = async function () {
        try {
            await fetch('api_escala.php?action=processar_fila_whatsapp', { method: 'POST' });
            toast('Processamento da fila iniciado.', 'info');
        } catch (e) { toast('Falha ao iniciar a fila.', 'error'); }
        setTimeout(window.carregarFilaWpp, 1500);
    };

    window.limparFalhasFilaWpp = async function () {
        try {
            await fetch('api_escala.php?action=limpar_fila_erros_whatsapp', { method: 'POST' });
            toast('Falhas removidas da lista.', 'success');
        } catch (e) { toast('Falha ao limpar a lista.', 'error'); }
        window.carregarFilaWpp();
    };

    function linhaAtiva(it, seg) {
        const eta = it.eta === null ? null : Math.max(0, it.eta - seg);
        const idade = it.idade + seg;
        let pct, rotulo, cor;
        if (eta === null) { pct = 6; rotulo = 'Aguardando processador'; cor = 'bg-slate-300'; }
        else if (it.status === 'processando') { pct = 92; rotulo = 'Enviando…'; cor = 'bg-blue-500 animate-pulse'; }
        else {
            pct = Math.min(100, Math.max(3, (idade / (idade + eta)) * 100));
            rotulo = eta < 1 ? 'Executando em instantes…' : 'em ' + fmtTempo(eta);
            cor = 'bg-amber-500';
        }
        const tent = it.tentativas > 0 ? ` <span class="text-red-600">(tentativa ${it.tentativas + 1}/3)</span>` : '';
        return `<div class="px-3 py-2 border-b last:border-b-0">
            <div class="flex items-center justify-between gap-3 text-xs">
                <div class="flex items-center gap-2 min-w-0">
                    <i data-lucide="${iconeAcao(it.acao)}" class="w-3.5 h-3.5 shrink-0 text-slate-500"></i>
                    <span class="font-bold text-slate-800 truncate">${esc(it.colaborador || '—')}</span>
                    <span class="text-slate-500 truncate">${nomeAcao(it.acao)} <b>${esc(it.grupo)}</b>${tent}</span>
                </div>
                <span class="shrink-0 font-bold text-slate-700 tabular-nums">${rotulo}</span>
            </div>
            <div class="mt-1.5 h-2 w-full bg-slate-200 rounded-full overflow-hidden"><div class="h-full ${cor} rounded-full transition-all duration-700" style="width:${pct.toFixed(1)}%"></div></div>
        </div>`;
    }

    function linhaFalha(it, seg) {
        return `<div class="px-3 py-2 border-b last:border-b-0 bg-red-50" title="${esc(it.detalhe || '')}">
            <div class="flex items-center justify-between gap-3 text-xs">
                <div class="flex items-center gap-2 min-w-0">
                    <i data-lucide="alert-circle" class="w-3.5 h-3.5 shrink-0 text-red-600"></i>
                    <span class="font-bold text-slate-800 truncate">${esc(it.colaborador || '—')}</span>
                    <span class="text-slate-500 truncate">${nomeAcao(it.acao)} <b>${esc(it.grupo)}</b></span>
                </div>
                <span class="shrink-0 font-bold text-red-700">Falhou há ${fmtTempo(it.ha + seg)}</span>
            </div>
            <div class="mt-1.5 h-2 w-full bg-slate-200 rounded-full overflow-hidden"><div class="h-full bg-red-500 rounded-full" style="width:100%"></div></div>
        </div>`;
    }

    function renderFilaWpp() {
        const lista = document.getElementById('fila-wpp-lista');
        const aviso = document.getElementById('fila-wpp-aviso');
        if (!lista || !aviso || !filaDados) return;
        const d = filaDados;
        const seg = (Date.now() - filaRecebidoEm) / 1000;

        // do menor para o maior tempo de execução (sem previsão por último); concluídas somem da lista
        const ativos = (d.ativos || []).slice().sort((a, b) => {
            const ea = a.eta === null ? 1e12 : a.eta, eb = b.eta === null ? 1e12 : b.eta;
            return (ea - eb) || (a.id - b.id);
        });

        aviso.innerHTML = (ativos.length > 0 && !d.processador_ativo)
            ? '<div class="flex items-center gap-2 text-[11px] font-bold text-red-700"><span>Fila parada: nenhum processador em execução.</span><button type="button" onclick="processarFilaWppAgora()" class="bg-emerald-600 hover:bg-emerald-700 text-white px-2 py-1 rounded">Processar agora</button></div>'
            : '';

        const falhas = d.finalizados || [];
        let html = '';
        if (ativos.length === 0 && falhas.length === 0) {
            html += '<div class="p-4 text-center text-xs text-slate-400 font-bold">Nenhuma ação na fila no momento.</div>';
        }
        html += ativos.map(it => linhaAtiva(it, seg)).join('');
        if (falhas.length > 0) {
            html += '<div class="px-3 py-1 bg-slate-100 border-y flex items-center justify-between text-[10px] font-bold uppercase text-slate-500"><span>Falhas recentes (24h)</span><button type="button" onclick="limparFalhasFilaWpp()" class="text-red-600 hover:text-red-800 normal-case">Limpar falhas</button></div>';
            html += falhas.map(it => linhaFalha(it, seg)).join('');
        }
        lista.innerHTML = html;

        const at = document.getElementById('fila-wpp-atualizado');
        if (at) at.textContent = 'Atualizado às ' + new Date(filaRecebidoEm).toLocaleTimeString('pt-BR');
        if (typeof lucide !== 'undefined') lucide.createIcons();

        // quando o tempo de alguma ação acaba, busca na hora para ela sair da lista assim que concluir
        const venceu = ativos.some(it => it.eta !== null && it.eta - seg <= 0);
        if (venceu && Date.now() - filaRecebidoEm > 1500) window.carregarFilaWpp();
    }

    ['fila-cfg-min', 'fila-cfg-max', 'fila-cfg-ordem'].forEach(id => {
        const el = document.getElementById(id);
        if (el) { el.addEventListener('input', () => { cfgSujo = true; }); el.addEventListener('change', () => { cfgSujo = true; }); }
    });

    // atualiza os dados a cada 3s e a contagem regressiva a cada 1s (só com o painel aberto)
    setInterval(() => { if (modalAberto()) window.carregarFilaWpp(); }, 3000);
    setInterval(() => { if (modalAberto() && filaDados) renderFilaWpp(); }, 1000);

    if (typeof abrirConfiguracoes === 'function') {
        const _abrirCfgFila = abrirConfiguracoes;
        abrirConfiguracoes = function () { cfgSujo = false; const r = _abrirCfgFila.apply(this, arguments); window.carregarFilaWpp(); return r; };
    }
})();
</script>
<script>
// --- STATUS DO COLABORADOR + REGRA AUTOMÁTICA DE GRUPOS ---
(function () {
    const ROT = { SEM_ESCALA: 'SEM_ESCALA', SOBREAVISO: 'SOBREAVISO', FOLGA: 'FOLGA', AFASTAMENTO: 'AFASTAMENTO', LICENCA: 'LICENÇA', FERIAS: 'FÉRIAS' };
    const COR = {
        SEM_ESCALA: 'bg-slate-200 text-slate-700', SOBREAVISO: 'bg-green-100 text-green-800', FOLGA: 'bg-yellow-100 text-yellow-800',
        AFASTAMENTO: 'bg-red-100 text-red-800', LICENCA: 'bg-purple-100 text-purple-800', FERIAS: 'bg-orange-100 text-orange-800'
    };
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const toast = (m, t) => { if (typeof showToast === 'function') showToast(m, t); };
    const rot = s => (s && ROT[s]) || s || '—';

    // ---- status no cadastro do colaborador
    window.carregarStatusColaborador = async function (id) {
        const box = document.getElementById('func-status-box');
        if (!box) return;
        if (!id) { box.classList.add('hidden'); return; }
        try {
            const res = await fetch('api_escala.php?action=get_status_colaborador&id=' + encodeURIComponent(id));
            const d = await res.json();
            if (!d || !d.sucesso) { box.classList.add('hidden'); return; }
            const badge = document.getElementById('func-status-badge');
            const det = document.getElementById('func-status-detalhe');
            badge.className = 'shrink-0 px-2 py-1 rounded font-black text-[11px] tracking-wide ' + (COR[d.status] || COR.SEM_ESCALA);
            badge.textContent = rot(d.status);
            const partes = [];
            if (d.desde) partes.push('desde ' + d.desde);
            if (d.afastado) partes.push('fora dos grupos de WhatsApp (' + d.motivo + ')');
            else if (d.fora_dos_grupos > 0) partes.push('retorno aos grupos pendente');
            else partes.push('participa dos grupos de WhatsApp');
            det.textContent = partes.join(' · ');
            box.classList.remove('hidden');
        } catch (e) { box.classList.add('hidden'); }
    };

    if (typeof editarFuncionario === 'function') {
        const _edit = editarFuncionario;
        editarFuncionario = function (f) { const r = _edit.apply(this, arguments); window.carregarStatusColaborador(f && f.id); return r; };
    }
    if (typeof abrirFormFuncionario === 'function') {
        const _novo = abrirFormFuncionario;
        abrirFormFuncionario = function () { const r = _novo.apply(this, arguments); const b = document.getElementById('func-status-box'); if (b) b.classList.add('hidden'); return r; };
    }

    // ---- card da regra automática
    let sujo = false;
    window.carregarRegraGrupos = async function () {
        try {
            const res = await fetch('api_escala.php?action=get_regra_grupos');
            const d = await res.json();
            if (!d || !d.sucesso) return;
            const inp = document.getElementById('regra-limite');
            if (inp && !sujo) inp.value = d.limite;
            const ult = document.getElementById('regra-ultima');
            if (ult) ult.textContent = (d.ultima ? 'Última verificação: ' + d.ultima : 'Ainda não verificado') + ' · Fora dos grupos agora: ' + d.afastados;
            const lista = document.getElementById('regra-alteracoes');
            if (lista) {
                const alts = d.alteracoes || [];
                lista.innerHTML = alts.length === 0
                    ? '<div class="p-3 text-center text-xs text-slate-400 font-bold">Nenhuma mudança de status registrada ainda.</div>'
                    : alts.map(a => `<div class="px-3 py-1.5 border-b last:border-b-0 text-[11px] text-slate-700"><b>${esc(a.quando)}</b> — ${esc(a.nome)}: ${esc(rot(a.status_anterior))} → <b>${esc(rot(a.status_novo))}</b> <span class="text-slate-500">(${esc(a.acao)})</span></div>`).join('');
            }
        } catch (e) { console.error(e); }
    };

    window.salvarRegraGrupos = async function () {
        const lim = parseInt(document.getElementById('regra-limite').value, 10);
        if (!(lim >= 1 && lim <= 60)) { toast('Informe um limite entre 1 e 60 dias úteis.', 'error'); return; }
        try {
            const res = await fetch('api_escala.php?action=salvar_regra_grupos', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ limite: lim }) });
            const d = await res.json();
            if (d && d.sucesso) { sujo = false; toast('Limite de dias úteis salvo!', 'success'); window.carregarRegraGrupos(); }
            else toast((d && d.erro) || 'Erro ao salvar.', 'error');
        } catch (e) { toast('Falha de comunicação ao salvar.', 'error'); }
    };

    window.verificarGruposAgora = async function () {
        toast('Verificando status dos colaboradores…', 'info');
        try {
            const res = await fetch('api_escala.php?action=sincronizar_status_grupos', { method: 'POST' });
            const d = await res.json();
            if (d && d.sucesso) {
                toast('Verificados: ' + d.verificados + ' · Mudanças: ' + d.alteracoes + ' · Remoções na fila: ' + d.remocoes + ' · Inclusões na fila: ' + d.adicoes, 'success');
            } else toast((d && d.erro) || 'Erro na verificação.', 'error');
        } catch (e) { toast('Falha de comunicação na verificação.', 'error'); }
        window.carregarRegraGrupos();
        if (typeof carregarFilaWpp === 'function') setTimeout(carregarFilaWpp, 800);
    };

    const campo = document.getElementById('regra-limite');
    if (campo) campo.addEventListener('input', () => { sujo = true; });

    if (typeof abrirConfiguracoes === 'function') {
        const _abrir = abrirConfiguracoes;
        abrirConfiguracoes = function () { sujo = false; const r = _abrir.apply(this, arguments); window.carregarRegraGrupos(); return r; };
    }
})();
</script>
<script>
// --- PLANTÃO COMPARTILHADO: permitido, com alerta ao gestor ---
(function () {
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const fmt = d => d.split('-').reverse().join('/');

    // Quem mais está de plantão no mesmo dia, no mesmo setor/par.
    // Usa as mesmas regras que antes bloqueavam o lançamento (ELE/REF de Salvador: até 2 técnicos no FDS/sexta).
    window.listarSobreposicoesPlantao = function (nome, dataStr) {
        const cel = plantaoMensalDados[nome] && plantaoMensalDados[nome][dataStr];
        const st = (typeof cel === 'string') ? cel : (cel ? cel.status : null);
        if (!st || !String(st).startsWith('plantao')) return null;
        const meu = tecnicosDB.find(t => t.nome === nome);
        if (!meu) return null;

        const escalados = [];
        let qtdEleRefSsa = 0;
        for (const outro in plantaoMensalDados) {
            if (outro === nome) continue;
            const c = plantaoMensalDados[outro][dataStr];
            const s = (typeof c === 'string') ? c : (c ? c.status : null);
            if (s && String(s).startsWith('plantao')) {
                const f = tecnicosDB.find(t => t.nome === outro);
                if (f) {
                    escalados.push({ nome: outro, func: f });
                    if (f.setor === 'ELE/REF' && f.localidade === 'SALVADOR') qtdEleRefSsa++;
                }
            }
        }
        if (escalados.length === 0) return null;

        const dow = new Date(dataStr + 'T00:00:00').getDay();
        const fdsSexta = (dow === 0 || dow === 5 || dow === 6);
        let meuPar = [meu.sigla];
        for (const p of PARES_SIGLA) { if (p.includes(meu.sigla)) { meuPar = p; break; } }

        const nomes = [];
        let limite = false;
        for (const o of escalados) {
            const e = o.func;
            let acerto = false;
            if (meu.setor === 'RECORRENCIA' && e.setor === 'RECORRENCIA') acerto = true;
            else if (meu.setor === 'VIDEOWALL' && e.setor === 'VIDEOWALL') acerto = true;
            else if (meu.setor === 'ELE/REF') {
                if (meu.localidade === 'SALVADOR') {
                    if (e.setor === 'ELE/REF' && e.localidade === 'SALVADOR') {
                        if (fdsSexta) { if (qtdEleRefSsa >= 2) { acerto = true; limite = true; } }
                        else acerto = true;
                    }
                } else if (e.setor === 'ELE/REF' && meuPar.includes(e.sigla)) acerto = true;
            } else if (meu.setor === e.setor && meu.localidade === e.localidade) acerto = true;
            if (acerto) nomes.push(o.nome);
        }
        return nomes.length ? { nomes: nomes, limite: limite } : null;
    };

    function textoAviso(nome, dataStr, r) {
        const lista = r.nomes.join(', ');
        return r.limite
            ? fmt(dataStr) + ': ' + nome + ' ultrapassa o limite de 2 técnicos ELE/REF de Salvador (também de plantão: ' + lista + ')'
            : fmt(dataStr) + ': ' + nome + ' divide o plantão com ' + lista + ' (mesmo dia e setor/par)';
    }

    // balão amarelo: entra correndo da direita, mostra uma barra de tempo (5 s) e sai voltando para a direita
    window.mostrarAvisoPlantao = function (titulo, linhas) {
        const cont = document.getElementById('toast-container');
        if (!cont) return;
        const DURACAO = 5000;   // tempo de visualização, em ms
        const ENTRADA = 450;    // duração da animação de entrada, em ms
        const fora = 'translateX(calc(100% + 24px))';
        const mk = (tag, cls) => { const e = document.createElement(tag); if (cls) e.className = cls; return e; };

        const t = mk('div', 'bg-amber-500 text-white rounded shadow-lg overflow-hidden font-bold text-sm pointer-events-auto max-w-md');
        t.setAttribute('role', 'alert');
        t.style.transform = fora;
        t.style.transition = 'transform ' + ENTRADA + 'ms cubic-bezier(.22,.61,.36,1)';

        const corpo = mk('div', 'flex items-start gap-2 px-4 py-3');
        const icone = mk('i', 'w-5 h-5 shrink-0');
        icone.setAttribute('data-lucide', 'alert-triangle');
        const texto = mk('div', 'flex-1');
        const tit = mk('div');
        tit.textContent = String(titulo).replace(/^\u26A0\uFE0F?\s*/, '');
        texto.appendChild(tit);
        linhas.slice(0, 8).forEach(l => { const d = mk('div', 'text-xs font-semibold mt-1'); d.textContent = '• ' + l; texto.appendChild(d); });
        if (linhas.length > 8) { const m = mk('div', 'text-xs font-semibold mt-1'); m.textContent = '… e mais ' + (linhas.length - 8) + ' dia(s)'; texto.appendChild(m); }
        const btn = mk('button', 'shrink-0 -mr-1 text-white/90 hover:text-white font-black leading-none');
        btn.setAttribute('type', 'button');
        btn.setAttribute('aria-label', 'Fechar');
        btn.textContent = '✕';
        corpo.appendChild(icone);
        corpo.appendChild(texto);
        corpo.appendChild(btn);

        // barra de tempo: começa cheia e esvazia em 5 segundos
        const trilho = mk('div', 'aviso-barra-tempo');
        trilho.style.height = '4px';
        trilho.style.width = '100%';
        trilho.style.background = 'rgba(0,0,0,0.2)';
        const barra = mk('div');
        barra.style.height = '100%';
        barra.style.width = '100%';
        barra.style.background = '#ffffff';
        trilho.appendChild(barra);

        t.appendChild(corpo);
        t.appendChild(trilho);
        cont.appendChild(t);
        if (typeof lucide !== 'undefined') lucide.createIcons();

        let saiu = false;
        const sair = () => {
            if (saiu) return;
            saiu = true;
            t.style.transition = 'transform 500ms cubic-bezier(.55,.06,.68,.19)';
            t.style.transform = fora;                 // volta correndo para a direita, por onde entrou
            setTimeout(() => t.remove(), 550);
        };
        btn.addEventListener('click', sair);

        // entra deslizando da direita; os 5 segundos contam depois que ele terminou de entrar
        setTimeout(() => { void t.offsetWidth; t.style.transform = 'translateX(0)'; }, 20);
        setTimeout(() => {
            barra.style.transition = 'width ' + DURACAO + 'ms linear';
            barra.style.width = '0%';
        }, ENTRADA + 20);
        setTimeout(sair, ENTRADA + 20 + DURACAO);
    };

    window.REGRAS_SO = window.REGRAS_SO || { limite_24h: true, bloquear_ausencia: true, avisar_compartilhado: true };
    fetch('api_escala.php?action=get_regras_sobreaviso').then(function (r) { return r.json(); }).then(function (j) { if (j && j.sucesso) window.REGRAS_SO = j.regras; }).catch(function () {});
    window.avisarSobreposicaoPlantao = function (nome, dataStr) {
        if (window.REGRAS_SO && window.REGRAS_SO.avisar_compartilhado === false) return;
        const r = window.listarSobreposicoesPlantao(nome, dataStr);
        if (r) window.mostrarAvisoPlantao('⚠️ Plantão compartilhado (o lançamento foi salvo)', [textoAviso(nome, dataStr, r)]);
    };

    window.avisarSobreposicaoPlantaoLote = function (nome, ini, fim) {
        if (window.REGRAS_SO && window.REGRAS_SO.avisar_compartilhado === false) return;
        const linhas = [];
        const d = new Date(ini + 'T00:00:00');
        const f = new Date(fim + 'T00:00:00');
        while (d <= f) {
            const ds = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
            const r = window.listarSobreposicoesPlantao(nome, ds);
            if (r) linhas.push(textoAviso(nome, ds, r));
            d.setDate(d.getDate() + 1);
        }
        if (linhas.length) window.mostrarAvisoPlantao('⚠️ Plantão compartilhado em ' + linhas.length + ' dia(s) (o lançamento foi salvo)', linhas);
    };
})();
</script>
<script>
// --- HISTÓRICO DE MUDANÇAS DE STATUS (recolhido, 10 por página, filtro e exportação XLSX) ---
(function () {
    const POR_PAGINA = 10;
    const ROT = { SEM_ESCALA: 'SEM_ESCALA', SOBREAVISO: 'SOBREAVISO', FOLGA: 'FOLGA', AFASTAMENTO: 'AFASTAMENTO', LICENCA: 'LICENÇA', FERIAS: 'FÉRIAS' };
    const rot = s => (s && ROT[s]) || s || '—';
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const toast = (m, t) => { if (typeof showToast === 'function') showToast(m, t); };
    const el = id => document.getElementById(id);

    let aberto = false, pagina = 1, paginas = 1, total = 0, timer = null, buscando = false;

    function filtros() {
        return {
            busca: ((el('regra-log-busca') || {}).value || '').trim(),
            de: (el('regra-log-de') || {}).value || '',
            ate: (el('regra-log-ate') || {}).value || ''
        };
    }
    function periodoInvalido(f) {
        if (f.de && f.ate && f.de > f.ate) { toast('A data inicial não pode ser maior que a data final.', 'error'); return true; }
        return false;
    }
    function url(extra) {
        const f = filtros();
        const p = new URLSearchParams(Object.assign({ action: 'get_log_status_grupos' }, extra));
        if (f.busca) p.set('busca', f.busca);
        if (f.de) p.set('de', f.de);
        if (f.ate) p.set('ate', f.ate);
        return 'api_escala.php?' + p.toString();
    }

    function linha(a) {
        return '<div class="px-3 py-1.5 border-b last:border-b-0 text-[11px] text-slate-700"><b>' + esc(a.quando) + '</b> — ' + esc(a.nome) + ': '
            + esc(rot(a.status_anterior)) + ' → <b>' + esc(rot(a.status_novo)) + '</b> <span class="text-slate-500">(' + esc(a.acao) + ')</span></div>';
    }

    function render(itens) {
        const f = filtros();
        const temFiltro = !!(f.busca || f.de || f.ate);
        el('regra-log-total').textContent = '(' + total + (total === 1 ? ' registro' : ' registros') + ')';
        el('regra-log-lista').innerHTML = itens.length
            ? itens.map(linha).join('')
            : '<div class="p-4 text-center text-xs text-slate-400 font-bold">' + (temFiltro ? 'Nenhum registro encontrado para o filtro.' : 'Nenhuma mudança de status registrada ainda.') + '</div>';

        const ini = total === 0 ? 0 : (pagina - 1) * POR_PAGINA + 1;
        const fim = Math.min(total, pagina * POR_PAGINA);
        el('regra-log-info').textContent = total === 0 ? '0 registros' : 'Mostrando ' + ini + '–' + fim + ' de ' + total;
        const btn = (rotulo, destino, desab) => '<button type="button" ' + (desab ? 'disabled' : 'onclick="irPaginaLogStatus(' + destino + ')"')
            + ' class="px-2 py-1 rounded border text-[11px] font-bold ' + (desab ? 'text-slate-300 bg-slate-50 cursor-not-allowed' : 'text-slate-700 bg-white hover:bg-slate-100') + '">' + rotulo + '</button>';
        el('regra-log-pag').innerHTML = btn('«', 1, pagina <= 1) + btn('‹', pagina - 1, pagina <= 1)
            + '<span class="px-2 text-[11px] font-bold text-slate-600">Página ' + pagina + ' de ' + paginas + '</span>'
            + btn('›', pagina + 1, pagina >= paginas) + btn('»', paginas, pagina >= paginas);
    }

    async function carregar(pag) {
        if (!el('regra-log') || buscando) return;
        const f = filtros();
        if (periodoInvalido(f)) return;
        buscando = true;
        try {
            const res = await fetch(url({ pagina: pag || 1 }));
            const d = await res.json();
            if (d && d.sucesso) { pagina = d.pagina; paginas = d.paginas; total = d.total; render(d.itens); }
            else el('regra-log-lista').innerHTML = '<div class="p-4 text-center text-xs text-red-500 font-bold">' + esc((d && d.erro) || 'Erro ao carregar o histórico.') + '</div>';
        } catch (e) { console.error(e); }
        buscando = false;
    }

    function agendar() { clearTimeout(timer); timer = setTimeout(() => carregar(1), 350); }

    window.alternarLogStatus = function () {
        aberto = !aberto;
        el('regra-log-corpo').classList.toggle('hidden', !aberto);
        el('regra-log-seta').style.transform = aberto ? 'rotate(90deg)' : 'rotate(0deg)';
        el('regra-log-dica').textContent = aberto ? 'clique para recolher' : 'clique para expandir';
        if (aberto) carregar(pagina);
    };
    window.irPaginaLogStatus = function (n) { carregar(n); };
    window.limparFiltroLogStatus = function () {
        el('regra-log-busca').value = ''; el('regra-log-de').value = ''; el('regra-log-ate').value = '';
        carregar(1);
    };

    window.exportarLogStatusXlsx = async function () {
        if (typeof ExcelJS === 'undefined') { toast('Biblioteca de planilhas indisponível.', 'error'); return; }
        const f = filtros();
        if (periodoInvalido(f)) return;
        try {
            const res = await fetch(url({ todos: 1 }));
            const d = await res.json();
            if (!d || !d.sucesso) { toast((d && d.erro) || 'Erro ao exportar.', 'error'); return; }
            if (!d.itens.length) { toast('Nenhum registro para exportar com o filtro atual.', 'error'); return; }

            const wb = new ExcelJS.Workbook();
            const ws = wb.addWorksheet('Mudanças de status');
            ws.columns = [
                { header: 'Data/Hora', key: 'quando', width: 18 },
                { header: 'Colaborador', key: 'nome', width: 32 },
                { header: 'Status anterior', key: 'ant', width: 18 },
                { header: 'Status novo', key: 'novo', width: 18 },
                { header: 'Ação nos grupos', key: 'acao', width: 28 }
            ];
            ws.getRow(1).eachCell(cell => {
                cell.font = { bold: true, color: { argb: 'FFFFFFFF' } };
                cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF1E3A8A' } };
                cell.alignment = { vertical: 'middle', horizontal: 'center' };
                cell.border = { top: { style: 'thin' }, left: { style: 'thin' }, bottom: { style: 'thin' }, right: { style: 'thin' } };
            });
            d.itens.forEach(i => {
                const r = ws.addRow({ quando: i.quando, nome: i.nome, ant: i.status_anterior ? rot(i.status_anterior) : '—', novo: rot(i.status_novo), acao: i.acao });
                r.eachCell(cell => { cell.border = { top: { style: 'thin' }, left: { style: 'thin' }, bottom: { style: 'thin' }, right: { style: 'thin' } }; });
            });
            ws.views = [{ state: 'frozen', ySplit: 1 }];
            ws.autoFilter = { from: 'A1', to: 'E1' };

            const buffer = await wb.xlsx.writeBuffer();
            const blob = new Blob([buffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
            const link = document.createElement('a');
            const u = URL.createObjectURL(blob);
            const hoje = new Date();
            const dataArq = hoje.getFullYear() + '-' + String(hoje.getMonth() + 1).padStart(2, '0') + '-' + String(hoje.getDate()).padStart(2, '0');
            link.setAttribute('href', u);
            link.setAttribute('download', 'HISTORICO_STATUS_GRUPOS_' + dataArq + '.xlsx');
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            setTimeout(() => URL.revokeObjectURL(u), 100);
            toast(d.limitado ? 'Exportados os ' + d.itens.length + ' registros mais recentes (limite de 5000).' : 'Exportados ' + d.itens.length + ' registro(s).', 'success');
        } catch (e) { console.error(e); toast('Falha ao exportar o histórico.', 'error'); }
    };

    const busca = el('regra-log-busca');
    if (busca) {
        busca.addEventListener('input', agendar);
        busca.addEventListener('keydown', ev => { if (ev.key === 'Enter') { clearTimeout(timer); carregar(1); } });
    }
    ['regra-log-de', 'regra-log-ate'].forEach(id => { const c = el(id); if (c) c.addEventListener('change', () => carregar(1)); });

    // mantém o histórico atualizado quando o card é recarregado (abrir o painel, "Verificar agora")
    if (typeof window.carregarRegraGrupos === 'function') {
        const _orig = window.carregarRegraGrupos;
        window.carregarRegraGrupos = async function () { const r = await _orig.apply(this, arguments); carregar(pagina); return r; };
    }
    // ao abrir o painel, o histórico começa recolhido
    if (typeof abrirConfiguracoes === 'function') {
        const _abrir = abrirConfiguracoes;
        abrirConfiguracoes = function () {
            aberto = false; pagina = 1;
            const corpo = el('regra-log-corpo'), seta = el('regra-log-seta'), dica = el('regra-log-dica');
            if (corpo) corpo.classList.add('hidden');
            if (seta) seta.style.transform = 'rotate(0deg)';
            if (dica) dica.textContent = 'clique para expandir';
            return _abrir.apply(this, arguments);
        };
    }
})();
</script>
<script>
// --- PREENCHER SOBREAVISO SEGUINDO O MÊS ANTERIOR (mês inteiro e linha do colaborador) ---
(function () {
    const pad = n => String(n).padStart(2, '0');
    const fmtD = d => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    const parseD = s => new Date(s + 'T00:00:00');
    const addDias = (s, n) => { const d = parseD(s); d.setDate(d.getDate() + n); return fmtD(d); };
    const dm = s => s.slice(8, 10) + '/' + s.slice(5, 7);
    const dmy = s => s.slice(8, 10) + '/' + s.slice(5, 7) + '/' + s.slice(0, 4);
    const statusDe = c => (typeof c === 'string') ? c : (c ? c.status : null);
    const ehPlantao = s => !!s && String(s).startsWith('plantao');
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const toast = (m, t) => { if (typeof showToast === 'function') showToast(m, t); };
    const ordenaNome = (a, b) => a.localeCompare(b, 'pt-BR');
    const DIAS = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];

    function periodos() {
        const y = currentMonthDate.getFullYear(), m = currentMonthDate.getMonth();
        return { ini: fmtD(new Date(y, m, 15)), fim: fmtD(new Date(y, m + 1, 14)),
                 iniAnt: fmtD(new Date(y, m - 1, 15)), fimAnt: fmtD(new Date(y, m, 14)) };
    }
    function listaDatas(ini, fim) { const out = []; for (let d = ini; d <= fim; d = addDias(d, 1)) out.push(d); return out; }

    // mesmos grupos das regras de conflito: setor / par de cidades
    function grupoDe(f) {
        if (f.setor === 'RECORRENCIA') return { chave: 'RECORRENCIA', rotulo: 'RECORRÊNCIA' };
        if (f.setor === 'VIDEOWALL') return { chave: 'VIDEOWALL', rotulo: 'VIDEOWALL' };
        if (f.setor === 'ELE/REF') {
            if (f.localidade === 'SALVADOR') return { chave: 'ELE/REF|SALVADOR', rotulo: 'ELE/REF Salvador' };
            const par = PARES_SIGLA.find(p => p.includes(f.sigla));
            if (par) return { chave: 'ELE/REF|PAR|' + par.join('/'), rotulo: 'ELE/REF ' + par.join('/') };
            return { chave: 'ELE/REF|' + f.localidade + '|' + f.sigla, rotulo: 'ELE/REF ' + (f.sigla || f.localidade) };
        }
        return { chave: f.setor + '|' + f.localidade, rotulo: f.setor + ' ' + f.localidade };
    }

    function podeEditar(f) {
        if (currentAccess === 'mestre') return true;
        if (currentAccess !== 'admin') return false;
        if (!setoresGerenciaveisLogin) return true;
        const arr = setoresGerenciaveisLogin.split(',').map(s => s.trim().toUpperCase());
        return arr.includes(String(f.setor || '').toUpperCase());
    }

    // sobreaviso de cada dia do mês anterior, no grupo
    function historico(membros, datasAnt) {
        const H = {};
        for (const f of membros) {
            const dados = plantaoMensalDados[f.nome];
            if (!dados) continue;
            for (const d of datasAnt) {
                const st = statusDe(dados[d]);
                if (ehPlantao(st)) (H[d] = H[d] || []).push({ nome: f.nome, status: st });
            }
        }
        for (const d in H) H[d].sort((a, b) => ordenaNome(a.nome, b.nome));
        return H;
    }

    // ordem do rodízio = ordem em que os colaboradores aparecem no mês anterior; quem não apareceu entra no fim
    function ordemRodizio(H, datasAnt, membros) {
        const ordem = [];
        for (const d of datasAnt) for (const a of (H[d] || [])) if (!ordem.includes(a.nome)) ordem.push(a.nome);
        membros.map(f => f.nome).sort(ordenaNome).forEach(n => { if (!ordem.includes(n)) ordem.push(n); });
        return ordem;
    }

    const rotacionar = (lista, r, ordem, idx) => lista.map(a => ({ nome: ordem[(idx[a.nome] + r) % ordem.length], status: a.status }));
    const chaveLista = lista => lista.map(a => a.nome + '|' + a.status).sort().join(';');

    // descobre quantas semanas (L) e quantas posições (r) o rodízio avança, comparando o mês anterior com ele mesmo
    function detectar(H, datasAnt, iniAnt, ordem, idx) {
        const n = ordem.length;
        let melhor = null;
        for (const L of [7, 14]) {
            for (let r = 0; r < n; r++) {
                let pares = 0, ok = 0;
                for (const d of datasAnt) {
                    const dest = H[d];
                    if (!dest || dest.length === 0) continue;
                    const src = addDias(d, -L);
                    if (src < iniAnt) continue;
                    pares++;
                    const a = H[src] || [];
                    if (a.length && chaveLista(rotacionar(a, r, ordem, idx)) === chaveLista(dest)) ok++;
                }
                if (pares < 3) continue;
                const acc = ok / pares;
                if (!melhor || acc > melhor.acc + 1e-9) melhor = { L, r, acc, pares };
            }
        }
        return melhor;
    }

    // dias da semana em que o grupo costuma ter sobreaviso (metade ou mais das semanas do mês anterior)
    function coberturaSemana(H, datasAnt) {
        const tot = [0, 0, 0, 0, 0, 0, 0], com = [0, 0, 0, 0, 0, 0, 0];
        for (const d of datasAnt) { const w = parseD(d).getDay(); tot[w]++; if (H[d] && H[d].length) com[w]++; }
        return tot.map((t, w) => t > 0 && com[w] / t >= 0.5);
    }

    // continua o rodízio dia a dia: dia = (mesmo dia da semana L dias antes) com todos avançados r posições
    function projetar(H, ordem, idx, L, r, per, cobertura) {
        const S = {};
        const get = d => (d >= per.ini ? S[d] : H[d]) || [];
        for (const d of listaDatas(per.ini, per.fim)) {
            for (let k = 1; k <= 4; k++) {
                const src = addDias(d, -k * L);
                if (src < per.iniAnt) break;
                const lista = get(src);
                if (lista.length) { S[d] = rotacionar(lista, (r * k) % ordem.length, ordem, idx); break; }
                // dia que não costuma ter sobreaviso: não busca semanas mais antigas
                if (k === 1 && !cobertura[parseD(d).getDay()]) break;
            }
        }
        return S;
    }

    function textoPadrao(L, r) {
        if (r === 0) return 'mesmos colaboradores nos mesmos dias da semana';
        return 'rodízio ' + (L === 7 ? 'a cada semana' : 'a cada 2 semanas') + ', avançando ' + r + (r === 1 ? ' posição' : ' posições') + ' na sequência';
    }

    // plano de preenchimento do mês em exibição (apenasNome = só a linha desse colaborador)
    function montarPlano(apenasNome) {
        const per = periodos();
        const datasAnt = listaDatas(per.iniAnt, per.fimAnt);
        const datasAtual = listaDatas(per.ini, per.fim);
        const porNome = {};
        tecnicosDB.forEach(f => { porNome[f.nome] = f; });
        const alvo = apenasNome ? porNome[apenasNome] : null;
        const chaveAlvo = alvo ? grupoDe(alvo).chave : null;

        const grupos = {};
        tecnicosDB.forEach(f => {
            const g = grupoDe(f);
            if (chaveAlvo && g.chave !== chaveAlvo) return;
            (grupos[g.chave] = grupos[g.chave] || { rotulo: g.rotulo, membros: [] }).membros.push(f);
        });

        const plano = { per, itens: [], grupos: [], colaboradores: {}, jaPreenchidos: 0 };
        for (const chave of Object.keys(grupos).sort()) {
            const g = grupos[chave];
            const info = { rotulo: g.rotulo, membros: g.membros.length, preencher: 0, ja: 0 };
            plano.grupos.push(info);
            if (!g.membros.some(podeEditar)) { info.motivo = 'sem permissão para editar este setor'; continue; }
            const H = historico(g.membros, datasAnt);
            if (Object.keys(H).length === 0) { info.motivo = 'sem sobreaviso lançado no mês anterior'; continue; }
            const ordem = ordemRodizio(H, datasAnt, g.membros);
            const idx = {}; ordem.forEach((nm, i) => { idx[nm] = i; });
            let det = detectar(H, datasAnt, per.iniAnt, ordem, idx);
            if (!det || det.acc < 0.75) { // PREENCHER_4SEMANAS_V1: sem rodízio regular, repete o mês anterior pelo mesmo dia da semana (4 semanas antes)
                det = { L: 28, r: 0, acc: null };
                info.padrao = 'mesmos colaboradores nos mesmos dias da semana de 4 semanas antes (o mês anterior não tem um rodízio regular)';
                info.acc = null;
            } else {
                info.padrao = textoPadrao(det.L, det.r);
                info.acc = Math.round(det.acc * 100);
            }
            const S = projetar(H, ordem, idx, det.L, det.r, per, coberturaSemana(H, datasAnt));
            for (const d of datasAtual) {
                for (const a of (S[d] || [])) {
                    if (apenasNome && a.nome !== apenasNome) continue;
                    const f = porNome[a.nome];
                    if (!f || !podeEditar(f)) continue;
                    const existente = plantaoMensalDados[a.nome] && plantaoMensalDados[a.nome][d];
                    if (existente) { info.ja++; plano.jaPreenchidos++; continue; }
                    plano.itens.push({ nome: a.nome, data: d, status: a.status });
                    info.preencher++;
                    plano.colaboradores[a.nome] = (plano.colaboradores[a.nome] || 0) + 1;
                }
            }
        }
        return plano;
    }
    window.montarPlanoSobreaviso = montarPlano;

    // ---------------------------------------------------------------- janela de confirmação
    function janela(titulo, corpoHtml, textoOk, aoConfirmar) {
        const ov = document.createElement('div');
        ov.className = 'fixed inset-0 z-[400] bg-slate-900/60 flex items-center justify-center p-4';
        const card = document.createElement('div');
        card.className = 'bg-white rounded-xl shadow-2xl w-full max-w-xl max-h-[85vh] flex flex-col';
        const cab = document.createElement('div');
        cab.className = 'px-5 py-3 border-b font-black text-slate-800 text-sm uppercase';
        cab.textContent = titulo;
        const corpo = document.createElement('div');
        corpo.className = 'px-5 py-3 overflow-auto text-xs text-slate-700 space-y-2';
        corpo.innerHTML = corpoHtml;
        const rod = document.createElement('div');
        rod.className = 'px-5 py-3 border-t flex justify-end gap-2';
        const fechar = () => ov.remove();
        const bCancel = document.createElement('button');
        bCancel.setAttribute('type', 'button');
        bCancel.className = 'px-4 py-2 rounded bg-slate-200 hover:bg-slate-300 text-slate-700 font-bold text-xs';
        bCancel.textContent = aoConfirmar ? 'Cancelar' : 'Fechar';
        bCancel.addEventListener('click', fechar);
        rod.appendChild(bCancel);
        if (aoConfirmar) {
            const bOk = document.createElement('button');
            bOk.setAttribute('type', 'button');
            bOk.className = 'px-4 py-2 rounded bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs';
            bOk.textContent = textoOk;
            bOk.addEventListener('click', async () => { bOk.disabled = true; bOk.textContent = 'Gravando…'; await aoConfirmar(); fechar(); });
            rod.appendChild(bOk);
        }
        card.appendChild(cab); card.appendChild(corpo); card.appendChild(rod);
        ov.appendChild(card);
        ov.addEventListener('click', e => { if (e.target === ov) fechar(); });
        document.body.appendChild(ov);
        return ov;
    }

    async function gravar(plano, origem) {
        try {
            const res = await fetch('api_escala.php?action=preencher_sobreaviso_lote', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ itens: plano.itens, origem, usuario_logado: (typeof currentLogin !== 'undefined' ? currentLogin : '') })
            });
            const d = await res.json();
            if (d && d.sucesso) {
                toast('Sobreaviso preenchido: ' + d.inseridos + ' dia(s)' + (d.ignorados ? ' (' + d.ignorados + ' já preenchido(s) mantido(s))' : '') + '.', 'success');
                if (d.bloqueados_24h && d.bloqueados_24h.length) alert('LIMITE DE 24H DE SOBREAVISO (CLT)\n\n' + d.bloqueados_24h.length + ' dia(s) não foram lançados porque deixariam o colaborador mais de 24h seguidas de sobreaviso:\n\n' + d.bloqueados_24h.join(', '));
                if (typeof recarregarDadosBanco === 'function') await recarregarDadosBanco();
            } else toast((d && d.erro) || 'Erro ao preencher o sobreaviso.', 'error');
        } catch (e) { console.error(e); toast('Falha de comunicação ao preencher o sobreaviso.', 'error'); }
    }

    // ---------------------------------------------------------------- botão do mês inteiro
    window.preencherMesSobreaviso = function () {
        if (currentAccess !== 'admin' && currentAccess !== 'mestre') return;
        const plano = montarPlano(null);
        const per = plano.per;
        let html = '<div class="text-slate-500">Mês em exibição: <b>' + dmy(per.ini) + ' a ' + dmy(per.fim) + '</b> · modelo: <b>' + dmy(per.iniAnt) + ' a ' + dmy(per.fimAnt) + '</b></div>';
        plano.grupos.forEach(g => {
            if (g.motivo) {
                html += '<div class="border rounded p-2 bg-slate-50"><b>' + esc(g.rotulo) + '</b> <span class="text-slate-400">(' + g.membros + ' colab.)</span><div class="text-slate-500">Não preenchido: ' + esc(g.motivo) + '.</div></div>';
            } else {
                html += '<div class="border rounded p-2"><b>' + esc(g.rotulo) + '</b> <span class="text-slate-400">(' + g.membros + ' colab.)</span>'
                    + '<div class="text-slate-500">Padrão: ' + esc(g.padrao) + (g.acc != null ? ' · aderência ' + g.acc + '%' : '') + '</div>'
                    + '<div class="font-bold ' + (g.preencher ? 'text-emerald-700' : 'text-slate-500') + '">' + g.preencher + ' dia(s) a preencher'
                    + (g.ja ? ' <span class="text-slate-400 font-semibold">· ' + g.ja + ' já preenchido(s), mantido(s)</span>' : '') + '</div></div>';
            }
        });
        const qtdColab = Object.keys(plano.colaboradores).length;
        if (plano.itens.length === 0) {
            html += '<div class="font-bold text-slate-600">Nada a preencher: não há dias vazios que o padrão do mês anterior preencheria.</div>';
            janela('Preencher sobreaviso do mês', html, '', null);
            return;
        }
        html += '<div class="font-black text-slate-800">Total: ' + plano.itens.length + ' dia(s) de sobreaviso em ' + qtdColab + ' colaborador(es).</div>'
              + '<div class="text-slate-500">Dias que já têm lançamento (sobreaviso, folga, férias etc.) não são alterados.</div>';
        janela('Preencher sobreaviso do mês', html, 'Preencher ' + plano.itens.length + ' dia(s)', () => gravar(plano, 'mes'));
    };

    // ---------------------------------------------------------------- botão da linha do colaborador
    window.preencherLinhaSobreaviso = function () {
        const alvo = (typeof targetCellData !== 'undefined' && targetCellData) ? targetCellData.nome : null;
        const menu = document.getElementById('cell-context-menu');
        if (menu) menu.classList.add('hidden');
        if (!alvo || (currentAccess !== 'admin' && currentAccess !== 'mestre')) return;
        const plano = montarPlano(alvo);
        const g = plano.grupos[0] || {};
        const per = plano.per;
        let html = '<div class="text-slate-500">Mês em exibição: <b>' + dmy(per.ini) + ' a ' + dmy(per.fim) + '</b> · grupo: <b>' + esc(g.rotulo || '—') + '</b></div>';
        if (g.motivo) {
            html += '<div class="font-bold text-slate-600">Não foi possível montar a sequência: ' + esc(g.motivo) + '.</div>';
            janela('Preencher sequência — ' + alvo, html, '', null);
            return;
        }
        html += '<div class="text-slate-500">Padrão: ' + esc(g.padrao) + (g.acc != null ? ' · aderência ' + g.acc + '%' : '') + '</div>';
        if (plano.itens.length === 0) {
            html += '<div class="font-bold text-slate-600">Nenhum dia a preencher para ' + esc(alvo) + ' seguindo o padrão do mês anterior'
                  + (g.ja ? ' (' + g.ja + ' dia(s) já preenchido(s)).' : '.') + '</div>';
            janela('Preencher sequência — ' + alvo, html, '', null);
            return;
        }
        const dias = plano.itens.map(i => dm(i.data) + ' ' + DIAS[parseD(i.data).getDay()]).join(', ');
        html += '<div class="font-black text-slate-800">' + plano.itens.length + ' dia(s) a preencher:</div><div class="border rounded p-2 bg-slate-50 leading-relaxed">' + esc(dias) + '</div>'
              + (g.ja ? '<div class="text-slate-500">' + g.ja + ' dia(s) já preenchido(s) foram mantidos.</div>' : '')
              + '<div class="text-slate-500">Se outro colaborador do grupo estiver no mesmo dia, o sistema mostrará o alerta de plantão compartilhado.</div>';
        janela('Preencher sequência — ' + alvo, html, 'Preencher ' + plano.itens.length + ' dia(s)', () => gravar(plano, 'linha'));
    };

    // ---------------------------------------------------------------- visibilidade dos botões
    function ehGestor() { return currentAccess === 'admin' || currentAccess === 'mestre'; }
    function atualizarBotaoMes() {
        const b = document.getElementById('btn-preencher-mes');
        if (b) b.classList.toggle('hidden', !(ehGestor() && currentView === 'month'));
    }
    if (typeof switchView === 'function') {
        const _sv = switchView;
        switchView = function () { const r = _sv.apply(this, arguments); atualizarBotaoMes(); return r; };
    }
    if (typeof openCellMenu === 'function') {
        const _open = openCellMenu;
        openCellMenu = function () {
            const r = _open.apply(this, arguments);
            const b = document.getElementById('btn-preencher-linha');
            if (b) b.classList.toggle('hidden', !ehGestor());
            return r;
        };
    }
    atualizarBotaoMes();
})();
</script>
<script src="gestao.js?v=rel2"></script>
<script src="relatorios.js?v=4"></script>
<script>/* SESSAO_INATIV_V1: volta ao login quando a tela fica parada; enquanto há uso, mantém a sessão viva */
(function () {
  var logado = <?php echo !empty($_SESSION['usuario']) ? 'true' : 'false'; ?>, lim = <?php echo (int)$sessaoLimMin; ?> * 60000;
  if (!logado || !lim) return;
  var ult = Date.now(), ping = Date.now();
  ['click', 'keydown', 'mousemove', 'wheel', 'touchstart', 'scroll'].forEach(function (e) { document.addEventListener(e, function () { ult = Date.now(); }, { passive: true, capture: true }); });
  setInterval(function () {
    var agora = Date.now();
    if (agora - ult > lim) { location.href = 'index.php?sair_inativo=1'; return; }
    if (ult > ping && agora - ping > 240000) { ping = agora; fetch('api_escala.php?action=sessao_ping', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) { if (!j.sucesso) location.href = 'index.php?sair_inativo=1'; }).catch(function () {}); }
  }, 20000);
})();
</script>
</body>
</html>
