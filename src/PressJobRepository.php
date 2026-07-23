<?php

final class PressJobRepository
{
    private const PRESSES = [
        'P1' => 'TFS01 700-to',
        'P2' => 'TFS02 2000-to',
        'P3' => 'TFS03 1400-to',
        'P4' => 'TFS04 2000-to',
    ];

    private const ORDER_COLUMNS = ['AUFNR', 'ORDER_ID', 'ORDERID', 'FERTIGUNGSAUFTRAG'];
    private const MATERIAL_COLUMNS = ['MATNR', 'MATERIAL', 'MATERIAL_NUMBER', 'ARTIKEL'];
    private const DESCRIPTION_COLUMNS = ['KTEXT', 'MAKTX', 'DESCRIPTION', 'BEZEICHNUNG', 'TEXT'];
    private const QUANTITY_COLUMNS = ['GAMNG', 'QUANTITY', 'QTY', 'MENGE'];
    private const UNIT_COLUMNS = ['GMEIN', 'MEINS', 'UNIT', 'EINHEIT'];
    private const START_COLUMNS = ['GSTRP', 'START_DATE', 'PLANNED_START', 'STARTDATUM'];
    private const END_COLUMNS = ['GLTRP', 'END_DATE', 'PLANNED_END', 'ENDDATUM'];
    private const WORK_CENTER_COLUMNS = ['ARBPLZ'];

    private ?bool $databaseStateAvailable = null;
    private ?bool $workplaceMappingsAvailable = null;

    public function __construct(
        private readonly ?PDO $pdo,
        private readonly bool $demoMode = false,
        private readonly string $loiproTable = 'sapdata.dbo.LOIPRO',
        private readonly string $workplaceTable = 'dbo.press_workplace_assignments',
        private readonly string $clientHostname = ''
    ) {}

    public function context(): array
    {
        return [
            'presses' => $this->presses(),
            'workplace' => $this->workplaceContext(),
        ];
    }

    public function adminWorkplaceMappings(): array
    {
        $this->assertWorkplaceMappingTable();

        $statement = $this->pdo->query(
            'select id, hostname, press_id, workplace_label, press_operator, is_active, created_at, updated_at
             from ' . $this->qualifiedWorkplaceTable() . '
             order by hostname, press_id, id'
        );

        return array_map(fn (array $row): array => $this->mapWorkplaceMapping($row), $statement->fetchAll());
    }

    public function saveWorkplaceMapping(array $payload): array
    {
        $this->assertWorkplaceMappingTable();

        $id = (int) ($payload['id'] ?? 0);
        $hostname = $this->normalizeHostname((string) ($payload['hostname'] ?? ''));
        $pressId = trim((string) ($payload['pressId'] ?? ''));
        $workplaceLabel = trim((string) ($payload['workplaceLabel'] ?? ''));
        $pressOperator = trim((string) ($payload['pressOperator'] ?? ''));
        $isActive = ! empty($payload['isActive']) ? 1 : 0;

        $this->assertPress($pressId);

        if ($id > 0) {
            $statement = $this->pdo->prepare(
                'update ' . $this->qualifiedWorkplaceTable() . '
                 set hostname = :hostname,
                     press_id = :press_id,
                     workplace_label = :workplace_label,
                     press_operator = :press_operator,
                     is_active = :is_active,
                     updated_at = sysdatetime()
                 where id = :id'
            );
            $statement->execute([
                'id' => $id,
                'hostname' => $hostname,
                'press_id' => $pressId,
                'workplace_label' => $workplaceLabel,
                'press_operator' => $pressOperator,
                'is_active' => $isActive,
            ]);

            return $this->adminWorkplaceMappings();
        }

        $statement = $this->pdo->prepare(
            'insert into ' . $this->qualifiedWorkplaceTable() . ' (hostname, press_id, workplace_label, press_operator, is_active)
             values (:hostname, :press_id, :workplace_label, :press_operator, :is_active)'
        );
        $statement->execute([
            'hostname' => $hostname,
            'press_id' => $pressId,
            'workplace_label' => $workplaceLabel,
            'press_operator' => $pressOperator,
            'is_active' => $isActive,
        ]);

        return $this->adminWorkplaceMappings();
    }

    public function deleteWorkplaceMapping(int $id): array
    {
        $this->assertWorkplaceMappingTable();

        if ($id <= 0) {
            throw new InvalidArgumentException('Ungueltiger Zuordnungseintrag.');
        }

        $statement = $this->pdo->prepare(
            'delete from ' . $this->qualifiedWorkplaceTable() . '
             where id = :id'
        );
        $statement->execute(['id' => $id]);

        return $this->adminWorkplaceMappings();
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
        $runs = $this->usesFileState()
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

    public function summary(): array
    {
        $runs = $this->usesFileState()
            ? $this->demoRuns()
            : $this->allDatabaseRuns();

        $summaryByPress = [];
        foreach ($this->presses() as $press) {
            $summaryByPress[$press['id']] = [
                ...$press,
                'finishedCount' => 0,
                'activeCount' => 0,
                'pausedCount' => 0,
                'totalElapsedMs' => 0,
                'lastEndedAt' => '',
            ];
        }

        foreach ($runs as $run) {
            $mapped = $this->mapRun($run);
            $pressId = $mapped['pressId'];

            if (! isset($summaryByPress[$pressId])) {
                continue;
            }

            if ($mapped['status'] === 'active') {
                $summaryByPress[$pressId]['activeCount']++;
                continue;
            }

            if ($mapped['status'] === 'paused') {
                $summaryByPress[$pressId]['pausedCount']++;
                continue;
            }

            $summaryByPress[$pressId]['finishedCount']++;
            $summaryByPress[$pressId]['totalElapsedMs'] += (int) $mapped['elapsedMs'];

            if ($mapped['endedAt'] !== '' && strcmp($mapped['endedAt'], (string) $summaryByPress[$pressId]['lastEndedAt']) > 0) {
                $summaryByPress[$pressId]['lastEndedAt'] = $mapped['endedAt'];
            }
        }

        $overall = [
            'finishedCount' => 0,
            'activeCount' => 0,
            'pausedCount' => 0,
            'totalElapsedMs' => 0,
        ];

        foreach ($summaryByPress as $pressSummary) {
            $overall['finishedCount'] += (int) $pressSummary['finishedCount'];
            $overall['activeCount'] += (int) $pressSummary['activeCount'];
            $overall['pausedCount'] += (int) $pressSummary['pausedCount'];
            $overall['totalElapsedMs'] += (int) $pressSummary['totalElapsedMs'];
        }

        return [
            'serverTime' => $this->nowIso(),
            'overall' => $overall,
            'presses' => array_values($summaryByPress),
        ];
    }

    public function history(array $filters = []): array
    {
        $runs = $this->usesFileState()
            ? $this->demoRuns()
            : $this->allDatabaseRuns();

        $pressFilter = trim((string) ($filters['press'] ?? ''));
        $shiftFilter = trim((string) ($filters['shift'] ?? ''));
        $startFrom = $this->dateValue($filters['startFrom'] ?? '');
        $startTo = $this->dateValue($filters['startTo'] ?? '');

        $summaryByPress = [];
        foreach ($this->presses() as $press) {
            $summaryByPress[$press['id']] = [
                ...$press,
                'finishedCount' => 0,
                'totalElapsedMs' => 0,
                'lastEndedAt' => '',
            ];
        }

        $history = [];
        foreach ($runs as $run) {
            $mapped = $this->mapRun($run);

            if ($mapped['status'] !== 'finished') {
                continue;
            }

            if ($pressFilter !== '' && $mapped['pressId'] !== $pressFilter) {
                continue;
            }

            if ($shiftFilter !== '' && $mapped['shiftName'] !== $shiftFilter) {
                continue;
            }

            if ($startFrom !== '' || $startTo !== '') {
                $startedTimestamp = strtotime($mapped['startedAt']);

                if ($mapped['startedAt'] === '' || $startedTimestamp === false) {
                    continue;
                }

                $fromTimestamp = $startFrom !== '' ? strtotime($startFrom) : false;
                if ($fromTimestamp !== false && $startedTimestamp < $fromTimestamp) {
                    continue;
                }

                $toTimestamp = $startTo !== '' ? strtotime($startTo) : false;
                if ($toTimestamp !== false && $startedTimestamp > $toTimestamp) {
                    continue;
                }
            }

            if (! isset($summaryByPress[$mapped['pressId']])) {
                continue;
            }

            $summaryByPress[$mapped['pressId']]['finishedCount']++;
            $summaryByPress[$mapped['pressId']]['totalElapsedMs'] += (int) $mapped['elapsedMs'];

            if ($mapped['endedAt'] !== '' && strcmp($mapped['endedAt'], (string) $summaryByPress[$mapped['pressId']]['lastEndedAt']) > 0) {
                $summaryByPress[$mapped['pressId']]['lastEndedAt'] = $mapped['endedAt'];
            }

            $history[] = $mapped;
        }

        usort($history, static fn (array $left, array $right): int => strcmp((string) $right['startedAt'], (string) $left['startedAt']));

        $overall = [
            'finishedCount' => 0,
            'totalElapsedMs' => 0,
        ];

        foreach ($summaryByPress as $pressSummary) {
            $overall['finishedCount'] += (int) $pressSummary['finishedCount'];
            $overall['totalElapsedMs'] += (int) $pressSummary['totalElapsedMs'];
        }

        return [
            'serverTime' => $this->nowIso(),
            'overall' => $overall,
            'presses' => array_values($summaryByPress),
            'historyCount' => count($history),
            'history' => array_slice($history, 0, 250),
        ];
    }

    public function start(string $pressId, array $order, string $operator): array
    {
        $this->assertPress($pressId);
        $this->assertWorkplaceAccess($pressId);
        $operator = $this->normalizeOperator($operator, $pressId);
        $shiftName = $this->currentShiftName();
        $orderId = trim((string) ($order['id'] ?? ''));

        if ($orderId === '') {
            throw new InvalidArgumentException('Bitte einen Fertigungsauftrag waehlen.');
        }

        if ($this->activeRun($pressId)) {
            throw new RuntimeException('Auf dieser Presse laeuft bereits ein Auftrag.');
        }

        if ($this->usesFileState()) {
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
                'started_by' => $operator,
                'shift_name' => $shiftName,
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
                planned_start, planned_end, status, started_by, shift_name, started_at
             ) values (
                :press_id, :order_id, :order_label, :material, :description, :quantity, :unit,
                :planned_start, :planned_end, :status, :started_by, :shift_name, sysdatetime()
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
            'started_by' => $operator,
            'shift_name' => $shiftName,
        ]);

        return $this->snapshot();
    }

    public function pause(string $pressId, string $operator): array
    {
        return $this->changeState($pressId, $operator, 'pause');
    }

    public function resume(string $pressId, string $operator): array
    {
        return $this->changeState($pressId, $operator, 'resume');
    }

    public function finish(string $pressId, string $operator): array
    {
        return $this->changeState($pressId, $operator, 'finish');
    }

    private function changeState(string $pressId, string $operator, string $action): array
    {
        $this->assertPress($pressId);
        $this->assertWorkplaceAccess($pressId);
        $operator = $this->normalizeOperator($operator, $pressId);

        if ($this->usesFileState()) {
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
                    $run['ended_by'] = $operator;
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
            $statement->execute(['id' => $run['id'], 'ended_by' => $operator]);
        }

        return $this->snapshot();
    }

    private function activeRun(string $pressId): ?array
    {
        if ($this->usesFileState()) {
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

    private function allDatabaseRuns(): array
    {
        $statement = $this->pdo->query(
            "select *
             from dbo.press_job_runs
             order by started_at desc"
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
        $workCenterColumn = $this->firstExistingColumn($columns, getenv('PRESS_WORK_CENTER_COLUMN') ?: '', self::WORK_CENTER_COLUMNS);
        if ($workCenterColumn === null) {
            throw new RuntimeException('In LOIPRO wurde keine Arbeitsplatz-Spalte ARBPLZ gefunden. Bitte PRESS_WORK_CENTER_COLUMN setzen.');
        }

        $select = [
            $this->quoteIdentifier($orderColumn) . ' as order_id',
            $materialColumn ? $this->quoteIdentifier($materialColumn) . ' as material' : "cast('' as nvarchar(1)) as material",
            $descriptionColumn ? $this->quoteIdentifier($descriptionColumn) . ' as description' : "cast('' as nvarchar(1)) as description",
            $quantityColumn ? 'try_convert(float, ' . $this->quoteIdentifier($quantityColumn) . ') as quantity' : 'cast(null as float) as quantity',
            $unitColumn ? $this->quoteIdentifier($unitColumn) . ' as unit' : "cast('' as nvarchar(1)) as unit",
            $startColumn ? $this->quoteIdentifier($startColumn) . ' as planned_start' : "cast('' as nvarchar(1)) as planned_start",
            $endColumn ? $this->quoteIdentifier($endColumn) . ' as planned_end' : "cast('' as nvarchar(1)) as planned_end",
        ];
        $params = [
            'work_center' => strtoupper(trim((string) (getenv('PRESS_WORK_CENTER_VALUE') ?: 'EINSTE'))),
        ];
        $where = $this->quoteIdentifier($orderColumn) . ' is not null
             and upper(cast(' . $this->quoteIdentifier($workCenterColumn) . ' as nvarchar(120))) = :work_center';

        if ($query !== '') {
            $where .= ' and (
                cast(' . $this->quoteIdentifier($orderColumn) . ' as nvarchar(120)) like :query_order
                or cast(' . $this->quoteIdentifier($materialColumn) . ' as nvarchar(120)) like :query_material
            )';
            $params['query_order'] = '%' . $query . '%';
            $params['query_material'] = '%' . $query . '%';
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
        $shiftName = (string) ($row['shift_name'] ?? '');

        if (($row['status'] ?? '') === 'paused' && $pauseStartedAt !== '') {
            $pausedMs += $this->millisBetween($pauseStartedAt, $this->nowIso());
        }

        if ($shiftName === '' && $startedAt !== '') {
            $shiftName = $this->shiftNameForDate($startedAt);
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
            'shiftName' => $shiftName,
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

    private function assertWorkplaceMappingTable(): void
    {
        if (! $this->canUseWorkplaceMappings()) {
            throw new RuntimeException('Die Arbeitsplatz-Zuordnungstabelle ist nicht verfuegbar.');
        }

        $this->ensureWorkplaceMappingSchema();
    }

    private function normalizeHostname(string $hostname): string
    {
        $hostname = strtoupper(trim($hostname));
        $hostname = preg_replace('/[^A-Z0-9._-]/', '', $hostname) ?: '';

        if ($hostname === '') {
            throw new InvalidArgumentException('Bitte einen Hostnamen angeben.');
        }

        return $hostname;
    }

    private function mapWorkplaceMapping(array $row): array
    {
        $pressId = trim((string) ($row['press_id'] ?? ''));

        return [
            'id' => (int) ($row['id'] ?? 0),
            'hostname' => trim((string) ($row['hostname'] ?? '')),
            'pressId' => $pressId,
            'pressLabel' => self::PRESSES[$pressId] ?? $pressId,
            'workplaceLabel' => trim((string) ($row['workplace_label'] ?? '')),
            'pressOperator' => trim((string) ($row['press_operator'] ?? '')),
            'isActive' => (bool) ($row['is_active'] ?? false),
            'createdAt' => $this->dateValue($row['created_at'] ?? ''),
            'updatedAt' => $this->dateValue($row['updated_at'] ?? ''),
        ];
    }

    private function workplaceContext(): array
    {
        $assignments = $this->workplaceAssignmentsForClient();
        $mappingAvailable = $this->canUseWorkplaceMappings();

        return [
            'hostname' => $this->clientHostname,
            'mappingAvailable' => $mappingAvailable,
            'restricted' => $mappingAvailable,
            'allowedPressIds' => array_values(array_unique(array_column($assignments, 'pressId'))),
            'assignments' => $assignments,
        ];
    }

    private function assertWorkplaceAccess(string $pressId): void
    {
        if (! $this->canUseWorkplaceMappings()) {
            return;
        }

        $allowedPressIds = array_column($this->workplaceAssignmentsForClient(), 'pressId');

        if ($allowedPressIds === []) {
            throw new RuntimeException('Dieser Arbeitsplatz ist keiner Presse zugeordnet.');
        }

        if (! in_array($pressId, $allowedPressIds, true)) {
            throw new RuntimeException('Dieser Arbeitsplatz ist nicht fuer diese Presse freigegeben.');
        }
    }

    private function workplaceAssignmentsForClient(): array
    {
        if (! $this->canUseWorkplaceMappings() || $this->clientHostname === '') {
            return [];
        }

        $this->ensureWorkplaceMappingSchema();
        $shortHostname = explode('.', $this->clientHostname)[0] ?? $this->clientHostname;
        $statement = $this->pdo->prepare(
            'select press_id, workplace_label, press_operator
             from ' . $this->qualifiedWorkplaceTable() . '
             where is_active = 1
               and (
                    upper(hostname) in (:hostname, :short_hostname)
                    or upper(workplace_label) in (:workplace, :short_workplace)
               )
             order by press_id'
        );
        $statement->execute([
            'hostname' => strtoupper($this->clientHostname),
            'short_hostname' => strtoupper($shortHostname),
            'workplace' => strtoupper($this->clientHostname),
            'short_workplace' => strtoupper($shortHostname),
        ]);

        return array_values(array_filter(array_map(function (array $row): array {
            $pressId = trim((string) ($row['press_id'] ?? ''));

            if (! array_key_exists($pressId, self::PRESSES)) {
                return [];
            }

            return [
                'pressId' => $pressId,
                'pressLabel' => self::PRESSES[$pressId],
                'workplaceLabel' => trim((string) ($row['workplace_label'] ?? '')),
                'pressOperator' => trim((string) ($row['press_operator'] ?? '')),
            ];
        }, $statement->fetchAll())));
    }

    private function usesFileState(): bool
    {
        return $this->demoMode || ! $this->pdo || ! $this->canUseDatabaseState();
    }

    private function canUseDatabaseState(): bool
    {
        if ($this->demoMode || ! $this->pdo) {
            return false;
        }

        if ($this->databaseStateAvailable !== null) {
            return $this->databaseStateAvailable;
        }

        try {
            $this->ensureSchema();
            $this->databaseStateAvailable = true;
        } catch (Throwable) {
            $this->databaseStateAvailable = false;
        }

        return $this->databaseStateAvailable;
    }

    private function canUseWorkplaceMappings(): bool
    {
        if ($this->demoMode || ! $this->pdo) {
            return false;
        }

        if ($this->workplaceMappingsAvailable !== null) {
            return $this->workplaceMappingsAvailable;
        }

        try {
            $this->workplaceMappingsAvailable = $this->workplaceMappingTableExists();
        } catch (Throwable) {
            $this->workplaceMappingsAvailable = false;
        }

        return $this->workplaceMappingsAvailable;
    }

    private function workplaceMappingTableExists(): bool
    {
        $statement = $this->pdo->query(
            "select case
                when object_id(N'" . str_replace("'", "''", $this->workplaceObjectName()) . "', N'U') is null then 0
                else 1
             end as table_exists"
        );
        $row = $statement->fetch();

        return (int) ($row['table_exists'] ?? 0) === 1;
    }

    private function assertPress(string $pressId): void
    {
        if (! array_key_exists($pressId, self::PRESSES)) {
            throw new InvalidArgumentException('Unbekannte Presse.');
        }
    }

    private function normalizeOperator(string $operator, string $pressId): string
    {
        $operator = trim($operator);

        if ($operator !== '') {
            return $operator;
        }

        foreach ($this->workplaceAssignmentsForClient() as $assignment) {
            if (($assignment['pressId'] ?? '') === $pressId && trim((string) ($assignment['workplaceLabel'] ?? '')) !== '') {
                return trim((string) $assignment['workplaceLabel']);
            }
        }

        return $this->clientHostname !== '' ? $this->clientHostname : 'Arbeitsplatz';
    }

    private function currentShiftName(): string
    {
        return $this->shiftNameForDate('now');
    }

    private function shiftNameForDate(string $value): string
    {
        $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        $hour = (int) $date->setTimezone($this->databaseTimezone())->format('G');

        if ($hour >= 6 && $hour < 14) {
            return 'Fruehschicht';
        }

        if ($hour >= 14 && $hour < 22) {
            return 'Spaetschicht';
        }

        return 'Nachtschicht';
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
                shift_name nvarchar(40) null,
                ended_by nvarchar(120) null,
                started_at datetime2 not null default sysdatetime(),
                ended_at datetime2 null,
                pause_started_at datetime2 null,
                paused_ms bigint not null default 0
             )"
        );

        $this->pdo->exec(
            "if col_length('dbo.press_job_runs', 'shift_name') is null
             alter table dbo.press_job_runs add shift_name nvarchar(40) null"
        );
    }

    private function ensureWorkplaceMappingSchema(): void
    {
        $objectName = str_replace("'", "''", $this->workplaceObjectName());
        $this->pdo->exec(
            "if col_length('{$objectName}', 'press_operator') is null
             alter table " . $this->qualifiedWorkplaceTable() . ' add press_operator nvarchar(120) null'
        );
    }

    private function qualifiedLoiproTable(): string
    {
        [$database, $schema, $table] = $this->loiproTableParts();

        return $this->quoteIdentifier($database) . '.' . $this->quoteIdentifier($schema) . '.' . $this->quoteIdentifier($table);
    }

    private function qualifiedWorkplaceTable(): string
    {
        [$database, $schema, $table] = $this->workplaceTableParts();
        $parts = [$this->quoteIdentifier($schema), $this->quoteIdentifier($table)];

        if ($database !== '') {
            array_unshift($parts, $this->quoteIdentifier($database));
        }

        return implode('.', $parts);
    }

    private function workplaceObjectName(): string
    {
        [$database, $schema, $table] = $this->workplaceTableParts();

        return implode('.', array_filter([$database, $schema, $table], static fn (string $part): bool => $part !== ''));
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

    private function workplaceTableParts(): array
    {
        $parts = array_values(array_filter(explode('.', $this->workplaceTable), static fn (string $part): bool => trim($part) !== ''));

        if (count($parts) === 1) {
            return ['', 'dbo', $parts[0]];
        }

        if (count($parts) === 2) {
            return ['', $parts[0], $parts[1]];
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
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM);
    }

    private function dateValue(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            $localValue = $value->format('Y-m-d H:i:s.u');

            return (new DateTimeImmutable($localValue, $this->databaseTimezone()))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format(DATE_ATOM);
        }

        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        try {
            $timezone = preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/', $value)
                ? null
                : $this->databaseTimezone();
            $date = new DateTimeImmutable($value, $timezone);

            return $date->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
        } catch (Throwable) {
            return $value;
        }
    }

    private function databaseTimezone(): DateTimeZone
    {
        return new DateTimeZone(getenv('PRESS_DB_TIMEZONE') ?: 'Europe/Berlin');
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
