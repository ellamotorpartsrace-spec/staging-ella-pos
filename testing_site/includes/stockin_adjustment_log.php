<?php
declare(strict_types=1);

function ensureStockinAdjustmentLogTable(PDO $conn): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    $conn->exec("
        CREATE TABLE IF NOT EXISTS stockin_adjustment_log (
            id INT(11) NOT NULL AUTO_INCREMENT,
            movement_id INT(11) NOT NULL,
            adjusted_by INT(11) NOT NULL,
            old_quantity INT(11) NOT NULL DEFAULT 0,
            new_quantity INT(11) NOT NULL DEFAULT 0,
            old_capital DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            new_capital DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            old_variation_id INT(11) NULL,
            new_variation_id INT(11) NULL,
            action_type VARCHAR(50) NOT NULL,
            reason VARCHAR(255) NOT NULL,
            notes TEXT NULL,
            adjusted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_stockin_adjustment_movement (movement_id),
            KEY idx_stockin_adjustment_action (action_type),
            KEY idx_stockin_adjustment_adjusted_at (adjusted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $columns = [];
    $stmt = $conn->query("SHOW COLUMNS FROM stockin_adjustment_log");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $columns[$column['Field']] = $column;
    }

    $alters = [];
    $requiredColumns = [
        'movement_id' => 'ADD COLUMN movement_id INT(11) NOT NULL DEFAULT 0',
        'adjusted_by' => 'ADD COLUMN adjusted_by INT(11) NOT NULL DEFAULT 0',
        'old_quantity' => 'ADD COLUMN old_quantity INT(11) NOT NULL DEFAULT 0',
        'new_quantity' => 'ADD COLUMN new_quantity INT(11) NOT NULL DEFAULT 0',
        'old_capital' => 'ADD COLUMN old_capital DECIMAL(10,2) NOT NULL DEFAULT 0.00',
        'new_capital' => 'ADD COLUMN new_capital DECIMAL(10,2) NOT NULL DEFAULT 0.00',
        'old_variation_id' => 'ADD COLUMN old_variation_id INT(11) NULL',
        'new_variation_id' => 'ADD COLUMN new_variation_id INT(11) NULL',
        'action_type' => "ADD COLUMN action_type VARCHAR(50) NOT NULL DEFAULT ''",
        'reason' => "ADD COLUMN reason VARCHAR(255) NOT NULL DEFAULT ''",
        'notes' => 'ADD COLUMN notes TEXT NULL',
        'adjusted_at' => 'ADD COLUMN adjusted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ];

    foreach ($requiredColumns as $name => $definition) {
        if (!isset($columns[$name])) {
            $alters[] = $definition;
        }
    }

    if (isset($columns['action_type']) && stripos((string) $columns['action_type']['Type'], 'enum(') === 0) {
        $alters[] = 'MODIFY COLUMN action_type VARCHAR(50) NOT NULL';
    }

    if ($alters) {
        $conn->exec("ALTER TABLE stockin_adjustment_log " . implode(", ", $alters));
    }

    $checked = true;
}

function insertStockinAdjustmentLog(PDO $conn, array $data): void
{
    ensureStockinAdjustmentLogTable($conn);

    $stmt = $conn->prepare("
        INSERT INTO stockin_adjustment_log
            (movement_id, adjusted_by, old_quantity, new_quantity, old_capital, new_capital, old_variation_id, new_variation_id, action_type, reason, notes)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        (int) ($data['movement_id'] ?? 0),
        (int) ($data['adjusted_by'] ?? 0),
        (int) ($data['old_quantity'] ?? 0),
        (int) ($data['new_quantity'] ?? 0),
        (float) ($data['old_capital'] ?? 0),
        (float) ($data['new_capital'] ?? 0),
        isset($data['old_variation_id']) ? (int) $data['old_variation_id'] : null,
        isset($data['new_variation_id']) ? (int) $data['new_variation_id'] : null,
        (string) ($data['action_type'] ?? ''),
        (string) ($data['reason'] ?? ''),
        ($data['notes'] ?? null) !== '' ? ($data['notes'] ?? null) : null,
    ]);
}
