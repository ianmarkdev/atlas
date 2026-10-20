# Deliverable 1 — Product and scope definition

## 1.1 Goals
1. Cut time from "pasted job description" to "approved, copy-ready answers" to **< 20 minutes** of human time for a typical application (5 questions + cover letter).
2. Every factual statement about the candidate in a final answer is either backed by verified evidence or visibly flagged.
3. Knowledge improves over time through **explicit, approved** updates, with full history.
4. VAs can do most of the preparation work; the candidate approves facts and final output.
5. No cross-workspace data leakage — enforced server-side at query, embedding and retrieval level.

## 1.2 Non-goals (MVP)
- Automatic submission to job portals, browser automation, scraping of job boards.
- Model fine-tuning.
- Billing, self-serve SaaS signup, public marketing site.
- Real-time collaborative (Google-Docs-style) editing — optimistic locking only.
- Mobile apps; email/calendar integrations; ATS integrations.
- Multi-LLM-provider routing (abstraction exists, only Claude implemented).

## 1.3 Personas and permissions (MVP: deliberately simple)

MVP is **personal use**: exactly **1 candidate (you) + up to 3 VAs**, one workspace, one candidate profile. Only two roles:

| Role | Who | Can do |
|---|---|---|
| `candidate` | You (also the admin) | Everything: manage VAs, settings, AI budget, verify facts, approve/reject all proposals, approve final answers, export/delete data |
| `va` | Up to 3 virtual assistants | Create applications, paste/group JDs, run analysis/generation, edit drafts, submit for review, **edit verified facts only as proposals** (never directly), propose new facts/answers/preferences |

Hard rules:
- Only the `candidate` can move a claim to `verified` or approve a knowledge proposal.
- A VA "editing" a verified fact creates a `knowledge_proposal(revise_claim)`; the canonical claim is unchanged until the candidate approves.
- Seat limit enforced server-side: max 3 active `va` memberships (config `app.max_vas = 3`).
- VA accounts are created by the candidate (invite link or direct creation) — no self-signup.

Future-proofing kept cheap: tables still carry `workspace_id` and `candidate_profile_id` so multi-team SaaS later is an additive change, but the MVP UI never exposes workspaces/profiles and there is no profile-level access control, reviewer, or viewer role.

## 1.4 Core workflows
1. **Onboard knowledge**: type or paste career history as plain text into UI forms (employments, projects, credentials, one statement per claim, or bulk-add one claim per line) → candidate verifies → verified knowledge base. No file uploads, no document parsing, no AI extraction (D8).
2. **New application (fast path)**: paste JD (+ URL, company, role, deadline) → analysis job → review brief (requirements, questions, evidence matches, gaps) → answer clarification questions → generate → edit with evidence panel → resolve flagged claims → approve → copy/export.
3. **Achievement discovery**: AI flags thin records ("API integration, no outcome") → asks targeted questions → candidate confirms/revises/rejects → confirmed items become verified claims.
4. **Feedback learning**: edited answer → diff analysis → classified suggestions (fact correction / reusable answer / writing preference / one-off) → approval queue → versioned knowledge.
5. **JD collections**: as you apply, JDs are grouped into named collections (e.g. "Laravel backend roles", "Agency roles"); a collection can be flagged `use_for_eval` so its JDs + your approved answers become evaluation cases.
6. **VA workflow**: candidate assigns application to VA → VA prepares → submits for review → candidate approves or requests changes → marked submitted externally.

## 1.5 MVP vs later

| MVP (Phase 1) | Phase 2 | Phase 3 (SaaS) |
|---|---|---|
| Auth, 1 candidate + ≤3 VAs, two roles | Reviewer role, multiple candidate profiles | Multiple workspaces, self-serve signup, billing, SSO, Postgres RLS |
| Manual knowledge entry via plain-text UI forms (no file uploads) | Portfolio URL import, LinkedIn export import | Per-tenant encryption keys |
| Claims with verification + provenance + revisions | Duplicate merge UI with semantic clustering | Data residency options |
| JD analysis → brief | JD import from URL (fetched server-side, sanitised) | Browser extension for copy-fill |
| Evidence-first generation, claim labels, evidence panel | Reranker model, retrieval tuning UI | Graph agent framework if justified (see ADR-4) |
| Achievement suggestions + approval | Interview prep mode | |
| Feedback diff → proposals → approval queue | Writing-style analytics | |
| Assignment, statuses, deadlines, activity | Notifications (email/Slack) | |
| Copy-to-clipboard + plain-text export | Markdown/DOCX/PDF export | |
| Usage/cost tracking, **monthly AI budget ceiling**, audit log, data export/delete | Eval dashboard in-app | |
| JD records grouped into collections as you apply (feeds eval set) | | |

## 1.6 Key risks and assumptions

Risks:
| Risk | Mitigation |
|---|---|
| LLM labels an invented claim as `verified` | Server-side validator: `verified` only allowed if the claim cites a retrieved record whose status is `verified`; otherwise downgraded to `proposed` and flagged |
| Fabricated citations | Citations must reference IDs from the supplied context set; unknown IDs rejected |
| Proposed claims "laundering" into facts via reuse | Generated answers are never indexed as evidence; only human-confirmed claims become `verified` |
| Cross-tenant leakage via vector search | Tenant filter inside the SQL query + RLS defence-in-depth + automated leakage tests |
| Prompt injection in pasted JDs | Untrusted-content wrapping, no action-capable tools, schema validation, human approval for all writes |
| Cost creep | Per-run token logging, per-workspace monthly budget cap, prompt caching **[VERIFY]**, cheaper model for JD requirement extraction/classification |
| Solo-developer scope | Strict milestone ordering, MVP cut list, spikes before commitments |

Confirmed decisions (2026-10-20):
- D1. Personal use: 1 candidate + max 3 VAs; low volume (< 50 applications/month assumed).
- D2. Data residency / GDPR constraints: none beyond normal good practice for MVP; hosting region free choice.
- D3. Standard Anthropic and embedding-provider data retention is acceptable (no zero-retention arrangement needed).
- D4. Monthly AI budget ceiling is required (see 4.4 "Budget guard").
- D5. VAs may edit verified facts **only as proposals**.
- D6. Export format: **plain text** only (plus copy-to-clipboard).
- D7. Evaluation set: you will group real JD records as you apply; these become the real-world eval set alongside synthetic CI fixtures.
- D8. **Plain-text input only**: every input (career knowledge, JDs, questions, answers, preferences) is typed or pasted as plain text in the UI. No PDF/DOCX/file uploads, no text extraction from files, no AI claim extraction from documents.

Remaining assumptions:
- A1. Content is English.

## 1.7 Open questions
1. Initial monthly AI budget value — plan default is **US$30/month** (warn at 80%, hard-stop at 100%, candidate can raise it in Settings). Adjust if you have a number in mind.
2. Should VAs see the AI usage/cost page, or only the candidate? (Plan default: candidate only.)
