# Deliverable 7 — Initial implementation backlog

### Technical spikes (do first)
- [ ] S1 Claude from PHP: SDK vs HTTP, forced-tool structured output, caching, usage fields, error types
- [ ] S2 Embedding provider: model/dims/price/limits; produce fixture embeddings
- [x] S3 Hybrid SQL perf: exact vs HNSW + filters at 20k / 200k units — `docs/spikes/S3.md`
- [ ] S6 Hosting: pgvector version, extensions, backups/PITR, region

### MVP epics
**E1 Foundation** — repo, starter kit, CI, static analysis, Sentry, staging deploy, 2FA.
**E2 Users & security** — install command (workspace + candidate + profile); candidate/VA roles; VA management with 3-seat limit; workspace scope trait; gates/policies; audit logger; scoping test suite; secure headers.
**E3 Knowledge** — contact info (email, phone, location, links, languages); employments/projects/credentials (education, certifications) CRUD (plain-text forms); skills; resume variants (document validator, editor with live preview, default variant, duplicate, revisions, plain-text copy); manual answer library (Q&A CRUD, draft→approved for VA entries, revisions, bulk paste, copy); claims + revisions + sources; bulk-add claims (one per line); text normalisation + limits; verify flow; tags; writing preferences; dup detection; knowledge manager UI; revision history UI.
**E4 AI gateway** — LlmClient + Anthropic impl + fake; prompt registry; schema validation + repair; runs + payloads; cost table; retries/rate limit; monthly budget ceiling (BudgetGuard, warning, hard stop, settings); usage page.
**E5 Ingestion** — removed (D8 / ADR-8): no uploads or extraction.
**E6 Retrieval** — retrieval_units sync; embedding provider + jobs; hybrid SQL; ranker; MMR; context builder; eval fixtures + command; search playground.
**E7 Applications & analysis** — application CRUD; JD collections; job postings; analysis prompt; requirements/questions; evidence matrix; clarifications; injection warnings; tailored resume per application (copy from variant, manual editor, versions, diff vs. variant, approve, save back to variant).
**E8 Generation & editor** — generation prompt; AI-tailored resume version; cover letter from the tailored resume's storyline; validator (answers and resume bullets); versions/claims/evidence; editor + highlights; evidence panel; regenerate; copy.
**E9 Claim review & achievements** — review queue; convert to proposal; suggestion flow; suppression.
**E10 Feedback** — diff; analysis job; proposals; approval queue; applier with conflicts; save approved answer to library.
**E11 VA workflow** — assignment; status machine; deadlines; review/approve; activity timeline; dashboard.
**E12 Privacy & ops** — plain-text export; eval snapshot of real JD collections; data export; deletion; retention; backups/restore drill; prod deploy; runbooks; E2E tests.

### Optional enhancements (post-MVP)
- AI-suggested claims from pasted CV text, landing as proposals (only if manual entry becomes a bottleneck, see ADR-8; file upload stays out of scope)
- Realtime updates via Laravel Reverb; notifications (email/Slack)
- Reranker model; grounding-check pass by default; in-app eval dashboard
- JD import from URL (server-side fetch, SSRF protections)
- Markdown/DOCX/PDF export (answers and resumes, with resume templates); cover letter templates
- Multi-candidate / multi-team support, extra roles, Postgres RLS
- Interview prep mode; question bank analytics
- Semantic duplicate clustering & merge UI
- Per-workspace model selection UI; multi-provider LLM
- Browser extension for portal copy-fill
- SaaS: signup, billing, SSO, per-tenant keys, data residency

### Recommended deployment (small team)
Laravel Forge (or Laravel Cloud **[VERIFY pgvector support]**) on one VPS in any convenient region (web + Horizon workers) + **managed PostgreSQL with pgvector** (e.g. a provider whose pgvector version ≥ 0.8 **[VERIFY]**) + managed Redis + a private storage disk for data-export ZIPs only (local disk on the VPS, or an S3-compatible bucket with SSE) + Sentry + uptime monitor. Secrets in Forge/Cloud env (or a secrets manager), separate API keys for staging/prod, budget alerts on Anthropic console.
