<?php
// ============================================================
// MAKRO GENERATOR - GET-gesteuert, SQLite, cURL-Proxy
// ============================================================

// ---------- Konfiguration ----------
define('DB_FILE', __DIR__ . '/macro_generator.db');
define('API_KEY_FILE', __DIR__ . '/api_key.php');  // API-Key in einer separaten PHP-Datei speichern

// ---------- API-Key laden ----------
function loadApiKey() {
    if (!file_exists(API_KEY_FILE)) {
        die(json_encode(['error' => 'API-Key-Datei nicht gefunden']));
    }
    require_once(API_KEY_FILE);
    return $apiKey;
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
        return $db;
    } catch (Exception $e) {
        die(json_encode(['error' => 'Datenbankfehler: ' . $e->getMessage()]));
    }
}

// ---------- Authentifizierung ----------
function auth() {
    $apiKey = loadApiKey();
    if (!isset($_GET['key']) || $_GET['key'] !== $apiKey) {
        http_response_code(401);
        die(json_encode(['error' => 'Ungültiger oder fehlender API-Key']));
    }
}

// ---------- cURL-Proxy für POST/GET/PUT/DELETE ----------
function executeMacro($macro) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $macro['url']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $macro['method']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    
    // Body (JSON) setzen
    if (!empty($macro['body'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $macro['body']);
    }
    
    // Headers
    $headers = json_decode($macro['headers'], true) ?? [];
    if (!empty($macro['body'])) {
        $headers['Content-Type'] = 'application/json';
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, array_map(function($k, $v) {
        return "$k: $v";
    }, array_keys($headers), $headers));
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return [
            'status' => 500,
            'error' => $error
        ];
    }
    
    return [
        'status' => $httpCode,
        'response' => json_decode($response, true) ?: $response
    ];
}

// ---------- Hauptlogik ----------
auth();
$db = initDB();
$action = $_GET['action'] ?? '';

// ------------------- 1. Makro anlegen (CREATE) -------------------
if ($action === 'create') {
    $name = $_GET['name'] ?? '';
    $method = strtoupper($_GET['method'] ?? 'POST');
    $url = $_GET['url'] ?? '';
    $body = $_GET['body'] ?? '';
    $headers = $_GET['headers'] ?? '{}';
    
    if (empty($name) || empty($url)) {
        die(json_encode(['error' => 'name und url sind erforderlich']));
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
            echo json_encode(['success' => true, 'id' => $db->lastInsertRowID(), 'message' => "Makro '$name' angelegt"]);
        } else {
            echo json_encode(['error' => 'Fehler beim Anlegen (existiert schon?)']);
        }
    } catch (Exception $e) {
        echo json_encode(['error' => 'Datenbankfehler: ' . $e->getMessage()]);
    }
    exit;
}

// ------------------- 2. Makro ausführen (RUN) -------------------
if ($action === 'run') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        die(json_encode(['error' => 'id erforderlich']));
    }
    
    try {
        $stmt = $db->prepare("SELECT * FROM macros WHERE id = :id");
        $stmt->bindValue(':id', $id);
        $result = $stmt->execute();
        $macro = $result->fetchArray(SQLITE3_ASSOC);
        
        if (!$macro) {
            die(json_encode(['error' => 'Makro nicht gefunden']));
        }
        
        // Makro ausführen
        $output = executeMacro($macro);
        echo json_encode([
            'success' => true,
            'macro' => $macro['name'],
            'target_url' => $macro['url'],
            'method' => $macro['method'],
            'response' => $output
        ]);
    } catch (Exception $e) {
        echo json_encode(['error' => 'Datenbankfehler: ' . $e->getMessage()]);
    }
    exit;
}

// ------------------- 3. Makros auflisten (LIST) -------------------
if ($action === 'list') {
    try {
        $result = $db->query("SELECT id, name, method, url, created_at FROM macros ORDER BY created_at DESC");
        $macros = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $macros[] = $row;
        }
        echo json_encode(['macros' => $macros, 'count' => count($macros)]);
    } catch (Exception $e) {
        echo json_encode(['error' => 'Datenbankfehler: ' . $e->getMessage()]);
    }
    exit;
}

// ------------------- 4. Makro löschen (DELETE) -------------------
if ($action === 'delete') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        die(json_encode(['error' => 'id erforderlich']));
    }
    
    try {
        $stmt = $db->prepare("DELETE FROM macros WHERE id = :id");
        $stmt->bindValue(':id', $id);
        $stmt->execute();
        echo json_encode(['success' => true, 'message' => "Makro $id gelöscht"]);
    } catch (Exception $e) {
        echo json_encode(['error' => 'Datenbankfehler: ' . $e->getMessage()]);
    }
    exit;
}

// ------------------- 5. Hilfe / Readme -------------------
if ($action === 'help' || !$action) {
    echo "<h1>🤖 Makro Generator</h1>
    <h3>GET-Endpunkte:</h3>
    <ul>
        <li><b>?action=create&name=NAME&method=POST&url=...&body=...&headers=...&key=geheim123</b> – Makro anlegen</li>
        <li><b>?action=run&id=123&key=geheim123</b> – Makro ausführen (führt POST/GET aus)</li>
        <li><b>?action=list&key=geheim123</b> – Alle Makros anzeigen</li>
        <li><b>?action=delete&id=123&key=geheim123</b> – Makro löschen</li>
    </ul>
    <p><b>Beispiel-Aufruf (Makro anlegen):</b><br>
    <code>?action=create&name=test&method=POST&url=https://httpbin.org/post&body={\"foo\":\"bar\"}&key=geheim123</code></p>
    <p><b>Beispiel-Aufruf (Makro ausführen):</b><br>
    <code>?action=run&id=1&key=geheim123</code></p>";
    exit;
}

// ---------- Fallback ----------
http_response_code(400);
echo json_encode(['error' => 'Unbekannte Aktion. ?action=help für Hilfe.']);
