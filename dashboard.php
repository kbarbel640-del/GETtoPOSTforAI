<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/includes/common.php';

try {
    $config = loadConfig();
} catch (Throwable $e) {
    http_response_code(500);
    exit('Configuration error');
}

enforceHttps($config);

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verifyCsrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if ($token === '' || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }
}

function isAuthenticated(): bool
{
    return !empty($_SESSION['dashboard_auth']);
}

function requireAuth(): void
{
    if (!isAuthenticated()) {
        http_response_code(401);
        exit('Unauthorized');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function consumeFlash(): ?array
{
    if (empty($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $flash;
}

function fetchStats(SQLite3 $db): array
{
    $macros = (int) ($db->querySingle('SELECT COUNT(*) FROM macros') ?? 0);
    $executions = (int) ($db->querySingle('SELECT COUNT(*) FROM execution_log') ?? 0);
    $successes = (int) ($db->querySingle('SELECT COUNT(*) FROM execution_log WHERE success = 1') ?? 0);
    $today = (int) ($db->querySingle("SELECT COUNT(*) FROM execution_log WHERE date(executed_at) = date('now')") ?? 0);
    $rate = $executions > 0 ? round(($successes / $executions) * 100, 1) : 0.0;

    $recent = [];
    $result = $db->query('SELECT macro_name, ip, http_status, success, error, executed_at
        FROM execution_log ORDER BY executed_at DESC LIMIT 15');
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $recent[] = $row;
    }

    $top = [];
    $result = $db->query('SELECT macro_name, COUNT(*) AS runs,
        SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) AS ok_runs
        FROM execution_log GROUP BY macro_name ORDER BY runs DESC LIMIT 10');
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $top[] = $row;
    }

    return compact('macros', 'executions', 'successes', 'today', 'rate', 'recent', 'top');
}

function validateMacroPayload(array $macro, array $config): ?string
{
    $name = (string) ($macro['name'] ?? '');
    $method = strtoupper((string) ($macro['method'] ?? 'POST'));
    $url = (string) ($macro['url'] ?? '');
    $body = (string) ($macro['body'] ?? '');
    $headers = (string) ($macro['headers'] ?? '{}');

    if ($name === '' || $url === '') {
        return 'Each macro needs name and url';
    }

    foreach ([validateMacroName($name), validateMethod($method), validateTargetUrl($url, $config), validateBody($body), validateHeaders($headers)] as $error) {
        if ($error !== null) {
            return $error;
        }
    }

    return null;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'login') {
        verifyCsrf();
        $key = (string) ($_POST['api_key'] ?? '');
        if ($config['apiKey'] === '' || !hash_equals($config['apiKey'], $key)) {
            flash('error', 'Invalid API key');
            header('Location: dashboard.php');
            exit;
        }

        session_regenerate_id(true);
        $_SESSION['dashboard_auth'] = true;
        flash('success', 'Signed in successfully');
        header('Location: dashboard.php');
        exit;
    }

    requireAuth();
    verifyCsrf();

    if ($action === 'logout') {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        header('Location: dashboard.php');
        exit;
    }

    $db = openDb();

    if ($action === 'export_macros') {
        $rows = [];
        $result = $db->query('SELECT name, method, url, body, headers, created_at FROM macros ORDER BY id ASC');
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }

        $payload = [
            'exported_at' => gmdate('c'),
            'version' => 1,
            'macros' => $rows,
        ];

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="gettopostforai-macros-' . date('Y-m-d') . '.json"');
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'export_db') {
        if (!is_readable(DB_FILE)) {
            flash('error', 'Database file is not readable');
            header('Location: dashboard.php');
            exit;
        }

        header('Content-Type: application/x-sqlite3');
        header('Content-Disposition: attachment; filename="macro_generator-' . date('Y-m-d') . '.db"');
        readfile(DB_FILE);
        exit;
    }

    if ($action === 'import_macros') {
        $mode = $_POST['import_mode'] ?? 'merge';
        if (!in_array($mode, ['merge', 'replace'], true)) {
            flash('error', 'Invalid import mode');
            header('Location: dashboard.php');
            exit;
        }

        if (empty($_FILES['backup_file']['tmp_name']) || !is_uploaded_file($_FILES['backup_file']['tmp_name'])) {
            flash('error', 'Please choose a JSON backup file');
            header('Location: dashboard.php');
            exit;
        }

        $raw = file_get_contents($_FILES['backup_file']['tmp_name']);
        $data = json_decode($raw ?: '', true);
        if (!is_array($data)) {
            flash('error', 'Invalid JSON backup file');
            header('Location: dashboard.php');
            exit;
        }

        $macros = $data['macros'] ?? $data;
        if (!is_array($macros)) {
            flash('error', 'Backup file does not contain a macros array');
            header('Location: dashboard.php');
            exit;
        }

        try {
            $db->exec('BEGIN');
            if ($mode === 'replace') {
                $db->exec('DELETE FROM macros');
            }

            $imported = 0;
            $skipped = 0;
            $stmt = $db->prepare('INSERT INTO macros (name, method, url, body, headers) VALUES (:name, :method, :url, :body, :headers)');

            foreach ($macros as $macro) {
                if (!is_array($macro)) {
                    $skipped++;
                    continue;
                }

                $error = validateMacroPayload($macro, $config);
                if ($error !== null) {
                    throw new RuntimeException($error);
                }

                $stmt->bindValue(':name', (string) $macro['name']);
                $stmt->bindValue(':method', strtoupper((string) ($macro['method'] ?? 'POST')));
                $stmt->bindValue(':url', (string) $macro['url']);
                $stmt->bindValue(':body', (string) ($macro['body'] ?? ''));
                $stmt->bindValue(':headers', (string) ($macro['headers'] ?? '{}'));

                try {
                    $stmt->execute();
                    $imported++;
                } catch (Throwable $e) {
                    if ($mode === 'merge' && str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
                        $skipped++;
                        continue;
                    }
                    throw $e;
                }
            }

            $db->exec('COMMIT');
            flash('success', "Import finished: $imported imported, $skipped skipped");
        } catch (Throwable $e) {
            $db->exec('ROLLBACK');
            flash('error', 'Import failed: ' . $e->getMessage());
        }

        header('Location: dashboard.php');
        exit;
    }

    if ($action === 'purge_logs') {
        $days = max(1, (int) ($_POST['days'] ?? 30));
        $stmt = $db->prepare("DELETE FROM execution_log WHERE executed_at < datetime('now', :offset)");
        $stmt->bindValue(':offset', '-' . $days . ' days');
        $stmt->execute();
        $deleted = $db->changes();
        flash('success', "Deleted $deleted log entries older than $days days");
        header('Location: dashboard.php');
        exit;
    }

    http_response_code(400);
    exit('Unknown action');
}

if ($method === 'GET' && !isAuthenticated()) {
    $flash = consumeFlash();
    $flashHtml = '';
    if ($flash) {
        $class = $flash['type'] === 'success' ? 'alert-success' : 'alert-error';
        $flashHtml = '<div class="alert ' . $class . '">' . h($flash['message']) . '</div>';
    }

    $body = '<header><div><h1>GETtoPOSTforAI Dashboard</h1><p class="muted">Sign in with your API key to view stats and manage backups.</p></div></header>'
        . $flashHtml
        . '<div class="card" style="max-width:420px"><form method="post">'
        . '<input type="hidden" name="action" value="login">'
        . '<input type="hidden" name="csrf_token" value="' . h(csrfToken()) . '">'
        . '<p><label for="api_key">API key</label><br><input type="password" id="api_key" name="api_key" required autocomplete="current-password"></p>'
        . '<p><button class="btn" type="submit">Sign in</button></p>'
        . '</form></div>'
        . '<p class="muted"><a href="macro_generator.php?action=help&amp;key=YOUR_KEY">API help</a></p>';

    renderDashboardPage('Dashboard Login', $body);
}

requireAuth();

$db = openDb();
$stats = fetchStats($db);
$flash = consumeFlash();
$flashHtml = '';
if ($flash) {
    $class = $flash['type'] === 'success' ? 'alert-success' : 'alert-error';
    $flashHtml = '<div class="alert ' . $class . '">' . h($flash['message']) . '</div>';
}

$recentRows = '';
foreach ($stats['recent'] as $row) {
    $badgeClass = ((int) $row['success'] === 1) ? 'badge-ok' : 'badge-bad';
    $badgeLabel = ((int) $row['success'] === 1) ? 'OK' : 'FAIL';
    $recentRows .= '<tr><td>' . h($row['executed_at']) . '</td><td>' . h($row['macro_name']) . '</td><td>' . h($row['ip']) . '</td><td>' . h((string) ($row['http_status'] ?? '-')) . '</td><td><span class="badge ' . $badgeClass . '">' . $badgeLabel . '</span></td><td>' . h((string) ($row['error'] ?? '')) . '</td></tr>';
}
if ($recentRows === '') {
    $recentRows = '<tr><td colspan="6" class="muted">No executions logged yet.</td></tr>';
}

$topRows = '';
foreach ($stats['top'] as $row) {
    $topRows .= '<tr><td>' . h($row['macro_name']) . '</td><td>' . h((string) $row['runs']) . '</td><td>' . h((string) $row['ok_runs']) . '</td></tr>';
}
if ($topRows === '') {
    $topRows = '<tr><td colspan="3" class="muted">No macro runs yet.</td></tr>';
}

$token = h(csrfToken());

$body = '<header><div><h1>GETtoPOSTforAI Dashboard</h1><p class="muted">Stats, backups, and maintenance for your macro proxy.</p></div>'
    . '<form method="post" class="inline"><input type="hidden" name="action" value="logout"><input type="hidden" name="csrf_token" value="' . $token . '"><button class="btn btn-secondary" type="submit">Sign out</button></form></header>'
    . $flashHtml
    . '<div class="grid">'
    . '<div class="card stat"><span class="muted">Macros</span><strong>' . h((string) $stats['macros']) . '</strong></div>'
    . '<div class="card stat"><span class="muted">Executions</span><strong>' . h((string) $stats['executions']) . '</strong></div>'
    . '<div class="card stat"><span class="muted">Success rate</span><strong>' . h((string) $stats['rate']) . '%</strong></div>'
    . '<div class="card stat"><span class="muted">Runs today</span><strong>' . h((string) $stats['today']) . '</strong></div>'
    . '</div>'
    . '<div class="card"><h2>Backup &amp; restore</h2><p class="muted">Export macros as JSON or download the full SQLite database. Import uses POST and validates URLs against your whitelist.</p><div class="actions">'
    . '<form method="post" class="inline"><input type="hidden" name="action" value="export_macros"><input type="hidden" name="csrf_token" value="' . $token . '"><button class="btn" type="submit">Export macros (JSON)</button></form>'
    . '<form method="post" class="inline"><input type="hidden" name="action" value="export_db"><input type="hidden" name="csrf_token" value="' . $token . '"><button class="btn btn-secondary" type="submit">Download database</button></form>'
    . '</div><form method="post" enctype="multipart/form-data" style="margin-top:1rem"><input type="hidden" name="action" value="import_macros"><input type="hidden" name="csrf_token" value="' . $token . '">'
    . '<p><label for="backup_file">Import macros JSON</label><br><input type="file" id="backup_file" name="backup_file" accept="application/json,.json" required></p>'
    . '<p><label for="import_mode">Import mode</label><br><select id="import_mode" name="import_mode"><option value="merge">Merge (skip duplicate names)</option><option value="replace">Replace all macros</option></select></p>'
    . '<p><button class="btn" type="submit">Import backup</button></p></form></div>'
    . '<div class="card"><h2>Maintenance</h2><form method="post" class="actions"><input type="hidden" name="action" value="purge_logs"><input type="hidden" name="csrf_token" value="' . $token . '">'
    . '<label for="days">Delete execution logs older than</label> <input type="number" id="days" name="days" value="30" min="1" max="3650" style="width:90px;padding:.45rem;border:1px solid #d4d4d8;border-radius:8px"> <span>days</span> '
    . '<button class="btn btn-danger" type="submit">Purge logs</button></form></div>'
    . '<div class="card"><h2>Top macros</h2><table><thead><tr><th>Name</th><th>Runs</th><th>Successful</th></tr></thead><tbody>' . $topRows . '</tbody></table></div>'
    . '<div class="card"><h2>Recent executions</h2><table><thead><tr><th>Time</th><th>Macro</th><th>IP</th><th>Status</th><th>Result</th><th>Error</th></tr></thead><tbody>' . $recentRows . '</tbody></table></div>'
    . '<p class="muted"><a href="macro_generator.php?action=list&amp;format=html&amp;key=YOUR_KEY">Macro API</a></p>';

renderDashboardPage('Dashboard', $body);