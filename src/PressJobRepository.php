<?php

final class PressJobRepository
{
    private const PRESSES = [
        'P1' => 'Presse 1',
        'P2' => 'Presse 2',
        'P3' => 'Presse 3',
        'P4' => 'Presse 4',
    ];

    private const DEMO_USERS = [
        'Anlage 1',
        'Anlage 2',
        'Schichtfuehrer',
        'Coda',
    ];

    private const ORDER_COLUMNS = ['AUFNR', 'ORDER_ID', 'ORDERID', 'FERTIGUNGSAUFTRAG'];
    private const MATERIAL_COLUMNS = ['MATNR', 'MATERIAL', 'MATERIAL_NUMBER', 'ARTIKEL'];
    private const DESCRIPTION_COLUMNS = ['KTEXT', 'MAKTX', 'DESCRIPTION', 'BEZEICHNUNG', 'TEXT'];
    private const QUANTITY_COLUMNS = ['GAMNG', 'QUANTITY', 'QTY', 'MENGE'];
    private const UNIT_COLUMNS = ['GMEIN', 'MEINS', 'UNIT', 'EINHEIT'];
    private const START_COLUMNS = ['GSTRP', 'START_DATE', 'PLANNED_START', 'STARTDATUM'];
    private const END_COLUMNS = ['GLTRP', 'END_DATE', 'PLANNED_END', 'ENDDATUM'];

    public function __construct(
        private readonly ?PDO $pdo,
        private readonly bool $demoMode = false,
        private readonly string $loiproTable = 'sapdata.dbo.LOIPRO'
    ) {
        if (! $this->demoMode) {
            $this->ensureSchema();
        }
    }

    public function context(): array
    {
        return [
            'presses' => $this->presses(),
            'users' => $this->users(),
        ];
    }

    public function orders(string $pressId, string $query = ''): array
    {
        $this->assertPress($pressId);

        if ($this->demoMode || ! $this->pdo) {
            return $this->demoOrders($query);
        }

        return $this->loiproOrders($query);
    }

    public function snapshot(): array
    {
        $runs = $this->demoMode || ! $this->pdo
            ? $this->demoRuns()
            : $this->databaseRuns();

        $activeByPress = [];
        $historyByPress = [];

        foreach ($runs as $run) {
            $mapped = $this->mapRun($run);
            $pressId = $mapped['pressId'];

            if (in_array($mapped['status'], ['active', 'paused'], true)) {
                $activeByPress[$pressId] = $mapped;
                continue;
            }

            $historyByPress[$pressId][] = $mapped;
        }

        foreach ($historyByPress as $pressId => $items) {
            usort($items, static fn (array $left, array $right): int => strcmp((string) $right['startedAt'], (string) $left['startedAt']));
            $historyByPress[$pressId] = array_slice($items, 0, 8);
        }

        return [
            'serverTime' => $this->nowIso(),
            'presses' => array_map(function (array $press) use ($activeByPress, $historyByPress): array {
                $pressId = $press['id'];

                return [
                    ...$press,
                    'activeRun' => $activeByPress[$pressId] ?? null,
                    'history' => $historyByPress[$pressId] ?? [],
                ];
            }, $this->presses()),
        ];
    }

    public function start(string $pressId, array $order, string $user): array
    {
        $this->assertPress($pressId);
        $user = $this->normalizeUser($user);
        $orderId = trim((string) ($order['id'] ?? ''));

        if ($orderId === '') {
            throw new InvalidArgumentException('Bitte einen Fertigungsauftrag waehlen.');
        }

        if ($this->activeRun($pressId)) {
            throw new RuntimeException('Auf dieser Presse laeuft bereits ein Auftrag.');
        }

        if ($this->demoMode || ! $this->pdo) {
            $runs = $this->demoRuns();
            $runs[] = [
                'id' => $this->nextDemoId($runs),
                'press_id' => $pressId,
                'order_id' => $orderId,
                'order_label' => (string) ($order['label'] ?? $orderId),
                'material' => (string) ($order['material'] ?? ''),
                'description' => (string) ($order['description'] ?? ''),
                'quantity' => (string) ($order['quantity'] ?? ''),
                'unit' => (string) ($order['unit'] ?? ''),
                'planned_start' => (string) ($order['plannedStart'] ?? ''),
                'planned_end' => (string) ($order['plannedEnd'] ?? ''),
                'status' => 'active',
                'started_by' => $user,
                'ended_by' => '',
                'started_at' => $this->nowIso(),
                'ended_at' => '',
                'pause_started_at' => '',
                'paused_ms' => 0,
            ];
            $this->saveDemoRuns($runs);

            return $this->snapshot();
        }

        $statement = $this->pdo->prepare(
            'insert into dbo.press_job_runs (
                press_id, order_id, order_label, material, description, quantity, unit,
                planned_start, planned_end, status, started_by, started_at
             ) values (
                :press_id, :order_id, :order_label, :material, :description, :quantity, :unit,
                :planned_start, :planned_end, :status, :started_by, sysdatetime()
             )'
        );
        $statement->execute([
            'press_id' => $pressId,
            'order_id' => $orderId,
            'order_label' => (string) ($order['label'] ?? $orderId),
            'material' => (string) ($order['material'] ?? ''),
            'description' => (string) ($order['description'] ?? ''),
            'quantity' => (string) ($order['quantity'] ?? ''),
            'unit' => (string) ($order['unit'] ?? ''),
            'planned_start' => (string) ($order['plannedStart'] ?? ''),
            'planned_end' => (string) ($order['plannedEnd'] ?? ''),
            'status' => 'active',
            'started_by' => $user,
        ]);

        return $this->snapshot();
    }

    public function pause(string $pressId, string $user): array
    {
        return $this->changeState($pressId, $user, 'pause');
    }

    public function resume(string $pressId, string $user): array
    {
        return $this->changeState($pressId, $user, 'resume');
    }

    public function finish(string $pressId, string $user): array
    {
        return $this->changeState($pressId, $user, 'finish');
    }

    private function changeState(string $pressId, string $user, string $action): array
    {
        $this->assertPress($pressId);
        $user = $this->normalizeUser($user);

        if ($this->demoMode || ! $this->pdo) {
            $runs = $this->demoRuns();
            $now = $this->nowIso();

            foreach ($runs as &$run) {
                if ($run['press_id'] !== $pressId || ! in_array($run['status'], ['active', 'paused'], true)) {
                    continue;
                }

                if ($action === 'pause' && $run['status'] === 'active') {
                    $run['status'] = 'paused';
                    $run['pause_started_at'] = $now;
                } elseif ($action === 'resume' && $run['status'] === 'paused') {
                    $run['status'] = 'active';
                    $run['paused_ms'] = (int) ($run['paused_ms'] ?? 0) + $this->millisBetween($run['pause_started_at'], $now);
                    $run['pause_started_at'] = '';
                } elseif ($action === 'finish') {
                    if ($run['status'] === 'paused') {
                        $run['paused_ms'] = (int) ($run['paused_ms'] ?? 0) + $this->millisBetween($run['pause_started_at'], $now);
                    }
                    $run['status'] = 'finished';
                    $run['ended_at'] = $now;
                    $run['ended_by'] = $user;
                    $run['pause_started_at'] = '';
                }

                break;
            }
            unset($run);

            $this->saveDemoRuns($runs);

            return $this->snapshot();
        }

        $run = $this->activeRun($pressId);
        if (! $run) {
            throw new RuntimeException('Auf dieser Presse laeuft kein Auftrag.');
        }

        if ($action === 'pause') {
            if ($run['status'] !== 'active') {
                throw new RuntimeException('Der Auftrag ist bereits pausiert.');
            }

            $statement = $this->pdo->prepare(
                "update dbo.press_job_runs
                 set status = 'paused', pause_started_at = sysdatetime()
                 where id = :id"
            );
            $statement->execute(['id' => $run['id']]);
        } elseif ($action === 'resume') {
            if ($run['status'] !== 'paused') {
                throw new RuntimeException('Der Auftrag ist nicht pausiert.');
            }

            $statement = $this->pdo->prepare(
                "update dbo.press_job_runs
                 set status = 'active',
                     paused_ms = paused_ms + datediff_big(millisecond, pause_started_at, sysdatetime()),
                     pause_started_at = null
                 where id = :id"
            );
            $statement->execute(['id' => $run['id']]);
        } else {
            $statement = $this->pdo->prepare(
                "update dbo.press_job_runs
                 set status = 'finished',
                     ended_at = sysdatetime(),
                     ended_by = :ended_by,
                     paused_ms = paused_ms + case
                        when pause_started_at is null then 0
                        else datediff_big(millisecond, pause_started_at, sysdatetime())
                     end,
                     pause_started_at = null
                 where id = :id"
            );
            $statement->execute(['id' => $run['id'], 'ended_by' => $user]);
        }

        return $this->snapshot();
    }

    private function activeRun(string $pressId): ?array
    {
        if ($this->demoMode || ! $this->pdo) {
            foreach ($this->demoRuns() as $run) {
                if ($run['press_id'] === $pressId && in_array($run['status'], ['active', 'paused'], true)) {
                    return $run;
                }
            }

            return null;
        }

        $statement = $this->pdo->prepare(
            "select top 1 *
             from dbo.press_job_runs
             where press_id = :press_id and status in ('active', 'paused')
             order by started_at desc"
        );
        $statement->execute(['press_id' => $pressId]);
        $run = $statement->fetch();

        return $run ?: null;
    }

    private function databaseRuns(): array
    {
        $statement = $this->pdo->query(
            "select top 80 *
             from dbo.press_job_runs
             order by case when status in ('active', 'paused') then 0 else 1 end, started_at desc"
        );

        return $statement->fetchAll();
    }

    private function loiproOrders(string $query): array
    {
        $columns = $this->loiproColumns();
        $orderColumn = $this->firstExistingColumn($columns, getenv('PRESS_ORDER_ID_COLUMN') ?: '', self::ORDER_COLUMNS);

        if ($orderColumn === null) {
            throw new RuntimeException('In LOIPRO wurde keine Auftragsnummer-Spalte gefunden. Bitte PRESS_ORDER_ID_COLUMN setzen.');
        }

        $materialColumn = $this->firstExistingColumn($columns, getenv('PRESS_MATERIAL_COLUMN') ?: '', self::MATERIAL_COLUMNS);
        if ($materialColumn === null) {
            throw new RuntimeException('In LOIPRO wurde keine Materialnummer-Spalte gefunden. Bitte PRESS_MATERIAL_COLUMN setzen.');
        }

        $descriptionColumn = $this->firstExistingColumn($columns, getenv('PRESS_DESCRIPTION_COLUMN') ?: '', self::DESCRIPTION_COLUMNS);
        $quantityColumn = $this->firstExistingColumn($columns, getenv('PRESS_QUANTITY_COLUMN') ?: '', self::QUANTITY_COLUMNS);
        $unitColumn = $this->firstExistingColumn($columns, getenv('PRESS_UNIT_COLUMN') ?: '', self::UNIT_COLUMNS);
        $startColumn = $this->firstExistingColumn($columns, getenv('PRESS_PLANNED_START_COLUMN') ?: '', self::START_COLUMNS);
        $endColumn = $this->firstExistingColumn($columns, getenv('PRESS_PLANNED_END_COLUMN') ?: '', self::END_COLUMNS);
        $select = [
            $this->quoteIdentifier($orderColumn) . ' as order_id',
            $materialColumn ? $this->quoteIdentifier($materialColumn) . ' as material' : "cast('' as nvarchar(1)) as material",
            $descriptionColumn ? $this->quoteIdentifier($descriptionColumn) . ' as description' : "cast('' as nvarchar(1)) as description",
            $quantityColumn ? 'try_convert(float, ' . $this->quoteIdentifier($quantityColumn) . ') as quantity' : 'cast(null as float) as quantity',
            $unitColumn ? $this->quoteIdentifier($unitColumn) . ' as unit' : "cast('' as nvarchar(1)) as unit",
            $startColumn ? $this->quoteIdentifier($startColumn) . ' as planned_start' : "cast('' as nvarchar(1)) as planned_start",
            $endColumn ? $this->quoteIdentifier($endColumn) . ' as planned_end' : "cast('' as nvarchar(1)) as planned_end",
        ];
        $params = [];
        $where = $this->quoteIdentifier($orderColumn) . ' is not null';

        if ($query !== '') {
            $where .= ' and (
                cast(' . $this->quoteIdentifier($orderColumn) . ' as nvarchar(120)) like :query
                or cast(' . $this->quoteIdentifier($materialColumn) . ' as nvarchar(120)) like :query
            )';
            $params['query'] = '%' . $query . '%';
        }

        $statement = $this->pdo->prepare(
            'select distinct top 80 ' . implode(', ', $select) . '
             from ' . $this->qualifiedLoiproTable() . '
             where ' . $where . '
             order by order_id desc'
        );
        $statement->execute($params);

        return array_map(fn (array $row): array => $this->mapOrder($row), $statement->fetchAll());
    }

    private function loiproColumns(): array
    {
        [$database, $schema, $table] = $this->loiproTableParts();
        $statement = $this->pdo->prepare(
            'select c.name
             from ' . $this->quoteIdentifier($database) . '.sys.columns c
             join ' . $this->quoteIdentifier($database) . '.sys.objects o on o.object_id = c.object_id
             join ' . $this->quoteIdentifier($database) . '.sys.schemas s on s.schema_id = o.schema_id
             where s.name = :schema and o.name = :table'
        );
        $statement->execute(['schema' => $schema, 'table' => $table]);

        return array_map(static fn (array $row): string => strtoupper((string) $row['name']), $statement->fetchAll());
    }

    private function firstExistingColumn(array $columns, string $configured, array $candidates): ?string
    {
        if ($configured !== '' && in_array(strtoupper($configured), $columns, true)) {
            return $configured;
        }

        foreach ($candidates as $candidate) {
            if (in_array(strtoupper($candidate), $columns, true)) {
                return $candidate;
            }
        }

        return null;
    }

    private function mapOrder(array $row): array
    {
        $orderId = trim((string) ($row['order_id'] ?? ''));
        $material = trim((string) ($row['material'] ?? ''));
        $description = trim((string) ($row['description'] ?? ''));

        return [
            'id' => $orderId,
            'label' => trim($orderId . ($material !== '' ? ' - ' . $material : '')),
            'material' => $material,
            'description' => $description,
            'quantity' => $row['quantity'] !== null ? (string) $row['quantity'] : '',
            'unit' => trim((string) ($row['unit'] ?? '')),
            'plannedStart' => trim((string) ($row['planned_start'] ?? '')),
            'plannedEnd' => trim((string) ($row['planned_end'] ?? '')),
        ];
    }

    private function demoOrders(string $query): array
    {
        $orders = [
            ['id' => '10048271', 'label' => '10048271 - Traeger links', 'material' => 'MAT-4711', 'description' => 'Traeger links', 'quantity' => '1200', 'unit' => 'ST', 'plannedStart' => '', 'plannedEnd' => ''],
            ['id' => '10048272', 'label' => '10048272 - Traeger rechts', 'material' => 'MAT-4712', 'description' => 'Traeger rechts', 'quantity' => '1200', 'unit' => 'ST', 'plannedStart' => '', 'plannedEnd' => ''],
            ['id' => '10048290', 'label' => '10048290 - Quertraeger', 'material' => 'MAT-4890', 'description' => 'Quertraeger', 'quantity' => '850', 'unit' => 'ST', 'plannedStart' => '', 'plannedEnd' => ''],
            ['id' => '10048310', 'label' => '10048310 - Halter vorne', 'material' => 'MAT-5010', 'description' => 'Halter vorne', 'quantity' => '640', 'unit' => 'ST', 'plannedStart' => '', 'plannedEnd' => ''],
        ];

        if ($query === '') {
            return $orders;
        }

        $query = strtolower($query);

        return array_values(array_filter($orders, static fn (array $order): bool => str_contains(strtolower(implode(' ', $order)), $query)));
    }

    private function mapRun(array $row): array
    {
        $startedAt = $this->dateValue($row['started_at'] ?? '');
        $endedAt = $this->dateValue($row['ended_at'] ?? '');
        $pauseStartedAt = $this->dateValue($row['pause_started_at'] ?? '');
        $pausedMs = (int) ($row['paused_ms'] ?? 0);

        if (($row['status'] ?? '') === 'paused' && $pauseStartedAt !== '') {
            $pausedMs += $this->millisBetween($pauseStartedAt, $this->nowIso());
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'pressId' => (string) ($row['press_id'] ?? ''),
            'pressLabel' => self::PRESSES[(string) ($row['press_id'] ?? '')] ?? (string) ($row['press_id'] ?? ''),
            'orderId' => (string) ($row['order_id'] ?? ''),
            'orderLabel' => (string) ($row['order_label'] ?? $row['order_id'] ?? ''),
            'material' => (string) ($row['material'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'quantity' => (string) ($row['quantity'] ?? ''),
            'unit' => (string) ($row['unit'] ?? ''),
            'plannedStart' => (string) ($row['planned_start'] ?? ''),
            'plannedEnd' => (string) ($row['planned_end'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'startedBy' => (string) ($row['started_by'] ?? ''),
            'endedBy' => (string) ($row['ended_by'] ?? ''),
            'startedAt' => $startedAt,
            'endedAt' => $endedAt,
            'pauseStartedAt' => $pauseStartedAt,
            'pausedMs' => $pausedMs,
            'elapsedMs' => max(0, $this->millisBetween($startedAt, $endedAt ?: $this->nowIso()) - $pausedMs),
        ];
    }

    private function presses(): array
    {
        return array_map(
            static fn (string $id, string $label): array => ['id' => $id, 'label' => $label],
            array_keys(self::PRESSES),
            array_values(self::PRESSES)
        );
    }

    private function users(): array
    {
        $configured = array_values(array_filter(array_map('trim', explode(',', (string) (getenv('PRESS_USERS') ?: '')))));

        return $configured !== [] ? $configured : self::DEMO_USERS;
    }

    private function assertPress(string $pressId): void
    {
        if (! array_key_exists($pressId, self::PRESSES)) {
            throw new InvalidArgumentException('Unbekannte Presse.');
        }
    }

    private function normalizeUser(string $user): string
    {
        $user = trim($user);

        if ($user === '') {
            throw new InvalidArgumentException('Bitte einen Benutzer auswaehlen.');
        }

        return $user;
    }

    private function ensureSchema(): void
    {
        $this->pdo->exec(
            "if object_id('dbo.press_job_runs', 'U') is null
             create table dbo.press_job_runs (
                id int identity(1,1) primary key,
                press_id nvarchar(40) not null,
                order_id nvarchar(120) not null,
                order_label nvarchar(255) null,
                material nvarchar(120) null,
                description nvarchar(255) null,
                quantity nvarchar(80) null,
                unit nvarchar(40) null,
                planned_start nvarchar(80) null,
                planned_end nvarchar(80) null,
                status nvarchar(20) not null,
                started_by nvarchar(120) not null,
                ended_by nvarchar(120) null,
                started_at datetime2 not null default sysdatetime(),
                ended_at datetime2 null,
                pause_started_at datetime2 null,
                paused_ms bigint not null default 0
             )"
        );
    }

    private function qualifiedLoiproTable(): string
    {
        [$database, $schema, $table] = $this->loiproTableParts();

        return $this->quoteIdentifier($database) . '.' . $this->quoteIdentifier($schema) . '.' . $this->quoteIdentifier($table);
    }

    private function loiproTableParts(): array
    {
        $parts = array_values(array_filter(explode('.', $this->loiproTable), static fn (string $part): bool => trim($part) !== ''));

        if (count($parts) === 1) {
            return ['sapdata', 'dbo', $parts[0]];
        }

        if (count($parts) === 2) {
            return ['sapdata', $parts[0], $parts[1]];
        }

        return [$parts[count($parts) - 3], $parts[count($parts) - 2], $parts[count($parts) - 1]];
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '[' . str_replace(']', ']]', $identifier) . ']';
    }

    private function demoRuns(): array
    {
        $file = $this->demoStateFile();

        if (! is_file($file)) {
            return [];
        }

        $payload = json_decode((string) file_get_contents($file), true);

        return is_array($payload['runs'] ?? null) ? $payload['runs'] : [];
    }

    private function saveDemoRuns(array $runs): void
    {
        $file = $this->demoStateFile();
        $directory = dirname($file);

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($file, json_encode(['runs' => $runs], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
    }

    private function demoStateFile(): string
    {
        return __DIR__ . '/../storage/press-state.json';
    }

    private function nextDemoId(array $runs): int
    {
        return array_reduce($runs, static fn (int $max, array $run): int => max($max, (int) ($run['id'] ?? 0)), 0) + 1;
    }

    private function nowIso(): string
    {
        return date('c');
    }

    private function dateValue(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        $timestamp = strtotime($value);

        return $timestamp !== false ? date('c', $timestamp) : $value;
    }

    private function millisBetween(string $start, string $end): int
    {
        $startTime = strtotime($start);
        $endTime = strtotime($end);

        if ($startTime === false || $endTime === false) {
            return 0;
        }

        return max(0, ($endTime - $startTime) * 1000);
    }
}
