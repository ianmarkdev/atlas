# Deliverable 6 — Architecture Decision Records

### ADR-1 Laravel + Inertia + React + TypeScript
- **Context**: solo Laravel developer; rich editor/review UI; need server-side auth everywhere.
- **Decision**: Laravel monolith with Inertia (React, TS, Tailwind). No Next.js, no separate SPA API.
- **Alternatives**: Livewire (less suited to complex interactive editor/evidence panel); separate SPA + API (doubles auth/validation surface).
- **Consequences**: one deploy, session auth, server-driven routing; TS types generated from PHP DTOs (e.g. spatie/typescript-transformer **[VERIFY]**). Long-running AI ops are async + polling (Reverb broadcast later).

### ADR-2 PostgreSQL + pgvector
- **Decision**: single Postgres for relational, FTS, vectors, RLS.
- **Alternatives**: Pinecone/Qdrant/Weaviate (extra infra, sync complexity, tenant-filter duplication); Elasticsearch/OpenSearch (heavy).
- **Consequences**: transactional consistency between knowledge and index; must choose a host with pgvector support; ANN tuning knowledge needed at scale.
- **Revisit when**: > ~1M vectors/workspace or retrieval p95 > 300 ms after tuning.

### ADR-3 Claude API + independent embedding provider
- **Decision**: Claude for all generation, JD analysis and classification (Sonnet-class for writing, Haiku-class for JD requirement extraction/classification — IDs in config). Embeddings behind `EmbeddingProvider`; superseded for the embedding vendor by ADR-10 (local Ollama) and for generation by ADR-11 (Ollama default, Claude optional).
- **Consequences**: two vendors' DPAs; embeddings re-generable on switch via `embedding_models`. Secrets server-side only (env/secret manager), never in browser.

### ADR-4 Retrieval & orchestration in plain Laravel services
- **Decision**: deterministic pipelines (retrieve → build context → generate → validate → persist) in services/jobs. No LangChain/LangGraph/agent framework.
- **Why**: flows are fixed, short, auditable; model has no actions.
- **Adopt a graph/agent framework when**: multi-step tool use with branching decided by the model (e.g. agent browsing employer sites, iterative research), long-running resumable multi-agent workflows with human checkpoints across many steps, or > ~5 distinct chained LLM steps with conditional loops that become hard to test in plain code.

### ADR-5 Team & tenant isolation
- **Context**: MVP is personal use — 1 candidate, ≤ 3 VAs, one workspace.
- **Decision**: two roles (`candidate`, `va`) enforced by Laravel gates/policies; single workspace created at install; `workspace_id` + `candidate_profile_id` kept on tenant rows with a global scope and explicit retrieval filters so multi-team is additive later. **Postgres RLS deferred** to the SaaS phase (adds friction, little MVP protection).
- **Alternatives**: full multi-tenant + RLS now (premature); no `workspace_id` at all (simplest now, painful retrofit).
- **Revisit when**: a second workspace/team or a second candidate is onboarded.

### ADR-6 Background jobs
- **Decision**: Laravel queues on Redis with Horizon; queues `ai` (low concurrency, rate-limited), `embeddings`, `default`. Jobs: `ShouldBeUnique` / idempotency key, `afterCommit`, bounded retries with backoff, `failed()` updates run status visible in UI, timeouts > provider timeout.
- **Consequences**: requires Redis + supervisor-managed workers in prod.

### ADR-7 Human approval & knowledge versioning
- **Decision**: canonical knowledge changes only through human actions or approved `knowledge_proposals`; append-only revisions; AI output can never set `verified`; generated answers never indexed as evidence; optimistic locking + base-revision conflict detection.
- **Consequences**: more clicks; mitigated with bulk approve and good queue UX. Integrity of facts is the product's core promise.

### ADR-8 Plain-text input only (no file uploads or extraction)
- **Context**: CV import through PDF/DOCX parsing and AI claim extraction added a large milestone (upload security, MIME validation, parser quality, chunking, quote verification, a review UI) for a one-off onboarding step used by one candidate.
- **Decision**: every input is typed or pasted as plain text in the UI: knowledge forms, bulk-add claims (one per line, no AI), JDs, questions, answers and preferences. No file uploads, no PDF/DOCX/text extraction, no `documents`/`document_chunks` tables, no claim-extraction prompt.
- **Alternatives**: PDF/DOCX parsing (`smalot/pdfparser`, `phpoffice/phpword`) plus AI extraction (dropped); paste CV text and let AI suggest claims (not in MVP).
- **Consequences**: smaller attack surface (no file handling or storage of uploads), less infrastructure (no ingest queue; object storage only for export ZIPs), no extraction cost. Onboarding takes more manual typing, which bulk add mitigates. Every claim is entered by a human, so its provenance is always `user_confirmation` or a proposal.
- **Revisit when**: manual entry proves to be a real bottleneck, for example when onboarding another candidate. The first step would then be AI-suggested claims from pasted text, still landing as proposals.

### ADR-9 Facts once, wording per variant and per job
- **Context**: the candidate wants several resumes (e.g. backend vs. full-stack) and a version tailored to every job description, which the cover letter then follows. Copying employments and projects into each resume would duplicate facts, let them drift apart, and break verification.
- **Decision**: three layers. (1) One verified **master record** (contact, employments, projects, claims, credentials, skills, languages, links). (2) **Resume variants** with append-only revisions. (3) A **tailored resume per application** with append-only versions. Variants and versions are stored as one JSON document format that **refers** to master-record items by ULID and holds only presentation: title, summary, selection, order, item summaries and bullet wording, with bullets citing claims. Facts are rendered from the master record; tailored versions also keep a `facts_snapshot` so a submitted resume can be reproduced.
- **Alternatives**: normalised per-variant copy tables (facts duplicated, many tables, harder versioning); fully free-text resumes (no link to evidence, no validation).
- **Consequences**: one editor, one validator and one schema for variants, tailored versions and AI output; facts can't be changed per job; the AI can only select and word. Refs inside JSON have no foreign key, so validation runs on every save and the editor shows "Removed from your record" for archived refs. Variants and tailored versions are never indexed as evidence.
- **Amendment (resume-first UI)**: authoring happens inside resumes; new jobs/projects/bullets are written to the shared library (through the same services, so verification and duplicate checks apply) and then included in the resume. Two presentation-only overrides are allowed per resume, candidate-only: a job's **display title** (official title kept in the library and in tailored-version snapshots) and a bullet's **wording** (the claim stays the evidence; "Apply everywhere" turns the rewording into a new claim revision). Every resume bullet is backed by a claim.

### ADR-10 Local embeddings with Ollama + bge-m3
- **Context**: retrieval (M6) needs an embedding model; Anthropic offers none. Hosted APIs need another vendor, key and DPA, and free tiers may train on the data. The corpus is one candidate's library (hundreds to a few thousand short texts plus job descriptions).
- **Decision**: run `bge-m3` locally through Ollama (`OllamaEmbeddingProvider`, Laravel HTTP client, no new Composer dependency). 1024 dimensions to match S3; vectors stored as `halfvec` with `STORAGE PLAIN`. Model and endpoint in `config/embeddings.php`; the model is recorded in `embedding_models`.
- **Alternatives**: Voyage AI or OpenAI embeddings (cost, data leaves the machine); in-process PHP inference (TransformersPHP: new dependency + FFI); free hosted tiers (rate limits, data-use terms).
- **Consequences**: free and private; embedding speed bound by the local CPU (fine at this scale); production hosting needs an Ollama sidecar or a switch to a hosted provider, which is a config change plus a re-embedding backfill (`embedding_models`).
- **Revisit when**: deploying somewhere that cannot run Ollama, or retrieval eval misses Recall@8 ≥ 0.85 with `bge-m3`.

### ADR-11 Local-first LLM gateway (Ollama default, Claude optional)
- **Context**: the plan assumed Claude for all generation (ADR-3). The candidate has no API key yet and prefers to keep career data local; the laptop has an RTX 3050 (4 GB VRAM). Writing quality and instruction-following of small local models are lower than Claude's, so the choice should be measured, not assumed.
- **Decision**: one `LlmClient` interface with `OllamaLlmClient` (default), `ClaudeLlmClient` (Laravel HTTP client, no SDK) and `FakeLlmClient`; `LlmRouter` picks provider and model per task from `config/ai.php` and refuses external providers unless `AI_ALLOW_EXTERNAL=true`; `AiGateway` validates every structured output on the server (`JsonSchemaValidator`, one repair retry), enforces the budget for paid calls, and records `generation_runs` with encrypted, 14-day `generation_payloads`. Claim labels are decided by `ClaimGroundingValidator`, never by the model. `app:compare-llm` + Settings → Model comparison score providers blind on synthetic samples.
- **Alternatives**: Claude only (needs a key, data leaves the machine); local only (no path to better writing); the Anthropic PHP SDK and a JSON-schema package (new dependencies; the HTTP API and our schema subset are small).
- **Consequences**: the app is fully usable offline; per-task routing lets cheap structured tasks stay local while writing can move to Claude after measurement; the in-house schema validator covers only the keywords our prompts use.
- **Revisit when**: comparison scores show local output is not good enough for a task, or prompts need JSON-Schema features beyond the supported subset.
