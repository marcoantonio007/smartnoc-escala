<?php
class WhatsAppManager {
    private $evolutionUrl = '';
    private $apiKey = '';
    private $instanceName = '';
    private $intervaloMin = 8;
    private $intervaloMax = 15;
    private $ordem = 'acao_primeiro';
    private $pdo;
    private $filaPronta = false;

    public function __construct($pdo = null) {
        $this->pdo = $pdo;
        $this->carregarConfigs();
    }

    private function carregarConfigs() {
        if (!$this->pdo) return;
        try {
            $stmt = $this->pdo->query("SELECT chave, valor FROM configuracoes WHERE chave IN ('evo_url', 'evo_key', 'evo_inst', 'evo_intervalo_min', 'evo_intervalo_max', 'evo_fila_ordem')");
            $configs = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $configs[$row['chave']] = trim((string)$row['valor']);
            }
            $url = rtrim($configs['evo_url'] ?? '', '/');
            $url = preg_replace('#/manager$#i', '', $url);   // o /manager é a interface web, não a API
            $this->evolutionUrl = rtrim($url, '/');
            $this->apiKey = $configs['evo_key'] ?? '';
            $this->instanceName = $configs['evo_inst'] ?? '';

            $min = (int)($configs['evo_intervalo_min'] ?? 0);
            $max = (int)($configs['evo_intervalo_max'] ?? 0);
            if ($min < 1) $min = 8;
            if ($max < $min) $max = $min + 7;
            $this->intervaloMin = $min;
            $this->intervaloMax = $max;
            $this->ordem = (($configs['evo_fila_ordem'] ?? '') === 'mensagem_primeiro') ? 'mensagem_primeiro' : 'acao_primeiro';
        } catch (Exception $e) {}
    }

    private function limparTelefone($tel) {
        $n = preg_replace('/\D/', '', (string)$tel);
        if (strlen($n) === 10 || strlen($n) === 11) $n = '55' . $n;
        return $n;
    }

    private function normalizarGrupo($jid) {
        $jid = trim((string)$jid);
        if ($jid !== '' && strpos($jid, '@') === false) $jid .= '@g.us';
        return $jid;
    }

    private function request($endpoint, $method = 'POST', $data = [], $query = []) {
        if ($this->evolutionUrl === '' || $this->apiKey === '' || $this->instanceName === '') {
            return [
                'success' => false,
                'http_code' => 0,
                'curl_error' => 'Configuração da Evolution incompleta (URL, API Key ou Instância).',
                'url_tentada' => '',
                'response' => null
            ];
        }

        $url = $this->evolutionUrl . $endpoint . '/' . rawurlencode($this->instanceName);
        if (!empty($query)) $url .= '?' . http_build_query($query);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json",
                "apikey: {$this->apiKey}"
            ]
        ]);
        if ($method !== 'GET' && !empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $decoded = json_decode((string)$response, true);

        return [
            'success' => ($httpCode >= 200 && $httpCode < 300),
            'http_code' => $httpCode,
            'curl_error' => $curlError,
            'url_tentada' => $url,
            'response' => $decoded !== null ? $decoded : $response
        ];
    }

    public function adicionarAoGrupo($groupJid, $telefone) {
        return $this->request('/group/updateParticipant', 'POST', [
            "action" => "add",
            "participants" => [$this->limparTelefone($telefone)]
        ], ["groupJid" => $this->normalizarGrupo($groupJid)]);
    }

    public function removerDoGrupo($groupJid, $telefone) {
        return $this->request('/group/updateParticipant', 'POST', [
            "action" => "remove",
            "participants" => [$this->limparTelefone($telefone)]
        ], ["groupJid" => $this->normalizarGrupo($groupJid)]);
    }

    public function enviarTexto($remoteJid, $text) {
        return $this->request('/message/sendText', 'POST', [
            "number" => $this->normalizarGrupo($remoteJid) ?: $remoteJid,
            "text" => $text
        ]);
    }

    public function listarGrupos() {
        return $this->request('/group/fetchAllGroups', 'GET', [], ["getParticipants" => "false"]);
    }

    public function testarConexao() {
        $r = $this->request('/instance/connectionState', 'GET');
        $state = $r['response']['instance']['state'] ?? ($r['response']['state'] ?? null);
        $r['state'] = $state;
        $r['success'] = $r['success'] && $state === 'open';
        return $r;
    }

    // ------------------------------------------------------------------
    // FILA COM INTERVALO ENTRE AS AÇÕES (evita sobrecarregar/bloquear no WhatsApp)
    // ------------------------------------------------------------------
    private function garantirFila() {
        if ($this->filaPronta || !$this->pdo) return;
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS fila_whatsapp (
            id SERIAL PRIMARY KEY,
            acao VARCHAR(10) NOT NULL,
            group_jid VARCHAR(100) NOT NULL,
            telefone VARCHAR(30),
            texto TEXT,
            colaborador VARCHAR(150),
            status VARCHAR(12) NOT NULL DEFAULT 'pendente',
            tentativas INT NOT NULL DEFAULT 0,
            detalhe TEXT,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_fila_whatsapp_status ON fila_whatsapp (status, id)");
        $this->filaPronta = true;
    }

    // acao: 'add' | 'remove' | 'texto'
    public function enfileirar($acao, $groupJid, $telefone = null, $texto = null, $colaborador = null) {
        if (!$this->pdo) return false;
        $this->garantirFila();
        $stmt = $this->pdo->prepare("INSERT INTO fila_whatsapp (acao, group_jid, telefone, texto, colaborador) VALUES (?, ?, ?, ?, ?)");
        return $stmt->execute([$acao, $this->normalizarGrupo($groupJid), $telefone, $texto, $colaborador]);
    }

    // Processa a fila, uma ação por vez, esperando um intervalo aleatório entre elas.
    // Só um processador roda por vez (trava no banco); os outros saem na hora.
    public function processarFila($limite = 100) {
        if (!$this->pdo) return 0;
        $this->garantirFila();

        $travou = $this->pdo->query("SELECT pg_try_advisory_lock(847201)")->fetchColumn();
        if (!$travou) return 0;

        $feitos = 0;
        try {
            // itens que ficaram "processando" por mais de 10 min (processo interrompido) voltam para a fila
            $this->pdo->exec("UPDATE fila_whatsapp SET status = 'pendente' WHERE status = 'processando' AND atualizado_em < NOW() - INTERVAL '10 minutes'");

            while ($feitos < $limite) {
                $this->carregarConfigs();   // pega mudanças de intervalo/ordem feitas na tela
                $item = $this->pdo->query("SELECT * FROM fila_whatsapp WHERE status = 'pendente' ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                if (!$item) break;

                $this->pdo->prepare("UPDATE fila_whatsapp SET status = 'processando', atualizado_em = NOW() WHERE id = ?")->execute([$item['id']]);

                if ($item['acao'] === 'add') {
                    $r = $this->adicionarAoGrupo($item['group_jid'], $item['telefone']);
                } elseif ($item['acao'] === 'remove') {
                    $r = $this->removerDoGrupo($item['group_jid'], $item['telefone']);
                } elseif ($item['acao'] === 'texto') {
                    $r = $this->enviarTexto($item['group_jid'], $item['texto']);
                } else {
                    $r = ['success' => false, 'http_code' => 0, 'curl_error' => 'Ação desconhecida', 'response' => null];
                }

                $tentativas = (int)$item['tentativas'] + 1;
                $status = $r['success'] ? 'ok' : ($tentativas >= 3 ? 'erro' : 'pendente');
                $detalhe = 'HTTP ' . $r['http_code'] . ' ' . $r['curl_error'] . ' ' . substr((string)json_encode($r['response'], JSON_UNESCAPED_UNICODE), 0, 500);

                $this->pdo->prepare("UPDATE fila_whatsapp SET status = ?, tentativas = ?, detalhe = ?, atualizado_em = NOW() WHERE id = ?")
                    ->execute([$status, $tentativas, $detalhe, $item['id']]);
                if ($status === 'ok') $this->registrarEfeitoVinculo($item);   // atualiza o controle de afastamentos
                $feitos++;

                // se ainda há algo na fila, espera antes da próxima ação
                $restam = $this->pdo->query("SELECT 1 FROM fila_whatsapp WHERE status = 'pendente' LIMIT 1")->fetchColumn();
                if ($restam) {
                    $espera = random_int($this->intervaloMin, $this->intervaloMax);
                    $this->registrarProximaExecucao(time() + $espera);
                    sleep($espera);
                }
            }
        } finally {
            $this->pdo->query("SELECT pg_advisory_unlock(847201)")->fetchColumn();
        }
        return $feitos;
    }

    // Enfileira a ação (add/remove) e a mensagem do grupo, na ordem escolhida na tela
    public function enfileirarComMensagem($acao, $groupJid, $telefone, $texto, $colaborador = null) {
        if ($this->ordem === 'mensagem_primeiro') {
            $this->enfileirar('texto', $groupJid, null, $texto, $colaborador);
            $this->enfileirar($acao, $groupJid, $telefone, null, $colaborador);
        } else {
            $this->enfileirar($acao, $groupJid, $telefone, null, $colaborador);
            $this->enfileirar('texto', $groupJid, null, $texto, $colaborador);
        }
    }

    // Salva intervalo (min/max em segundos) e ordem de envio escolhidos na tela
    public function salvarConfigFila($min, $max, $ordem) {
        if (!$this->pdo) return ['sucesso' => false, 'erro' => 'Sem conexão com o banco'];
        $min = (int)$min;
        $max = (int)$max;
        if ($min < 1 || $max < 1) return ['sucesso' => false, 'erro' => 'Informe intervalos de pelo menos 1 segundo.'];
        if ($max < $min) return ['sucesso' => false, 'erro' => 'O intervalo máximo não pode ser menor que o mínimo.'];
        if ($max > 600) return ['sucesso' => false, 'erro' => 'O intervalo máximo permitido é de 600 segundos.'];
        if (!in_array($ordem, ['acao_primeiro', 'mensagem_primeiro'], true)) return ['sucesso' => false, 'erro' => 'Ordem de envio inválida.'];

        $stmt = $this->pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?, ?) ON CONFLICT (chave) DO UPDATE SET valor = EXCLUDED.valor");
        $stmt->execute(['evo_intervalo_min', (string)$min]);
        $stmt->execute(['evo_intervalo_max', (string)$max]);
        $stmt->execute(['evo_fila_ordem', $ordem]);

        $this->intervaloMin = $min;
        $this->intervaloMax = $max;
        $this->ordem = $ordem;
        return ['sucesso' => true, 'intervalo_min' => $min, 'intervalo_max' => $max, 'ordem' => $ordem];
    }

    // Remove da fila as ações que falharam (status 'erro')
    public function limparErrosFila() {
        if (!$this->pdo) return ['sucesso' => false, 'erro' => 'Sem conexão com o banco'];
        $this->garantirFila();
        $n = $this->pdo->exec("DELETE FROM fila_whatsapp WHERE status = 'erro'");
        return ['sucesso' => true, 'removidos' => (int)$n];
    }

    // ------------------------------------------------------------------
    // AÇÕES AUTOMÁTICAS POR STATUS (férias, licença, folga, afastamento)
    // ------------------------------------------------------------------
    public function prepararFila() {
        $this->garantirFila();
        $this->garantirColunasVinculo();
    }

    private function garantirColunasVinculo() {
        static $ok = false;
        if ($ok || !$this->pdo) return;
        $this->pdo->exec("ALTER TABLE fila_whatsapp ADD COLUMN IF NOT EXISTS func_id INT");
        $this->pdo->exec("ALTER TABLE fila_whatsapp ADD COLUMN IF NOT EXISTS grupo_local_id INT");
        $ok = true;
    }

    // Enfileira a ação (add/remove) ligada a um colaborador/grupo, mais a mensagem do grupo,
    // na ordem escolhida na tela. Quando a ação der certo, o controle de afastamentos é atualizado.
    public function enfileirarVinculado($acao, $groupJid, $telefone, $texto, $colaborador, $funcId, $grupoLocalId) {
        if (!$this->pdo) return 0;
        $this->prepararFila();
        $jid = $this->normalizarGrupo($groupJid);
        $ins = $this->pdo->prepare("INSERT INTO fila_whatsapp (acao, group_jid, telefone, texto, colaborador, func_id, grupo_local_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $linhaAcao = [$acao, $jid, $telefone, null, $colaborador, $funcId, $grupoLocalId];
        $linhaTexto = ['texto', $jid, null, $texto, $colaborador, null, null];
        if ($this->ordem === 'mensagem_primeiro') {
            $ins->execute($linhaTexto);
            $ins->execute($linhaAcao);
        } else {
            $ins->execute($linhaAcao);
            $ins->execute($linhaTexto);
        }
        return 2;
    }

    private function registrarEfeitoVinculo($item) {
        $func = $item['func_id'] ?? null;
        $grupo = $item['grupo_local_id'] ?? null;
        if (!$func || !$grupo) return;
        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS grupos_removidos_auto (
                funcionario_id INT NOT NULL,
                grupo_id INT NOT NULL,
                removido_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (funcionario_id, grupo_id)
            )");
            if ($item['acao'] === 'remove') {
                $this->pdo->prepare("INSERT INTO grupos_removidos_auto (funcionario_id, grupo_id) VALUES (?, ?) ON CONFLICT DO NOTHING")->execute([$func, $grupo]);
            } elseif ($item['acao'] === 'add') {
                $this->pdo->prepare("DELETE FROM grupos_removidos_auto WHERE funcionario_id = ? AND grupo_id = ?")->execute([$func, $grupo]);
            }
        } catch (Exception $e) {}
    }

    // Guarda quando a próxima ação da fila será executada (usado na tela de acompanhamento)
    private function registrarProximaExecucao($epoch) {
        try {
            $this->pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('fila_wpp_proximo_em', ?) ON CONFLICT (chave) DO UPDATE SET valor = EXCLUDED.valor")
                ->execute([(string)$epoch]);
        } catch (Exception $e) {}
    }

    private function sqlNomeGrupo() {
        $existe = $this->pdo->query("SELECT to_regclass('public.whatsapp_grupos')")->fetchColumn();
        if (!$existe) return "NULL::text";
        return "(SELECT g.nome FROM whatsapp_grupos g WHERE g.grupo_id = f.group_jid LIMIT 1)";
    }

    // Dados para a tela "Fila de Intervalo do WhatsApp"
    public function statusFila() {
        if (!$this->pdo) return ['sucesso' => false, 'erro' => 'Sem conexão com o banco'];
        $this->garantirFila();

        $agora = time();
        $mediaIntervalo = ($this->intervaloMin + $this->intervaloMax) / 2;
        $porAcao = $mediaIntervalo + 2;   // intervalo médio + ~2s da chamada na API

        // há um processador rodando? (trava da fila segurada por outra conexão)
        $ativo = ((int)$this->pdo->query("SELECT COUNT(*) FROM pg_locks WHERE locktype = 'advisory' AND classid = 0 AND objid = 847201 AND objsubid = 1")->fetchColumn()) > 0;

        $proximoEm = (int)$this->pdo->query("SELECT valor FROM configuracoes WHERE chave = 'fila_wpp_proximo_em'")->fetchColumn();
        $nomeGrupo = $this->sqlNomeGrupo();

        $lista = $this->pdo->query("SELECT f.id, f.acao, f.colaborador, f.group_jid, f.status, f.tentativas, $nomeGrupo AS grupo_nome,
                EXTRACT(EPOCH FROM (NOW()::timestamp - f.criado_em)) AS idade
            FROM fila_whatsapp f
            WHERE f.status IN ('pendente', 'processando')
            ORDER BY f.id ASC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);

        $temProcessando = false;
        foreach ($lista as $l) { if ($l['status'] === 'processando') $temProcessando = true; }
        $base = $temProcessando ? (2 + $mediaIntervalo) : max(0, $proximoEm - $agora);

        $ativos = [];
        $k = 0;
        $totalEstimado = 0;
        foreach ($lista as $l) {
            if (!$ativo) {
                $eta = null;                                   // ninguém processando: sem previsão
            } elseif ($l['status'] === 'processando') {
                $eta = 0;
            } else {
                $eta = (int)round($base + $k * $porAcao);
                $k++;
            }
            if ($eta !== null && $eta > $totalEstimado) $totalEstimado = $eta;
            $ativos[] = [
                'id' => (int)$l['id'],
                'acao' => $l['acao'],
                'colaborador' => $l['colaborador'],
                'grupo' => $l['grupo_nome'] ?: $l['group_jid'],
                'status' => $l['status'],
                'tentativas' => (int)$l['tentativas'],
                'idade' => (int)round($l['idade']),
                'eta' => $eta
            ];
        }
        // do menor para o maior tempo de execução (sem previsão por último)
        usort($ativos, function ($a, $b) {
            $ea = $a['eta'] === null ? PHP_INT_MAX : $a['eta'];
            $eb = $b['eta'] === null ? PHP_INT_MAX : $b['eta'];
            if ($ea === $eb) return $a['id'] <=> $b['id'];
            return $ea <=> $eb;
        });

        $fin = $this->pdo->query("SELECT f.id, f.acao, f.colaborador, f.group_jid, f.status, f.tentativas, f.detalhe, $nomeGrupo AS grupo_nome,
                EXTRACT(EPOCH FROM (NOW()::timestamp - f.atualizado_em)) AS ha
            FROM fila_whatsapp f
            WHERE f.status = 'erro' AND f.atualizado_em > NOW()::timestamp - INTERVAL '24 hours'
            ORDER BY f.atualizado_em DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        $finalizados = [];
        foreach ($fin as $f) {
            $finalizados[] = [
                'id' => (int)$f['id'],
                'acao' => $f['acao'],
                'colaborador' => $f['colaborador'],
                'grupo' => $f['grupo_nome'] ?: $f['group_jid'],
                'status' => $f['status'],
                'tentativas' => (int)$f['tentativas'],
                'detalhe' => $f['detalhe'],
                'ha' => (int)round($f['ha'])
            ];
        }

        $cont = $this->pdo->query("SELECT status, COUNT(*) AS n FROM fila_whatsapp
            WHERE status IN ('pendente', 'processando') OR atualizado_em > NOW()::timestamp - INTERVAL '24 hours'
            GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);

        return [
            'sucesso' => true,
            'processador_ativo' => $ativo,
            'intervalo_min' => $this->intervaloMin,
            'intervalo_max' => $this->intervaloMax,
            'ordem' => $this->ordem,
            'total_estimado' => $totalEstimado,
            'contagem' => [
                'pendente' => (int)($cont['pendente'] ?? 0),
                'processando' => (int)($cont['processando'] ?? 0),
                'ok' => (int)($cont['ok'] ?? 0),
                'erro' => (int)($cont['erro'] ?? 0)
            ],
            'ativos' => $ativos,
            'finalizados' => $finalizados
        ];
    }

    // Responde ao navegador imediatamente e continua processando a fila em segundo plano.
    public function responderEProcessarFila(array $payload) {
        ignore_user_abort(true);
        set_time_limit(0);
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close(); // não trava outras telas do usuário

        $json = json_encode($payload);
        while (ob_get_level() > 0) { @ob_end_clean(); }
        header('Content-Length: ' . strlen($json));
        header('Connection: close');
        echo $json;
        flush();
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

        return $this->processarFila();
    }
}
