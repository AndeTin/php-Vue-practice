<?php
/**
 * Front Controller for PHP Built-in Server
 *
 * Usage: php -S localhost:8000 router.php
 *
 * 職責：
 *   1. CORS 前置處理
 *   2. IP 白名單/封鎖檢查
 *   3. 管理 API 端點 (whitelist, blocklist, monitor)
 *   4. 路由至現有 PHP 端點
 *   5. 請求日誌記錄
 *   6. 即時攻擊行為偵測 (shutdown function 內執行)
 */

// ==========================================
// 1. CORS 前置處理
// ==========================================
require_once __DIR__ . '/cors.php';

// ==========================================
// 2. 載入安全模組 + 資料庫連線
// ==========================================
require_once __DIR__ . '/security/LogManager.php';
require_once __DIR__ . '/security/BlocklistManager.php';
require_once __DIR__ . '/security/WhitelistManager.php';
require_once __DIR__ . '/security/CursorManager.php';
require_once __DIR__ . '/security/DetectionEngine.php';
require_once __DIR__ . '/security/Rules/ScanDetectionRule.php';
require_once __DIR__ . '/security/Rules/MaliciousUserAgentRule.php';
require_once __DIR__ . '/db.php';

// ==========================================
// 3. 讀取設定 (router 自用一份，與 db.php 的 $dbConfig 不衝突)
// ==========================================
$envPath = __DIR__ . '/.env';
$config = [];
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $config[trim($parts[0])] = trim($parts[1]);
        }
    }
}

// ==========================================
// 4. 初始化管理物件
// ==========================================
$logManager       = new LogManager();
$blocklistManager = new BlocklistManager($pdo, $config);
$whitelistManager = new WhitelistManager($pdo, $config);
$cursorManager    = new CursorManager($pdo);

$detectionEngine = new DetectionEngine();
$detectionEngine->registerRule(new ScanDetectionRule($config));
$detectionEngine->registerRule(new MaliciousUserAgentRule());

$ip     = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$ua     = $_SERVER['HTTP_USER_AGENT'] ?? '';

// ==========================================
// 5. 註冊關閉函數（確保 exit() 後仍能記錄日誌與偵測）
//    PHP 會在輸出緩衝區 flush 後、腳本真正結束前呼叫此函數
// ==========================================
register_shutdown_function(function () use (
    $logManager, $blocklistManager, $whitelistManager, $detectionEngine,
    $ip, $method, $uri, $ua
) {
    $statusCode = http_response_code();
    $logManager->logRequest($ip, $method, $uri, $statusCode, $ua);

    if ($whitelistManager->isWhitelisted($ip)) {
        return;
    }

    $violations = $detectionEngine->runAll($logManager, $ip);
    foreach ($violations as $v) {
        $blocklistManager->block($v['ip'], $v['reason'], $v['rule']);
    }
});

// ==========================================
// 6. IP 白名單 / 封鎖檢查
// ==========================================
if (!$whitelistManager->isWhitelisted($ip) && $blocklistManager->isBlocked($ip)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied：Your IP has been blocked'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return true;
}

// ==========================================
// 7. 管理 API 端點
// ==========================================
$adminKey = $config['ADMIN_KEY'] ?? '';

if (str_starts_with($uri, '/admin/')) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/Bearer\s(\S+)/', $authHeader, $m) || $m[1] !== $adminKey) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized admin request'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return true;
    }

    match ($uri) {
        '/admin/whitelist' => (function () use ($whitelistManager) {
            echo json_encode(['success' => true, 'data' => $whitelistManager->list()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        })(),

        '/admin/whitelist/add' => (function () use ($whitelistManager) {
            $data = json_decode(file_get_contents('php://input'), true);
            if (empty($data['ip']) || !filter_var($data['ip'], FILTER_VALIDATE_IP)) {
                echo json_encode(['success' => false, 'message' => 'Invalid IP address'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                return;
            }
            $whitelistManager->add($data['ip']);
            echo json_encode(['success' => true, 'message' => "Adding to Whitelist: {$data['ip']}"], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        })(),

        '/admin/whitelist/remove' => (function () use ($whitelistManager) {
            $data = json_decode(file_get_contents('php://input'), true);
            if (empty($data['ip'])) {
                echo json_encode(['success' => false, 'message' => 'IP address required'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                return;
            }
            $removed = $whitelistManager->remove($data['ip']);
            echo json_encode([
                'success' => $removed,
                'message' => $removed ? "Remove complete: {$data['ip']}" : 'IP not found in Whitelist',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        })(),

        '/admin/blocklist' => (function () use ($blocklistManager) {
            echo json_encode(['success' => true, 'data' => $blocklistManager->list()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        })(),

        '/admin/blocklist/unblock' => (function () use ($blocklistManager) {
            $data = json_decode(file_get_contents('php://input'), true);
            if (empty($data['ip'])) {
                echo json_encode(['success' => false, 'message' => 'IP address required'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                return;
            }
            $unblocked = $blocklistManager->unblock($data['ip']);
            echo json_encode([
                'success' => $unblocked,
                'message' => $unblocked ? "Unblock complete: {$data['ip']}" : 'IP not found in blocklist',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        })(),

        '/admin/monitor/run' => (function () use ($detectionEngine, $logManager, $blocklistManager, $whitelistManager) {
            $violations = $detectionEngine->runAll($logManager);
            $blockedCount = 0;
            $skippedCount = 0;
            foreach ($violations as $v) {
                if ($whitelistManager->isWhitelisted($v['ip'])) {
                    $skippedCount++;
                    continue;
                }
                $blocklistManager->block($v['ip'], $v['reason'], $v['rule']);
                $blockedCount++;
            }
            $msg = "Scanning complete，{$blockedCount} IP have been blocked";
            if ($skippedCount > 0) {
                $msg .= "，{$skippedCount} IP skipped due to Whitelist";
            }
            echo json_encode(['success' => true, 'message' => $msg], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        })(),

        default => (function () {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Admin endpoint not exist'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        })(),
    };

    return true;
}

// ==========================================
// 8. 路由至現有 PHP 端點
// ==========================================
ob_start();

$filePath = __DIR__ . $uri;

if (file_exists($filePath) && pathinfo($filePath, PATHINFO_EXTENSION) === 'php') {
    require $filePath;
} else {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Not Found'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

return true;
