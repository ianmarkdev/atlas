# Deliverable 2 — Architecture

## 2.1 System diagram

```mermaid
flowchart LR
  subgraph Browser
    UI[Inertia + React + TS<br/>Tailwind]
  end

  subgraph App["Laravel monolith"]
    HTTP[Controllers + Form Requests<br/>Policies / Gates]
    SVC[Application Services<br/>Knowledge / Applications / Feedback]
    RET[Retrieval module<br/>HybridSearch, Ranker, ContextBuilder]
    AI[AI module<br/>LlmGateway, PromptRegistry,<br/>OutputValidators]
    EMB[Embedding module<br/>EmbeddingProvider interface]
    TEN[TenantContext<br/>scopes + RLS session vars]
    AUD[Audit + Usage logging]
  end

  subgraph Workers["Queue workers (Horizon)"]
    J2[EmbedRetrievalUnitsJob]
    J3[AnalyseJobPostingJob]
    J4[GenerateAnswersJob]
    J5[AnalyseFeedbackJob]
  end

  PG[(PostgreSQL + pgvector<br/>FTS + RLS)]
  R[(Redis<br/>queues, cache, locks, rate limits)]
  S3[(Private storage disk<br/>data-export ZIPs only)]
  CL[Anthropic Claude API]
  VE[Embedding API<br/>e.g. Voyage AI]

  UI <--> HTTP --> SVC
  SVC --> RET --> PG
  SVC --> AI --> CL
  SVC --> TEN
  RET --> EMB --> VE
  SVC --> R
  R --> Workers
  Workers --> SVC
  SVC --> S3
  SVC --> AUD --> PG
```

Single deployable Laravel app; web and worker processes from the same codebase. All input arrives as plain text from UI forms (D8) — there is no file-upload or document-ingestion path. No separate retrieval service, Python backend, vector DB, or agent framework in MVP.

## 2.2 Component responsibilities (module layout)

Use domain folders under `app/` (no package/modules framework needed):

```
app/
  Http/Controllers/…            thin: authorise, validate, call service, return Inertia
  Http/Requests/…               validation
  Policies/…                    per-model authorisation
  Domain/
    Tenancy/                    Workspace, Membership, TenantContext, BelongsToWorkspace trait, RLS middleware
    Knowledge/                  CandidateProfile, Employment, Project, Claim, ClaimRevision, Source,
                                AnswerLibraryEntry, WritingPreference; KnowledgeService, DuplicateDetector
    Retrieval/                  RetrievalUnit, Embedding, EmbeddingModel; RetrievalUnitSync,
                                HybridSearch, EvidenceRanker, ContextBuilder, RetrievalQuery DTO
    Ai/                         LlmClient (interface), AnthropicLlmClient, PromptRegistry, PromptTemplate,
                                GenerationRun, StructuredOutput validators, CostCalculator
    Embeddings/                 EmbeddingProvider (interface), VoyageEmbeddingProvider, FakeEmbeddingProvider
    Applications/               JobApplication, JobPosting, JobAnalysis, Requirement, ApplicationQuestion,
                                AnswerDraft, AnswerVersion, AnswerClaim, EvidenceReference;
                                JobAnalysisService, AnswerGenerationService, ClaimValidator, ExportService
    Feedback/                   FeedbackEvent, KnowledgeProposal; FeedbackAnalysisService, ProposalApplier
    Audit/                      AuditEvent, AuditLogger
  Jobs/                         queue jobs (thin; call services)
  Data/                         DTOs (spatie/laravel-data or plain readonly classes — decide in M1)
resources/js/
  Pages/…  Components/…  types/ (generated from DTOs)
prompts/                        versioned prompt templates (files in git) + JSON schemas
```

Rules: controllers never call the LLM directly; services own transactions; jobs are idempotent wrappers around services; only `Retrieval` builds vector/FTS SQL; only `Ai` talks to Anthropic.

## 2.3 Request and job flows

**JD analysis (async with progress):**
```mermaid
sequenceDiagram
  participant U as User (VA)
  participant C as ApplicationController
  participant S as JobAnalysisService
  participant Q as Queue
  participant W as AnalyseJobPostingJob
  participant L as LlmGateway (Claude)
  participant R as HybridSearch
  U->>C: POST /applications (JD text, company, role)
  C->>S: createApplication(dto) [txn]
  S->>Q: dispatch AnalyseJobPostingJob(analysis_id) afterCommit
  C-->>U: redirect to brief page (status: analysing)
  W->>L: extract_requirements prompt (JD as untrusted data)
  L-->>W: JSON (validated against schema)
  W->>R: per requirement: hybrid search (tenant+profile filters)
  W->>L: gap/clarification prompt with evidence candidates
  W->>S: persist brief, requirements, questions, matches [txn]
  U->>C: poll / Inertia reload (or Reverb broadcast later)
```

**Generation:** `POST /applications/{id}/generate` → `GenerateAnswersJob(application_id, question_ids, idempotency_key)` → for each question: RetrievalQuery → ContextBuilder (evidence pack with stable IDs `E1..En`) → Claude structured output → ClaimValidator → persist `answer_versions` + `answer_claims` + `evidence_references` in one transaction.

**Feedback:** saving a human edit creates a new `answer_version` (author=user). On "Approve" or explicit "Learn from my edits", dispatch `AnalyseFeedbackJob(feedback_event_id)` → Claude classifies diff → `knowledge_proposals` (status `pending`) → approval queue → `ProposalApplier` writes new revisions in a transaction.

## 2.4 RAG data flow

```mermaid
flowchart TD
  A[Source: plain-text user entry / confirmation / approved proposal] --> B[Claim or Library entry<br/>+ revision + source link]
  B --> C[RetrievalUnitSync<br/>build normalised search text,<br/>filters, tsvector]
  C --> D[EmbedRetrievalUnitsJob<br/>content_hash check]
  D --> E[(embeddings<br/>per model)]
  F[Requirement / question] --> G[RetrievalQuery<br/>workspace, profile, types, statuses, tags]
  G --> H[HybridSearch SQL:<br/>vector top-k ∪ FTS top-k → RRF]
  E --> H
  C --> H
  H --> I[EvidenceRanker<br/>status, specificity, freshness, MMR dedupe]
  I --> J[ContextBuilder<br/>evidence pack E1..En, token budget]
  J --> K[Claude]
  K --> L[ClaimValidator → answer_claims + evidence_references]
```

## 2.5 Key design decisions (summary; full ADRs in Deliverable 6)
- **Monolith** over services: one developer, low volume; module boundaries in code suffice.
- **Hybrid retrieval inside PostgreSQL** (one SQL query, reciprocal rank fusion) — no separate search service until measured need (see ADR-4 triggers).
- **Derived `retrieval_units` table** decouples canonical knowledge (claims, library answers, employment/project summaries) from search representation; can be rebuilt any time.
- **Claims as atomic facts** with append-only revisions — the unit of verification and citation.
- **Human-in-the-loop for every canonical write** originating from AI.
- **Async jobs for every LLM call** (no LLM calls in the web request path), so timeouts/retries are controlled.
