<?php

interface RuleInterface
{
    public function getName(): string;

    /**
     * @param LogManager $logManager
     * @param string|null $targetIp 若提供，只檢查此 IP
     * @return array [['ip' => string, 'reason' => string, 'rule' => string], ...]
     */
    public function analyze(LogManager $logManager, ?string $targetIp = null): array;
}
