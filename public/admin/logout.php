<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
headers_seguranca();
iniciar_sessao();
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && csrf_ok($_POST['csrf'] ?? null)) {
    sair();
}
header('Location: /admin/login.php');
