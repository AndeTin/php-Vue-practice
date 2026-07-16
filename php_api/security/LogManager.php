<?php

class LogManager
{
    private string $logFile;
    private array $preloadedEntries = [];

    public function __construct(?string $logFile = null)
    {
        $this->logFile = $logFile ?? __DIR__ . '/../log/access.log';
        $this->ensureDirExists(dirname($this->logFile));
    }

    /**
     * 預載來自已 rotation 舊檔的未處理條目
     */
    public function setPreloadedEntries(array $entries): void
    {
        $this->preloadedEntries = $entries;
    }

    public function clearPreloadedEntries(): void
    {
        $this->preloadedEntries = [];
    }

    public function logRequest(string $ip, string $method, string $uri, int $status, string $ua): void
    {
        $entry = json_encode([
            'time'   => time(),
            'ip'     => $ip,
            'method' => $method,
            'uri'    => $uri,
            'status' => $status,
            'ua'     => $ua,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        file_put_contents($this->logFile, $entry . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * 從指定 byte 位置開始增量讀取新行
     *
     * @return array{entries: array, newPosition: int, inode: int, size: int}
     */
    public function getNewEntriesFrom(string $filePath, int $position): array
    {
        $result = [
            'entries'     => [],
            'newPosition' => $position,
            'inode'       => 0,
            'size'        => 0,
        ];

        if (!file_exists($filePath)) {
            return $result;
        }

        $stat = stat($filePath);
        $result['inode'] = $stat['ino'] ?? 0;
        $result['size']  = $stat['size'] ?? 0;

        if ($position >= $result['size']) {
            return $result;
        }

        $handle = fopen($filePath, 'r');
        if (!$handle) {
            return $result;
        }

        if ($position > 0) {
            fseek($handle, $position);
        }

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') continue;

            $entry = json_decode($line, true);
            if ($entry) {
                $result['entries'][] = $entry;
            }
        }

        $result['newPosition'] = ftell($handle);
        fclose($handle);

        return $result;
    }

    /**
     * 取得指定時間範圍內的所有日誌條目（含已 preload 的舊檔條目）
     */
    public function getRecentEntries(int $windowSeconds = 60): array
    {
        if (!file_exists($this->logFile) && empty($this->preloadedEntries)) {
            return [];
        }

        $cutoff = time() - $windowSeconds;

        // 將檔案與預載條目合併，統一 filter
        $allEntries = $this->preloadedEntries;

        if (file_exists($this->logFile)) {
            $handle = fopen($this->logFile, 'r');
            if ($handle) {
                while (($line = fgets($handle)) !== false) {
                    $line = trim($line);
                    if ($line === '') continue;
                    $entry = json_decode($line, true);
                    if ($entry) {
                        $allEntries[] = $entry;
                    }
                }
                fclose($handle);
            }
        }

        return array_values(array_filter($allEntries, function ($e) use ($cutoff) {
            return isset($e['time']) && $e['time'] >= $cutoff;
        }));
    }

    /**
     * 取得指定 IP 在時間範圍內的日誌條目（含已 preload 的舊檔條目）
     */
    public function getRecentEntriesByIp(string $ip, int $windowSeconds = 60): array
    {
        if (!file_exists($this->logFile) && empty($this->preloadedEntries)) {
            return [];
        }

        $cutoff = time() - $windowSeconds;

        $allEntries = $this->preloadedEntries;

        if (file_exists($this->logFile)) {
            $handle = fopen($this->logFile, 'r');
            if ($handle) {
                while (($line = fgets($handle)) !== false) {
                    $line = trim($line);
                    if ($line === '') continue;
                    $entry = json_decode($line, true);
                    if ($entry) {
                        $allEntries[] = $entry;
                    }
                }
                fclose($handle);
            }
        }

        return array_values(array_filter($allEntries, function ($e) use ($cutoff, $ip) {
            return isset($e['time'], $e['ip'])
                && $e['time'] >= $cutoff
                && $e['ip'] === $ip;
        }));
    }

    /**
     * 從指定檔案讀取時間範圍內的條目（用於已 rotation 的舊檔）
     */
    public function getRecentEntriesFromFile(string $filePath, int $windowSeconds): array
    {
        if (!file_exists($filePath)) return [];

        $cutoff = time() - $windowSeconds;
        $entries = [];

        $handle = fopen($filePath, 'r');
        if (!$handle) return [];

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') continue;
            $entry = json_decode($line, true);
            if ($entry && isset($entry['time']) && $entry['time'] >= $cutoff) {
                $entries[] = $entry;
            }
        }
        fclose($handle);

        return $entries;
    }

    private function ensureDirExists(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}
