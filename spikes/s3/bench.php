<?php

/**
 * S3 spike: hybrid retrieval SQL performance on synthetic data.
 *
 * Usage: php spikes/s3/bench.php <units> [--skip-seed]
 *   e.g. php spikes/s3/bench.php 20000
 *        php spikes/s3/bench.php 200000
 *
 * Mirrors the planned schema (docs/03-database-design.md): retrieval_units + embeddings with an
 * untyped `vector` column and a per-model partial expression HNSW index.
 * Synthetic data: 200 topic clusters; embeddings = cluster centroid + noise; text mixes cluster
 * topic words with general vocabulary, so semantic and lexical signals are correlated.
 * Throwaway code — not part of the application.
 */

require __DIR__.'/db.php';

const DIMS = 1024;
const CLUSTERS = 200;
const QUERIES = 50;
const TIMED_RUNS = 3;
const SEMANTIC_K = 40;
const LEXICAL_K = 40;
const FUSED_K = 30;
const RRF_K = 60;

$units = (int) ($argv[1] ?? 20000);
$skipSeed = in_array('--skip-seed', $argv, true);
$pdo = spikeDb();
// Laravel prepares a fresh statement per query, so Postgres always plans with the real parameters.
// This script reuses prepared statements; without this, Postgres switches to a generic plan after
// 5 executions and the timings no longer reflect the application.
$pdo->exec('SET plan_cache_mode = force_custom_plan');
$results =['units' => $units, 'dims' => DIMS, 'clusters' => CLUSTERS, 'queries' => QUERIES, 'timed_runs' => TIMED_RUNS, 'started_at' => date('c')];

/** @return float milliseconds */
function timed(callable $fn): float
{
    $start = hrtime(true);
    $fn();

    return (hrtime(true) - $start) / 1e6;
}

function percentile(array $values, float $p): float
{
    sort($values);
    $index = (int) ceil($p / 100 * count($values)) - 1;

    return round($values[max(0, $index)], 2);
}

function say(string $message): void
{
    echo '['.date('H:i:s').'] '.$message.PHP_EOL;
}

/** @return list<string> */
function vocabulary(): array
{
    $words = explode(' ', 'laravel php react typescript javascript postgres mysql redis docker kubernetes aws azure gcp terraform api rest graphql integration migration performance optimisation caching queue worker scaling testing pest phpunit ci cd deployment monitoring sentry security authentication authorisation oauth payments stripe billing invoicing reporting dashboard analytics etl pipeline data warehouse search elasticsearch vector embeddings ai llm prompt automation workflow crm erp ecommerce shopify magento wordpress cms agency client stakeholder requirements roadmap planning estimation mentoring leadership team hiring onboarding code review architecture refactoring legacy monolith microservices event sourcing domain design patterns accessibility frontend backend fullstack mobile responsive tailwind css html vue livewire inertia node express python django flask java spring golang rust compliance gdpr audit fintech healthcare logistics retail saas b2b b2c startup enterprise reduced increased improved delivered launched built designed led managed owned migrated automated scaled shipped hours weeks months percent revenue cost latency uptime incidents tickets customers users orders transactions');
    for ($i = 0; count($words) < 2000; $i++) {
        $words[] = 'term'.$i;
    }

    return $words;
}

$results['server'] = [];
foreach (['server_version', 'shared_buffers', 'work_mem', 'maintenance_work_mem', 'max_parallel_maintenance_workers', 'max_parallel_workers_per_gather', 'effective_cache_size', 'random_page_cost'] as $setting) {
    $results['server'][$setting] = $pdo->query("SHOW {$setting}")->fetchColumn();
}
$results['server']['vector'] = $pdo->query("SELECT extversion FROM pg_extension WHERE extname = 'vector'")->fetchColumn();

// ---------------------------------------------------------------- seed
if (! $skipSeed) {
    say("Seeding {$units} units…");
    $pdo->exec('DROP TABLE IF EXISTS embeddings, retrieval_units, spike_centroids, spike_queries, spike_vocab');
    $pdo->exec(<<<'SQL'
        CREATE TABLE retrieval_units (
            id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            workspace_id int NOT NULL,
            candidate_profile_id int,
            unit_type text NOT NULL,
            status text NOT NULL,
            is_active boolean NOT NULL DEFAULT true,
            search_text text NOT NULL,
            tsv tsvector GENERATED ALWAYS AS (to_tsvector('english', search_text)) STORED,
            tag_ids int[] NOT NULL DEFAULT '{}',
            cluster_id int NOT NULL
        )
        SQL);
    $pdo->exec(<<<'SQL'
        CREATE TABLE embeddings (
            id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            workspace_id int NOT NULL,
            retrieval_unit_id bigint NOT NULL REFERENCES retrieval_units(id),
            embedding_model_id smallint NOT NULL,
            embedding vector NOT NULL,
            UNIQUE (retrieval_unit_id, embedding_model_id)
        )
        SQL);
    $pdo->exec('CREATE TABLE spike_vocab (words text[] NOT NULL)');
    $pdo->prepare('INSERT INTO spike_vocab VALUES (?)')->execute(['{'.implode(',', vocabulary()).'}']);
    $pdo->exec('CREATE TABLE spike_centroids (id int PRIMARY KEY, v float4[] NOT NULL)');
    $pdo->exec('INSERT INTO spike_centroids SELECT c, (SELECT array_agg((random() - 0.5)::float4) FROM generate_series(1, '.DIMS.') d WHERE c IS NOT NULL) FROM generate_series(1, '.CLUSTERS.') c');

    // Workspace 1 holds ~50% of units (the "big" tenant); workspaces 2–10 share the rest.
    // Two profiles per workspace (80/20). Status/type mix approximates a mature knowledge base.
    $clusters = CLUSTERS;
    $results['seed_units_ms'] = round(timed(fn () => $pdo->exec(<<<SQL
        INSERT INTO retrieval_units (workspace_id, candidate_profile_id, unit_type, status, is_active, search_text, cluster_id)
        SELECT ws,
               ws * 10 + CASE WHEN random() < 0.8 THEN 1 ELSE 2 END,
               (ARRAY['claim','claim','claim','claim','claim','claim','library_answer','project_summary','project_summary','employment_summary'])[1 + floor(random() * 10)::int],
               (ARRAY['verified','verified','verified','verified','verified','verified','proposed','proposed','approved','archived'])[1 + floor(random() * 10)::int],
               random() < 0.95,
               (SELECT string_agg(v.words[1 + 0 * w + CASE WHEN random() < 0.6 THEN (cl * 7 + floor(random() * 30)::int) % 2000 ELSE floor(random() * 2000)::int END], ' ')
                  FROM generate_series(1, 15 + floor(random() * 25)::int) w WHERE g IS NOT NULL),
               cl
          FROM (SELECT g,
                       CASE WHEN random() < 0.5 THEN 1 ELSE 2 + floor(random() * 9)::int END AS ws,
                       1 + floor(random() * {$clusters})::int AS cl
                  FROM generate_series(1, {$units}) g) s, spike_vocab v
        SQL)), 0);
    say('  units inserted in '.$results['seed_units_ms'].' ms');

    // Embeddings: centroid + uniform noise of equal amplitude (intra-cluster cosine ≈ 0.5), in batches.
    $dims = DIMS;
    $batch = 20000;
    $results['seed_embeddings_ms'] = 0;
    for ($from = 1; $from <= $units; $from += $batch) {
        $to = $from + $batch - 1;
        $results['seed_embeddings_ms'] += timed(fn () => $pdo->exec(<<<SQL
            INSERT INTO embeddings (workspace_id, retrieval_unit_id, embedding_model_id, embedding)
            SELECT u.workspace_id, u.id, 1,
                   (SELECT array_agg(c.v[d] + (random() - 0.5)::float4 ORDER BY d) FROM generate_series(1, {$dims}) d)::vector
              FROM retrieval_units u JOIN spike_centroids c ON c.id = u.cluster_id
             WHERE u.id BETWEEN {$from} AND {$to}
            SQL));
        say("  embeddings {$from}–".min($to, $units));
    }
    $results['seed_embeddings_ms'] = round($results['seed_embeddings_ms'], 0);

    // Queries: half target the big workspace, half the small ones; vector near a cluster centroid, text = 4 topic words.
    $pdo->exec('CREATE TABLE spike_queries (id int PRIMARY KEY, workspace_id int, candidate_profile_id int, cluster_id int, qvec vector('.DIMS.'), qtext text)');
    $queries = QUERIES;
    $pdo->exec(<<<SQL
        INSERT INTO spike_queries
        SELECT q, ws, ws * 10 + 1, cl,
               (SELECT array_agg(c.v[d] + ((random() - 0.5) * 0.5)::float4 ORDER BY d) FROM generate_series(1, {$dims}) d)::vector,
               (SELECT string_agg(v.words[1 + 0 * w + (cl * 7 + floor(random() * 30)::int) % 2000], ' or ') FROM generate_series(1, 4) w WHERE q IS NOT NULL)
          FROM (SELECT q, CASE WHEN q % 2 = 0 THEN 1 ELSE 2 + (q % 9) END AS ws, 1 + floor(random() * {$clusters})::int AS cl
                  FROM generate_series(1, {$queries}) q) s
          JOIN spike_centroids c ON c.id = s.cl, spike_vocab v
        SQL);

    say('Creating btree/GIN indexes…');
    $results['btree_gin_build_ms'] = round(timed(function () use ($pdo): void {
        $pdo->exec('CREATE INDEX ru_filter_idx ON retrieval_units (workspace_id, candidate_profile_id, is_active, unit_type, status)');
        $pdo->exec('CREATE INDEX ru_tsv_idx ON retrieval_units USING gin (tsv)');
        $pdo->exec('CREATE INDEX ru_tags_idx ON retrieval_units USING gin (tag_ids)');
        $pdo->exec('CREATE INDEX emb_ws_model_idx ON embeddings (workspace_id, embedding_model_id)');
    }), 0);
    $pdo->exec('VACUUM ANALYZE retrieval_units');
    $pdo->exec('VACUUM ANALYZE embeddings');
}

$pdo->exec('DROP INDEX IF EXISTS emb_hnsw_m1_idx');
$pdo->exec('ANALYZE embeddings');

$results['distribution'] = $pdo->query(<<<'SQL'
    SELECT CASE WHEN workspace_id = 1 THEN 'big (ws 1)' ELSE 'small (ws 2-10, each)' END AS tenant,
           round(avg(n)) AS units_per_workspace,
           round(avg(n_filtered)) AS units_matching_filters_per_query_profile
      FROM (SELECT workspace_id,
                   count(*) AS n,
                   count(*) FILTER (WHERE candidate_profile_id = workspace_id * 10 + 1 AND is_active AND status IN ('verified','proposed','approved')) AS n_filtered
              FROM retrieval_units GROUP BY workspace_id) t
     GROUP BY 1 ORDER BY 1
    SQL)->fetchAll();

$queries = $pdo->query('SELECT id, workspace_id, candidate_profile_id, qvec::text AS qvec, qtext FROM spike_queries ORDER BY id')->fetchAll();

// ---------------------------------------------------------------- SQL under test
$filters = "u.workspace_id = :ws AND u.candidate_profile_id = :profile AND u.is_active
            AND u.status IN ('verified','proposed','approved')
            AND u.unit_type IN ('claim','library_answer','project_summary','employment_summary')";

$semanticSql = <<<SQL
    SELECT u.id
      FROM embeddings e JOIN retrieval_units u ON u.id = e.retrieval_unit_id
     WHERE e.embedding_model_id = 1 AND e.workspace_id = :ws AND {$filters}
     ORDER BY e.embedding::vector(1024) <=> CAST(:q AS vector(1024))
     LIMIT 40
    SQL;

$hybridSql = <<<SQL
    WITH semantic AS (
        SELECT u.id, row_number() OVER () AS rnk
          FROM (SELECT u.id
                  FROM embeddings e JOIN retrieval_units u ON u.id = e.retrieval_unit_id
                 WHERE e.embedding_model_id = 1 AND e.workspace_id = :ws AND {$filters}
                 ORDER BY e.embedding::vector(1024) <=> CAST(:q AS vector(1024))
                 LIMIT 40) u
    ),
    lexical AS (
        SELECT u.id, row_number() OVER (ORDER BY ts_rank_cd(u.tsv, query) DESC) AS rnk
          FROM retrieval_units u, websearch_to_tsquery('english', :qt) query
         WHERE {$filters} AND u.tsv @@ query
         ORDER BY ts_rank_cd(u.tsv, query) DESC
         LIMIT 40
    ),
    fused AS (
        SELECT coalesce(s.id, l.id) AS id,
               coalesce(1.0 / (60 + s.rnk), 0) + coalesce(1.0 / (60 + l.rnk), 0) AS rrf,
               s.rnk AS semantic_rank, l.rnk AS lexical_rank
          FROM semantic s FULL OUTER JOIN lexical l ON l.id = s.id
    )
    SELECT * FROM fused ORDER BY rrf DESC LIMIT 30
    SQL;

/**
 * Runs every query (1 warm-up pass + TIMED_RUNS timed passes) and collects latency, row counts and ids.
 *
 * @return array{p50: float, p95: float, p50_big: float, p95_big: float, p50_small: float, p95_small: float, max: float, avg_rows_big: float, avg_rows_small: float, short_queries: int, ids: array<int, list<int>>}
 */
function bench(PDO $pdo, string $sql, array $queries, array $settings = [], int $expectedRows = SEMANTIC_K): array
{
    $pdo->beginTransaction();
    foreach ($settings as $setting => $value) {
        $pdo->exec("SET LOCAL {$setting} = '{$value}'");
    }
    $statement = $pdo->prepare($sql);
    $usesText = str_contains($sql, ':qt');
    $run = function (array $query) use ($statement, $usesText): array {
        $params = ['ws' => $query['workspace_id'], 'profile' => $query['candidate_profile_id'], 'q' => $query['qvec']];
        if ($usesText) {
            $params['qt'] = $query['qtext'];
        }
        $statement->execute($params);

        return array_map('intval', array_column($statement->fetchAll(), 'id'));
    };

    $ids = [];
    foreach ($queries as $query) {
        $ids[$query['id']] = $run($query);
    }
    $latencies = [];
    $byTenant = ['big' => [], 'small' => []];
    for ($i = 0; $i < TIMED_RUNS; $i++) {
        foreach ($queries as $query) {
            $ms = timed(fn () => $run($query));
            $latencies[] = $ms;
            $byTenant[$query['workspace_id'] === 1 ? 'big' : 'small'][] = $ms;
        }
    }
    $pdo->commit();

    $rows = ['big' => [], 'small' => []];
    $short = 0;
    foreach ($queries as $query) {
        $rows[$query['workspace_id'] === 1 ? 'big' : 'small'][] = count($ids[$query['id']]);
        $short += count($ids[$query['id']]) < $expectedRows ? 1 : 0;
    }

    return [
        'p50' => percentile($latencies, 50),
        'p95' => percentile($latencies, 95),
        'p50_big' => percentile($byTenant['big'], 50),
        'p95_big' => percentile($byTenant['big'], 95),
        'p50_small' => percentile($byTenant['small'], 50),
        'p95_small' => percentile($byTenant['small'], 95),
        'max' => round(max($latencies), 2),
        'avg_rows_big' => round(array_sum($rows['big']) / count($rows['big']), 1),
        'avg_rows_small' => round(array_sum($rows['small']) / count($rows['small']), 1),
        'short_queries' => $short,
        'ids' => $ids,
    ];
}

/** Recall of $candidate against exact ground truth, averaged over queries, split by tenant size. */
function recall(array $exactIds, array $candidateIds, array $queries): array
{
    $byTenant = ['big' => [], 'small' => []];
    foreach ($queries as $query) {
        $truth = $exactIds[$query['id']];
        if ($truth === []) {
            continue;
        }
        $hit = count(array_intersect($truth, $candidateIds[$query['id']]));
        $byTenant[$query['workspace_id'] === 1 ? 'big' : 'small'][] = $hit / count($truth);
    }

    return array_map(fn (array $values): float => round(array_sum($values) / max(1, count($values)), 3), $byTenant);
}

/** First line(s) of the plan showing which access path the planner picked. */
function plan(PDO $pdo, string $sql, array $query, array $settings = []): string
{
    $pdo->beginTransaction();
    foreach ($settings as $setting => $value) {
        $pdo->exec("SET LOCAL {$setting} = '{$value}'");
    }
    $statement = $pdo->prepare('EXPLAIN (ANALYZE, BUFFERS, COSTS OFF) '.$sql);
    $statement->execute(['ws' => $query['workspace_id'], 'profile' => $query['candidate_profile_id'], 'q' => $query['qvec']]);
    $lines = array_column($statement->fetchAll(PDO::FETCH_NUM), 0);
    $pdo->commit();

    return implode("\n", $lines);
}

$bigQuery = current(array_filter($queries, fn ($q) => $q['workspace_id'] === 1));
$smallQuery = current(array_filter($queries, fn ($q) => $q['workspace_id'] !== 1));

// ---------------------------------------------------------------- exact (no ANN index)
say('Exact scan (no HNSW index)…');
$exact = bench($pdo, $semanticSql, $queries);
$results['semantic']['exact'] = array_diff_key($exact, ['ids' => 0]);
$results['plans']['exact_big'] = plan($pdo, $semanticSql, $bigQuery);
$results['plans']['exact_small'] = plan($pdo, $semanticSql, $smallQuery);
$exactHybrid = bench($pdo, $hybridSql, $queries, [], FUSED_K);
$results['hybrid']['exact'] = array_diff_key($exactHybrid, ['ids' => 0]);

// ---------------------------------------------------------------- HNSW
say('Building HNSW index…');
$pdo->exec("SET maintenance_work_mem = '2GB'");
$pdo->exec('SET max_parallel_maintenance_workers = 4');
$results['hnsw_build_ms'] = round(timed(fn () => $pdo->exec(
    'CREATE INDEX emb_hnsw_m1_idx ON embeddings USING hnsw ((embedding::vector(1024)) vector_cosine_ops) WHERE embedding_model_id = 1'
)), 0);
$pdo->exec('ANALYZE embeddings');
say('  built in '.$results['hnsw_build_ms'].' ms');

$modes = [
    'hnsw_default' => [],
    'hnsw_ef100' => ['hnsw.ef_search' => 100],
    'hnsw_iterative_relaxed' => ['hnsw.iterative_scan' => 'relaxed_order'],
    'hnsw_iterative_strict' => ['hnsw.iterative_scan' => 'strict_order'],
    'hnsw_forced_default' => ['enable_seqscan' => 'off', 'enable_bitmapscan' => 'off', 'enable_sort' => 'off'],
    'hnsw_forced_iterative_relaxed' => ['enable_seqscan' => 'off', 'enable_bitmapscan' => 'off', 'enable_sort' => 'off', 'hnsw.iterative_scan' => 'relaxed_order'],
];
foreach ($modes as $mode => $settings) {
    say("Semantic: {$mode}…");
    $run = bench($pdo, $semanticSql, $queries, $settings);
    $results['semantic'][$mode] = array_diff_key($run, ['ids' => 0]) + ['recall_vs_exact' => recall($exact['ids'], $run['ids'], $queries)];
    $results['plans']["{$mode}_big"] = plan($pdo, $semanticSql, $bigQuery, $settings);
    $results['plans']["{$mode}_small"] = plan($pdo, $semanticSql, $smallQuery, $settings);
}
foreach (['hnsw_default' => [], 'hnsw_iterative_relaxed' => ['hnsw.iterative_scan' => 'relaxed_order']] as $mode => $settings) {
    say("Hybrid: {$mode}…");
    $run = bench($pdo, $hybridSql, $queries, $settings, FUSED_K);
    $results['hybrid'][$mode] = array_diff_key($run, ['ids' => 0]) + ['overlap_vs_exact_hybrid' => recall($exactHybrid['ids'], $run['ids'], $queries)];
}

// ---------------------------------------------------------------- storage variants (exact scan only)
// Default `vector(1024)` values are 4 KB → TOASTed; exact scans pay a detoast per row.
// Variants: same float32 vector with STORAGE PLAIN (inline), and halfvec(1024) with STORAGE PLAIN.
say('Storage variants…');
$pdo->exec('DROP TABLE IF EXISTS embeddings_plain, embeddings_half');
$results['variant_build_ms']['plain'] = round(timed(function () use ($pdo): void {
    $pdo->exec('CREATE TABLE embeddings_plain (id bigint PRIMARY KEY, workspace_id int NOT NULL, retrieval_unit_id bigint NOT NULL, embedding_model_id smallint NOT NULL, embedding vector NOT NULL)');
    $pdo->exec('ALTER TABLE embeddings_plain ALTER COLUMN embedding SET STORAGE PLAIN');
    $pdo->exec('INSERT INTO embeddings_plain SELECT id, workspace_id, retrieval_unit_id, embedding_model_id, embedding FROM embeddings');
    $pdo->exec('CREATE UNIQUE INDEX ON embeddings_plain (retrieval_unit_id, embedding_model_id)');
    $pdo->exec('CREATE INDEX ON embeddings_plain (workspace_id, embedding_model_id)');
    $pdo->exec('VACUUM ANALYZE embeddings_plain');
}), 0);
$results['variant_build_ms']['half'] = round(timed(function () use ($pdo): void {
    $pdo->exec('CREATE TABLE embeddings_half (id bigint PRIMARY KEY, workspace_id int NOT NULL, retrieval_unit_id bigint NOT NULL, embedding_model_id smallint NOT NULL, embedding halfvec(1024) NOT NULL)');
    $pdo->exec('ALTER TABLE embeddings_half ALTER COLUMN embedding SET STORAGE PLAIN');
    $pdo->exec('INSERT INTO embeddings_half SELECT id, workspace_id, retrieval_unit_id, embedding_model_id, embedding::halfvec(1024) FROM embeddings');
    $pdo->exec('CREATE UNIQUE INDEX ON embeddings_half (retrieval_unit_id, embedding_model_id)');
    $pdo->exec('CREATE INDEX ON embeddings_half (workspace_id, embedding_model_id)');
    $pdo->exec('VACUUM ANALYZE embeddings_half');
}), 0);

$plainSql = str_replace('FROM embeddings e', 'FROM embeddings_plain e', $semanticSql);
$halfSql = str_replace(
    ['FROM embeddings e', 'e.embedding::vector(1024) <=> CAST(:q AS vector(1024))'],
    ['FROM embeddings_half e', 'e.embedding <=> CAST(:q AS halfvec(1024))'],
    $semanticSql,
);
foreach (['exact_plain' => $plainSql, 'exact_halfvec_plain' => $halfSql] as $mode => $sql) {
    say("Semantic: {$mode}…");
    $run = bench($pdo, $sql, $queries);
    $results['semantic'][$mode] = array_diff_key($run, ['ids' => 0]) + ['recall_vs_exact' => recall($exact['ids'], $run['ids'], $queries)];
    $results['plans']["{$mode}_big"] = plan($pdo, $sql, $bigQuery);
}
$hybridPlainSql = str_replace('FROM embeddings e', 'FROM embeddings_plain e', $hybridSql);
say('Hybrid: exact_plain…');
$run = bench($pdo, $hybridPlainSql, $queries, [], FUSED_K);
$results['hybrid']['exact_plain'] = array_diff_key($run, ['ids' => 0]) + ['overlap_vs_exact_hybrid' => recall($exactHybrid['ids'], $run['ids'], $queries)];

// ---------------------------------------------------------------- sizes
$results['sizes'] = $pdo->query(<<<'SQL'
    SELECT c.relname AS name, pg_size_pretty(pg_total_relation_size(c.oid)) AS total, pg_size_pretty(pg_relation_size(c.oid)) AS own
      FROM pg_class c
     WHERE c.relname IN ('retrieval_units', 'embeddings', 'embeddings_plain', 'embeddings_half', 'emb_hnsw_m1_idx', 'ru_tsv_idx', 'ru_filter_idx', 'emb_ws_model_idx')
     ORDER BY pg_total_relation_size(c.oid) DESC
    SQL)->fetchAll();

$results['finished_at'] = date('c');
$file = __DIR__."/results-{$units}.json";
file_put_contents($file, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

say("Done → {$file}");
foreach (['semantic', 'hybrid'] as $kind) {
    foreach ($results[$kind] as $mode => $r) {
        printf("%-8s %-30s big p50/p95 %7.2f/%7.2f  small p50/p95 %7.2f/%7.2f ms  rows %5.1f/%5.1f  short %2d  %s\n",
            $kind, $mode, $r['p50_big'], $r['p95_big'], $r['p50_small'], $r['p95_small'], $r['avg_rows_big'], $r['avg_rows_small'], $r['short_queries'],
            isset($r['recall_vs_exact']) ? 'recall '.json_encode($r['recall_vs_exact']) : (isset($r['overlap_vs_exact_hybrid']) ? 'overlap '.json_encode($r['overlap_vs_exact_hybrid']) : ''));
    }
}
