<?php

final class WeightSeries
{
    private const DOTNET_EPOCH_TICKS = 621355968000000000;
    private const MIN_VALUE = 50;
    private const MAX_VALUE = 15000;
    private const DROP_MIN_ABSOLUTE = 500;
    private const DROP_MIN_RATIO = 0.25;
    private const DROP_RESET_RATIO = 0.25;
    private const DROP_RESET_RISE = 500;

    private const NODES = [
        74366 => 'Waage1',
        74371 => 'Waage2',
        74376 => 'Waage3',
    ];

    private ?string $timestampMode = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $table
    ) {
    }

    public function meta(): array
    {
        $statement = $this->pdo->query(sprintf(
            'select min(CreationTimestampTicks) as min_ticks, max(CreationTimestampTicks) as max_ticks, count(*) as rows from %s where NodeID in (%s)',
            $this->quotedTable(),
            $this->nodeList()
        ));
        $row = $statement->fetch();

        if (! $row || $row['min_ticks'] === null || $row['max_ticks'] === null) {
            throw new RuntimeException('Keine Gewichtsdaten fuer die konfigurierten NodeIDs gefunden.');
        }

        $minMs = $this->ticksToMillis((int) $row['min_ticks']);
        $maxMs = $this->ticksToMillis((int) $row['max_ticks']);

        return [
            'min' => $minMs,
            'max' => $maxMs,
            'minLabel' => $this->formatMillis($minMs),
            'maxLabel' => $this->formatMillis($maxMs),
            'rows' => (int) $row['rows'],
            'nodes' => array_map(
                static fn (int $nodeId, string $label): array => ['id' => $nodeId, 'label' => $label],
                array_keys(self::NODES),
                array_values(self::NODES)
            ),
        ];
    }

    public function rows(int $startMs, int $endMs, int $limit = 6000): array
    {
        if ($startMs > $endMs) {
            [$startMs, $endMs] = [$endMs, $startMs];
        }

        $startTicks = $this->millisToTicks($startMs);
        $endTicks = $this->millisToTicks($endMs);
        $limit = max(100, min($limit, 20000));
        $stride = $this->stride($startTicks, $endTicks, $limit);

        $statement = $this->pdo->prepare(sprintf(
            <<<'SQL'
                with ordered_rows as (
                    select
                        NodeID,
                        try_convert(float, Value) as Value,
                        CreationTimestampTicks,
                        row_number() over (order by CreationTimestampTicks asc) as row_number
                    from %s
                    where NodeID in (%s)
                        and CreationTimestampTicks between :start_ticks and :end_ticks
                        and try_convert(float, Value) >= :min_value
                        and try_convert(float, Value) <= :max_value
                )
                select NodeID, Value, CreationTimestampTicks
                from ordered_rows
                where ((row_number - 1) %% :stride) = 0
                order by CreationTimestampTicks asc
            SQL,
            $this->quotedTable(),
            $this->nodeList()
        ));
        $statement->execute([
            'start_ticks' => $startTicks,
            'end_ticks' => $endTicks,
            'stride' => $stride,
            'min_value' => self::MIN_VALUE,
            'max_value' => self::MAX_VALUE,
        ]);

        return array_map(function (array $row): array {
            $nodeId = (int) $row['NodeID'];
            $timestamp = $this->ticksToMillis((int) $row['CreationTimestampTicks']);

            return [
                'nodeId' => $nodeId,
                'label' => self::NODES[$nodeId] ?? (string) $nodeId,
                'timestamp' => $timestamp,
                'timeLabel' => $this->formatMillis($timestamp),
                'value' => (float) $row['Value'],
            ];
        }, $statement->fetchAll());
    }

    public function drops(int $startMs, int $endMs): array
    {
        if ($startMs > $endMs) {
            [$startMs, $endMs] = [$endMs, $startMs];
        }

        $startTicks = $this->millisToTicks($startMs);
        $endTicks = $this->millisToTicks($endMs);

        $statement = $this->pdo->prepare(sprintf(
            <<<'SQL'
                select
                    NodeID,
                    try_convert(float, Value) as Value,
                    CreationTimestampTicks
                from %s
                where NodeID in (%s)
                    and CreationTimestampTicks between :start_ticks and :end_ticks
                    and try_convert(float, Value) >= :min_value
                    and try_convert(float, Value) <= :max_value
                order by NodeID asc, CreationTimestampTicks asc
            SQL,
            $this->quotedTable(),
            $this->nodeList()
        ));
        $statement->execute([
            'start_ticks' => $startTicks,
            'end_ticks' => $endTicks,
            'min_value' => self::MIN_VALUE,
            'max_value' => self::MAX_VALUE,
        ]);

        $drops = [];
        $stateByNode = [];

        foreach ($statement->fetchAll() as $row) {
            $nodeId = (int) $row['NodeID'];
            $value = (float) $row['Value'];
            $ticks = (int) $row['CreationTimestampTicks'];

            $state = $stateByNode[$nodeId] ?? [
                'peakValue' => null,
                'peakTicks' => null,
                'locked' => false,
                'eventPeak' => null,
                'valley' => null,
            ];

            if ($state['peakValue'] === null || $value > $state['peakValue']) {
                $state['peakValue'] = $value;
                $state['peakTicks'] = $ticks;
            }

            if ($state['locked']) {
                $state['valley'] = min($state['valley'], $value);

                $hasReachedLowPoint = $state['valley'] <= max(self::MIN_VALUE, $state['eventPeak'] * self::DROP_RESET_RATIO);
                $isBuildingAgain = $value >= $state['valley'] + self::DROP_RESET_RISE;
                $hasExceededEventPeak = $value > $state['eventPeak'];

                if (($hasReachedLowPoint && $isBuildingAgain) || $hasExceededEventPeak) {
                    $state['locked'] = false;
                    $state['peakValue'] = $value;
                    $state['peakTicks'] = $ticks;
                    $state['eventPeak'] = null;
                    $state['valley'] = null;
                }

                $stateByNode[$nodeId] = $state;
                continue;
            }

            $dropAmount = $state['peakValue'] - $value;
            $isStrongDrop = $dropAmount >= self::DROP_MIN_ABSOLUTE
                && $value <= $state['peakValue'] * (1 - self::DROP_MIN_RATIO);

            if ($isStrongDrop) {
                $timestamp = $this->ticksToMillis((int) $state['peakTicks']);

                $drops[] = [
                    'nodeId' => $nodeId,
                    'label' => self::NODES[$nodeId] ?? (string) $nodeId,
                    'reachedWeight' => (float) $state['peakValue'],
                    'afterWeight' => $value,
                    'dropAmount' => $dropAmount,
                    'timestamp' => $timestamp,
                    'timeLabel' => $this->formatMillis($timestamp),
                    'fallTimestamp' => $this->ticksToMillis($ticks),
                    'fallTimeLabel' => $this->formatMillis($this->ticksToMillis($ticks)),
                ];

                $state['locked'] = true;
                $state['eventPeak'] = $state['peakValue'];
                $state['valley'] = $value;
            }

            $stateByNode[$nodeId] = $state;
        }

        usort($drops, static fn (array $left, array $right): int => $right['timestamp'] <=> $left['timestamp']);

        return $drops;
    }

    private function stride(int $startTicks, int $endTicks, int $limit): int
    {
        $statement = $this->pdo->prepare(sprintf(
            'select count(*) as rows from %s where NodeID in (%s) and CreationTimestampTicks between :start_ticks and :end_ticks and try_convert(float, Value) >= :min_value and try_convert(float, Value) <= :max_value',
            $this->quotedTable(),
            $this->nodeList()
        ));
        $statement->execute([
            'start_ticks' => $startTicks,
            'end_ticks' => $endTicks,
            'min_value' => self::MIN_VALUE,
            'max_value' => self::MAX_VALUE,
        ]);

        $rows = (int) ($statement->fetch()['rows'] ?? 0);

        return max(1, (int) ceil($rows / $limit));
    }

    private function ticksToMillis(int $ticks): int
    {
        return match ($this->timestampMode()) {
            'dotnet' => (int) floor(($ticks - self::DOTNET_EPOCH_TICKS) / 10000),
            'unix_seconds' => $ticks * 1000,
            default => $ticks,
        };
    }

    private function millisToTicks(int $millis): int
    {
        return match ($this->timestampMode()) {
            'dotnet' => ($millis * 10000) + self::DOTNET_EPOCH_TICKS,
            'unix_seconds' => (int) floor($millis / 1000),
            default => $millis,
        };
    }

    private function timestampMode(): string
    {
        if ($this->timestampMode !== null) {
            return $this->timestampMode;
        }

        $statement = $this->pdo->query(sprintf(
            'select max(CreationTimestampTicks) as max_ticks from %s where NodeID in (%s)',
            $this->quotedTable(),
            $this->nodeList()
        ));
        $maxTicks = (int) ($statement->fetch()['max_ticks'] ?? 0);

        if ($maxTicks > self::DOTNET_EPOCH_TICKS) {
            return $this->timestampMode = 'dotnet';
        }

        if ($maxTicks < 100000000000) {
            return $this->timestampMode = 'unix_seconds';
        }

        return $this->timestampMode = 'unix_millis';
    }

    private function formatMillis(int $millis): string
    {
        return gmdate('Y-m-d H:i:s', (int) floor($millis / 1000));
    }

    private function nodeList(): string
    {
        return implode(',', array_keys(self::NODES));
    }

    private function quotedTable(): string
    {
        $parts = array_values(array_filter(explode('.', $this->table), static fn (string $part): bool => trim($part) !== ''));

        if (count($parts) === 1) {
            $parts = ['dbo', $parts[0]];
        }

        $schema = $parts[count($parts) - 2];
        $table = $parts[count($parts) - 1];

        return $this->quoteIdentifier($schema) . '.' . $this->quoteIdentifier($table);
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '[' . str_replace(']', ']]', $identifier) . ']';
    }
}
