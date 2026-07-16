<?php

class WhitelistManager
{
    private PDO $pdo;
    private array $staticIps = [];

    public function __construct(PDO $pdo, array $config = [])
    {
        $this->pdo = $pdo;

        if (!empty($config['WHITELIST_IPS'])) {
            $ips = explode(',', $config['WHITELIST_IPS']);
            foreach ($ips as $ip) {
                $ip = trim($ip);
                if ($ip !== '') {
                    $this->staticIps[$ip] = true;
                }
            }
        }

        self::migrateFromJson($pdo);
    }

    /**
     * 從舊 JSON 檔案匯入 (單次遷移用)
     */
    public static function migrateFromJson(PDO $pdo, string $jsonPath = ''): void
    {
        if ($jsonPath === '') {
            $jsonPath = __DIR__ . '/../log/whitelist.json';
        }
        if (!file_exists($jsonPath)) return;

        $count = $pdo->query('SELECT COUNT(*) FROM whitelist_dynamic')->fetchColumn();
        if ($count > 0) return;

        $data = json_decode(file_get_contents($jsonPath), true);
        if (!is_array($data)) return;

        $stmt = $pdo->prepare('INSERT IGNORE INTO whitelist_dynamic (ip) VALUES (?)');
        foreach ($data as $ip) {
            if (is_string($ip) && $ip !== '') {
                $stmt->execute([$ip]);
            }
        }
    }

    private function loadDynamicFromDb(): array
    {
        $rows = $this->pdo->query('SELECT ip FROM whitelist_dynamic')->fetchAll();
        $ips = [];
        foreach ($rows as $row) {
            $ips[$row['ip']] = true;
        }
        return $ips;
    }

    public function isWhitelisted(string $ip): bool
    {
        if (isset($this->staticIps[$ip])) return true;

        // 避免每次查詢都讀全部，先試 SELECT 單一 IP
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM whitelist_dynamic WHERE ip = ?');
        $stmt->execute([$ip]);
        return $stmt->fetchColumn() > 0;
    }

    public function add(string $ip): void
    {
        $stmt = $this->pdo->prepare('INSERT IGNORE INTO whitelist_dynamic (ip) VALUES (?)');
        $stmt->execute([$ip]);
    }

    public function remove(string $ip): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM whitelist_dynamic WHERE ip = ?');
        $stmt->execute([$ip]);
        return $stmt->rowCount() > 0;
    }

    public function list(): array
    {
        $dynamicKeys = array_keys($this->loadDynamicFromDb());
        return [
            'static'  => array_keys($this->staticIps),
            'dynamic' => $dynamicKeys,
        ];
    }
}
