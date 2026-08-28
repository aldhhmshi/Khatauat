<?php

declare(strict_types=1);

function load_env(string $path): void
{
    if (!is_file($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if (($value[0] ?? '') === '"' && str_ends_with($value, '"')) $value = substr($value, 1, -1);
        if (getenv($key) === false) putenv($key . '=' . $value);
    }
}

function config(string $key, mixed $default = null): mixed
{
    return $GLOBALS['config'][$key] ?? $default;
}

function root_path(string $path = ''): string
{
    return dirname(__DIR__, 2) . ($path ? '/' . ltrim($path, '/') : '');
}

function storage_path(string $path = ''): string
{
    $root = rtrim((string) config('storage_root', root_path('storage')), '/\\');
    return $root . ($path ? '/' . ltrim($path, '/\\') : '');
}

function secure_path(string $path = ''): string
{
    $root = rtrim((string) config('secure_root', storage_path('secure')), '/\\');
    return $root . ($path ? '/' . ltrim($path, '/\\') : '');
}

function uploads_path(string $path = ''): string
{
    $root = rtrim((string) config('uploads_root', root_path('public/assets/uploads')), '/\\');
    return $root . ($path ? '/' . ltrim($path, '/\\') : '');
}

function public_uploads_path(string $path = ''): string
{
    $root = rtrim((string) config('public_uploads_root', root_path('public/assets/uploads')), '/\\');
    return $root . ($path ? '/' . ltrim($path, '/\\') : '');
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    $base = (string) config('base_url', '');
    if ($base !== '') return $base . '/' . ltrim($path, '/');
    return '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    return url('assets/' . ltrim($path, '/'));
}

function redirect(string $path): never
{
    header('Location: ' . (str_starts_with($path, 'http') ? $path : url($path)));
    exit;
}

function old(string $key, string $default = ''): string
{
    return e($_SESSION['_old'][$key] ?? $default);
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function pull_flashes(): array
{
    $flashes = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $flashes;
}

function request_method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function input(string $key, mixed $default = null): mixed
{
    return $_POST[$key] ?? $_GET[$key] ?? $default;
}

function is_post(): bool { return request_method() === 'POST'; }

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function now(): string { return date('Y-m-d H:i:s'); }

function excerpt(string $text, int $limit = 130): string
{
    $text = trim(strip_tags($text));
    if (mb_strlen($text) <= $limit) return $text;
    return rtrim(mb_substr($text, 0, $limit - 1)) . '…';
}

function slugify(string $text): string
{
    $text = trim(mb_strtolower($text));
    $text = preg_replace('/[^\p{Arabic}\p{L}\p{N}]+/u', '-', $text) ?? '';
    return trim($text, '-');
}

function current_url_path(): string
{
    return parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
}

function csrf_field(): string
{
    return \Khatauat\Core\Csrf::field();
}

function setting(string $key, mixed $default = null): mixed
{
    try { return \Khatauat\Core\Settings::get($key, $default); } catch (Throwable) { return $default; }
}



function safe_hex_color(?string $value, string $default): string
{
    $value = trim((string)$value);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : $default;
}

function safe_href(?string $value, string $default = '#'): string
{
    $value = trim((string)$value);
    if ($value === '') return $default;
    if (str_starts_with($value, '/')) return $value;
    if (!preg_match('/^[a-z][a-z0-9+.-]*:/i', $value)) return url($value);
    $scheme = strtolower((string)parse_url($value, PHP_URL_SCHEME));
    return in_array($scheme, ['http','https'], true) ? $value : $default;
}

function render_ad_slot(string $placement): void
{
    $safe = ['home_mid','procedures_mid','article_mid','calculators_mid','updates_mid'];
    if (!in_array($placement, $safe, true)) return;
    try {
        $exp = \Khatauat\Core\Database::fetch("SELECT * FROM ad_experiments WHERE placement=? AND status='running' AND (starts_at IS NULL OR starts_at<=CURRENT_TIMESTAMP) AND (ends_at IS NULL OR ends_at>=CURRENT_TIMESTAMP) ORDER BY id DESC LIMIT 1", [$placement]);
        if (!$exp) return;
        $key = '_ad_variant_' . (int)$exp['id'];
        if (!isset($_SESSION[$key])) $_SESSION[$key] = random_int(1, 100) <= (int)$exp['traffic_split'] ? 'A' : 'B';
        $variant = $_SESSION[$key];
        $code = $variant === 'A' ? $exp['variant_a'] : $exp['variant_b'];
        $token = trim((string)$code);
        if ($token === '{{adsense}}') $code = (string)setting('adsense_enabled','0') === '1' ? (string)setting('adsense_code','') : '';
        if ($token === '{{monetag}}') $code = (string)setting('monetag_enabled','0') === '1' ? (string)setting('monetag_code','') : '';
        if (trim((string)$code) === '') return;
        \Khatauat\Core\Database::execute('INSERT INTO ad_experiment_events(experiment_id,variant,event_type,created_at) VALUES(?,?,?,CURRENT_TIMESTAMP)', [(int)$exp['id'],$variant,'impression']);
        echo '<aside class="ad-slot" aria-label="إعلان" data-ad-experiment="' . (int)$exp['id'] . '" data-ad-variant="' . e($variant) . '"><span class="ad-label">إعلان</span><div class="ad-content">' . $code . '</div></aside>';
    } catch (Throwable) {}
}
