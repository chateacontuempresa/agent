<?php
/**
 * Chatea con tu Empresa — Agente PHP para Hosting Compartido
 * Archivo único: sube este archivo a tu hosting, ábrelo en el navegador y configura.
 *
 * Modos de operación:
 *   - Navegador:        https://tudominio.com/cce-agent/agent.php
 *   - Cron (PHP CLI):   php agent.php run
 *   - Cron (HTTP URL):  https://tudominio.com/cce-agent/agent.php?action=run&secret=TU_SECRET
 */

define('CCE_VERSION', '1.0.0');
define('CCE_SAAS_URL', 'https://chateacontuempresa.com');
define('CONFIG_FILE', __DIR__ . '/agent-config.json');
define('LOG_FILE',    __DIR__ . '/agent-log.json');
define('MAX_LOGS',    100);

// ─── Helpers ────────────────────────────────────────────────────────────────

function loadConfig(): array {
    if (!file_exists(CONFIG_FILE)) return [];
    $data = json_decode(file_get_contents(CONFIG_FILE), true);
    return is_array($data) ? $data : [];
}

function saveConfig(array $cfg): void {
    file_put_contents(CONFIG_FILE, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function addLog(string $level, string $message): void {
    $logs = [];
    if (file_exists(LOG_FILE)) {
        $logs = json_decode(file_get_contents(LOG_FILE), true) ?: [];
    }
    array_unshift($logs, ['time' => date('Y-m-d H:i:s'), 'level' => $level, 'msg' => $message]);
    if (count($logs) > MAX_LOGS) $logs = array_slice($logs, 0, MAX_LOGS);
    file_put_contents(LOG_FILE, json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function getLogs(): array {
    if (!file_exists(LOG_FILE)) return [];
    return json_decode(file_get_contents(LOG_FILE), true) ?: [];
}

function gatewayRequest(string $method, string $path, array $cfg, array $data = [], int $timeout = 60): array {
    // Siempre habla con el SaaS (HTTPS) usando las rutas /api/http-agent/*.
    // El SaaS proxea internamente al gateway, así el agente PHP nunca necesita
    // acceso directo al puerto 8787 ni guardar una URL interna.
    $baseUrl = rtrim(CCE_SAAS_URL, '/');
    $url = $baseUrl . $path;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);   // seguir redirects automáticamente
    curl_setopt($ch, CURLOPT_POSTREDIR, 7);           // mantener método POST en 307/308
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . ($cfg['token'] ?? ''),
        'Content-Type: application/json',
        'Accept: application/json',
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    } elseif ($method === 'GET') {
        curl_setopt($ch, CURLOPT_HTTPGET, true);
    }
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($error) return ['ok' => false, 'error' => $error, 'code' => 0];
    $body = json_decode($response, true) ?: [];
    $body['_http_code'] = $httpCode;
    $body['_raw'] = $response;
    return $body;
}

function getDbConnection(array $cfg): PDO {
    $type = $cfg['db_type'] ?? 'mysql';
    $host = $cfg['db_host'] ?? 'localhost';
    $port = (int)($cfg['db_port'] ?? 3306);
    $name = $cfg['db_name'] ?? '';
    $user = $cfg['db_user'] ?? '';
    $pass = $cfg['db_pass'] ?? '';

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 10,
    ];

    if ($type === 'mysql') {
        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        return new PDO($dsn, $user, $pass, $options);
    } elseif ($type === 'pgsql') {
        $dsn = "pgsql:host={$host};port={$port};dbname={$name}";
        return new PDO($dsn, $user, $pass, $options);
    } elseif ($type === 'sqlite') {
        $dsn = "sqlite:{$name}";
        return new PDO($dsn, null, null, $options);
    }
    throw new \Exception("Tipo de BD no soportado: {$type}");
}

function extractSchema(PDO $pdo, array $cfg): array {
    $type = $cfg['db_type'] ?? 'mysql';
    $schema = [];

    if ($type === 'mysql') {
        $dbName = $cfg['db_name'] ?? '';
        $stmt = $pdo->prepare("
            SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, COLUMN_TYPE as full_type,
                   COLUMN_COMMENT as column_comment,
                   IF(COLUMN_KEY='PRI',1,0) as is_primary,
                   IF(EXTRA LIKE '%auto_increment%',1,0) as is_identity,
                   IF(IS_NULLABLE='YES',1,0) as is_nullable
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = ?
            ORDER BY TABLE_NAME, ORDINAL_POSITION
        ");
        $stmt->execute([$dbName]);
        $schema = $stmt->fetchAll();
    } elseif ($type === 'pgsql') {
        $stmt = $pdo->query("
            SELECT c.table_name AS TABLE_NAME, c.column_name AS COLUMN_NAME,
                   c.data_type AS DATA_TYPE, c.data_type AS full_type, '' AS column_comment,
                   CASE WHEN pk.column_name IS NOT NULL THEN 1 ELSE 0 END AS is_primary,
                   CASE WHEN c.column_default LIKE 'nextval%' THEN 1 ELSE 0 END AS is_identity,
                   CASE WHEN c.is_nullable='YES' THEN 1 ELSE 0 END AS is_nullable
            FROM information_schema.columns c
            LEFT JOIN (
                SELECT ku.column_name, ku.table_name
                FROM information_schema.table_constraints tc
                JOIN information_schema.key_column_usage ku ON tc.constraint_name = ku.constraint_name
                WHERE tc.constraint_type = 'PRIMARY KEY' AND tc.table_schema = 'public'
            ) pk ON pk.column_name = c.column_name AND pk.table_name = c.table_name
            WHERE c.table_schema = 'public'
            ORDER BY c.table_name, c.ordinal_position
        ");
        $schema = $stmt->fetchAll();
    } elseif ($type === 'sqlite') {
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll();
        foreach ($tables as $t) {
            $tname = $t['name'];
            $cols = $pdo->query("PRAGMA table_info(\"{$tname}\")")->fetchAll();
            foreach ($cols as $col) {
                $schema[] = [
                    'TABLE_NAME'     => $tname,
                    'COLUMN_NAME'    => $col['name'],
                    'DATA_TYPE'      => $col['type'],
                    'full_type'      => $col['type'],
                    'column_comment' => '',
                    'is_primary'     => (int)$col['pk'],
                    'is_identity'    => 0,
                    'is_nullable'    => !$col['notnull'] ? 1 : 0,
                ];
            }
        }
    }
    return $schema;
}

function isReadOnlySql(string $sql): bool {
    $sql = trim(preg_replace('/;+\s*$/', '', $sql));
    if (empty($sql)) return false;
    if (count(array_filter(explode(';', $sql), 'trim')) > 1) return false;
    if (preg_match('/\b(DROP|DELETE|UPDATE|INSERT|ALTER|TRUNCATE|CREATE|GRANT|REVOKE|RENAME|REPLACE|MERGE|CALL|EXEC|EXECUTE)\b/i', $sql)) return false;
    return (bool)preg_match('/^(SELECT|WITH)\b/i', $sql);
}

function computeSchemaHash(array $schema): string {
    return hash('sha256', json_encode($schema));
}

// ─── Agent Run Cycle ─────────────────────────────────────────────────────────

function runAgentCycle(bool $cli = false): void {
    $cfg = loadConfig();
    if (empty($cfg['token'])) {
        $msg = 'Agente no configurado. Abre el panel web para configurar.';
        if ($cli) echo "[ERROR] {$msg}\n";
        addLog('error', $msg);
        return;
    }

    // Determine gateway URL
    $gatewayUrl = $cfg['gateway_url'] ?? '';
    if (empty($gatewayUrl)) {
        // Bootstrap to get gateway URL
        $boot = gatewayRequest('POST', '/api/agent/bootstrap', $cfg, [
            'token'        => $cfg['token'],
            'platform'     => 'php-hosting',
            'appVersion'   => CCE_VERSION,
            'dbType'       => $cfg['db_type'] ?? 'mysql',
            'databaseName' => $cfg['db_name'] ?? '',
        ]);
        if (!empty($boot['gateway'])) {
            // Convert ws:// to http:// for HTTP polling endpoints
            $gwUrl = $boot['gateway']['wsUrl'] ?? '';
            $gwHttpUrl = preg_replace('/^wss?:\/\//', 'http://', $gwUrl);
            $gwHttpUrl = preg_replace('/\/socket$/', '', $gwHttpUrl);
            $cfg['gateway_url'] = $gwHttpUrl;
            $cfg['installation_id'] = $boot['installation']['id'] ?? '';
            saveConfig($cfg);
            $gatewayUrl = $gwHttpUrl;
        } else {
            $gatewayUrl = CCE_SAAS_URL;
        }
    }

    $tempCfg = array_merge($cfg, ['gateway_url' => $gatewayUrl]);

    // Auth
    if ($cli) echo "[INFO] Autenticando con gateway...\n";
    $authPayload = [
        'platform'      => 'php-hosting',
        'appVersion'    => CCE_VERSION,
        'dbType'        => $cfg['db_type'] ?? 'mysql',
        'databaseName'  => $cfg['db_name'] ?? '',
    ];
    // Registrar webhook URL para que el gateway haga push directo (ultra rápido)
    if (!empty($cfg['webhook_url']) && !empty($cfg['webhook_secret'])) {
        $authPayload['webhookUrl']    = $cfg['webhook_url'];
        $authPayload['webhookSecret'] = $cfg['webhook_secret'];
        if ($cli) echo "[INFO] Webhook registrado: {$cfg['webhook_url']}\n";
    }
    $auth = gatewayRequest('POST', '/api/http-agent/auth', $tempCfg, $authPayload);

    if (empty($auth['ok'])) {
        $errMsg = $auth['error'] ?? ('Auth falló, HTTP: ' . ($auth['_http_code'] ?? '?'));
        addLog('error', "Auth: {$errMsg}");
        if ($cli) echo "[ERROR] {$errMsg}\n";
        return;
    }

    $installationId = $auth['installationId'] ?? ($cfg['installation_id'] ?? '');
    addLog('info', 'Agente autenticado OK');
    if ($cli) echo "[OK] Autenticado. InstallationId: {$installationId}\n";

    // Schema sync (only if changed)
    try {
        $pdo = getDbConnection($cfg);
        $schema = extractSchema($pdo, $cfg);
        $schemaHash = computeSchemaHash($schema);
        $lastHash = $cfg['last_schema_hash'] ?? '';
        if ($schemaHash !== $lastHash) {
            if ($cli) echo "[INFO] Sincronizando esquema ({$schemaHash})...\n";
            $syncRes = gatewayRequest('POST', '/api/http-agent/schema', $tempCfg, [
                'schema'       => $schema,
                'schemaHash'   => $schemaHash,
                'dbType'       => $cfg['db_type'] ?? 'mysql',
                'databaseName' => $cfg['db_name'] ?? '',
            ]);
            if (!empty($syncRes['ok'])) {
                $cfg['last_schema_hash'] = $schemaHash;
                saveConfig($cfg);
                addLog('info', "Esquema sincronizado: {$schemaHash}");
                if ($cli) echo "[OK] Esquema sincronizado.\n";
            }
        } else {
            if ($cli) echo "[INFO] Esquema sin cambios, saltando sync.\n";
        }
    } catch (\Exception $e) {
        addLog('error', 'BD: ' . $e->getMessage());
        if ($cli) echo "[ERROR] BD: " . $e->getMessage() . "\n";
        return;
    }

    // Multi-query loop: mantener el agente escuchando durante todo el ciclo del cron
    // Con polls de 25s x 2 rondas = 50s activo de cada 60s del cron.
    // Esto elimina el gap de 30s que causaba timeouts.
    $cycleStart  = microtime(true);
    $maxCycleSeconds = 52; // Dejar 8s de margen antes del próximo cron
    $queriesHandled  = 0;

    if ($cli) echo "[INFO] Iniciando ciclo de escucha (~{$maxCycleSeconds}s)...\n";

    while (true) {
        $elapsed   = microtime(true) - $cycleStart;
        $remaining = $maxCycleSeconds - $elapsed;

        if ($remaining < 3) break; // No queda tiempo suficiente

        // Poll con la mitad del tiempo restante (mín 5s, máx 25s)
        $pollMs      = (int) min(25000, max(5000, ($remaining / 2) * 1000));
        $pollTimeout = (int) ($pollMs / 1000) + 5;

        if ($cli) echo "[INFO] Poll " . ($queriesHandled + 1) . " — {$pollMs}ms (quedan " . round($remaining) . "s)...\n";

        $pollRes = gatewayRequest('GET', "/api/http-agent/poll?timeout={$pollMs}", $tempCfg, [], $pollTimeout);

        if (!isset($pollRes['query']) || $pollRes['query'] === null) {
            // Sin consulta en esta ventana, intentar de nuevo si queda tiempo
            continue;
        }

        $query   = $pollRes['query'];
        $queryId = $query['queryId'] ?? '';
        $sql     = $query['sql'] ?? '';

        if (empty($queryId) || empty($sql)) {
            addLog('warn', 'Consulta recibida sin queryId o sql.');
            continue;
        }

        if ($cli) echo "[INFO] Consulta recibida [{$queryId}]: " . substr($sql, 0, 80) . "\n";
        addLog('info', "Consulta: {$queryId} — " . substr($sql, 0, 100));

        // Validar que sea SELECT
        if (!isReadOnlySql($sql)) {
            $errMsg = 'Solo se permiten consultas SELECT.';
            gatewayRequest('POST', '/api/http-agent/result', $tempCfg, [
                'queryId' => $queryId,
                'success' => false,
                'error'   => $errMsg,
            ]);
            addLog('error', $errMsg);
            if ($cli) echo "[ERROR] {$errMsg}\n";
            $queriesHandled++;
            continue;
        }

        // Ejecutar la consulta
        try {
            $pdo = getDbConnection($cfg);
            if (!preg_match('/\bLIMIT\b/i', $sql) && !preg_match('/\bCOUNT\s*\(/i', $sql)) {
                $sql .= ' LIMIT 500';
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            gatewayRequest('POST', '/api/http-agent/result', $tempCfg, [
                'queryId' => $queryId,
                'success' => true,
                'rows'    => $rows,
            ]);
            $count = count($rows);
            addLog('info', "Consulta {$queryId} OK — {$count} filas devueltas.");
            if ($cli) echo "[OK] {$count} filas devueltas para {$queryId}.\n";
        } catch (\Exception $e) {
            $errMsg = $e->getMessage();
            gatewayRequest('POST', '/api/http-agent/result', $tempCfg, [
                'queryId' => $queryId,
                'success' => false,
                'error'   => $errMsg,
            ]);
            addLog('error', "Error ejecutando consulta: {$errMsg}");
            if ($cli) echo "[ERROR] {$errMsg}\n";
        }

        $queriesHandled++;
    }

    if ($queriesHandled === 0) {
        addLog('info', 'Sin consultas en este ciclo.');
    }
    if ($cli) echo "[FIN CICLO] {$queriesHandled} consulta(s) procesada(s).\n";
}

// ─── CLI Mode ─────────────────────────────────────────────────────────────────

if (php_sapi_name() === 'cli') {
    $arg = $argv[1] ?? '';
    if ($arg === 'run') {
        echo "[Chatea con tu Empresa — Agente PHP v" . CCE_VERSION . "]\n";
        runAgentCycle(true);
        echo "[FIN]\n";
    } else {
        echo "Uso: php agent.php run\n";
    }
    exit(0);
}

// ─── HTTP Mode ────────────────────────────────────────────────────────────────

$action = $_REQUEST['action'] ?? $_GET['action'] ?? '';
$cfg = loadConfig();

// ── WEBHOOK: Gateway llama directo aquí → respuesta ultra rápida (~1s) ────────
if ($action === 'webhook' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    // Validar webhook secret enviado por el gateway
    $incomingSecret = $_SERVER['HTTP_X_CCE_WEBHOOK_SECRET'] ?? '';
    $storedSecret   = $cfg['webhook_secret'] ?? '';
    if (empty($storedSecret) || $incomingSecret !== $storedSecret) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Webhook secret inválido']);
        exit;
    }
    $body    = json_decode(file_get_contents('php://input'), true) ?: [];
    $sql     = trim($body['sql'] ?? '');
    $queryId = trim($body['queryId'] ?? '');
    if (empty($sql) || empty($queryId)) {
        echo json_encode(['success' => false, 'error' => 'sql y queryId son requeridos']);
        exit;
    }
    if (!isReadOnlySql($sql)) {
        echo json_encode(['success' => false, 'error' => 'Solo se permiten consultas SELECT']);
        exit;
    }
    try {
        $pdo = getDbConnection($cfg);
        if (!preg_match('/\bLIMIT\b/i', $sql) && !preg_match('/\bCOUNT\s*\(/i', $sql)) {
            $sql .= ' LIMIT 500';
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        addLog('info', "Webhook {$queryId} OK — " . count($rows) . " filas.");
        echo json_encode(['success' => true, 'rows' => $rows]);
    } catch (\Exception $e) {
        addLog('error', "Webhook {$queryId} error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Cron via URL (protected by secret)
if ($action === 'run') {
    $secret = $_GET['secret'] ?? '';
    $cfgSecret = $cfg['cron_secret'] ?? '';
    if (!empty($cfgSecret) && $secret !== $cfgSecret) {
        http_response_code(403);
        echo json_encode(['error' => 'Secret inválido']);
        exit;
    }
    header('Content-Type: application/json');
    ob_start();
    runAgentCycle(false);
    ob_end_clean();
    echo json_encode(['ok' => true, 'logs' => array_slice(getLogs(), 0, 5)]);
    exit;
}

// API: save config
if ($action === 'save-config' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    // Detect own public URL for webhook (auto-set)
    $selfUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
               . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'];
    // Keep existing secrets or generate new ones
    $webhookSecret = $cfg['webhook_secret'] ?? bin2hex(random_bytes(20));
    $newCfg = [
        'token'            => trim($_POST['token'] ?? ''),
        'cron_secret'      => trim($_POST['cron_secret'] ?? ''),
        'webhook_url'      => $selfUrl,
        'webhook_secret'   => $webhookSecret,
        'db_type'          => $_POST['db_type'] ?? 'mysql',
        'db_host'          => trim($_POST['db_host'] ?? 'localhost'),
        'db_port'          => (int)($_POST['db_port'] ?? 3306),
        'db_name'          => trim($_POST['db_name'] ?? ''),
        'db_user'          => trim($_POST['db_user'] ?? ''),
        'db_pass'          => trim($_POST['db_pass'] ?? ''),
        'gateway_url'      => $cfg['gateway_url'] ?? '',
        'installation_id'  => $cfg['installation_id'] ?? '',
        'last_schema_hash' => $cfg['last_schema_hash'] ?? '',
    ];
    saveConfig($newCfg);
    echo json_encode(['ok' => true, 'webhookUrl' => $selfUrl]);
    exit;
}

// API: test DB connection
if ($action === 'test-db' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $testCfg = [
        'db_type' => $_POST['db_type'] ?? 'mysql',
        'db_host' => trim($_POST['db_host'] ?? 'localhost'),
        'db_port' => (int)($_POST['db_port'] ?? 3306),
        'db_name' => trim($_POST['db_name'] ?? ''),
        'db_user' => trim($_POST['db_user'] ?? ''),
        'db_pass' => trim($_POST['db_pass'] ?? ''),
    ];
    try {
        $pdo = getDbConnection($testCfg);
        $schema = extractSchema($pdo, $testCfg);
        $tables = count(array_unique(array_column($schema, 'TABLE_NAME')));
        echo json_encode(['ok' => true, 'tables' => $tables, 'columns' => count($schema)]);
    } catch (\Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// API: get logs
if ($action === 'get-logs') {
    header('Content-Type: application/json');
    echo json_encode(['logs' => getLogs()]);
    exit;
}

// API: run now (AJAX)
if ($action === 'run-now' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    runAgentCycle(false);
    echo json_encode(['ok' => true, 'logs' => array_slice(getLogs(), 0, 10)]);
    exit;
}

// ─── Web UI ───────────────────────────────────────────────────────────────────

$isConfigured = !empty($cfg['token']) && !empty($cfg['db_name']);
$currentUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'];
$logs = getLogs();

?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Agente Hosting — Chatea con tu Empresa</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --bg: #0f1117;
    --surface: #1a1d27;
    --surface2: #222534;
    --border: #2d3148;
    --accent: #6366f1;
    --accent-hover: #818cf8;
    --success: #22c55e;
    --warning: #f59e0b;
    --error: #ef4444;
    --text: #e2e8f0;
    --muted: #94a3b8;
    --radius: 10px;
  }
  body[data-theme="light"] {
    --bg: #f5f7fb;
    --surface: #ffffff;
    --surface2: #f7f8fc;
    --border: #dbe1ee;
    --accent: #4f46e5;
    --accent-hover: #4338ca;
    --success: #16a34a;
    --warning: #d97706;
    --error: #dc2626;
    --text: #111827;
    --muted: #64748b;
  }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; display: flex; flex-direction: column; }
  .topbar { background: var(--surface); border-bottom: 1px solid var(--border); padding: 14px 24px; display: flex; align-items: center; gap: 12px; }
  .topbar-logo { width: 32px; height: 32px; background: var(--accent); border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 18px; }
  .topbar-title { font-weight: 700; font-size: 16px; }
  .topbar-sub { color: var(--muted); font-size: 13px; margin-left: auto; }
  .theme-toggle {
    margin-left: 12px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    border-radius: 999px;
    border: 1px solid var(--border);
    background: var(--surface2);
    color: var(--text);
    cursor: pointer;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.03em;
  }
  .theme-toggle:hover { border-color: var(--accent); color: var(--accent); }
  .layout { display: flex; flex: 1; }
  .sidebar { width: 220px; background: var(--surface); border-right: 1px solid var(--border); padding: 20px 12px; display: flex; flex-direction: column; gap: 4px; }
  .nav-item { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 8px; cursor: pointer; font-size: 14px; color: var(--muted); border: none; background: none; width: 100%; text-align: left; transition: all 0.15s; }
  .nav-item:hover { background: var(--surface2); color: var(--text); }
  .nav-item.active { background: var(--accent); color: #fff; }
  .nav-icon { font-size: 16px; width: 20px; text-align: center; }
  .main { flex: 1; padding: 28px; overflow-y: auto; }
  .page { display: none; }
  .page.active { display: block; }
  h2 { font-size: 20px; font-weight: 700; margin-bottom: 6px; }
  .subtitle { color: var(--muted); font-size: 14px; margin-bottom: 24px; }
  .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 20px; margin-bottom: 20px; }
  .card-title { font-size: 14px; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 16px; }
  .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
  .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
  .stat-box { background: var(--surface2); border: 1px solid var(--border); border-radius: 8px; padding: 16px; }
  .stat-label { font-size: 12px; color: var(--muted); margin-bottom: 6px; }
  .stat-value { font-size: 24px; font-weight: 700; }
  .stat-value.green { color: var(--success); }
  .stat-value.red { color: var(--error); }
  .stat-value.yellow { color: var(--warning); }
  .badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 99px; font-size: 12px; font-weight: 600; }
  .badge-online { background: rgba(34,197,94,0.15); color: var(--success); }
  .badge-offline { background: rgba(239,68,68,0.15); color: var(--error); }
  .badge-unknown { background: rgba(148,163,184,0.15); color: var(--muted); }
  .dot { width: 7px; height: 7px; border-radius: 50%; background: currentColor; }
  label { display: block; font-size: 13px; color: var(--muted); margin-bottom: 5px; margin-top: 14px; }
  label:first-child { margin-top: 0; }
  input, select { width: 100%; padding: 9px 12px; background: var(--surface2); border: 1px solid var(--border); border-radius: 7px; color: var(--text); font-size: 14px; outline: none; transition: border-color 0.15s; }
  input:focus, select:focus { border-color: var(--accent); }
  .btn { display: inline-flex; align-items: center; justify-content: center; gap: 7px; padding: 9px 18px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; border: none; transition: all 0.15s; }
  .btn-primary { background: var(--accent); color: #fff; }
  .btn-primary:hover { background: var(--accent-hover); }
  .btn-secondary { background: var(--surface2); color: var(--text); border: 1px solid var(--border); }
  .btn-secondary:hover { background: var(--border); }
  .btn-success { background: rgba(34,197,94,0.15); color: var(--success); border: 1px solid rgba(34,197,94,0.3); }
  .btn-danger { background: rgba(239,68,68,0.15); color: var(--error); border: 1px solid rgba(239,68,68,0.3); }
  .btn-sm { padding: 6px 12px; font-size: 13px; }
  .btn:disabled { opacity: 0.5; cursor: not-allowed; }
  .alert { padding: 12px 16px; border-radius: 8px; font-size: 14px; margin-bottom: 16px; display: flex; gap: 10px; align-items: flex-start; }
  .alert-info { background: rgba(99,102,241,0.1); border: 1px solid rgba(99,102,241,0.3); color: #a5b4fc; }
  .alert-success { background: rgba(34,197,94,0.1); border: 1px solid rgba(34,197,94,0.3); color: #86efac; }
  .alert-error { background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3); color: #fca5a5; }
  .alert-warning { background: rgba(245,158,11,0.1); border: 1px solid rgba(245,158,11,0.3); color: #fcd34d; }
  .log-entry { display: flex; gap: 12px; padding: 8px 0; border-bottom: 1px solid var(--border); font-size: 13px; }
  .log-entry:last-child { border: none; }
  .log-time { color: var(--muted); white-space: nowrap; }
  .log-level { padding: 1px 7px; border-radius: 4px; font-size: 11px; font-weight: 700; text-transform: uppercase; white-space: nowrap; }
  .log-info { background: rgba(99,102,241,0.15); color: #a5b4fc; }
  .log-error { background: rgba(239,68,68,0.15); color: #fca5a5; }
  .log-warn { background: rgba(245,158,11,0.15); color: #fcd34d; }
  .code-block { background: var(--surface2); border: 1px solid var(--border); border-radius: 7px; padding: 14px; font-family: monospace; font-size: 13px; color: #a5b4fc; overflow-x: auto; }
  .actions-row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
  .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
  #toast { position: fixed; bottom: 24px; right: 24px; padding: 12px 20px; border-radius: 10px; font-size: 14px; font-weight: 600; z-index: 1000; display: none; animation: slideIn 0.2s ease; }
  @keyframes slideIn { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
  #status-indicator { display: flex; align-items: center; gap: 8px; }
  @media (max-width: 768px) { .layout { flex-direction: column; } .sidebar { width: 100%; flex-direction: row; overflow-x: auto; padding: 12px; gap: 8px; } .grid-2, .grid-3, .form-row { grid-template-columns: 1fr; } }
</style>
</head>
<body>

<div class="topbar">
  <div class="topbar-logo">🤖</div>
  <div>
    <div class="topbar-title">Chatea con tu Empresa</div>
  </div>
  <div class="topbar-sub">Agente PHP v<?= CCE_VERSION ?> — Hosting</div>
  <button type="button" id="theme-toggle" class="theme-toggle" onclick="toggleTheme()">
    <span id="theme-toggle-icon">🌙</span>
    <span id="theme-toggle-label">Modo claro</span>
  </button>
</div>

<div class="layout">
  <aside class="sidebar">
    <button class="nav-item active" onclick="showPage('dashboard', this)">
      <span class="nav-icon">📊</span> Dashboard
    </button>
    <button class="nav-item" onclick="showPage('config', this)">
      <span class="nav-icon">⚙️</span> Configuración
    </button>
    <button class="nav-item" onclick="showPage('cron', this)">
      <span class="nav-icon">⏰</span> Cron Job
    </button>
    <button class="nav-item" onclick="showPage('logs', this)">
      <span class="nav-icon">📋</span> Logs
    </button>
  </aside>

  <main class="main">

    <!-- DASHBOARD -->
    <div id="page-dashboard" class="page active">
      <h2>Estado del Agente</h2>
      <p class="subtitle">Monitorea la conexión y actividad de tu agente de hosting.</p>

      <?php if (!$isConfigured): ?>
      <div class="alert alert-warning">
        ⚠️ El agente no está configurado todavía. Ve a <strong>Configuración</strong> para comenzar.
      </div>
      <?php else: ?>
      <div class="alert alert-info">
        ℹ️ El agente funciona mediante Cron Jobs. Asegúrate de haber configurado el cron en cPanel.
      </div>
      <?php endif; ?>

      <div class="grid-3" style="margin-bottom:20px">
        <div class="stat-box">
          <div class="stat-label">Estado</div>
          <?php
            $lastLog = $logs[0] ?? null;
            $lastTime = $lastLog ? $lastLog['time'] : null;
            $isOnline = $lastTime && (time() - strtotime($lastTime)) < 120;
          ?>
          <div id="status-indicator">
            <?php if ($isConfigured && $isOnline): ?>
              <span class="badge badge-online"><span class="dot"></span> Online</span>
            <?php elseif ($isConfigured): ?>
              <span class="badge badge-offline"><span class="dot"></span> Inactivo</span>
            <?php else: ?>
              <span class="badge badge-unknown"><span class="dot"></span> No configurado</span>
            <?php endif; ?>
          </div>
        </div>
        <div class="stat-box">
          <div class="stat-label">Base de Datos</div>
          <div class="stat-value" style="font-size:15px;margin-top:4px">
            <?php echo $isConfigured ? htmlspecialchars(strtoupper($cfg['db_type'] ?? '')) . ' — ' . htmlspecialchars($cfg['db_name'] ?? '') : '—'; ?>
          </div>
        </div>
        <div class="stat-box" style="grid-column: span 1">
          <div class="stat-label">Modo de conexión</div>
          <div style="margin-top:6px">
            <?php if (!empty($cfg['webhook_url'])): ?>
              <span class="badge badge-online">⚡ Webhook activo</span>
              <div style="font-size:11px;color:var(--muted);margin-top:4px;word-break:break-all"><?= htmlspecialchars($cfg['webhook_url']) ?></div>
            <?php else: ?>
              <span class="badge badge-unknown">⏰ Cron polling</span>
              <div style="font-size:11px;color:var(--muted);margin-top:4px">Guarda la config para activar webhook</div>
            <?php endif; ?>
          </div>
        </div>
        <div class="stat-box">
          <div class="stat-label">Última actividad</div>
          <div class="stat-value" style="font-size:14px;margin-top:4px">
            <?php echo $lastTime ? htmlspecialchars($lastTime) : '—'; ?>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-title">Acciones</div>
        <div class="actions-row">
          <button class="btn btn-primary" onclick="runNow()" <?= !$isConfigured ? 'disabled' : '' ?>>
            ▶ Ejecutar ahora
          </button>
          <button class="btn btn-secondary" onclick="refreshLogs()">
            🔄 Actualizar logs
          </button>
        </div>
        <div id="run-result" style="margin-top:16px"></div>
      </div>

      <div class="card">
        <div class="card-title">Últimos logs</div>
        <div id="logs-preview">
          <?php foreach (array_slice($logs, 0, 8) as $log): ?>
          <div class="log-entry">
            <span class="log-time"><?= htmlspecialchars($log['time']) ?></span>
            <span class="log-level log-<?= htmlspecialchars($log['level']) ?>"><?= htmlspecialchars($log['level']) ?></span>
            <span><?= htmlspecialchars($log['msg']) ?></span>
          </div>
          <?php endforeach; ?>
          <?php if (empty($logs)): ?>
          <p style="color:var(--muted);font-size:14px">Sin actividad aún. Ejecuta el agente para ver logs aquí.</p>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- CONFIGURACIÓN -->
    <div id="page-config" class="page">
      <h2>Configuración</h2>
      <p class="subtitle">Configura la conexión al SaaS y a tu base de datos local.</p>

      <form id="config-form" onsubmit="saveConfig(event)">
        <div class="card">
          <div class="card-title">🔗 Conexión al SaaS</div>
          <div class="alert alert-info" style="margin-bottom:0">
            🌐 Conectando a <strong><?= CCE_SAAS_URL ?></strong>
          </div>
          <label style="margin-top:16px">Token del Agente *</label>
          <input type="text" name="token" placeholder="cce_agent_xxxxxxxxxxxxxxxxxxxx" value="<?= htmlspecialchars($cfg['token'] ?? '') ?>" required>
          <p style="font-size:12px;color:var(--muted);margin-top:5px">Obtenlo desde el panel de administración → Agentes → Nuevo agente.</p>
          <label style="margin-top:16px">Cron Secret <span style="color:var(--muted);font-weight:400">(opcional)</span></label>
          <input type="text" name="cron_secret" placeholder="clave-secreta-para-proteger-la-url" value="<?= htmlspecialchars($cfg['cron_secret'] ?? '') ?>">
          <div class="alert alert-info" style="margin-top:10px">
            ℹ️ <strong>¿Para qué sirve?</strong> Solo si corres el agente vía URL en el cron:<br>
            <code style="font-size:11px">?action=run&amp;secret=TU_SECRET</code><br>
            Si usas <code>php agent.php run</code> en el cron (CLI), <strong>no necesitas esto</strong>.
          </div>
        </div>

        <div class="card">
          <div class="card-title">🗄️ Base de Datos Local</div>
          <label>Tipo de Base de Datos *</label>
          <select name="db_type" id="db-type" onchange="updatePortDefault()">
            <option value="mysql" <?= ($cfg['db_type'] ?? 'mysql') === 'mysql' ? 'selected' : '' ?>>MySQL / MariaDB</option>
            <option value="pgsql" <?= ($cfg['db_type'] ?? '') === 'pgsql' ? 'selected' : '' ?>>PostgreSQL</option>
            <option value="sqlite" <?= ($cfg['db_type'] ?? '') === 'sqlite' ? 'selected' : '' ?>>SQLite</option>
          </select>

          <div id="host-fields">
            <div class="form-row" style="margin-top:14px">
              <div>
                <label>Host *</label>
                <input type="text" name="db_host" placeholder="localhost" value="<?= htmlspecialchars($cfg['db_host'] ?? 'localhost') ?>">
              </div>
              <div>
                <label>Puerto *</label>
                <input type="number" name="db_port" id="db-port" placeholder="3306" value="<?= htmlspecialchars($cfg['db_port'] ?? '3306') ?>">
              </div>
            </div>
            <label>Nombre de la BD *</label>
            <input type="text" name="db_name" id="db-name" placeholder="mi_base_de_datos" value="<?= htmlspecialchars($cfg['db_name'] ?? '') ?>">
            <div class="form-row">
              <div>
                <label>Usuario</label>
                <input type="text" name="db_user" placeholder="root" value="<?= htmlspecialchars($cfg['db_user'] ?? '') ?>">
              </div>
              <div>
                <label>Contraseña</label>
                <input type="password" name="db_pass" placeholder="••••••••" value="<?= htmlspecialchars($cfg['db_pass'] ?? '') ?>">
              </div>
            </div>
          </div>

          <div id="sqlite-fields" style="display:none">
            <label>Ruta del archivo SQLite *</label>
            <input type="text" name="db_name_sqlite" placeholder="/ruta/al/archivo.db" value="">
          </div>

          <div style="margin-top:16px;display:flex;gap:10px">
            <button type="button" class="btn btn-secondary btn-sm" onclick="testConnection()">
              🔌 Probar conexión
            </button>
            <span id="test-result" style="font-size:13px;align-self:center"></span>
          </div>
        </div>

        <div class="actions-row">
          <button type="submit" class="btn btn-primary">💾 Guardar configuración</button>
        </div>
      </form>
    </div>

    <!-- CRON -->
    <div id="page-cron" class="page">
      <h2>Configurar Cron Job</h2>
      <p class="subtitle">Configura la tarea automática en tu hosting para que el agente se ejecute cada minuto.</p>

      <div class="card">
        <div class="card-title">📟 Opción 1 — PHP CLI (recomendado)</div>
        <p style="font-size:14px;color:var(--muted);margin-bottom:12px">
          En cPanel → Cron Jobs → agregar esta línea (ajusta la ruta):
        </p>
        <div class="code-block">* * * * * php <?= htmlspecialchars(__FILE__) ?> run 2&gt;&amp;1 | tail -1</div>
      </div>

      <div class="card">
        <div class="card-title">🌐 Opción 2 — URL HTTP</div>
        <p style="font-size:14px;color:var(--muted);margin-bottom:12px">
          Si no tienes acceso CLI, usa esta URL en el cron (con curl o wget):
        </p>
        <?php
          $secret = $cfg['cron_secret'] ?? '';
          $cronUrl = $currentUrl . '?action=run' . ($secret ? '&secret=' . urlencode($secret) : '');
        ?>
        <div class="code-block">* * * * * curl -s "<?= htmlspecialchars($cronUrl) ?>" &gt; /dev/null 2&gt;&amp;1</div>
        <?php if (empty($secret)): ?>
        <div class="alert alert-warning" style="margin-top:12px">
          ⚠️ Recomendamos configurar un <strong>Cron Secret</strong> en la configuración para proteger esta URL.
        </div>
        <?php endif; ?>
      </div>

      <div class="card">
        <div class="card-title">ℹ️ Cómo funciona</div>
        <p style="font-size:14px;color:var(--muted);line-height:1.7">
          El cron se ejecuta cada minuto. En cada ejecución, el agente:<br>
          1. Se autentica con el gateway del SaaS<br>
          2. Sincroniza el esquema de tu BD (si cambió)<br>
          3. Espera hasta <strong>50 segundos</strong> por una consulta (long-poll)<br>
          4. Ejecuta la consulta SQL en tu BD local<br>
          5. Devuelve el resultado al SaaS<br><br>
          La latencia máxima es de ~10 segundos en condiciones normales, o hasta 60s si el cron tarda en activarse.
        </p>
      </div>
    </div>

    <!-- LOGS -->
    <div id="page-logs" class="page">
      <h2>Registro de Actividad</h2>
      <p class="subtitle">Historial de las últimas <?= MAX_LOGS ?> operaciones del agente.</p>
      <div class="card">
        <div class="actions-row" style="margin-bottom:16px">
          <button class="btn btn-secondary btn-sm" onclick="refreshLogs()">🔄 Actualizar</button>
        </div>
        <div id="logs-full">
          <?php foreach ($logs as $log): ?>
          <div class="log-entry">
            <span class="log-time"><?= htmlspecialchars($log['time']) ?></span>
            <span class="log-level log-<?= htmlspecialchars($log['level']) ?>"><?= htmlspecialchars($log['level']) ?></span>
            <span><?= htmlspecialchars($log['msg']) ?></span>
          </div>
          <?php endforeach; ?>
          <?php if (empty($logs)): ?>
          <p style="color:var(--muted);font-size:14px">Sin registros aún.</p>
          <?php endif; ?>
        </div>
      </div>
    </div>

  </main>
</div>

<div id="toast"></div>

<script>
function applyTheme(theme) {
  const nextTheme = theme === 'light' ? 'light' : 'dark';
  document.body.setAttribute('data-theme', nextTheme);
  const icon = document.getElementById('theme-toggle-icon');
  const label = document.getElementById('theme-toggle-label');
  if (icon) icon.textContent = nextTheme === 'light' ? '☀️' : '🌙';
  if (label) label.textContent = nextTheme === 'light' ? 'Modo oscuro' : 'Modo claro';
  try { localStorage.setItem('cce_php_agent_theme', nextTheme); } catch (e) {}
}

function toggleTheme() {
  const currentTheme = document.body.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
  applyTheme(currentTheme === 'light' ? 'dark' : 'light');
}

function showPage(name, btn) {
  document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  document.getElementById('page-' + name).classList.add('active');
  if (btn) btn.classList.add('active');
}

function toast(msg, type = 'success') {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.style.display = 'block';
  t.style.background = type === 'success' ? '#22c55e' : type === 'error' ? '#ef4444' : '#6366f1';
  t.style.color = '#fff';
  setTimeout(() => { t.style.display = 'none'; }, 3000);
}

function updatePortDefault() {
  const type = document.getElementById('db-type').value;
  const portInput = document.getElementById('db-port');
  const hostFields = document.getElementById('host-fields');
  const sqliteFields = document.getElementById('sqlite-fields');
  if (type === 'sqlite') {
    hostFields.style.display = 'none';
    sqliteFields.style.display = 'block';
  } else {
    hostFields.style.display = 'block';
    sqliteFields.style.display = 'none';
    portInput.value = type === 'pgsql' ? '5432' : '3306';
  }
}

async function testConnection() {
  const form = document.getElementById('config-form');
  const data = new FormData(form);
  const res = await fetch(window.location.pathname + '?action=test-db', { method: 'POST', body: data });
  const json = await res.json();
  const el = document.getElementById('test-result');
  if (json.ok) {
    el.innerHTML = '<span style="color:var(--success)">✅ Conexión OK — ' + json.tables + ' tablas, ' + json.columns + ' columnas</span>';
  } else {
    el.innerHTML = '<span style="color:var(--error)">❌ ' + (json.error || 'Error desconocido') + '</span>';
  }
}

async function saveConfig(e) {
  e.preventDefault();
  const form = document.getElementById('config-form');
  const data = new FormData(form);
  if (data.get('db_type') === 'sqlite') {
    const sqlitePath = form.querySelector('[name="db_name_sqlite"]').value;
    data.set('db_name', sqlitePath);
  }
  const res = await fetch(window.location.pathname + '?action=save-config', { method: 'POST', body: data });
  const json = await res.json();
  if (json.ok) {
    toast('✅ Configuración guardada');
    setTimeout(() => location.reload(), 1000);
  } else {
    toast('❌ Error al guardar', 'error');
  }
}

async function runNow() {
  const el = document.getElementById('run-result');
  el.innerHTML = '<span style="color:var(--muted)">⏳ Ejecutando ciclo del agente (puede tardar ~55s)...</span>';
  const res = await fetch(window.location.pathname + '?action=run-now', { method: 'POST' });
  const json = await res.json();
  el.innerHTML = '<span style="color:var(--success)">✅ Ciclo completado.</span>';
  refreshLogs();
}

async function refreshLogs() {
  const res = await fetch(window.location.pathname + '?action=get-logs');
  const json = await res.json();
  const logs = json.logs || [];
  const renderLog = (log) => `
    <div class="log-entry">
      <span class="log-time">${log.time}</span>
      <span class="log-level log-${log.level}">${log.level}</span>
      <span>${log.msg}</span>
    </div>`;
  const html = logs.length ? logs.map(renderLog).join('') : '<p style="color:var(--muted);font-size:14px">Sin registros.</p>';
  const preview = document.getElementById('logs-preview');
  const full = document.getElementById('logs-full');
  if (preview) preview.innerHTML = logs.slice(0,8).map(renderLog).join('') || '<p style="color:var(--muted);font-size:14px">Sin actividad aún.</p>';
  if (full) full.innerHTML = html;
}

// Auto-refresh logs every 30s
setInterval(refreshLogs, 30000);

// Init sqlite toggle
updatePortDefault();
document.getElementById('db-type').addEventListener('change', updatePortDefault);

// Init theme
try {
  const savedTheme = localStorage.getItem('cce_php_agent_theme');
  applyTheme(savedTheme === 'light' ? 'light' : 'dark');
} catch (e) {
  applyTheme('dark');
}
</script>
</body>
</html>
