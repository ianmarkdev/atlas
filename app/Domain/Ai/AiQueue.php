<?php

namespace App\Domain\Ai;

/**
 * The queue an AI job waits on, by where its task runs and whether someone is waiting for it.
 * Local work (Ollama, embeddings) shares one GPU, so its lanes get one worker; external work
 * (Claude) waits on the network and can run in parallel. A worker drains its first queue
 * before the next, so `*-now` (someone is watching the page) goes before `*-bulk`:
 *
 *   queue:work --queue=local-now,local-bulk          (one process)
 *   queue:work --queue=claude-now,claude-bulk        (two processes)
 *   queue:work --queue=default                       (no AI)
 *
 * Decided when the job is queued: a job already waiting keeps its lane if the task's provider
 * changes.
 */
final class AiQueue
{
    public const LOCAL_NOW = 'local-now';

    public const LOCAL_BULK = 'local-bulk';

    public const CLAUDE_NOW = 'claude-now';

    public const CLAUDE_BULK = 'claude-bulk';

    /** Every AI lane, interactive first: for a single worker that should run them all (development). */
    public const ALL = [self::LOCAL_NOW, self::CLAUDE_NOW, self::LOCAL_BULK, self::CLAUDE_BULK];

    /**
     * The lane for a text-generation task, from its configured provider (as LlmRouter picks it).
     */
    public static function for(string $task, bool $bulk = false): string
    {
        $provider = config("ai.tasks.{$task}.provider") ?: config('ai.default_provider');
        $external = (bool) config("ai.providers.{$provider}.external", false);

        return match (true) {
            $external && $bulk => self::CLAUDE_BULK,
            $external => self::CLAUDE_NOW,
            $bulk => self::LOCAL_BULK,
            default => self::LOCAL_NOW,
        };
    }

    /**
     * Embeddings always run on the local Ollama: searches wait for them. Re-indexing a changed
     * library item waits here too, ahead of the embedding it queues.
     */
    public static function embeddings(): string
    {
        return self::LOCAL_NOW;
    }
}
