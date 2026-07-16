<?php

class BlocklistManager
{
    private PDO $pdo;
    private int $defaultDuration;
    private bool $ipsetEnabled = false;
    private string $helperScript = '/usr/local/bin/block-helper.sh';
    private static bool $ipsetRestored = false;

    public function __construct(PDO $pdo, array $config = [])
    {
        $this->pdo = $pdo;
        $this->defaultDuration = (int) ($config['BLOCK_DURATION'] ?? 3600);

        if (!empty($config['IPSET_ENABLED']) && $config['IPSET_ENABLED'] === 'true') {
            $this->ipsetEnabled = true;
        }
    }

    /**
     * 從舊 JSON 檔案匯入 (單次遷移用)
     */
    public static function migrateFromJson(PDO $pdo, string $jsonPath): void
    {
        if (!file_exists($jsonPath)) return;

        $count = $pdo->query('SELECT COUNT(*) FROM blocklist')->fetchColumn();
        if ($count > 0) return;

        $data = json_decode(file_get_contents($jsonPath), true);
        if (!is_array($data)) return;

        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO blocklist (ip, reason, rule, blocked_at, expires_at) VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($data as $entry) {
            if (isset($entry['ip'])) {
                $stmt->execute([
                    $entry['ip'],
                    $entry['reason'] ?? '',
                    $entry['rule'] ?? 'migrated',
                    $entry['blocked_at'] ?? time(),
                    $entry['expires_at'] ?? (time() + 3600),
                ]);
            }
        }
    }

    /**
     * 重啟時從 DB 復原封鎖 IP 到 ipset (只執行一次)
     */
    public function restoreIpset(): void
    {
        if (!self::$ipsetRestored && $this->ipsetEnabled) {
            self::$ipsetRestored = true;

            $rows = $this->pdo->query(
                'SELECT ip, expires_at FROM blocklist WHERE expires_at > ' . time()
            )->fetchAll();

            foreach ($rows as $row) {
                $remaining = (int) $row['expires_at'] - time();
                if ($remaining > 0) {
                    $this->execHelper('add', $row['ip'], $remaining);
                }
            }
        }
    }

    public function isBlocked(string $ip): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM blocklist WHERE ip = ? AND expires_at > ?'
        );
        $stmt->execute([$ip, time()]);
        return $stmt->fetchColumn() > 0;
    }

    public function block(string $ip, string $reason, string $rule, ?int $duration = null): void
    {
        $duration = $duration ?? $this->defaultDuration;
        $now = time();

        $stmt = $this->pdo->prepare(
            'INSERT INTO blocklist (ip, reason, rule, blocked_at, expires_at) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$ip, $reason, $rule, $now, $now + $duration]);

        if ($this->ipsetEnabled) {
            $this->execHelper('add', $ip, $duration);
        }
    }

    public function unblock(string $ip): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM blocklist WHERE ip = ?');
        $stmt->execute([$ip]);
        $removed = $stmt->rowCount() > 0;

        if ($removed && $this->ipsetEnabled) {
            $this->execHelper('del', $ip);
        }

        return $removed;
    }

    public function list(): array
    {
        $stmt = $this->pdo->query(
            'SELECT ip, reason, rule, blocked_at, expires_at FROM blocklist WHERE expires_at > ' . time()
            . ' ORDER BY blocked_at DESC'
        );
        return $stmt->fetchAll();
    }

    public function cleanExpired(): void
    {
        $this->pdo->exec('DELETE FROM blocklist WHERE expires_at <= ' . time());
    }

    public function isIpsetAvailable(): bool
    {
        return $this->ipsetEnabled && file_exists($this->helperScript);
    }

    private function execHelper(string $action, string $ip = '', int $timeout = 0): void
    {
        if (!file_exists($this->helperScript)) return;

        $escapedIp = escapeshellarg($ip);

        if ($action === 'add') {
            $cmd = sprintf('sudo %s add %s %d 2>/dev/null', $this->helperScript, $escapedIp, $timeout);
        } else {
            $cmd = sprintf('sudo %s del %s 2>/dev/null', $this->helperScript, $escapedIp);
        }

        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            error_log("block-helper.sh $action $ip failed with exit code $exitCode");
        }
    }
}
