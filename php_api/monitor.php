<?php
/**
 * CLI 全量掃描觸發器
 *
 * 用法：php monitor.php
 *
 * 會掃描 access.log 中的近期記錄，將違規 IP 加入封鎖列表。
 * 適合透過 cron 每分鐘執行。
 */

require_once __DIR__ . '/security/LogManager.php';
require_once __DIR__ . '/security/BlocklistManager.php';
require_once __DIR__ . '/security/WhitelistManager.php';
require_once __DIR__ . '/security/DetectionEngine.php';
require_once __DIR__ . '/security/Rules/ScanDetectionRule.php';
require_once __DIR__ . '/security/Rules/MaliciousUserAgentRule.php';

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

$logManager = new LogManager();
$blocklistManager = new BlocklistManager($config);
$whitelistManager = new WhitelistManager($config);

$detectionEngine = new DetectionEngine();
$detectionEngine->registerRule(new ScanDetectionRule($config));
$detectionEngine->registerRule(new MaliciousUserAgentRule());

echo "[" . date('Y-m-d H:i:s') . "] 開始安全掃描...\n";

// 讀取近期紀錄
$window = max(
    (int) ($config['SCAN_WINDOW'] ?? 60),
    60
);
echo "  掃描範圍: 最近 {$window} 秒\n";

$violations = $detectionEngine->runAll($logManager);

if (empty($violations)) {
    echo "  未發現違規 IP\n";
} else {
    $blockedCount = 0;
    foreach ($violations as $v) {
        if ($whitelistManager->isWhitelisted($v['ip'])) {
            echo "    - [{$v['rule']}] {$v['ip']}: 跳過 (白名單)\n";
            continue;
        }
        $blocklistManager->block($v['ip'], $v['reason'], $v['rule']);
        $blockedCount++;
        echo "    - [{$v['rule']}] {$v['ip']}: {$v['reason']}\n";
    }
    if ($blockedCount === 0) {
        echo "  所有違規 IP 均在白名單中，無需封鎖\n";
    }
}

echo "[" . date('Y-m-d H:i:s') . "] 掃描完成\n";
