<?php

class BlocklistManager
{
    private PDO $pdo;
    private int $defaultDuration;

    public function __construct(PDO $pdo, array $config = [])
    {
        $this->pdo = $pdo;
        $this->defaultDuration = (int) ($config['BLOCK_DURATION'] ?? 3600);
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
    }

    public function unblock(string $ip): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM blocklist WHERE ip = ?');
        $stmt->execute([$ip]);
        return $stmt->rowCount() > 0;
    }

    public function list(): array
    {
        $stmt = $this->pdo->query('SELECT ip, reason, rule, blocked_at, expires_at FROM blocklist WHERE expires_at > ' . time() . ' ORDER BY blocked_at DESC');
        return $stmt->fetchAll();
    }

    public function cleanExpired(): void
    {
        $this->pdo->exec('DELETE FROM blocklist WHERE expires_at <= ' . time());
    }
}
