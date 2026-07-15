<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Env.php';
require_once __DIR__ . '/../src/Database.php';

header('Content-Type: application/json; charset=utf-8');

Env::load(__DIR__ . '/../.env');

try {
    $pdo = Database::connect();
    $version = $pdo->query('select @@version as version')->fetch();
    $database = $pdo->query('select db_name() as name')->fetch();

    echo json_encode([
        'ok' => true,
        'database' => $database['name'] ?? null,
        'table' => getenv('DB_TABLE') ?: null,
        'server_version' => $version['version'] ?? null,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Datenbankverbindung fehlgeschlagen.',
        'detail' => $exception->getMessage(),
    ]);
}
