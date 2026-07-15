<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Env.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/WeightSeries.php';

header('Content-Type: application/json; charset=utf-8');

Env::load(__DIR__ . '/../.env');

function includeDropPoints(array $rows, array $drops): array
{
    $pointsByKey = [];

    foreach ($rows as $row) {
        $pointsByKey[$row['nodeId'] . ':' . $row['timestamp']] = $row;
    }

    foreach ($drops as $drop) {
        $peakTimestamp = (int) $drop['timestamp'];
        $pointsByKey[$drop['nodeId'] . ':' . $peakTimestamp] = [
            'nodeId' => (int) $drop['nodeId'],
            'label' => (string) $drop['label'],
            'timestamp' => $peakTimestamp,
            'timeLabel' => (string) $drop['timeLabel'],
            'value' => (float) $drop['reachedWeight'],
        ];

        if (isset($drop['fallTimestamp'])) {
            $fallTimestamp = (int) $drop['fallTimestamp'];
            $pointsByKey[$drop['nodeId'] . ':' . $fallTimestamp] = [
                'nodeId' => (int) $drop['nodeId'],
                'label' => (string) $drop['label'],
                'timestamp' => $fallTimestamp,
                'timeLabel' => (string) $drop['fallTimeLabel'],
                'value' => (float) $drop['afterWeight'],
            ];
        }
    }

    $points = array_values($pointsByKey);
    usort($points, static function (array $left, array $right): int {
        $timestampCompare = $left['timestamp'] <=> $right['timestamp'];

        return $timestampCompare !== 0 ? $timestampCompare : $left['nodeId'] <=> $right['nodeId'];
    });

    return $points;
}

try {
    $series = new WeightSeries(Database::connect(), getenv('DB_TABLE') ?: 'scraptable');
    $action = $_GET['action'] ?? 'data';

    if ($action === 'meta') {
        echo json_encode($series->meta(), JSON_THROW_ON_ERROR);
        exit;
    }

    $meta = $series->meta();
    $start = isset($_GET['start']) ? (int) $_GET['start'] : (int) $meta['min'];
    $end = isset($_GET['end']) ? (int) $_GET['end'] : (int) $meta['max'];
    $drops = $series->drops($start, $end);
    $rows = includeDropPoints($series->rows($start, $end), $drops);

    echo json_encode([
        'meta' => $meta,
        'data' => $rows,
        'drops' => $drops,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Gewichtsdaten konnten nicht geladen werden.',
        'detail' => $exception->getMessage(),
    ]);
}
