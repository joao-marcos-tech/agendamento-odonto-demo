<?php
declare(strict_types=1);
require __DIR__ . '/../../app/agenda.php';

headers_seguranca();
header('Cache-Control: no-store');
iniciar_sessao();

if (usuario_logado()) {
    header('Location: /admin/');
    exit;
}

$erro = '';
$usuario = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $usuario = texto_limpo($_POST['usuario'] ?? '', 40);
    $senha = is_string($_POST['senha'] ?? null) ? $_POST['senha'] : '';
    $chaveUser = chave_ip('login', mb_strtolower($usuario));
    $chaveIp = chave_ip('login-ip');

    if (!csrf_ok($_POST['csrf'] ?? null)) {
        $erro = 'A página ficou aberta muito tempo. Tente de novo.';
    } elseif (limite_excedido($chaveUser, (int)config('login_max_tentativas'), (int)config('login_janela_seg'))
        || limite_excedido($chaveIp, 20, (int)config('login_janela_seg'))) {
        $erro = 'Muitas tentativas. Espere 15 minutos e tente de novo.';
    } else {
        $st = db()->prepare('SELECT id, senha_hash FROM usuarios WHERE usuario = :u AND ativo = 1');
        $st->execute([':u' => $usuario]);
        $u = $st->fetch();
        // Mesmo sem usuário, roda o verify: o tempo de resposta não entrega quais usuários existem.
        $hash = $u['senha_hash'] ?? '$2y$10$hw5Uyv2i.G/8U4i5wOWFXOp.9wH92HdZlJiyBQ8wSHhn6A0dL6wTu';
        $ok = password_verify($senha, $hash) && $u;
        if ($ok) {
            if (password_needs_rehash($u['senha_hash'], PASSWORD_DEFAULT)) {
                db()->prepare('UPDATE usuarios SET senha_hash = :h WHERE id = :id')
                    ->execute([':h' => password_hash($senha, PASSWORD_DEFAULT), ':id' => $u['id']]);
            }
            limpar_tentativas($chaveUser);
            session_regenerate_id(true);
            $_SESSION = ['uid' => (int)$u['id'], 'login_em' => time(), 'ultimo_uso' => time(), 'csrf' => bin2hex(random_bytes(32))];
            auditar((int)$u['id'], 'login');
            header('Location: /admin/');
            exit;
        }
        registrar_tentativa($chaveUser);
        registrar_tentativa($chaveIp);
        $erro = 'Usuário ou senha incorretos.';
    }
}
$csrf = csrf_token();
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Entrar · Recepção (demo)</title>
<link rel="stylesheet" href="/assets/css/admin.css?v=1">
<link rel="icon" href="/assets/img/marca.svg" type="image/svg+xml">
</head>
<body class="pg-login">
<main class="login">
  <div class="login-arte" aria-hidden="true">
    <p class="login-frase">A agenda inteira,<br><em>numa tela só.</em></p>
  </div>
  <form class="login-form" method="post" action="/admin/login.php" autocomplete="on">
    <p class="login-marca"><svg viewBox="0 0 40 40" aria-hidden="true"><path d="M6 30c0-14 6-22 14-22s14 8 14 22" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round"/><circle cx="20" cy="28" r="3.4" fill="currentColor"/></svg> Esmalte · Recepção</p>
    <h1>Entrar</h1>
    <p class="login-demo">Demonstração. Os pacientes da agenda são fictícios.</p>
    <?php if ($erro): ?><p class="erro" role="alert"><?= e($erro) ?></p><?php endif; ?>
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <label for="usuario">Usuário</label>
    <input id="usuario" name="usuario" type="text" autocomplete="username" required maxlength="40" value="<?= e($usuario) ?>" autocapitalize="none" spellcheck="false">
    <label for="senha">Senha</label>
    <input id="senha" name="senha" type="password" autocomplete="current-password" required maxlength="200">
    <button class="b b-lima b-largo" type="submit">Entrar</button>
    <a class="voltar" href="/">← Voltar para o site</a>
  </form>
</main>
</body>
</html>
