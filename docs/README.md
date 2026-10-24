# AI Job Application Knowledge Engine — Engineering Plan

> Status: planning only. No application code yet. Project: `career-engine` (Laravel 13 + Inertia 3 / React 19 / TS starter kit).
> Items marked **[VERIFY]** are version-sensitive and must be checked against official docs before coding.

## Context

The user and their virtual assistants (VAs) prepare many job applications. Generic cover-letter generators produce unsupported, repetitive text and forget everything between applications. The goal is a Laravel + Inertia/React + PostgreSQL/pgvector platform that:

- keeps a **persistent, provenance-tracked career knowledge base**,
- analyses each job description into a structured **application brief**,
- generates answers using **Evidence-First + Creative Enhancement**, with every important claim labelled `verified | inferred | proposed | hypothetical` and linked to evidence,
- **learns from approved edits** without silently corrupting canonical facts,
- supports a **shared VA workspace** with strict tenant isolation,
- can grow into a multi-team SaaS.

---

## Confirmed decisions
- D1. Personal use: 1 candidate + max 3 VAs; low volume (< 50 applications/month assumed).
- D2. Data residency / GDPR constraints: none beyond normal good practice for MVP; hosting region free choice.
- D3. Standard Anthropic and embedding-provider data retention is acceptable (no zero-retention arrangement needed).
- D4. Monthly AI budget ceiling is required (see 4.4 "Budget guard").
- D5. VAs may edit verified facts **only as proposals**.
- D6. Export format: **plain text** only (plus copy-to-clipboard).
- D7. Evaluation set: you will group real JD records as you apply; these become the real-world eval set alongside synthetic CI fixtures.
- D8. **Plain-text input only**: everything is typed or pasted as plain text in the UI. No PDF/DOCX/file uploads, no text extraction, no AI claim extraction (ADR-8; M5 removed).
- D9. **Resume variants, tailored per job**: one verified master record → several resume variants → a versioned tailored resume per application, which the cover letter is written from. Facts are never edited per variant or job; only selection, order and wording vary (ADR-9).
- D10. **Manual applications before AI**: applications are tracked and prepared by hand first (stage + review status, tailored resume, questions, VA review), and the AI milestones fill the same tables later.
- D11. **Local embeddings**: Ollama + `bge-m3`, stored and searched in Postgres/pgvector (ADR-10).
- D12. **Local-first text generation**: Ollama by default; Claude optional per task, only with `AI_ALLOW_EXTERNAL=true` (ADR-11, [ai-setup.md](ai-setup.md)).

## Documents

| File | Contents |
|---|---|
| [PLAN.md](PLAN.md) | Full plan, single source of truth |
| [01-product-scope.md](01-product-scope.md) | Goals, non-goals, roles, workflows, MVP vs later, risks, open questions |
| [02-architecture.md](02-architecture.md) | System diagram, modules, request/job flows, RAG flow |
| [03-database-design.md](03-database-design.md) | ER diagram, tables, indexes, pgvector, access control, lifecycle |
| [04-ai-rag-design.md](04-ai-rag-design.md) | Plain-text knowledge entry, embeddings, hybrid retrieval, prompts, claim validation, feedback, evaluation |
| [05-roadmap.md](05-roadmap.md) | Milestones M0–M12 |
| [06-adrs.md](06-adrs.md) | Architecture Decision Records |
| [07-backlog.md](07-backlog.md) | Spikes, MVP epics, optional enhancements, deployment |
| [ai-setup.md](ai-setup.md) | Running AI locally with Ollama, and optional Claude API configuration |
| [08-how-it-works.md](08-how-it-works.md) | As built: the analyse → write → learn → submit flow in diagrams, each step linked to its code, and the AI calls |

> If PLAN.md and a split file disagree, PLAN.md wins. Edit PLAN.md first, then the split copy.

## Recommended implementation order & first milestone

Order: **M0 (spikes S1, S3 first) → M1 → M2 → M3 → M4 → M6 → M7 → M8 → M9 → M10 → M11 → M12.**
(M4 can run in parallel with M3 if desired; M6 must precede M7 because analysis needs evidence matching.)

**Start coding with M1 + M2 together as "Secure foundation"**, after a 2-day M0 covering S1 (Claude structured output from PHP) and S3 (hybrid SQL). Concretely, the first coding session:
1. Switch the existing project to PostgreSQL with `vector`, `pg_trgm`, `citext`.
2. CI with Pest, PHPStan, Pint, Vitest.
3. `app:install` (workspace + candidate + profile), `candidate|va` roles, VA management with 3-seat limit, workspace scope trait, first gates/policies.
4. Role-matrix + scoping tests, written before any knowledge tables exist, so every later model must pass them.

## Verification (of this plan, before/while coding)
- Each **[VERIFY]** item resolved in `docs/spikes/*` or ADR updates before its milestone.
- Each milestone's DoD checked in CI (tests + isolation suite) and, from M6 on, `eval:retrieval` run in CI; `eval:generation --live` run manually at M8, M10, M12 with results logged against the acceptance-criteria table.
- End-to-end: Playwright test "paste JD → analyse → generate → resolve flags → approve → copy" against staging with `FakeLlmClient`; one live run per release.
