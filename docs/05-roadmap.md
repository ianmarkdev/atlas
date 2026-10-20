# Deliverable 5 — Implementation roadmap

Ordering principle: security & data integrity foundations first, then knowledge, then AI plumbing, then the fast path, then learning loops.

### M0 — Technical spikes (3–5 days)
- **Objective**: de-risk version-sensitive choices before committing.
- **Scope**: S1 Claude structured output from PHP (official Anthropic PHP SDK vs Laravel HTTP client; forced tool / structured outputs; prompt caching; token usage fields). S2 Voyage (or alt) embeddings: model, dims, batching, rate limits. S3 pgvector hybrid SQL on 20k synthetic units: exact vs HNSW with filters, latency. S6 hosting: managed Postgres with pgvector version ≥ 0.8 **[VERIFY]**. (S4 RLS spike moved to Phase 3; S5 PDF/DOCX extraction dropped under D8.)
- **Dependencies**: API keys.
- **Files**: `spikes/` throwaway scripts + `docs/spikes/S*.md` findings.
- **Tests**: N/A (measurements recorded).
- **DoD**: each spike has a written decision; ADRs updated.
- **Risks**: SDK immaturity → fall back to HTTP client behind `LlmClient`.

### M1 — Project foundation & auth (3–4 days)
- **Objective**: deployable skeleton with CI.
- **Already done**: `career-engine` created with Laravel 13, Inertia 3 + React 19 + TS, Tailwind 4, Fortify (auth + 2FA), Wayfinder, Pest 5, Laravel Boost, PHPStan, Pint.
- **Done (2026-10-20)**: PostgreSQL 18 + pgvector 0.8.7 locally (native Windows); first migration `0000_00_00_000000_enable_postgres_extensions` (`vector`, `pg_trgm`, `citext`); PHP tests run on Postgres (`career_engine_test`) instead of SQLite; CI starts a `pgvector/pgvector:pg18` service; Vitest via vite-plus (`npm run test`, wired into `composer ci:check`); lint/format already covered by `vp check` (Oxlint/Oxfmt); PHPStan run with `--memory-limit=1G`.
- **Scope (remaining)**: Redis + Horizon (moved to the staging setup — Horizon needs `pcntl`/`posix`, unavailable on Windows; local dev uses the database queue); React Testing Library + jsdom (add with the first component tests; adding npm packages currently needs `--legacy-peer-deps` because of the vite-plus `vite` alias); Sentry; staging deploy.
- **Files**: `composer.json`, `phpunit.xml`, `vite.config.ts`, `.github/workflows/tests.yml`, `database/migrations/0000_00_00_000000_enable_postgres_extensions.php`.
- **Tests**: auth feature tests, CI green, smoke test page.
- **DoD**: login/2FA on staging; CI runs PHP + JS tests + static analysis.
- **Risks**: starter-kit churn between Laravel versions.

### M2 — Users, roles, audit (2–3 days)
- **Objective**: candidate + up to 3 VAs with enforced permissions.
- **Scope**: `php artisan app:install` (creates the single workspace, candidate account, candidate profile); memberships with `candidate|va`; candidate-only "Team" page to create/deactivate VAs (seat limit 3) and send password-reset link; `BelongsToWorkspace` trait + global scope; job middleware restoring workspace context; gates/policies; `AuditLogger`; 2FA required for candidate.
- **Files**: `app/Domain/Tenancy/*`, `app/Policies/*`, `app/Console/Commands/InstallCommand.php`, `app/Jobs/Middleware/WithWorkspaceContext.php`, `resources/js/Pages/Team/*`.
- **Tests**: role matrix (VA cannot verify/approve/manage VAs/change budget); 4th VA rejected; deactivated VA cannot log in; second seeded workspace's rows never returned (scoping suite, extended by later milestones).
- **DoD**: candidate can add 3 VAs; permission matrix green in CI.
- **Risks**: minimal.

### M3 — Career knowledge manager (7–10 days)
- **Objective**: manual knowledge base with verification, revisions and provenance — usable on its own before any AI exists.
- **Scope**: edit candidate profile (display name, headline); employments, projects, credentials, claims + revisions + sources, tags, writing preferences; **manual answer library** (question + your answer as plain text, category, optional linked claims; candidate saves as `approved`, VA saves as `draft` pending candidate approval; revisions; bulk paste of several Q&A pairs separated by a `---` line, previewed before saving, no AI; search/filter by category and text; copy button); all entered as plain text in UI forms (D8); bulk-add claims (paste a list, one claim per line, preview, then save, with no AI); server-side text normalisation + length limits; verify/archive actions; optimistic locking; exact + trigram duplicate detection; knowledge manager UI (list by employment, filters by status/kind/tag, revision history drawer).
- **Files**: `app/Domain/Knowledge/*`, `app/Http/Controllers/Knowledge/*`, `app/Http/Requests/Knowledge/*`, `resources/js/Pages/Knowledge/*`.
- **Tests**: verify requires candidate + confirmation source; bulk add splits lines and skips blanks/duplicates; library entry by candidate is `approved`, by VA is `draft` and only the candidate can approve it; editing an entry appends a revision; Q&A bulk paste splits on `---` and rejects a pair without a question or an answer; normalisation strips control/zero-width chars and enforces limits; revisions append-only; lock_version conflict → 409; duplicate guard; VA edit of a verified claim creates a pending `revise_claim` proposal and leaves the claim unchanged (minimal proposal table + candidate approve/reject lands here; the AI-driven feedback queue arrives in M10).
- **DoD**: you can enter your real career data and your standard Q&A answers, copy them out, and see full history.
- **Risks**: over-modelling — keep claim `kind` list small.

### M4 — AI gateway & usage tracking (4–5 days)
- **Objective**: one safe path to Claude.
- **Scope**: `LlmClient` interface, `AnthropicLlmClient`, `FakeLlmClient` (fixture responses), `PromptRegistry`, JSON schema validation + repair retry, `generation_runs`/`generation_payloads`, cost calculator (config price table), retries with backoff + jitter on 429/5xx/overloaded, timeouts, Redis rate limiter per workspace, monthly budget guard, idempotency keys.
- **Files**: `app/Domain/Ai/*`, `prompts/`, `config/ai.php`.
- **Also**: `BudgetGuard` with monthly ceiling (default US$30), 80% warning banner, 100% hard stop, candidate-only Settings → AI budget + usage page (spend this month, per purpose, per application).
- **Tests**: retry/timeout behaviour (HTTP fakes), invalid JSON → repair → fail, budget at 100% → refusal with clear error, concurrent jobs near the ceiling overshoot by at most one call, month rollover resets, idempotent re-dispatch doesn't double-charge.
- **DoD**: a demo prompt runs via queue with tokens/cost/latency recorded.
- **Risks**: API shape changes → isolate behind interface.

### M5 — Removed (D8)
Document import and claim extraction were dropped on 2026-10-20: no PDF/DOCX upload, text extraction, chunking or AI claim extraction. Knowledge is entered as plain text in M3. Milestone numbers are kept so that cross-references stay valid.

### M6 — Retrieval index, embeddings & retrieval eval (5–7 days)
- **Objective**: measurable hybrid retrieval.
- **Scope**: `retrieval_units` sync (model observers → queued sync), `EmbeddingProvider` + Voyage impl + fake, embedding jobs with content-hash idempotency, hybrid SQL, `EvidenceRanker`, MMR dedupe, `ContextBuilder` with tenant assertion; eval fixtures (Alex/Sam) + `eval:retrieval` command; debug "search playground" page (candidate only).
- **Files**: `app/Domain/Retrieval/*`, `app/Domain/Embeddings/*`, `tests/Eval/*`.
- **Tests**: leakage tests at SQL level, ranking unit tests, re-embed skipped when unchanged, model switch backfill.
- **DoD**: Recall@8 ≥ 0.85 on fixtures; zero leakage.
- **Risks**: weights over-fit to tiny dataset — keep defaults simple.

### M7 — Applications & JD analysis (5–6 days)
- **Objective**: paste JD → brief.
- **Scope**: applications CRUD, JD collections (create/assign/filter, `use_for_eval` flag), immutable job_postings, analysis job (requirements, questions, employer language, injection warnings), per-requirement evidence matching, gaps → clarification requests, brief page (requirements ↔ evidence matrix, editable question list).
- **Tests**: injection fixtures produce warnings and no state change beyond brief; JD stored verbatim; re-analysis creates new version.
- **DoD**: brief for 10 eval JDs looks correct on manual review.

### M8 — Answer generation, editor & evidence panel (8–10 days)
- **Objective**: the core value.
- **Scope**: generation job per question + cover letter; `ClaimValidator`; answer_versions/claims/evidence persistence; editor (textarea or lightweight rich editor — **decide**; recommend plain textarea + highlight overlay first), claim highlights by class, evidence side panel (open source record + revision), regenerate (whole / with instruction), optimistic locking, word/char counters, copy-to-clipboard per answer & all.
- **Tests**: validator downgrade rules, fabricated citation stripping, approval blocked with open proposed claims, concurrent save conflict, a11y (keyboard nav, labels, colour not sole indicator).
- **DoD**: end-to-end on eval JDs meets grounding targets; you complete one real application with it.

### M9 — Unsupported-claim review & achievement suggestions (4–5 days)
- **Scope**: claim review queue per answer, "convert to proposal", achievement suggestion proposals + confirmation UI, suppression of rejected suggestions.
- **Tests**: proposed claim never becomes verified without candidate confirmation; rejection suppression.

### M10 — Feedback learning & approval queue (5–6 days)
- **Scope**: feedback events, diff, analysis job, proposal routing by scope/role, approval queue UI (diff view, approve/edit-then-approve/reject), `ProposalApplier` with conflict detection, "save approved answer to library" (adds the AI path on top of the manual library from M3), writing preferences from proposals.
- **Tests**: version history integrity, conflict path, VA cannot approve factual proposals, approved knowledge appears in next retrieval; one_off creates no proposal.

### M11 — VA workflow (4–5 days)
- **Scope**: assignment, status machine with allowed transitions, deadlines + overdue view, submit-for-review / request changes / approve, activity timeline from audit events, dashboard pipeline (Kanban or table by status).
- **Tests**: transition guards, role checks, activity correctness.

### M12 — Export, privacy & production hardening (4–6 days)
- **Scope**: plain-text answer/application export, `eval:snapshot` for real JD collections, data export ZIP, full data deletion, retention jobs, security headers/CSP, backups + restore drill, rate limiting, production deploy, runbooks, live eval run.
- **Tests**: deletion removes rows + export files + embeddings + payloads; export completeness; E2E (Playwright) happy path.
- **DoD**: production launch checklist complete.

Total ≈ 11–14 working weeks solo (rough; refine after M0).
