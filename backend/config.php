<?php
// Basic configuration and helpers for the inventory backend.

declare(strict_types=1);

// Load simple .env if present
(function (): void {
    $envFile = __DIR__ . DIRECTORY_SEPARATOR . '.env';
    if (!is_readable($envFile)) {
        return;
    }
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if ($key !== '') {
            putenv("{$key}={$value}");
        }
    }
})();

function env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    return $value === false ? $default : $value;
}

function db(): PDO
{
    $host = env('DB_HOST', '127.0.0.1');
    $name = env('DB_NAME', 'car_inventory');
    $user = env('DB_USER', 'root');
    $pass = env('DB_PASS', '');
    $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function allow_cors(): void
{
    $origin = env('ALLOWED_ORIGIN', '*');
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
}

function json_body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function respond(mixed $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
}

function fail(string $message, int $status = 400): void
{
    respond(['error' => $message], $status);
}

function query_param(string $key, mixed $default = null): mixed
{
    return $_GET[$key] ?? $default;
}

function bearer_token(): ?string
{
    // Apache on Windows sometimes does not forward Authorization into HTTP_AUTHORIZATION.
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === 'authorization') {
                $header = $value;
                break;
            }
        }
    }
    if (str_starts_with($header, 'Bearer ')) {
        $token = trim(substr($header, 7));
        return $token !== '' ? $token : null;
    }
    return null;
}

function random_token(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

function log_event(PDO $pdo, string $level, string $message, array $context = []): void
{
    $contextJson = $context ? json_encode($context) : null;
    try {
        $stmt = $pdo->prepare('INSERT INTO app_logs (level, message, context) VALUES (:level, :message, :context)');
        $stmt->execute(['level' => $level, 'message' => $message, 'context' => $contextJson]);
    } catch (Throwable $e) {
        // Swallow logging errors to avoid cascading failures.
    }
}

function path_segments(): array
{
    $pathInfo = $_SERVER['PATH_INFO'] ?? '';
    if ($pathInfo === '' && isset($_SERVER['REQUEST_URI'])) {
        $parsed = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '';
        // Strip the script path (/CarInventorySystem/backend/index.php) 
        $scriptPath = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])) . '/' . basename($_SERVER['SCRIPT_NAME']);
        if (strpos($parsed, $scriptPath) === 0) {
            $pathInfo = substr($parsed, strlen($scriptPath));
        } else {
            $pathInfo = $parsed;
        }
    }
    $segments = array_values(array_filter(explode('/', trim((string) $pathInfo, '/'))));
    return $segments;
}
