<?php /* LOGIN_TELA_V1 — tela de login da Escala (mesmo visual escuro do SmartNOC, com a identidade "Plantão Técnico · Escala de Sobreaviso") */
$lgUsuario = htmlspecialchars((string)($_POST['usuario'] ?? ''), ENT_QUOTES, 'UTF-8');
?>
<style>
  #lg, #lg *, #lg *::before, #lg *::after { box-sizing: border-box; }
  #lg { position: fixed; inset: 0; z-index: 100; overflow: auto; background: radial-gradient(ellipse at 50% 35%, #1a1f4d 0%, #0b1030 45%, #060818 100%); color: #e2e8f0; font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
  #lg-rede { position: fixed; inset: 0; width: 100%; height: 100%; pointer-events: none; }
  #lg .lg-grade { position: fixed; inset: 0; pointer-events: none; background-image: linear-gradient(rgba(129,140,248,.05) 1px, transparent 1px), linear-gradient(90deg, rgba(129,140,248,.05) 1px, transparent 1px); background-size: 48px 48px; mask-image: radial-gradient(ellipse at center, #000 30%, transparent 80%); -webkit-mask-image: radial-gradient(ellipse at center, #000 30%, transparent 80%); }
  #lg .lg-topo { position: relative; z-index: 2; display: flex; justify-content: space-between; align-items: center; padding: 20px 28px; gap: 12px; }
  #lg .lg-marca { display: flex; align-items: center; gap: 12px; }
  #lg .lg-marca-ic { width: 40px; height: 40px; border-radius: 12px; display: grid; place-items: center; background: rgba(99,102,241,.18); border: 1px solid rgba(129,140,248,.35); }
  #lg .lg-marca b { display: block; font-size: 15px; color: #fff; letter-spacing: .2px; }
  #lg .lg-marca span { font-size: 12px; color: #a5b4fc; }
  #lg .lg-status { display: flex; align-items: center; gap: 8px; font-size: 12px; color: #e2e8f0; padding: 6px 12px; border-radius: 999px; border: 1px solid rgba(129,140,248,.35); background: rgba(15,18,48,.6); }
  #lg .lg-status i { width: 8px; height: 8px; border-radius: 50%; background: #34d399; box-shadow: 0 0 8px #34d399; }
  #lg .lg-centro { position: relative; z-index: 2; min-height: calc(100% - 180px); display: flex; align-items: center; justify-content: center; padding: 12px 16px; }
  #lg .lg-card { width: 100%; max-width: 420px; padding: 34px 36px 22px; border-radius: 24px; background: linear-gradient(180deg, rgba(23,27,66,.88), rgba(12,15,40,.92)); border: 1px solid rgba(129,140,248,.28); box-shadow: 0 30px 80px rgba(0,0,0,.55), inset 0 1px 0 rgba(255,255,255,.04); backdrop-filter: blur(10px); }
  #lg .lg-logo { width: 84px; height: 84px; margin: 0 auto 14px; border-radius: 22px; display: grid; place-items: center; background: linear-gradient(145deg, #6366f1, #7c3aed); box-shadow: 0 0 0 10px rgba(99,102,241,.10), 0 14px 34px rgba(99,102,241,.45); }
  #lg h1 { text-align: center; font-size: 30px; font-weight: 800; color: #fff; margin: 0; letter-spacing: -.5px; }
  #lg .lg-sub { text-align: center; color: #a5b4fc; font-size: 13px; font-weight: 600; letter-spacing: 2px; text-transform: uppercase; margin-top: 4px; }
  #lg .lg-desc { text-align: center; color: #94a3b8; font-size: 13px; margin: 6px 0 22px; }
  #lg label.lg-rot { display: block; font-size: 13px; font-weight: 600; color: #e2e8f0; margin: 0 0 6px; }
  #lg .lg-campo { position: relative; margin-bottom: 16px; }
  #lg .lg-campo svg.lg-ic { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #8b93b8; pointer-events: none; }
  #lg .lg-campo input { width: 100%; height: 50px; padding: 0 44px; border-radius: 12px; background: rgba(6,8,24,.65); border: 1px solid rgba(148,163,184,.25); color: #fff; font-size: 14px; outline: none; transition: border-color .15s, box-shadow .15s; }
  #lg .lg-campo input::placeholder { color: #64748b; }
  #lg .lg-campo input:focus { border-color: #818cf8; box-shadow: 0 0 0 3px rgba(129,140,248,.25); }
  #lg .lg-olho { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: 0; color: #8b93b8; cursor: pointer; padding: 6px; border-radius: 8px; }
  #lg .lg-olho:hover { color: #e2e8f0; }
  #lg .lg-linha { display: flex; justify-content: flex-end; margin: -4px 0 18px; }
  #lg .lg-link { background: none; border: 0; color: #a5b4fc; font-size: 13px; cursor: pointer; padding: 0; }
  #lg .lg-link:hover { color: #c7d2fe; text-decoration: underline; }
  #lg .lg-btn { width: 100%; height: 52px; border: 0; border-radius: 12px; cursor: pointer; color: #fff; font-weight: 700; font-size: 15px; display: flex; align-items: center; justify-content: center; gap: 10px; background: linear-gradient(90deg, #6366f1, #7c3aed); box-shadow: 0 10px 26px rgba(99,102,241,.4); transition: filter .15s, transform .05s; }
  #lg .lg-btn:hover { filter: brightness(1.08); } #lg .lg-btn:active { transform: translateY(1px); }
  #lg .lg-erro { margin: 14px 0 0; padding: 10px 12px; border-radius: 10px; background: rgba(239,68,68,.12); border: 1px solid rgba(239,68,68,.35); color: #fecaca; font-size: 13px; text-align: center; }
  #lg .lg-rodape { display: flex; justify-content: space-between; gap: 8px; margin-top: 20px; padding-top: 14px; border-top: 1px solid rgba(148,163,184,.15); font-size: 12px; color: #8b93b8; flex-wrap: wrap; }
  #lg .lg-rodape b { color: #e2e8f0; font-weight: 500; display: inline-flex; align-items: center; gap: 5px; }
  #lg .lg-pe { position: relative; z-index: 2; text-align: center; padding: 18px 16px 24px; }
  #lg .lg-hora { font-family: "JetBrains Mono", ui-monospace, Consolas, monospace; font-size: 32px; color: #fff; letter-spacing: 2px; }
  #lg .lg-data { font-size: 12px; color: #94a3b8; margin-top: 2px; }
  #lg .lg-esq-orig > div:first-child { display: none; } /* esconde o botão padrão; o link "Esqueceu a senha?" aciona o mesmo fluxo */
  #lg #rs-ok { display: block; max-width: 420px; margin: 0 auto; padding: 10px 14px; border-radius: 10px; background: rgba(16,185,129,.12); border: 1px solid rgba(16,185,129,.35); color: #6ee7b7 !important; position: relative; z-index: 2; }
  #lg #rs-ok:empty, #lg #rs-ok.hidden { display: none; }
  @media (max-width: 520px) { #lg .lg-card { padding: 28px 20px 18px; border-radius: 20px; } #lg h1 { font-size: 26px; } #lg .lg-topo { padding: 14px 16px; } #lg .lg-status { display: none; } #lg .lg-hora { font-size: 26px; } }
</style>
<div id="lg">
  <canvas id="lg-rede" aria-hidden="true"></canvas><div class="lg-grade" aria-hidden="true"></div>
  <div class="lg-topo">
    <div class="lg-marca">
      <div class="lg-marca-ic"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#c7d2fe" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 9h18"/><path d="M8 14l2.5 2.5L16 12"/></svg></div>
      <div><b>Plantão Técnico</b><span>Escala de Sobreaviso</span></div>
    </div>
    <div class="lg-status"><i></i> Sistema online</div>
  </div>
  <div class="lg-centro">
    <div class="lg-card">
      <div class="lg-logo"><svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="15" height="15" rx="2"/><path d="M14 2v4M7 2v4M3 9h15"/><circle cx="17.5" cy="17.5" r="4.5" fill="#7c3aed"/><path d="M17.5 15.5v2l1.4 1.2"/></svg></div>
      <h1>Plantão Técnico</h1>
      <div class="lg-sub">Escala de Sobreaviso</div>
      <div class="lg-desc">Entre com seu usuário e senha</div>
      <form method="POST" autocomplete="on">
        <input type="hidden" name="acao" value="login">
        <label class="lg-rot" for="lg-usuario">Usuário</label>
        <div class="lg-campo">
          <svg class="lg-ic" width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10zm0 2c-4.4 0-8 2.2-8 5v1h16v-1c0-2.8-3.6-5-8-5z"/></svg>
          <input id="lg-usuario" type="text" name="usuario" value="<?php echo $lgUsuario; ?>" placeholder="seu.usuario" autocomplete="username" autocapitalize="none" spellcheck="false" required <?php echo $lgUsuario === '' ? 'autofocus' : ''; ?>>
        </div>
        <label class="lg-rot" for="lg-senha">Senha</label>
        <div class="lg-campo">
          <svg class="lg-ic" width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M17 9V7a5 5 0 0 0-10 0v2H5v13h14V9h-2zm-8-2a3 3 0 0 1 6 0v2H9V7z"/></svg>
          <input id="lg-senha" type="password" name="senha" placeholder="••••••••" autocomplete="current-password" required <?php echo $lgUsuario !== '' ? 'autofocus' : ''; ?>>
          <button type="button" class="lg-olho" id="lg-olho" aria-label="Mostrar senha" title="Mostrar senha"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg></button>
        </div>
        <div class="lg-linha"><button type="button" class="lg-link" id="lg-esqueci">Esqueceu a senha?</button></div>
        <button type="submit" class="lg-btn">Acessar escala <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="M10 17l5-5-5-5M15 12H3"/></svg></button>
      </form>
      <?php if (!empty($msgErro)) echo '<div class="lg-erro">' . htmlspecialchars($msgErro, ENT_QUOTES, 'UTF-8') . '</div>'; ?>
      <div class="lg-rodape"><span>Acesso restrito a usuários autorizados.</span><b><svg width="12" height="12" viewBox="0 0 24 24" fill="#34d399"><path d="M17 9V7a5 5 0 0 0-10 0v2H5v13h14V9h-2zm-8-2a3 3 0 0 1 6 0v2H9V7z"/></svg> Conexão segura</b></div>
    </div>
  </div>
  <div class="lg-esq-orig"><?php include __DIR__ . '/senha_reset_ui.php'; // fica fora do card: o blur do card prenderia o modal dentro dele ?></div>
  <div class="lg-pe"><div class="lg-hora" id="lg-hora">--:--:--</div><div class="lg-data" id="lg-data"></div></div>
</div>
<script>
(function () {
  'use strict';
  var olho = document.getElementById('lg-olho'), senha = document.getElementById('lg-senha');
  olho.onclick = function () { var v = senha.type === 'password'; senha.type = v ? 'text' : 'password'; olho.setAttribute('aria-label', v ? 'Ocultar senha' : 'Mostrar senha'); };
  document.getElementById('lg-esqueci').onclick = function () { var b = document.getElementById('btn-esqueci'); if (b) b.click(); };
  var DIAS = ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'];
  var MESES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
  function p(n) { return String(n).padStart(2, '0'); }
  function relogio() { var d = new Date(); document.getElementById('lg-hora').textContent = p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
    document.getElementById('lg-data').textContent = DIAS[d.getDay()] + ', ' + d.getDate() + ' de ' + MESES[d.getMonth()] + ' de ' + d.getFullYear() + '  |  © ' + d.getFullYear() + ' Avantia · Plantão Técnico'; }
  relogio(); setInterval(relogio, 1000);
  // fundo: rede de pontos ligados (fica parada se o sistema pedir menos animação)
  var cv = document.getElementById('lg-rede'), cx = cv.getContext('2d'), pts = [], W, H, dpr = Math.min(window.devicePixelRatio || 1, 2);
  var calmo = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  function tamanho() { W = cv.clientWidth; H = cv.clientHeight; cv.width = W * dpr; cv.height = H * dpr; cx.setTransform(dpr, 0, 0, dpr, 0, 0);
    var n = Math.round(Math.min(90, W * H / 16000)); pts = [];
    for (var i = 0; i < n; i++) pts.push({ x: Math.random() * W, y: Math.random() * H, vx: (Math.random() - .5) * .25, vy: (Math.random() - .5) * .25, r: Math.random() * 2 + 1.2 }); }
  function quadro() {
    cx.clearRect(0, 0, W, H);
    for (var i = 0; i < pts.length; i++) { var a = pts[i];
      if (!calmo) { a.x += a.vx; a.y += a.vy; if (a.x < 0 || a.x > W) a.vx *= -1; if (a.y < 0 || a.y > H) a.vy *= -1; }
      for (var j = i + 1; j < pts.length; j++) { var b = pts[j], dx = a.x - b.x, dy = a.y - b.y, d = dx * dx + dy * dy;
        if (d < 16000) { cx.strokeStyle = 'rgba(129,140,248,' + (0.18 * (1 - d / 16000)).toFixed(3) + ')'; cx.lineWidth = 1; cx.beginPath(); cx.moveTo(a.x, a.y); cx.lineTo(b.x, b.y); cx.stroke(); } }
      cx.fillStyle = 'rgba(129,140,248,.55)'; cx.beginPath(); cx.arc(a.x, a.y, a.r, 0, 6.283); cx.fill();
      cx.fillStyle = 'rgba(165,180,252,.12)'; cx.beginPath(); cx.arc(a.x, a.y, a.r * 3, 0, 6.283); cx.fill(); }
    if (!calmo) requestAnimationFrame(quadro);
  }
  tamanho(); quadro(); window.addEventListener('resize', function () { tamanho(); if (calmo) quadro(); });
})();
</script>
