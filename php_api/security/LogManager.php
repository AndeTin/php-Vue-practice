<?php

class LogManager
{
    private string $logFile;

    public function __construct(?string $logFile = null)
    {
        $this->logFile = $logFile ?? __DIR__ . '/../log/access.log';
        $this->ensureDirExists(dirname($this->logFile));
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
     * 取得指定時間範圍內的所有日誌條目
     */
    public function getRecentEntries(int $windowSeconds = 60): array
    {
        if (!file_exists($this->logFile)) {
            return [];
        }

        $cutoff = time() - $windowSeconds;
        $entries = [];

        $handle = fopen($this->logFile, 'r');
        if (!$handle) {
            return [];
        }

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

    /**
     * 取得指定 IP 在時間範圍內的日誌條目
     */
    public function getRecentEntriesByIp(string $ip, int $windowSeconds = 60): array
    {
        if (!file_exists($this->logFile)) {
            return [];
        }

        $cutoff = time() - $windowSeconds;
        $entries = [];

        $handle = fopen($this->logFile, 'r');
        if (!$handle) {
            return [];
        }

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') continue;

            $entry = json_decode($line, true);
            if ($entry
                && isset($entry['time'], $entry['ip'])
                && $entry['time'] >= $cutoff
                && $entry['ip'] === $ip
            ) {
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
