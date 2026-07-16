<?php
/**
 * CLI 全量掃描觸發器 (增量版本)
 *
 * 用法：php monitor.php
 *
 * 利用 CursorManager 記錄上次讀取進度，每次只處理新增的日誌條目，
 * 避免重複掃描整個 access.log。
 *
 * 支援 log rotation：cursor 偵測到 inode 改變或檔案縮小即自動重置。
 *
 * 適合透過 cron 每分鐘執行：
 *   * * * * * /usr/bin/php /path/to/monitor.php >> /var/log/security-monitor.log 2>&1
 */

require_once __DIR__ . '/security/LogManager.php';
require_once __DIR__ . '/security/BlocklistManager.php';
require_once __DIR__ . '/security/WhitelistManager.php';
require_once __DIR__ . '/security/CursorManager.php';
require_once __DIR__ . '/security/DetectionEngine.php';
require_once __DIR__ . '/security/Rules/ScanDetectionRule.php';
require_once __DIR__ . '/security/Rules/MaliciousUserAgentRule.php';
require_once __DIR__ . '/db.php';

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

$logManager       = new LogManager();
$blocklistManager = new BlocklistManager($pdo, $config);
$whitelistManager = new WhitelistManager($pdo, $config);
$cursorManager    = new CursorManager($pdo);

$detectionEngine = new DetectionEngine();
$detectionEngine->registerRule(new ScanDetectionRule($config));
$detectionEngine->registerRule(new MaliciousUserAgentRule());

echo "[" . date('Y-m-d H:i:s') . "] 開始安全掃描...\n";

/**
 * 根據 inode 找出被 rotation 的舊檔案
 * 常見命名：access.log.1, access.log.old, access.log-YYYYMMDD
 */
function findRotatedFile(string $logFile, int $targetInode): ?string
{
    $dir  = dirname($logFile);
    $base = basename($logFile);

    $candidates = array_merge(
        glob($dir . '/' . $base . '.*') ?: [],
        glob($dir . '/' . $base . '-*') ?: [],
    );

    foreach ($candidates as $candidate) {
        if (!file_exists($candidate)) continue;
        $st = stat($candidate);
        if (($st['ino'] ?? 0) === $targetInode) {
            return $candidate;
        }
    }
    return null;
}

$logFile = __DIR__ . '/log/access.log';
$cursorId = 'monitor_full';

// === 檢查 rotation + 收集舊檔殘留條目 ===
$rotation = $cursorManager->checkRotation($cursorId, $logFile);
$startPosition = 0;
$preloadedCount = 0;

if ($rotation['rotated']) {
    echo "  偵測到 log rotation\n";

    // 找出舊檔案 (比對 inode)
    $oldFile = findRotatedFile($logFile, $rotation['oldInode']);
    if ($oldFile && $rotation['oldPosition'] > 0) {
        $oldData = $logManager->getNewEntriesFrom($oldFile, $rotation['oldPosition']);
        if (!empty($oldData['entries'])) {
            $preloadedCount = count($oldData['entries']);
            echo "  從舊檔 " . basename($oldFile) . " 載入 {$preloadedCount} 筆未處理條目\n";
            $logManager->setPreloadedEntries($oldData['entries']);
        }
    }

    $startPosition = 0;
} else {
    $cursor = $cursorManager->get($cursorId);
    $startPosition = $cursor ? (int) $cursor['position'] : 0;
}

// 增量讀取新檔
$newData = $logManager->getNewEntriesFrom($logFile, $startPosition);
$newCount = count($newData['entries']);

echo "  新增日誌: {$newCount} 筆\n";
if ($preloadedCount > 0) {
    echo "   (含 {$preloadedCount} 筆來自舊檔)\n";
}

if ($newCount === 0 && $preloadedCount === 0) {
    echo "  無新資料，略過掃描\n";
    $cursorManager->update(
        $cursorId,
        $logFile,
        $rotation['currentInode'] ?: $newData['inode'],
        $newData['newPosition'],
        $newData['size'],
    );
    echo "[" . date('Y-m-d H:i:s') . "] 掃描完成\n";
    exit(0);
}

// 執行規則 (規則內部的 getRecentEntries / getRecentEntriesByIp 會包含 preloaded 條目)
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

// 清除 preloaded，避免下次掃描汙染
$logManager->clearPreloadedEntries();

// 更新 cursor
$cursorManager->update(
    $cursorId,
    $logFile,
    $newData['inode'],
    $newData['newPosition'],
    $newData['size'],
);

echo "[" . date('Y-m-d H:i:s') . "] 掃描完成\n";
