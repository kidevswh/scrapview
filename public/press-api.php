<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Env.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/PressJobRepository.php';

header('Content-Type: application/json; charset=utf-8');

Env::load(__DIR__ . '/../.env');

function jsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_THROW_ON_ERROR);
}

function requestPayload(): array
{
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);

    return is_array($payload) ? $payload : [];
}

try {
    $demoMode = (getenv('APP_DEMO') ?: 'true') === 'true';
    $repository = new PressJobRepository(
        $demoMode ? null : Database::connect(),
        $demoMode,
        getenv('PRESS_LOIPRO_TABLE') ?: 'sapdata.dbo.LOIPRO'
    );
    $action = $_GET['action'] ?? 'snapshot';

    if ($action === 'context') {
        jsonResponse(['data' => $repository->context()]);
        return;
    }

    if ($action === 'orders') {
        $pressId = (string) ($_GET['press'] ?? '');
        $query = (string) ($_GET['query'] ?? '');
        jsonResponse(['data' => $repository->orders($pressId, $query)]);
        return;
    }

    if ($action === 'snapshot') {
        jsonResponse(['data' => $repository->snapshot()]);
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['error' => 'Methode nicht erlaubt.'], 405);
        return;
    }

    $payload = requestPayload();
    $pressId = (string) ($payload['pressId'] ?? '');
    $user = (string) ($payload['user'] ?? '');

    if ($action === 'start') {
        jsonResponse(['data' => $repository->start($pressId, (array) ($payload['order'] ?? []), $user)], 201);
        return;
    }

    if ($action === 'pause') {
        jsonResponse(['data' => $repository->pause($pressId, $user)]);
        return;
    }

    if ($action === 'resume') {
        jsonResponse(['data' => $repository->resume($pressId, $user)]);
        return;
    }

    if ($action === 'finish') {
        jsonResponse(['data' => $repository->finish($pressId, $user)]);
        return;
    }

    jsonResponse(['error' => 'Unbekannte Aktion.'], 404);
} catch (InvalidArgumentException $exception) {
    jsonResponse(['error' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    jsonResponse([
        'error' => 'Pressenstatus konnte nicht verarbeitet werden.',
        'detail' => $exception->getMessage(),
    ], 500);
}
