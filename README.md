# Career Engine

Laravel + Inertia/React app that keeps a provenance-tracked career library and prepares job applications with AI (local Ollama by default). The plan and design docs are in [docs/](docs/README.md); how the app works end to end is in [docs/08-how-it-works.md](docs/08-how-it-works.md), and the AI setup in [docs/ai-setup.md](docs/ai-setup.md).

## Running locally

Ollama must be running, with the models pulled (see [docs/ai-setup.md](docs/ai-setup.md)):

```powershell
ollama serve            # skip if Ollama already runs as a service
ollama pull bge-m3
ollama pull qwen2.5:3b
ollama pull qwen2.5:7b
```

### App, queue worker and scheduler

Run each in its own terminal:

```powershell
composer run dev                # web server, Vite, and one queue worker for every queue
php artisan schedule:work       # scheduler: needed for "Prepare in background" (auto flow)
```

`composer run dev` uses `queue:listen`, which reloads the code for every job, so code changes take effect without a restart.

The scheduler is what moves the auto flow forward: every minute `app:prepare-applications` takes each application's next step (analysis, questions, resume, answers, cover letter). It also analyses applications saved by Career VA, fails AI work left running when the PC was switched off, and prunes old AI payloads (see [routes/console.php](routes/console.php)).

### Separate workers (instead of the one in `composer run dev`)

AI jobs wait on queues by where they run and whether someone is waiting ([AiQueue](app/Domain/Ai/AiQueue.php)). A worker drains its first queue before the next.

```powershell
php artisan queue:listen --queue=local-now,local-bulk --tries=1 --timeout=0    # exactly one: Ollama shares one GPU
php artisan queue:listen --queue=claude-now,claude-bulk --tries=1 --timeout=0  # two of these, only when tasks run on Claude
php artisan queue:listen --queue=default --tries=1                             # re-indexing, no AI
php artisan schedule:work
```

Then start the app without its own worker: `php artisan serve` and `npm run dev`. Don't run `composer run dev` as well, or two workers compete for the GPU.

`queue:work` is faster than `queue:listen` but keeps the code it started with: after changing PHP code, prompts or `.env`, run `php artisan queue:restart` (it stops the workers; start them again). `--timeout=0` matters on the AI lanes: a 7B model partly on the CPU takes several minutes for an analysis or a cover letter.

With `DB_QUEUE_CONNECTION=local_queue` in `.env`, jobs wait in a local Postgres database (`QUEUE_DB_*`), so polling workers don't keep a remote database awake. That database must be running and migrated.

### Useful commands

```powershell
php artisan app:prepare-applications     # take the auto flow's next step now, instead of waiting for the scheduler
php artisan queue:failed                 # jobs that failed
php artisan queue:retry all              # try them again
php artisan app:eval-analysis --workspace=<ulid> --limit=20   # measure job-description analysis on the eval fixture (rolled back)
```

`--workspace` is needed when there is more than one workspace.

## Tests

```powershell
php artisan test --compact
```
