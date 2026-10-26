<?php

namespace App\Console\Commands;

use App\Domain\Knowledge\CandidateProfile;
use App\Domain\Knowledge\LibraryDocument;
use App\Domain\Knowledge\LibraryImporter;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\Workspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * @phpstan-import-type ImportReport from LibraryImporter
 */
#[Signature('app:import-library
    {files* : Markdown documents, read in order: a profile (employment, projects, claims) first, then CVs}
    {--workspace= : Workspace ULID (default: the only workspace)}
    {--dry-run : Show what would be added, then save nothing}')]
#[Description('Import jobs, projects and bullet points from Markdown documents into the library, as proposed items for the candidate to verify')]
class ImportLibrary extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(LibraryImporter $importer, TenantContext $tenant): int
    {
        $workspace = $this->workspace();
        $files = (array) $this->argument('files');
        $missing = array_filter($files, fn (string $file): bool => ! is_file($file));

        if ($workspace === null) {
            return self::FAILURE;
        }

        if ($missing !== []) {
            $this->components->error('No such file: '.implode(', ', $missing));

            return self::FAILURE;
        }

        DB::beginTransaction();

        try {
            $report = $tenant->run($workspace, function () use ($importer, $files): ?array {
                $report = null;
                $candidate = CandidateProfile::current()->user;

                foreach ($files as $file) {
                    $report = $importer->import(LibraryDocument::parse((string) file_get_contents($file)), $candidate, $report);
                }

                return $report;
            });
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        $this->option('dry-run') ? DB::rollBack() : DB::commit();

        $this->printReport($report ?? ['added' => [], 'duplicates' => [], 'unplaced' => []]);

        return self::SUCCESS;
    }

    /**
     * @param  ImportReport  $report
     */
    private function printReport(array $report): void
    {
        $this->table(['Added', 'Item'], array_map(fn (array $row): array => [$row[0], mb_strimwidth($row[1], 0, 140, '…')], $report['added']));

        foreach ($report['duplicates'] as $line) {
            $this->line("  <fg=gray>duplicate, skipped:</> {$line}");
        }

        foreach ($report['unplaced'] as $line) {
            $this->components->warn($line);
        }

        $summary = count($report['added']).' added, '.count($report['duplicates']).' duplicates skipped, '.count($report['unplaced']).' not placed.';

        $this->option('dry-run')
            ? $this->components->info("Dry run, nothing saved: {$summary}")
            : $this->components->info("{$summary} Everything added is proposed: verify it in the library.");
    }

    private function workspace(): ?Workspace
    {
        if ($this->option('workspace')) {
            $workspace = Workspace::query()->where('ulid', $this->option('workspace'))->first();

            if ($workspace === null) {
                $this->components->error('No workspace with that ULID.');
            }

            return $workspace;
        }

        $workspaces = Workspace::query()->limit(2)->get();

        if ($workspaces->count() !== 1) {
            $this->components->error($workspaces->isEmpty() ? 'No workspace yet: run php artisan app:install first.' : 'Several workspaces exist: pass --workspace=<ulid>.');

            return null;
        }

        return $workspaces->first();
    }
}
