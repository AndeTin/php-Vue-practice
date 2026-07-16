<?php

class CursorManager
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function get(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM scan_cursors WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function update(string $id, string $filename, int $inode, int $position, int $fileSize): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO scan_cursors (id, filename, inode, position, file_size)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 filename = VALUES(filename),
                 inode    = VALUES(inode),
                 position = VALUES(position),
                 file_size = VALUES(file_size)'
        );
        $stmt->execute([$id, $filename, $inode, $position, $fileSize]);
    }

    /**
     * 檢查檔案是否被 rotation 或截斷
     *
     * @return array{rotated: bool, currentInode: int, currentSize: int}
     */
    public function checkRotation(string $id, string $filePath): array
    {
        $currentInode = 0;
        $currentSize = 0;

        if (file_exists($filePath)) {
            $stat = stat($filePath);
            $currentInode = $stat['ino'] ?? 0;
            $currentSize = $stat['size'] ?? 0;
        }

        $cursor = $this->get($id);

        if ($cursor === null) {
            return ['rotated' => false, 'currentInode' => $currentInode, 'currentSize' => $currentSize];
        }

        $rotated =
            $cursor['inode'] !== $currentInode ||
            $currentSize < $cursor['position'];

        return [
            'rotated'      => $rotated,
            'currentInode' => $currentInode,
            'currentSize'  => $currentSize,
        ];
    }
}
