<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Env.php';
require_once __DIR__ . '/../src/Database.php';

header('Content-Type: application/json; charset=utf-8');

Env::load(__DIR__ . '/../.env');

$charts = require __DIR__ . '/../config/charts.php';
$chartId = $_GET['chart'] ?? '';

if (! isset($charts[$chartId])) {
    http_response_code(404);
    echo json_encode(['error' => 'Unbekanntes Diagramm.']);
    exit;
}

try {
    if ((getenv('APP_DEMO') ?: 'true') === 'true') {
        echo json_encode([
            'id' => $chartId,
            'title' => $charts[$chartId]['title'],
            'type' => $charts[$chartId]['type'],
            'data' => demoData($chartId),
        ], JSON_THROW_ON_ERROR);
        exit;
    }

    $pdo = Database::connect();
    $statement = $pdo->query($charts[$chartId]['sql']);

    echo json_encode([
        'id' => $chartId,
        'title' => $charts[$chartId]['title'],
        'type' => $charts[$chartId]['type'],
        'data' => $statement->fetchAll(),
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Daten konnten nicht geladen werden.',
        'detail' => $exception->getMessage(),
    ]);
}

function demoData(string $chartId): array
{
    return match ($chartId) {
        'scrap_trend' => [
            ['label' => '2026-06-24', 'value' => 42],
            ['label' => '2026-06-25', 'value' => 36],
            ['label' => '2026-06-26', 'value' => 58],
            ['label' => '2026-06-27', 'value' => 31],
            ['label' => '2026-06-28', 'value' => 47],
            ['label' => '2026-06-29', 'value' => 63],
            ['label' => '2026-06-30', 'value' => 51],
        ],
        default => [
            ['label' => 'Materialfehler', 'value' => 128],
            ['label' => 'Maschine', 'value' => 96],
            ['label' => 'Ruesten', 'value' => 72],
            ['label' => 'Transport', 'value' => 38],
            ['label' => 'Pruefung', 'value' => 29],
        ],
    };
}
