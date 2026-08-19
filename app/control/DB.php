<?php
/**
 * TEMPORARY STAND-IN FOR LOCAL PREVIEW ONLY.
 * Minimal version of the DB helper class used by the pages
 * (getTableById, getCoachName). Replace with your real class later —
 * it likely has more methods your existing sanda pages depend on.
 */
require_once __DIR__ . '/pdo-connection.php';

class DB
{
    private PDO $pdo;

    public function __construct()
    {
        global $pdocon;
        $this->pdo = $pdocon;
    }

    public function getTableById(string $table, ?int $id): array
    {
        if (!$id) {
            return [];
        }
        // Table name can't be parameterized; allow only known-safe characters.
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $stmt = $this->pdo->prepare("SELECT * FROM `{$table}` WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: [];
    }

    public function getCoachName(int $champion_id, ?int $board_id): string
    {
        // Placeholder — your real system likely has a coaches table.
        // Wire this up once you share that table's structure.
        return '';
    }
}
