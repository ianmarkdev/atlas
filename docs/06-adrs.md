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
- **Decision**: Claude for all generation, JD analysis and classification (Sonnet-class for writing, Haiku-class for JD requirement extraction/classification — IDs in config). Voyage AI for embeddings (Anthropic-recommended; **[VERIFY]**), behind `EmbeddingProvider`.
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
