<?php

require_once __DIR__ . '/RuleInterface.php';
require_once __DIR__ . '/../LogManager.php';

class ScanDetectionRule implements RuleInterface
{
    private int $threshold;
    private int $window;

    public function __construct(array $config = [])
    {
        $this->threshold = (int) ($config['SCAN_THRESHOLD'] ?? 50);
        $this->window    = (int) ($config['SCAN_WINDOW'] ?? 60);
    }

    public function getName(): string
    {
        return 'scan_detection';
    }

    public function analyze(LogManager $logManager, ?string $targetIp = null): array
    {
        if ($targetIp !== null) {
            $entries = $logManager->getRecentEntriesByIp($targetIp, $this->window);
            $count = 0;
            foreach ($entries as $entry) {
                if ($entry['status'] >= 400 && $entry['status'] < 500) {
                    $count++;
                }
            }
            if ($count >= $this->threshold) {
                return [[
                    'ip'     => $targetIp,
                    'reason' => "在 {$this->window} 秒內產生 {$count} 次 4xx 回應 (閾值: {$this->threshold})",
                    'rule'   => $this->getName(),
                ]];
            }
            return [];
        }

        $entries = $logManager->getRecentEntries($this->window);
        $countByIp = [];

        foreach ($entries as $entry) {
            if ($entry['status'] >= 400 && $entry['status'] < 500) {
                $ip = $entry['ip'];
                $countByIp[$ip] = ($countByIp[$ip] ?? 0) + 1;
            }
        }

        $violations = [];
        foreach ($countByIp as $ip => $count) {
            if ($count >= $this->threshold) {
                $violations[] = [
                    'ip'     => $ip,
                    'reason' => "在 {$this->window} 秒內產生 {$count} 次 4xx 回應 (閾值: {$this->threshold})",
                    'rule'   => $this->getName(),
                ];
            }
        }

        return $violations;
    }
}
