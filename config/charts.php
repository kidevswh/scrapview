<?php

return [
    'scrap_by_reason' => [
        'title' => 'Ausschuss nach Grund',
        'type' => 'bar',
        'sql' => <<<'SQL'
            select
                reason as label,
                sum(quantity) as value
            from scrap_records
            group by reason
            order by value desc
            limit 10
        SQL,
    ],
    'scrap_trend' => [
        'title' => 'Ausschuss Verlauf',
        'type' => 'line',
        'sql' => <<<'SQL'
            select
                cast(created_at as date) as label,
                sum(quantity) as value
            from scrap_records
            group by cast(created_at as date)
            order by label asc
            limit 30
        SQL,
    ],
];
