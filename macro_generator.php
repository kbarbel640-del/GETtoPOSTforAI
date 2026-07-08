<?php
// ============================================================
// MAKRO GENERATOR - GET-gesteuert, SQLite, cURL-Proxy
// ============================================================

// ---------- Konfiguration ----------
define('DB_FILE', __DIR__ . '/macro_generator.db');
define('API_KEY_FILE', __DIR__ . '/api_key.php');
define('ALLOWED_METHODS', ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS']);

// ---------- Ausgabeformat ----------
function wantsHtml(): bool {
    $format = strtolower($_GET['format'] ?? '');
    if ($format === 'html') {
        return true;
    }
    if ($format === 'json') {
        return false;
    }

    $action = $_GET['action'] ?? '';
    return $action === '' || $action === 'help';
}

function h(?string $value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function respondJson(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function renderPage(string $title, string $body, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>' . h($title) . '</title>
    <style>
        :root { color-scheme: light dark; }
        body { font-family: system-ui, sans-serif; line-height: 1.5; margin: 2rem auto; max-width: 960px; padding: 0 1rem; }
        h1, h2, h3 { line-height: 1.2; }
        code, pre { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
        pre { overflow: auto; padding: 1rem; border-radius: 8px; background: rgba(127,127,127,.12); }
        table { width: 100%; border-collapse: collapse; margin: 1rem 0; }
        th, td { border: 1px solid rgba(127,127,127,.35); padding: .5rem .75rem; text-align: left; vertical-align: top; }
        th { background: rgba(127,127,127,.12); }
        .badge { display: inline-block; padding: .15rem .5rem; border-radius: 999px; font-size: .85rem; font-weight: 600; }
        .badge-success { background: #d1fae5; color: #065f46; }
        .badge-error { background: #fee2e2; color: #991b1b; }
        .muted { opacity: .75; }
        .actions { margin-top: 1.5rem; }
        .actions a { margin-right: 1rem; }
        iframe.preview { width: 100%; min-height: 320px; border: 1px solid rgba(127,127,127,.35); border-radius: 8px; background: #fff; }
        ul { padding-left: 1.25rem; }
    </style>
</head>
<body>
' . $body . '
</body>
</html>';
    exit;
}

function respondError(string $message, int $status = 400): void {
    if (wantsHtml()) {
        $body = '<h1>Makro Generator</h1>
            <p><span class="badge badge-error">Fehler</span></p>
            <p>' . h($message) . '</p>
            <div class="actions">
                <a href="?action=help&amp;key=' . h($_GET['key'] ?? '') . '">Hilfe</a>
                <a href="?action=list&amp;format=html&amp;key=' . h($_GET['key'] ?? '') . '">Makros anzeigen</a>
            </div>';
        renderPage('Fehler', $body, $status);
    }

    respondJson(['error' => $message], $status);
}

function respondData(array $data, int $status = 200, ?callable $htmlRenderer = null): void {
    if (wantsHtml()) {
        $title = $data['success'] ?? false ? 'Erfolg' : 'Makro Generator';
        $body = $htmlRenderer ? $htmlRenderer($data) : '<pre>' . h(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre>';
        renderPage($title, $body, $status);
    }

    respondJson($data, $status);
}

function formatResponseContent(mixed $content): string {
    if (is_array($content) || is_object($content)) {
        return json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $text = (string) $content;
    $trimmed = ltrim($text);
    if ($trimmed !== '' && $trimmed[0] === '<') {
        return $text;
    }

    $decoded = json_decode($text, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    return $text;
}

function isHtmlContent(mixed $content): bool {
    if (!is_string($content)) {
        return false;
    }

    $trimmed = ltrim($content);
    return $trimmed !== '' && $trimmed[0] === '<';
}

function renderHelpPage(string $apiKey): string {
    $key = h($apiKey);

    return '<h1>Makro Generator</h1>
        <p class="muted">GET-gesteuerter HTTP-Makro-Proxy für KI-Systeme und Automatisierung.</p>
        <h2>GET-Endpunkte</h2>
        <ul>
            <li><code>?action=create&amp;name=NAME&amp;method=POST&amp;url=...&amp;body=...&amp;headers=...&amp;key=' . $key . '</code> – Makro anlegen</li>
            <li><code>?action=run&amp;id=123&amp;key=' . $key . '</code> – Makro ausführen</li>
            <li><code>?action=list&amp;key=' . $key . '</code> – Alle Makros anzeigen</li>
            <li><code>?action=delete&amp;id=123&amp;key=' . $key . '</code> – Makro löschen</li>
            <li><code>?action=help&amp;key=' . $key . '</code> – Diese Hilfeseite</li>
        </ul>
        <h2>Ausgabeformat</h2>
        <p>Mit <code>format=html</code> liefern alle Aktionen eine lesbare HTML-Seite. Ohne Angabe antworten <code>create</code>, <code>run</code>, <code>list</code> und <code>delete</code> als JSON. <code>help</code> ist standardmäßig HTML.</p>
        <ul>
            <li><code>?action=list&amp;format=html&amp;key=' . $key . '</code></li>
            <li><code>?action=run&amp;id=1&amp;format=html&amp;key=' . $key . '</code></li>
            <li><code>?action=help&amp;format=json&amp;key=' . $key . '</code></li>
        </ul>
        <h2>Beispiele</h2>
        <p><strong>Makro anlegen</strong><br>
        <code>?action=create&amp;name=test&amp;method=POST&amp;url=https://httpbin.org/post&amp;body={"foo":"bar"}&amp;key=' . $key . '</code></p>
        <p><strong>Makro ausführen</strong><br>
        <code>?action=run&amp;id=1&amp;format=html&amp;key=' . $key . '</code></p>
        <div class="actions">
            <a href="?action=list&amp;format=html&amp;key=' . $key . '">Makros anzeigen</a>
        </div>';
}

function renderCreateHtml(array $data): string {
    if (!($data['success'] ?? false)) {
        return '<h1>Makro anlegen</h1><p><span class="badge badge-error">Fehler</span></p><p>' . h($data['error'] ?? 'Unbekannter Fehler') . '</p>';
    }

    return '<h1>Makro anlegen</h1>
        <p><span class="badge badge-success">Erfolg</span></p>
        <p>' . h($data['message'] ?? 'Makro angelegt') . '</p>
        <table>
            <tr><th>ID</th><td>' . h((string) ($data['id'] ?? '')) . '</td></tr>
        </table>
        <div class="actions">
            <a href="?action=run&amp;id=' . h((string) ($data['id'] ?? '')) . '&amp;format=html&amp;key=' . h($_GET['key'] ?? '') . '">Makro ausführen</a>
            <a href="?action=list&amp;format=html&amp;key=' . h($_GET['key'] ?? '') . '">Alle Makros</a>
        </div>';
}

function renderRunHtml(array $data): string {
    if (!($data['success'] ?? false)) {
        return '<h1>Makro ausführen</h1><p><span class="badge badge-error">Fehler</span></p><p>' . h($data['error'] ?? 'Unbekannter Fehler') . '</p>';
    }

    $response = $data['response'] ?? [];
    $status = (string) ($response['status'] ?? '');
    $payload = $response['response'] ?? ($response['error'] ?? null);
    $body = '';

    $body .= '<h1>Makro ausführen</h1>
        <p><span class="badge badge-success">Ausgeführt</span></p>
        <table>
            <tr><th>Makro</th><td>' . h($data['macro'] ?? '') . '</td></tr>
            <tr><th>Methode</th><td>' . h($data['method'] ?? '') . '</td></tr>
            <tr><th>Ziel-URL</th><td><code>' . h($data['target_url'] ?? '') . '</code></td></tr>
            <tr><th>HTTP-Status</th><td>' . h($status) . '</td></tr>
        </table>';

    if (isset($response['error'])) {
        $body .= '<h2>Fehler</h2><pre>' . h((string) $response['error']) . '</pre>';
    } elseif ($payload !== null) {
        $formatted = formatResponseContent($payload);
        $body .= '<h2>Antwort</h2>';

        if (isHtmlContent($payload)) {
            $body .= '<p class="muted">Die Ziel-API hat HTML geliefert. Vorschau unten, Quelltext im ausklappbaren Block.</p>
                <iframe class="preview" sandbox="" srcdoc="' . h($formatted) . '" title="API-Antwort"></iframe>
                <details>
                    <summary>HTML-Quelltext</summary>
                    <pre>' . h($formatted) . '</pre>
                </details>';
        } else {
            $body .= '<pre>' . h($formatted) . '</pre>';
        }
    }

    $body .= '<div class="actions">
            <a href="?action=list&amp;format=html&amp;key=' . h($_GET['key'] ?? '') . '">Alle Makros</a>
            <a href="?action=help&amp;key=' . h($_GET['key'] ?? '') . '">Hilfe</a>
        </div>';

    return $body;
}

function renderListHtml(array $data): string {
    if (isset($data['error'])) {
        return '<h1>Makros</h1><p><span class="badge badge-error">Fehler</span></p><p>' . h($data['error']) . '</p>';
    }

    $macros = $data['macros'] ?? [];
    $rows = '';

    foreach ($macros as $macro) {
        $rows .= '<tr>
            <td>' . h((string) ($macro['id'] ?? '')) . '</td>
            <td>' . h($macro['name'] ?? '') . '</td>
            <td>' . h($macro['method'] ?? '') . '</td>
            <td><code>' . h($macro['url'] ?? '') . '</code></td>
            <td>' . h($macro['created_at'] ?? '') . '</td>
            <td>
                <a href="?action=run&amp;id=' . h((string) ($macro['id'] ?? '')) . '&amp;format=html&amp;key=' . h($_GET['key'] ?? '') . '">Ausführen</a>
                |
                <a href="?action=delete&amp;id=' . h((string) ($macro['id'] ?? '')) . '&amp;format=html&amp;key=' . h($_GET['key'] ?? '') . '">Löschen</a>
            </td>
        </tr>';
    }

    if ($rows === '') {
        $rows = '<tr><td colspan="6" class="muted">Keine Makros vorhanden.</td></tr>';
    }

    return '<h1>Makros</h1>
        <p><span class="badge badge-success">' . h((string) ($data['count'] ?? 0)) . ' Einträge</span></p>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Methode</th>
                    <th>URL</th>
                    <th>Erstellt</th>
                    <th>Aktionen</th>
                </tr>
            </thead>
            <tbody>' . $rows . '</tbody>
        </table>
        <div class="actions">
            <a href="?action=help&amp;key=' . h($_GET['key'] ?? '') . '">Hilfe</a>
        </div>';
}

function renderDeleteHtml(array $data): string {
    if (!($data['success'] ?? false)) {
        return '<h1>Makro löschen</h1><p><span class="badge badge-error">Fehler</span></p><p>' . h($data['error'] ?? 'Unbekannter Fehler') . '</p>';
    }

    return '<h1>Makro löschen</h1>
        <p><span class="badge badge-success">Erfolg</span></p>
        <p>' . h($data['message'] ?? 'Makro gelöscht') . '</p>
        <div class="actions">
            <a href="?action=list&amp;format=html&amp;key=' . h($_GET['key'] ?? '') . '">Alle Makros</a>
            <a href="?action=help&amp;key=' . h($_GET['key'] ?? '') . '">Hilfe</a>
        </div>';
}

// ---------- Konfiguration laden ----------
function loadConfig(): array {
    if (!file_exists(API_KEY_FILE)) {
        respondError('API-Key-Datei nicht gefunden', 500);
    }

    require API_KEY_FILE;

    return [
        'apiKey' => $apiKey ?? '',
        'requireHttps' => $requireHttps ?? true,
        'allowedDomains' => $allowedDomains ?? [],
        'rateLimitPerMinute' => (int) ($rateLimitPerMinute ?? 60),
    ];
}

function clientIp(): string {
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function enforceHttps(array $config): void {
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

    respondError('HTTPS ist erforderlich', 403);
}

// ---------- Validierung & SSRF-Schutz ----------
function validateMacroName(string $name): ?string {
    if (!preg_match('/^[a-zA-Z0-9_-]{1,50}$/', $name)) {
        return 'Ungültiger Makro-Name (erlaubt: a-z, A-Z, 0-9, _, -, max. 50 Zeichen)';
    }

    return null;
}

function validateMethod(string $method): ?string {
    if (!in_array(strtoupper($method), ALLOWED_METHODS, true)) {
        return 'Ungültige HTTP-Methode';
    }

    return null;
}

function validateHeaders(string $headers): ?string {
    if ($headers === '') {
        return null;
    }

    $decoded = json_decode($headers, true);
    if (!is_array($decoded)) {
        return 'headers muss ein gültiges JSON-Objekt sein';
    }

    foreach ($decoded as $key => $value) {
        if (!is_string($key) || (!is_string($value) && !is_numeric($value))) {
            return 'headers dürfen nur String-Schlüssel und String/Number-Werte enthalten';
        }
    }

    return null;
}

function isBlockedHost(string $host): bool {
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

function isBlockedIp(string $ip): bool {
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return true;
    }

    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) === false;
}

function isDomainAllowed(string $host, array $allowedDomains): bool {
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

function validateTargetUrl(string $url, array $config): ?string {
    if (strlen($url) > 2048) {
        return 'URL ist zu lang';
    }

    $parts = parse_url($url);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        return 'Ungültige URL';
    }

    $scheme = strtolower($parts['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) {
        return 'Nur http/https URLs erlaubt';
    }

    if ($config['requireHttps'] && $scheme !== 'https') {
        return 'Nur HTTPS-URLs erlaubt';
    }

    $host = strtolower($parts['host']);
    if (isBlockedHost($host)) {
        return 'Ziel-Host nicht erlaubt';
    }

    if (!isDomainAllowed($host, $config['allowedDomains'])) {
        return 'Domain nicht in der Whitelist';
    }

    if (filter_var($host, FILTER_VALIDATE_IP)) {
        if (isBlockedIp($host)) {
            return 'Ziel-IP nicht erlaubt';
        }

        return null;
    }

    $ips = @gethostbynamel($host) ?: [];
    if ($ips === []) {
        return 'Ziel-Host konnte nicht aufgelöst werden';
    }

    foreach ($ips as $ip) {
        if (isBlockedIp($ip)) {
            return 'Ziel-Host löst auf eine nicht erlaubte IP auf';
        }
    }

    return null;
}

function checkRateLimit(SQLite3 $db, array $config): void {
    if ($config['rateLimitPerMinute'] <= 0) {
        return;
    }

    $ip = clientIp();
    $window = (int) floor(time() / 60);

    $db->exec('CREATE TABLE IF NOT EXISTS rate_limits (
        ip TEXT NOT NULL,
        window_start INTEGER NOT NULL,
        count INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (ip, window_start)
    )');

    $db->exec('DELETE FROM rate_limits WHERE window_start < ' . ($window - 2));

    $stmt = $db->prepare('SELECT count FROM rate_limits WHERE ip = :ip AND window_start = :window');
    $stmt->bindValue(':ip', $ip);
    $stmt->bindValue(':window', $window, SQLITE3_INTEGER);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    $count = (int) ($row['count'] ?? 0);

    if ($count >= $config['rateLimitPerMinute']) {
        respondError('Rate-Limit überschritten. Bitte später erneut versuchen.', 429);
    }

    if ($row) {
        $update = $db->prepare('UPDATE rate_limits SET count = count + 1 WHERE ip = :ip AND window_start = :window');
    } else {
        $update = $db->prepare('INSERT INTO rate_limits (ip, window_start, count) VALUES (:ip, :window, 1)');
    }

    $update->bindValue(':ip', $ip);
    $update->bindValue(':window', $window, SQLITE3_INTEGER);
    $update->execute();
}

function logExecution(SQLite3 $db, int $macroId, string $macroName, ?int $httpStatus, bool $success, ?string $error = null): void {
    $stmt = $db->prepare('INSERT INTO execution_log (macro_id, macro_name, ip, http_status, success, error)
        VALUES (:macro_id, :macro_name, :ip, :http_status, :success, :error)');
    $stmt->bindValue(':macro_id', $macroId, SQLITE3_INTEGER);
    $stmt->bindValue(':macro_name', $macroName);
    $stmt->bindValue(':ip', clientIp());
    $stmt->bindValue(':http_status', $httpStatus, $httpStatus === null ? SQLITE3_NULL : SQLITE3_INTEGER);
    $stmt->bindValue(':success', $success ? 1 : 0, SQLITE3_INTEGER);
    $stmt->bindValue(':error', $error);
    $stmt->execute();
}

// ---------- SQLite initialisieren ----------
function initDB() {
    try {
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
    } catch (Exception $e) {
        respondError('Datenbankfehler: ' . $e->getMessage(), 500);
    }
}

// ---------- Authentifizierung ----------
function auth(array $config): void {
    if ($config['apiKey'] === '' || !isset($_GET['key']) || !hash_equals($config['apiKey'], (string) $_GET['key'])) {
        respondError('Ungültiger oder fehlender API-Key', 401);
    }
}

// ---------- cURL-Proxy für POST/GET/PUT/DELETE ----------
function executeMacro($macro) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $macro['url']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $macro['method']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
    curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);

    if (!empty($macro['body'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $macro['body']);
    }

    $headers = json_decode($macro['headers'], true) ?? [];
    if (!empty($macro['body'])) {
        $headers['Content-Type'] = 'application/json';
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, array_map(function ($k, $v) {
        return "$k: $v";
    }, array_keys($headers), $headers));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return [
            'status' => 500,
            'error' => $error,
        ];
    }

    return [
        'status' => $httpCode,
        'response' => json_decode($response, true) ?: $response,
    ];
}

// ---------- Hauptlogik ----------
$config = loadConfig();
enforceHttps($config);
auth($config);
$db = initDB();
checkRateLimit($db, $config);
$action = $_GET['action'] ?? '';
$apiKey = $config['apiKey'];

if ($action === 'create') {
    $name = $_GET['name'] ?? '';
    $method = strtoupper($_GET['method'] ?? 'POST');
    $url = $_GET['url'] ?? '';
    $body = $_GET['body'] ?? '';
    $headers = $_GET['headers'] ?? '{}';

    if (empty($name) || empty($url)) {
        respondError('name und url sind erforderlich');
    }

    foreach ([validateMacroName($name), validateMethod($method), validateTargetUrl($url, $config), validateHeaders($headers)] as $validationError) {
        if ($validationError !== null) {
            respondError($validationError);
        }
    }

    try {
        $stmt = $db->prepare("INSERT INTO macros (name, method, url, body, headers) VALUES (:name, :method, :url, :body, :headers)");
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':method', $method);
        $stmt->bindValue(':url', $url);
        $stmt->bindValue(':body', $body);
        $stmt->bindValue(':headers', $headers);
        $result = $stmt->execute();

        if ($result) {
            respondData([
                'success' => true,
                'id' => $db->lastInsertRowID(),
                'message' => "Makro '$name' angelegt",
            ], 200, 'renderCreateHtml');
        }

        respondData(['error' => 'Fehler beim Anlegen (existiert schon?)'], 400, 'renderCreateHtml');
    } catch (Exception $e) {
        respondData(['error' => 'Datenbankfehler: ' . $e->getMessage()], 500, 'renderCreateHtml');
    }
}

if ($action === 'run') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        respondError('id erforderlich');
    }

    try {
        $stmt = $db->prepare("SELECT * FROM macros WHERE id = :id");
        $stmt->bindValue(':id', $id);
        $result = $stmt->execute();
        $macro = $result->fetchArray(SQLITE3_ASSOC);

        if (!$macro) {
            respondError('Makro nicht gefunden', 404);
        }

        $urlError = validateTargetUrl($macro['url'], $config);
        if ($urlError !== null) {
            logExecution($db, $id, $macro['name'], null, false, $urlError);
            respondError($urlError);
        }

        $output = executeMacro($macro);
        $success = !isset($output['error']);
        logExecution(
            $db,
            $id,
            $macro['name'],
            isset($output['status']) ? (int) $output['status'] : null,
            $success,
            $output['error'] ?? null
        );

        respondData([
            'success' => true,
            'macro' => $macro['name'],
            'target_url' => $macro['url'],
            'method' => $macro['method'],
            'response' => $output,
        ], 200, 'renderRunHtml');
    } catch (Exception $e) {
        respondData(['error' => 'Datenbankfehler: ' . $e->getMessage()], 500, 'renderRunHtml');
    }
}

if ($action === 'list') {
    try {
        $result = $db->query("SELECT id, name, method, url, created_at FROM macros ORDER BY created_at DESC");
        $macros = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $macros[] = $row;
        }
        respondData(['macros' => $macros, 'count' => count($macros)], 200, 'renderListHtml');
    } catch (Exception $e) {
        respondData(['error' => 'Datenbankfehler: ' . $e->getMessage()], 500, 'renderListHtml');
    }
}

if ($action === 'delete') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        respondError('id erforderlich');
    }

    try {
        $stmt = $db->prepare("DELETE FROM macros WHERE id = :id");
        $stmt->bindValue(':id', $id);
        $stmt->execute();
        respondData([
            'success' => true,
            'message' => "Makro $id gelöscht",
        ], 200, 'renderDeleteHtml');
    } catch (Exception $e) {
        respondData(['error' => 'Datenbankfehler: ' . $e->getMessage()], 500, 'renderDeleteHtml');
    }
}

if ($action === 'help' || !$action) {
    if (wantsHtml()) {
        renderPage('Makro Generator', renderHelpPage($apiKey));
    }

    respondJson([
        'success' => true,
        'message' => 'Makro Generator Hilfe',
        'endpoints' => [
            'create' => '?action=create&name=NAME&method=POST&url=...&body=...&headers=...&key=...',
            'run' => '?action=run&id=123&key=...',
            'list' => '?action=list&key=...',
            'delete' => '?action=delete&id=123&key=...',
            'help' => '?action=help&key=...',
        ],
        'formats' => [
            'json' => 'Standard für create, run, list und delete',
            'html' => 'Lesbare HTML-Ausgabe mit format=html',
        ],
    ]);
}

respondError('Unbekannte Aktion. ?action=help für Hilfe.');