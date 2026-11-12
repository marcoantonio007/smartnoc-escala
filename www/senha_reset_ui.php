<?php /* SENHA_RESET_V1 — "Esqueci minha senha" na tela de login da Escala (incluído pelo index.php) */ ?>
<div class="text-center mt-3"><button type="button" id="btn-esqueci" class="text-xs font-bold text-blue-600 hover:text-blue-800 hover:underline">Esqueci minha senha</button></div>
<div id="modal-senha" class="hidden fixed inset-0 bg-black/60 z-[200] flex items-center justify-center p-4">
  <div class="bg-white p-6 rounded-xl shadow-2xl w-full max-w-sm">
    <div class="flex justify-between items-center mb-3 border-b pb-2">
      <h3 class="text-base font-black text-blue-950 uppercase">Recuperar senha</h3>
      <button type="button" id="rs-fechar" class="text-slate-400 hover:text-red-500 text-2xl leading-none" aria-label="Fechar">&times;</button>
    </div>
    <div id="rs-p1">
      <p class="text-xs text-slate-600 mb-3">Informe o seu usuário. Enviaremos um código de 6 dígitos para o e-mail cadastrado no seu cadastro de colaborador.</p>
      <label class="block text-[10px] font-bold uppercase mb-1">Usuário</label>
      <input id="rs-usuario" autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" maxlength="100" class="w-full border p-2 rounded">
      <div id="rs-erro1" class="hidden text-red-600 text-xs mt-2"></div>
      <button type="button" id="rs-enviar" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 rounded mt-4 disabled:opacity-50">Enviar código</button>
    </div>
    <div id="rs-p2" class="hidden">
      <p id="rs-info" class="text-xs text-slate-600 mb-3"></p>
      <label class="block text-[10px] font-bold uppercase mb-1">Código recebido por e-mail</label>
      <input id="rs-codigo" inputmode="numeric" maxlength="6" autocomplete="one-time-code" placeholder="000000" class="w-full border p-2 rounded mb-2 tracking-[0.4em] text-center font-mono text-lg">
      <label class="block text-[10px] font-bold uppercase mb-1">Nova senha</label>
      <input id="rs-nova" type="password" autocomplete="new-password" maxlength="64" class="w-full border p-2 rounded mb-2">
      <label class="block text-[10px] font-bold uppercase mb-1">Confirmar nova senha</label>
      <input id="rs-conf" type="password" autocomplete="new-password" maxlength="64" class="w-full border p-2 rounded">
      <label class="flex items-center gap-2 text-xs text-slate-600 mt-2"><input type="checkbox" id="rs-ver"> Mostrar senha</label>
      <ul id="rs-reqs" class="text-[11px] mt-2 space-y-0.5">
        <li data-r="cod">Código de 6 dígitos</li>
        <li data-r="len">Mínimo de 8 caracteres</li>
        <li data-r="letra">Pelo menos 1 letra</li>
        <li data-r="num">Pelo menos 1 número</li>
        <li data-r="esp">Pelo menos 1 caractere especial (! @ # $ % &amp; * …)</li>
        <li data-r="sem">Sem espaços</li>
        <li data-r="igual">As duas senhas são iguais</li>
      </ul>
      <div id="rs-erro2" class="hidden text-red-600 text-xs mt-2"></div>
      <button type="button" id="rs-salvar" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 rounded mt-3 disabled:opacity-50" disabled>Alterar senha</button>
      <div class="text-center mt-2"><button type="button" id="rs-reenviar" class="text-xs font-bold text-blue-600 hover:underline disabled:text-slate-400 disabled:no-underline" disabled>Reenviar código</button></div>
    </div>
  </div>
</div>
<script>
(function () {
  'use strict';
  function $(id) { return document.getElementById(id); }
  var rs = { usuario: '', timer: null };
  var campoUsuario = document.querySelector('form input[name="usuario"]'), campoSenha = document.querySelector('form input[name="senha"]');
  async function api(acao, dados) {
    var r = await fetch('senha_reset.php?acao=' + acao, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(dados) });
    try { return await r.json(); } catch (e) { return { sucesso: false, erro: 'Não foi possível falar com o servidor. Tente de novo.' }; }
  }
  function mostrar(p) { $('rs-p1').classList.toggle('hidden', p !== 1); $('rs-p2').classList.toggle('hidden', p !== 2); }
  function erro(n, m) { var e = $('rs-erro' + n); e.textContent = m || ''; e.classList.toggle('hidden', !m); }
  function req() {
    var s = $('rs-nova').value, c = $('rs-conf').value, cod = $('rs-codigo').value.replace(/\D/g, '');
    var t = { cod: [cod.length === 6, cod.length > 0], len: [s.length >= 8 && s.length <= 64, s.length > 0], letra: [/\p{L}/u.test(s), s.length > 0],
              num: [/\p{Nd}/u.test(s), s.length > 0], esp: [/[^\p{L}\p{N}\s]/u.test(s), s.length > 0], sem: [s.length > 0 && !/\s/.test(s), s.length > 0],
              igual: [s !== '' && s === c, c.length > 0] }, todos = true;
    Array.prototype.forEach.call(document.querySelectorAll('#rs-reqs li'), function (li) {
      var x = t[li.getAttribute('data-r')];
      li.className = !x[1] ? 'text-slate-500' : x[0] ? 'text-emerald-600 font-bold' : 'text-red-600';
      li.textContent = li.textContent.replace(/^[✓✗] /, '');
      if (x[1]) li.textContent = (x[0] ? '✓ ' : '✗ ') + li.textContent;
      if (!x[0]) todos = false;
    });
    $('rs-salvar').disabled = !todos;
  }
  function espera(n) {
    clearTimeout(rs.timer); var b = $('rs-reenviar'); b.disabled = true;
    (function t() { if (n <= 0) { b.disabled = false; b.textContent = 'Reenviar código'; return; } b.textContent = 'Reenviar código (' + n + 's)'; n--; rs.timer = setTimeout(t, 1000); })();
  }
  async function enviar() {
    var u = $('rs-usuario').value.trim(); erro(1, '');
    if (!u) return erro(1, 'Informe o usuário.');
    var b = $('rs-enviar'); b.disabled = true;
    try {
      var j = await api('solicitar', { usuario: u });
      if (!j.sucesso) return erro(1, j.erro);
      rs.usuario = u; $('rs-info').textContent = j.mensagem; erro(2, '');
      $('rs-codigo').value = $('rs-nova').value = $('rs-conf').value = ''; $('rs-ver').checked = false; $('rs-nova').type = $('rs-conf').type = 'password';
      mostrar(2); espera(60); req(); $('rs-codigo').focus();
    } finally { b.disabled = false; }
  }
  async function reenviar() {
    erro(2, ''); var b = $('rs-reenviar'); b.disabled = true;
    var j = await api('solicitar', { usuario: rs.usuario });
    if (!j.sucesso) { b.disabled = false; return erro(2, j.erro); }
    $('rs-info').textContent = 'Enviamos um novo código. O anterior deixou de valer.'; espera(60);
  }
  async function salvar() {
    erro(2, ''); var b = $('rs-salvar'); b.disabled = true;
    try {
      var j = await api('redefinir', { usuario: rs.usuario, codigo: $('rs-codigo').value.replace(/\D/g, ''), senha: $('rs-nova').value });
      if (!j.sucesso) return erro(2, j.erro);
      clearTimeout(rs.timer); $('modal-senha').classList.add('hidden');
      $('rs-codigo').value = $('rs-nova').value = $('rs-conf').value = '';
      if (campoUsuario) campoUsuario.value = rs.usuario;
      if (campoSenha) { campoSenha.value = ''; campoSenha.focus(); }
      var ok = $('rs-ok'); if (ok) { ok.textContent = 'Senha alterada com sucesso. Entre com a nova senha.'; ok.classList.remove('hidden'); }
    } finally { req(); }
  }
  $('btn-esqueci').onclick = function () {
    var u = campoUsuario ? campoUsuario.value.trim() : '';
    $('rs-usuario').value = u; erro(1, ''); mostrar(1); $('modal-senha').classList.remove('hidden');
    if (u) enviar(); else $('rs-usuario').focus();
  };
  $('rs-enviar').onclick = enviar; $('rs-reenviar').onclick = reenviar; $('rs-salvar').onclick = salvar;
  $('rs-fechar').onclick = function () { clearTimeout(rs.timer); $('modal-senha').classList.add('hidden'); };
  $('rs-usuario').addEventListener('keydown', function (e) { if (e.key === 'Enter') enviar(); });
  $('rs-codigo').addEventListener('input', function () { this.value = this.value.replace(/\D/g, '').slice(0, 6); req(); });
  $('rs-nova').addEventListener('input', req); $('rs-conf').addEventListener('input', req);
  $('rs-conf').addEventListener('keydown', function (e) { if (e.key === 'Enter' && !$('rs-salvar').disabled) salvar(); });
  $('rs-ver').onchange = function () { $('rs-nova').type = $('rs-conf').type = this.checked ? 'text' : 'password'; };
})();
</script>
<div id="rs-ok" class="hidden text-emerald-700 text-xs mt-2 text-center font-bold"></div>
