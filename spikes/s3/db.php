<?php

/**
 * S3 spike: shared PDO connection to the throwaway career_engine_spike database.
 * Reads SPIKE_DB_PASSWORD from the project .env; never prints it.
 */
function spikeDb(): PDO
{
    $env = file_get_contents(__DIR__.'/../../.env');
    preg_match('/^SPIKE_DB_PASSWORD=(.*)$/m', $env, $m);
    $password = trim($m[1] ?? '', " \t\r\n\"'");

    $pdo = new PDO(
        'pgsql:host=127.0.0.1;port=5432;dbname=career_engine_spike',
        'career',
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
    );

    return $pdo;
}
