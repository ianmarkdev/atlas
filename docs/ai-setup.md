# AI setup: Ollama (default) and optional Claude

Career Engine runs AI locally by default (D11, D12). Nothing is sent outside your machine
unless you explicitly configure it. Two local models are used:

| Use | Model | Config |
|---|---|---|
| Embeddings for search (M6) | `bge-m3` | `config/embeddings.php` |
| Text generation (M4): claim checking | `qwen2.5:3b` | `config/ai.php` |
| Text generation (M4): job-description analysis, answers and cover letters | `qwen2.5:7b` | `config/ai.php` |

Embeddings and text generation are configured separately; changing one never changes the other.

## Ollama-only setup (no API key)

1. Install Ollama for Windows from <https://ollama.com/download> (or `winget install Ollama.Ollama`).
   It runs in the background at `http://127.0.0.1:11434`.
2. Pull the models (about 7 GB in total):

   ```
   ollama pull bge-m3
   ollama pull qwen2.5:3b
   ollama pull qwen2.5:7b
   ```

3. In `.env` (these are also the defaults, so you can leave them out):

   ```
   EMBEDDINGS_PROVIDER=ollama
   AI_PROVIDER=ollama
   AI_ALLOW_EXTERNAL=false
   ```

4. Check both halves:

   ```
   php artisan app:check-embeddings
   php artisan app:rebuild-retrieval-index --embed --promote
   php artisan app:compare-llm --providers=ollama
   ```

   The last command runs the synthetic comparison samples through Ollama. Score the outputs under
   **Settings → Model comparison**.

### Hardware notes

On a laptop RTX 3050 (4 GB VRAM), 3–4B models run fully on the GPU; the 7B writer spills partly
into system RAM and is slower (expect tens of seconds per answer). If that is too slow, or the 7B
model is not pulled yet, use the 3B model for answers and resume tailoring too:

```
AI_ANSWER_WRITING_OLLAMA_MODEL=qwen2.5:3b
AI_RESUME_TAILORING_OLLAMA_MODEL=qwen2.5:3b
```

Measured with `qwen2.5:3b` on that laptop: tailoring a two-job resume ≈ 20 s, a cover letter ≈ 30 s.

Ollama keeps a model loaded for `OLLAMA_KEEP_ALIVE` (default `10m`) after the last call.

Every task asks Ollama for a context window of `AI_OLLAMA_CONTEXT` tokens (default `8192`, prompt +
output). Ollama's own default is 4096, and a longer prompt (a cover letter with its storyline) would be
cut from the start without an error. One size for all tasks keeps Ollama from reloading a model to
resize it. Measured with `qwen2.5:7b` on the RTX 3050 laptop (58% CPU): one 150-word answer ≈ 60 s.

`AI_ANSWER_CLAIMS=separate` (default): the 7B writer writes only the text, and the `claim_check` task
(3B, fully on the GPU: ~70 tokens/s against ~5) labels its claims in a second job. "Draft all" queues
the writes first, so the checks run together at the end and the models swap only twice. Measured on
a real application, 2 answers + a cover letter: 267 s instead of 380 s with `inline` (the writer
labels its own claims, answer_writing v4). The 3B checker is more cautious: it flags more claims to
check.

### Working without Ollama

Set `EMBEDDINGS_PROVIDER=fake` to keep keyword search working without embeddings. AI generation
has no offline fallback: calls fail with a clear "Ollama is not reachable" error and are recorded
as failed runs.

## Optional: Claude API

Claude is supported but never required. Use it for some or all tasks:

1. Create an API key at <https://console.anthropic.com/> and add to `.env`:

   ```
   ANTHROPIC_API_KEY=sk-ant-...
   AI_ALLOW_EXTERNAL=true
   ```

   `AI_ALLOW_EXTERNAL=true` is your explicit consent to send private career data (library facts,
   job descriptions, answers) to Anthropic. Without it, any task routed to Claude is refused before
   anything is sent.

2. Route tasks, either all at once or one by one:

   ```
   # everything on Claude
   AI_PROVIDER=claude

   # or only answer writing on Claude, the rest local
   AI_ANSWER_WRITING_PROVIDER=claude
   ```

   Models per task: `AI_JD_ANALYSIS_CLAUDE_MODEL`, `AI_ANSWER_WRITING_CLAUDE_MODEL`,
   `AI_RESUME_TAILORING_CLAUDE_MODEL`, `AI_ACHIEVEMENT_SUGGESTIONS_CLAUDE_MODEL`,
   `AI_FEEDBACK_ANALYSIS_CLAUDE_MODEL`, `AI_CLAIM_CHECK_CLAUDE_MODEL` (and the `..._OLLAMA_MODEL`
   equivalents).

3. Compare before switching: `php artisan app:compare-llm --providers=ollama,claude`. The samples are
   synthetic (`tests/Eval/fixtures/llm-comparison.php`), so no real application is sent.

If Claude is selected without a key, or the key is rejected, the call fails with a clear
configuration error. The key stays in `.env` on the server; it is never sent to the browser.

### Cost control

Paid calls are checked against the workspace's monthly AI budget (default US$30) before they are
sent, using the price table in `config/ai.php` (`prices`). Verify those prices against Anthropic's
current price list. A paid model without a price is refused rather than guessed. Local calls cost
nothing and are never limited.

## What the server enforces regardless of model

- **Structure**: every structured output is validated against the prompt's JSON schema
  (`prompts/{key}/v{n}.schema.json`); one repair attempt, then the run fails as `invalid_output`.
- **Claim labels**: `ClaimGroundingValidator` re-checks every `verified` / `inferred` /
  `proposed` / `hypothetical` label against the evidence actually supplied, downgrading claims
  without verified evidence and claims with numbers the evidence does not contain.
- **Untrusted job descriptions**: they are wrapped in `<job_description untrusted="true">` and the
  prompts tell the model to treat them as data; injection attempts are reported, not followed.
- **Records**: each call is a `generation_runs` row (task, prompt version, provider, model, tokens,
  cost, latency, status). Prompt and response bodies are stored encrypted in `generation_payloads`
  and deleted after 14 days (`php artisan schedule:run` must run for the daily prune).
- **Retries and timeouts**: bounded (3 attempts, exponential backoff with jitter, `Retry-After`
  honoured) with per-task timeouts in `config/ai.php`.
