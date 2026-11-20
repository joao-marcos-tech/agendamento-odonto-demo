<?php
// Base compartilhada: banco, erros, cabeçalhos, sessão, CSRF, rate limit, login e helpers.
declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');
ini_set('display_errors', '0');
ini_set('log_errors', '1');

const STATUS = [
    'pendente'   => 'Aguardando confirmação',
    'confirmado' => 'Confirmado',
    'concluido'  => 'Atendido',
    'faltou'     => 'Não compareceu',
    'cancelado'  => 'Cancelado',
];
// Status que ocupam horário na agenda.
const STATUS_ATIVOS = ['pendente', 'confirmado'];

function config(string $chave): mixed
{
    static $cfg = null;
    $cfg ??= require __DIR__ . '/config.php';
    return $cfg[$chave];
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . config('db_path'), null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA journal_mode = WAL');
    }
    return $pdo;
}

function eh_api(): bool
{
    return str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/');
}

set_exception_handler(function (Throwable $e): void {
    error_log('[agenda] ' . $e::class . ': ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
    }
    if (eh_api()) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['erro' => 'Não foi possível concluir agora. Tente de novo em instantes.'], JSON_UNESCAPED_UNICODE);
    } else {
        echo 'Algo deu errado. Tente de novo em instantes.';
    }
});

function https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function headers_seguranca(): void
{
    header_remove('X-Powered-By');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; media-src 'self'; font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'");
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    if (https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function iniciar_sessao(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.sid_length', '48');
    ini_set('session.sid_bits_per_character', '6');
    session_name(https() ? '__Host-agenda' : 'agenda_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function e(?string $t): string
{
    return htmlspecialchars((string)$t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---------- CSRF ----------
function csrf_token(): string
{
    iniciar_sessao();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_ok(?string $enviado): bool
{
    iniciar_sessao();
    return is_string($enviado) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $enviado);
}

// Para APIs JSON: token no cabeçalho X-CSRF-Token + checagem de origem.
function exigir_csrf_api(): void
{
    $origem = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origem !== '') {
        $host = parse_url($origem, PHP_URL_HOST) . (parse_url($origem, PHP_URL_PORT) ? ':' . parse_url($origem, PHP_URL_PORT) : '');
        if (!hash_equals((string)($_SERVER['HTTP_HOST'] ?? ''), $host)) {
            json_resposta(['erro' => 'Requisição recusada.'], 403);
        }
    }
    if (!csrf_ok($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
        json_resposta(['erro' => 'Sua sessão expirou. Recarregue a página.'], 403);
    }
}

// ---------- Rate limit (só hash do IP é guardado) ----------
function chave_ip(string $prefixo, string $extra = ''): string
{
    return $prefixo . ':' . hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '?') . '|' . $extra);
}

function limite_excedido(string $chave, int $max, int $janela): bool
{
    $st = db()->prepare('SELECT COUNT(*) FROM tentativas WHERE chave = :c AND criado_em > :t');
    $st->execute([':c' => $chave, ':t' => time() - $janela]);
    return (int)$st->fetchColumn() >= $max;
}

function registrar_tentativa(string $chave): void
{
    db()->prepare('INSERT INTO tentativas (chave, criado_em) VALUES (:c, :t)')->execute([':c' => $chave, ':t' => time()]);
    if (random_int(1, 20) === 1) {
        db()->prepare('DELETE FROM tentativas WHERE criado_em < :t')->execute([':t' => time() - 86400]);
    }
}

function limpar_tentativas(string $chave): void
{
    db()->prepare('DELETE FROM tentativas WHERE chave = :c')->execute([':c' => $chave]);
}

// ---------- Login do painel ----------
function usuario_logado(): ?array
{
    iniciar_sessao();
    if (empty($_SESSION['uid'])) {
        return null;
    }
    $agora = time();
    // Expira com 1 h parado ou 10 h no total.
    if ($agora - (int)($_SESSION['ultimo_uso'] ?? 0) > 3600 || $agora - (int)($_SESSION['login_em'] ?? 0) > 36000) {
        sair();
        return null;
    }
    $_SESSION['ultimo_uso'] = $agora;
    $st = db()->prepare('SELECT id, usuario, nome FROM usuarios WHERE id = :id AND ativo = 1');
    $st->execute([':id' => $_SESSION['uid']]);
    return $st->fetch() ?: null;
}

function exigir_login(bool $json = false): array
{
    $u = usuario_logado();
    if ($u === null) {
        if ($json) {
            json_resposta(['erro' => 'Sessão expirada. Entre de novo.'], 401);
        }
        header('Location: /admin/login.php');
        exit;
    }
    return $u;
}

function sair(): void
{
    iniciar_sessao();
    $_SESSION = [];
    $p = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 3600, 'path' => '/', 'secure' => $p['secure'],
        'httponly' => true, 'samesite' => 'Strict',
    ]);
    session_destroy();
}

function auditar(?int $usuarioId, string $acao, ?int $agendamentoId = null, string $detalhe = ''): void
{
    db()->prepare('INSERT INTO auditoria (usuario_id, acao, agendamento_id, detalhe, criado_em) VALUES (:u, :a, :g, :d, :t)')
        ->execute([':u' => $usuarioId, ':a' => $acao, ':g' => $agendamentoId, ':d' => mb_substr($detalhe, 0, 200), ':t' => date('Y-m-d H:i:s')]);
}

// ---------- Helpers ----------
function json_resposta(array $dados, int $codigo = 200): never
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
}

function exigir_metodo(string $metodo): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $metodo) {
        header('Allow: ' . $metodo);
        json_resposta(['erro' => 'Método não permitido.'], 405);
    }
}

function corpo_json(int $maxBytes = 4096): array
{
    $bruto = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    if ($bruto === false || strlen($bruto) > $maxBytes) {
        json_resposta(['erro' => 'Dados inválidos.'], 413);
    }
    $dados = json_decode($bruto, true);
    if (!is_array($dados)) {
        json_resposta(['erro' => 'Dados inválidos.'], 400);
    }
    return $dados;
}

function texto_limpo(mixed $v, int $max): string
{
    if (!is_string($v)) {
        return '';
    }
    // Tira caracteres de controle e espaços repetidos.
    $v = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v) ?? '';
    $v = trim(preg_replace('/\s{2,}/u', ' ', $v) ?? '');
    return mb_substr($v, 0, $max);
}

// Telefone BR -> 55 + DDD + número (12 ou 13 dígitos) ou '' se inválido.
function normalizar_whatsapp(mixed $v): string
{
    if (!is_string($v)) {
        return '';
    }
    $d = preg_replace('/\D+/', '', $v) ?? '';
    if (strlen($d) === 10 || strlen($d) === 11) {
        $d = '55' . $d;
    }
    if (!preg_match('/^55[1-9][0-9](9[0-9]{8}|[2-8][0-9]{7})$/', $d)) {
        return '';
    }
    return $d;
}

function telefone_bonito(string $d): string
{
    $n = substr($d, 2);
    $ddd = substr($n, 0, 2);
    $r = substr($n, 2);
    return '(' . $ddd . ') ' . (strlen($r) === 9 ? substr($r, 0, 5) . '-' . substr($r, 5) : substr($r, 0, 4) . '-' . substr($r, 4));
}

function hash_ip(): string
{
    return hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '?') . '|agenda');
}
