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
// 2. 載入安全模組
// ==========================================
require_once __DIR__ . '/security/LogManager.php';
require_once __DIR__ . '/security/BlocklistManager.php';
require_once __DIR__ . '/security/WhitelistManager.php';
require_once __DIR__ . '/security/DetectionEngine.php';
require_once __DIR__ . '/security/Rules/ScanDetectionRule.php';
require_once __DIR__ . '/security/Rules/MaliciousUserAgentRule.php';

// ==========================================
// 3. 讀取設定 (與 db.php 各自獨立，不衝突)
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
$blocklistManager = new BlocklistManager($config);
$whitelistManager = new WhitelistManager($config);

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
    echo json_encode(['success' => false, 'message' => '存取被拒：您的 IP 已被封鎖']);
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
        echo json_encode(['success' => false, 'message' => '未授權的管理請求']);
        return true;
    }

    match ($uri) {
        '/admin/whitelist' => (function () use ($whitelistManager) {
            echo json_encode(['success' => true, 'data' => $whitelistManager->list()]);
        })(),

        '/admin/whitelist/add' => (function () use ($whitelistManager) {
            $data = json_decode(file_get_contents('php://input'), true);
            if (empty($data['ip']) || !filter_var($data['ip'], FILTER_VALIDATE_IP)) {
                echo json_encode(['success' => false, 'message' => '無效的 IP 位址']);
                return;
            }
            $whitelistManager->add($data['ip']);
            echo json_encode(['success' => true, 'message' => "已加入白名單: {$data['ip']}"]);
        })(),

        '/admin/whitelist/remove' => (function () use ($whitelistManager) {
            $data = json_decode(file_get_contents('php://input'), true);
            if (empty($data['ip'])) {
                echo json_encode(['success' => false, 'message' => '缺少 IP 位址']);
                return;
            }
            $removed = $whitelistManager->remove($data['ip']);
            echo json_encode([
                'success' => $removed,
                'message' => $removed ? "已移除: {$data['ip']}" : 'IP 不在白名單中',
            ]);
        })(),

        '/admin/blocklist' => (function () use ($blocklistManager) {
            echo json_encode(['success' => true, 'data' => $blocklistManager->list()]);
        })(),

        '/admin/blocklist/unblock' => (function () use ($blocklistManager) {
            $data = json_decode(file_get_contents('php://input'), true);
            if (empty($data['ip'])) {
                echo json_encode(['success' => false, 'message' => '缺少 IP 位址']);
                return;
            }
            $unblocked = $blocklistManager->unblock($data['ip']);
            echo json_encode([
                'success' => $unblocked,
                'message' => $unblocked ? "已解封: {$data['ip']}" : 'IP 不在封鎖列表中',
            ]);
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
            $msg = "掃描完成，已封鎖 {$blockedCount} 個 IP";
            if ($skippedCount > 0) {
                $msg .= "，{$skippedCount} 個 IP 在白名單中跳過";
            }
            echo json_encode(['success' => true, 'message' => $msg]);
        })(),

        default => (function () {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => '管理端點不存在']);
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
    echo json_encode(['success' => false, 'message' => 'Not Found']);
}

return true;
