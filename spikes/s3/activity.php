<?php

/** Shows what the spike database is currently running (query vectors elided). */
require __DIR__.'/db.php';

$sql = <<<'SQL'
    SELECT pid, state, now() - query_start AS running, wait_event_type, wait_event,
           left(regexp_replace(query, '\[[^]]*\]', '[vec]', 'g'), 300) AS query
      FROM pg_stat_activity
     WHERE datname = 'career_engine_spike' AND pid <> pg_backend_pid()
    SQL;
foreach (spikeDb()->query($sql) as $row) {
    echo json_encode($row, JSON_UNESCAPED_SLASHES), PHP_EOL;
}
