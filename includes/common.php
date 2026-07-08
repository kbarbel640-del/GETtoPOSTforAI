<?php

define('DB_FILE', dirname(__DIR__) . '/macro_generator.db');
define('API_KEY_FILE', dirname(__DIR__) . '/api_key.php');
define('ALLOWED_METHODS', ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS']);
define('MAX_URL_LENGTH', 2048);
define('MAX_BODY_LENGTH', 65536);
define('MAX_HEADERS_LENGTH', 65536);

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function loadConfig(): array
{
    if (!file_exists(API_KEY_FILE)) {
        throw new RuntimeException('API key file not found');
    }

    require API_KEY_FILE;

    return [
        'apiKey' => $apiKey ?? '',
        'requireHttps' => $requireHttps ?? true,
        'allowedDomains' => $allowedDomains ?? [],
        'rateLimitPerMinute' => (int) ($rateLimitPerMinute ?? 60),
    ];
}

function enforceHttps(array $config): void
{
    if (!$config['requireHttps']) {
        return;
    }

    $https = $_SERVER['HTTPS'] ?? '';
    if ($https && $https !== 'off') {
        return;
    }

    $forwarded = strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
    if ($forwarded === 'https') {
        return;
    }

    http_response_code(403);
    exit('HTTPS is required');
}

function openDb(): SQLite3
{
    $db = new SQLite3(DB_FILE);
    $db->exec("CREATE TABLE IF NOT EXISTS macros (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT UNIQUE,
        method TEXT,
        url TEXT,
        body TEXT,
        headers TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec('CREATE TABLE IF NOT EXISTS execution_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        macro_id INTEGER,
        macro_name TEXT,
        ip TEXT,
        http_status INTEGER,
        success INTEGER NOT NULL,
        error TEXT,
        executed_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )');

    return $db;
}

function validateMacroName(string $name): ?string
{
    if (!preg_match('/^[a-zA-Z0-9_-]{1,50}$/', $name)) {
        return 'Invalid macro name';
    }

    return null;
}

function validateMethod(string $method): ?string
{
    if (!in_array(strtoupper($method), ALLOWED_METHODS, true)) {
        return 'Invalid HTTP method';
    }

    return null;
}

function validateBody(string $body): ?string
{
    if (strlen($body) > MAX_BODY_LENGTH) {
        return 'body is too long (max 64 KB)';
    }

    return null;
}

function validateHeaders(string $headers): ?string
{
    if (strlen($headers) > MAX_HEADERS_LENGTH) {
        return 'headers are too long (max 64 KB)';
    }

    if ($headers === '') {
        return null;
    }

    $decoded = json_decode($headers, true);
    if (!is_array($decoded)) {
        return 'headers must be a valid JSON object';
    }

    foreach ($decoded as $key => $value) {
        if (!is_string($key) || (!is_string($value) && !is_numeric($value))) {
            return 'headers may only contain string keys and string/number values';
        }
    }

    return null;
}

function isBlockedHost(string $host): bool
{
    $host = strtolower(trim($host, '[]'));
    $blockedHosts = ['localhost', 'metadata.google.internal', 'metadata.goog'];

    if (in_array($host, $blockedHosts, true)) {
        return true;
    }

    if (str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
        return true;
    }

    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return isBlockedIp($host);
    }

    return false;
}

function isBlockedIp(string $ip): bool
{
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return true;
    }

    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) === false;
}

function isDomainAllowed(string $host, array $allowedDomains): bool
{
    if ($allowedDomains === []) {
        return true;
    }

    $host = strtolower($host);
    foreach ($allowedDomains as $domain) {
        $domain = strtolower((string) $domain);
        if ($host === $domain || str_ends_with($host, '.' . $domain)) {
            return true;
        }
    }

    return false;
}

function validateTargetUrl(string $url, array $config): ?string
{
    if (strlen($url) > MAX_URL_LENGTH) {
        return 'URL is too long';
    }

    $parts = parse_url($url);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        return 'Invalid URL';
    }

    $scheme = strtolower($parts['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) {
        return 'Only http/https URLs are allowed';
    }

    if ($config['requireHttps'] && $scheme !== 'https') {
        return 'Only HTTPS URLs are allowed';
    }

    $host = strtolower($parts['host']);
    if (isBlockedHost($host)) {
        return 'Target host is not allowed';
    }

    if (!isDomainAllowed($host, $config['allowedDomains'])) {
        return 'Domain is not in the whitelist';
    }

    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return isBlockedIp($host) ? 'Target IP is not allowed' : null;
    }

    $ips = gethostbynamel($host);
    if ($ips === false || $ips === []) {
        return 'Target host could not be resolved';
    }

    foreach ($ips as $ip) {
        if (isBlockedIp($ip)) {
            return 'Target host resolves to a disallowed IP address';
        }
    }

    return null;
}

function dashboardStyles(): string
{
    return '<style>
        :root { color-scheme: light dark; }
        body { font-family: system-ui, sans-serif; line-height: 1.5; margin: 0; background: #f4f4f5; color: #111; }
        .wrap { max-width: 1100px; margin: 0 auto; padding: 1.5rem 1rem 3rem; }
        header { display: flex; justify-content: space-between; align-items: center; gap: 1rem; margin-bottom: 1.5rem; }
        h1, h2, h3 { line-height: 1.2; margin: 0 0 .75rem; }
        .card { background: #fff; border: 1px solid #d4d4d8; border-radius: 12px; padding: 1.25rem; margin-bottom: 1rem; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; }
        .stat { text-align: center; }
        .stat strong { display: block; font-size: 1.75rem; }
        .muted { color: #71717a; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border-bottom: 1px solid #e4e4e7; padding: .6rem .5rem; text-align: left; vertical-align: top; }
        th { font-size: .85rem; text-transform: uppercase; letter-spacing: .03em; color: #52525b; }
        code, pre { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
        .badge { display: inline-block; padding: .15rem .5rem; border-radius: 999px; font-size: .8rem; font-weight: 600; }
        .badge-ok { background: #dcfce7; color: #166534; }
        .badge-bad { background: #fee2e2; color: #991b1b; }
        .badge-warn { background: #fef9c3; color: #854d0e; }
        .btn { display: inline-block; border: 0; border-radius: 8px; padding: .55rem 1rem; background: #18181b; color: #fff; cursor: pointer; text-decoration: none; font: inherit; }
        .btn-secondary { background: #e4e4e7; color: #18181b; }
        .btn-danger { background: #b91c1c; }
        form.inline { display: inline-block; margin-right: .5rem; }
        .alert { padding: .75rem 1rem; border-radius: 8px; margin-bottom: 1rem; }
        .alert-error { background: #fee2e2; color: #991b1b; }
        .alert-success { background: #dcfce7; color: #166534; }
        input[type=password], input[type=file], select { width: 100%; max-width: 320px; padding: .55rem .65rem; border: 1px solid #d4d4d8; border-radius: 8px; }
        .actions { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; }
        @media (prefers-color-scheme: dark) {
            body { background: #09090b; color: #fafafa; }
            .card { background: #18181b; border-color: #3f3f46; }
            th { color: #a1a1aa; }
            th, td { border-bottom-color: #3f3f46; }
            .btn-secondary { background: #3f3f46; color: #fafafa; }
            input[type=password], input[type=file], select { background: #09090b; color: #fafafa; border-color: #3f3f46; }
        }
    </style>';
}

function renderDashboardPage(string $title, string $body): void
{
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h($title) . '</title>' . dashboardStyles() . '</head><body><div class="wrap">' . $body . '</div></body></html>';
    exit;
}