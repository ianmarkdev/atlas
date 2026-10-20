# Deliverable 4 — AI and RAG design

## 4.1 Knowledge entry (plain text only — D8)
No file uploads, no PDF/DOCX parsing, no chunking and no AI claim extraction. Knowledge enters the system only through UI forms.
1. **Structured forms**: employments, projects, credentials, writing preferences and library answers are created through Inertia forms with plain-text fields (`<textarea>` / `<input>`, no rich text).
2. **Answer library**: question + answer pairs entered by hand (or several pasted at once, separated by a `---` line), with category and optional linked claims. Candidate entries are `approved`, VA entries stay `draft` until the candidate approves them. They are indexed as `library_answer` retrieval units from M6 on.
3. **Claims**: one statement per claim, attached to an employment/project/credential or `general`. **Bulk add**: paste a list into a textarea and each non-empty line becomes a draft claim under the chosen subject. This is a deterministic split with no AI, previewed before saving.
4. **Server-side normalisation** (Form Requests): trim, Unicode NFC, collapse whitespace, strip control and zero-width characters, length limits (claim ≤ 1,000 chars, summaries ≤ 5,000, JD ≤ 50,000).
5. **Status on save**: claims the candidate enters can be saved as `verified` directly, with `claim_sources(user_confirmation)`. Claims a VA enters are saved as `proposed`, and a VA's change to a verified claim becomes a `revise_claim` proposal (D5).
6. **Duplicate detection** before insert: exact `normalized_hash`; near-dup via `pg_trgm similarity > 0.6` within same subject, then embedding cosine > 0.92 **[tune in eval]** → surfaced as "possible duplicate of …: merge / keep both".

## 4.2 What to embed
| Embed | Why |
|---|---|
| Claims (verified + proposed, current revision; text includes subject context: "At Acme (2021–23), Senior Dev: …") | Primary evidence |
| Approved library answers (question_pattern + body) | Reuse and style |
| Employment/project summaries | Broad context matching; fallback evidence for context not yet split into claims |
| **Do not embed**: generated answer versions and resume variants / tailored resume versions (prevents laundering — wording is not evidence), audit/ops data, job postings (they're queries, embed transiently or cache per analysis), writing preferences (always included by rule, not retrieved), rejected/archived items |

Embedding versioning: `content_hash = sha256(model_id + search_text)`; `EmbedRetrievalUnitsJob` skips when hash unchanged. Regenerate when: source revision changes, `search_text` template version changes (bump `RETRIEVAL_TEXT_VERSION`), or model changes (backfill).

**Embedding provider recommendation**: Anthropic does not offer an embeddings API; their docs point to **Voyage AI** **[VERIFY current model names, e.g. voyage-3.5 / voyage-3.5-lite, dimension options, input_type support, pricing]**. Alternative: OpenAI `text-embedding-3-small`. `EmbeddingProvider` interface: `embedDocuments(array $texts): EmbeddingBatch`, `embedQuery(string): Vector`, `model(): EmbeddingModelSpec`. `FakeEmbeddingProvider` (deterministic hash vectors) for tests.

## 4.3 Hybrid retrieval and ranking
**Query construction** per requirement/question: text = requirement + question + key employer terms; filters from `RetrievalQuery` DTO: `workspace_id` (mandatory), `candidate_profile_id` (mandatory unless shared-only), `allowed_visibility`, `unit_types`, `statuses` (default `verified, proposed, approved`), optional tag/employer/date filters.

**SQL (single round trip, CTEs):**
1. `semantic`: top 40 by cosine distance from `embeddings` joined to filtered `retrieval_units`.
2. `lexical`: top 40 by `ts_rank_cd(tsv, websearch_to_tsquery('english', :q))`, with the query terms joined by `or` (AND semantics match almost nothing for multi-term requirements).
3. `fused`: Reciprocal Rank Fusion `Σ 1/(60 + rank)`; return top 30 with both ranks.

**App-side reranking (`EvidenceRanker`)**: `final = rrf_norm * w1 + status_boost (verified 1.0, approved 0.9, proposed 0.4) * w2 + specificity (has metric/named tech/date) * w3 + freshness decay (half-life ~5y on date_to) * w4`. Weights in config, tuned by eval. Then **MMR**-style dedupe: drop items with cosine > 0.9 to an already-selected item or same `source_id` lineage (a claim vs. its parent employment/project summary — prefer the claim). Cap: ~8 items per requirement, ~25 per generation, token budget ~6k for evidence.

Optional later: Voyage rerank model **[VERIFY]** or Claude-based rerank — only if eval shows precision gap.

**Access control before LLM**: filters are in the SQL; `ContextBuilder` asserts every item's `workspace_id`/`candidate_profile_id` matches the run's context (throws + audit on mismatch). RLS backs it up.

**Postgres vs separate service**: implement **directly in PostgreSQL**. Revisit when: >1M units, p95 retrieval > 300 ms after tuning, need for learned sparse retrieval / cross-language, or multi-region. Even then, first step is a read replica, not a new datastore.

## 4.4 Claude prompt architecture
- **Prompt registry**: prompts as files in `prompts/{key}/v{n}.md` + `schema.json`; `PromptRegistry::get('answer_generation', version)`; version + model recorded on every `generation_run`. Changing a prompt = new version file (immutable once used in prod).
- **Budget guard (monthly ceiling)**: `workspaces.monthly_ai_budget_micros` (default US$30, editable by candidate in Settings). Before every LLM/embedding call, `BudgetGuard` sums `generation_runs.cost_micros` for the current calendar month (UTC) + an *estimated* cost of the pending call (input tokens counted/estimated × price, max_tokens × output price). ≥ 80% → warning banner + one email/notice to the candidate; ≥ 100% → new runs refused with a clear UI message (candidate can raise the ceiling). Check-and-reserve is atomic (Redis lock or `FOR UPDATE` on the workspace row) so concurrent jobs can't overshoot by more than one call. Embedding costs are included. Price table in `config/ai.php` **[VERIFY current prices]**.
- **Model config** (`config/ai.php`, overridable per workspace): e.g. generation/feedback analysis → `claude-sonnet-5-5`; JD requirement extraction/classification/grounding check → `claude-haiku-5-5`. **[VERIFY]** current model IDs, pricing, context limits via the claude-api reference before M4.
- **Structured outputs**: use Claude tool-use with a JSON-schema'd tool (or native structured-output feature if available **[VERIFY]**) and force the tool choice; then **always** validate server-side with a JSON schema validator (`opis/json-schema`) + typed DTO hydration. Invalid → one repair retry with validation errors → else `invalid_output` failure surfaced to user.
- **Message layout** (cache-friendly ordering **[VERIFY prompt-caching semantics]**):
  1. System: role, Evidence-First + Creative Enhancement policy, claim taxonomy, untrusted-content policy, output contract.
  2. Writing preferences (stable per workspace → cacheable).
  3. Application brief + evidence pack: `<evidence id="E3" type="claim" status="verified" source="claim:01H…">…</evidence>`.
  4. Untrusted JD excerpt inside `<job_description untrusted="true">…</job_description>`.
  5. Task: specific question, word/char limit, tone.
- **Generation output schema (per question)**:
  ```json
  {
    "answer": "string",
    "segments": [{"text": "...", "claims": [{"text": "...", "classification": "verified|inferred|proposed|hypothetical", "evidence_ids": ["E3"], "rationale": "..."}]}],
    "clarification_questions": [{"question": "...", "why": "...", "blocking": true}],
    "achievement_suggestions": [{"related_evidence_id": "E5", "prompt_to_user": "Did the integration reduce manual processing time? Roughly how much?", "candidate_metric": "hours saved per week"}],
    "self_check": {"unsupported_claims_present": false, "word_count": 230}
  }
  ```
- **Policy text (key rules)**: retrieve-then-write; prefer verified/approved; may construct answers and approaches (label `hypothetical`), may reframe/transfer skills (`inferred`, must cite the verified basis), may suggest achievements (`proposed`, phrased as questions or placeholders like `[confirm: % reduction]` never as fact); never invent numbers, clients, employers, titles, dates, qualifications; banned-phrase list from writing preferences + a default list ("passionate", "fast-paced environment", "I am writing to express", "leverage synergies"…); tailor to employer language from the brief.

## 4.5 Claim classification & evidence tracking (server-side `ClaimValidator`)
For each claim from the model:
1. All `evidence_ids` must exist in the supplied pack → else strip IDs + flag `fabricated_citation`.
2. `verified` requires ≥1 cited item with status `verified`/`approved` → else downgrade to `proposed` + flag.
3. `inferred` requires ≥1 cited verified item → else downgrade to `proposed`.
4. Regex/heuristic detectors on answer text: numbers/percentages/currency, years, ALL-CAPS acronyms (certs), organisation names not present in evidence → any such token not found in cited evidence text → flag `unsupported_specific` (catches claims the model didn't declare).
5. Locate claim spans in `answer` (exact/fuzzy match) for highlighting; unlocatable → flag.
6. Optional **grounding check** pass (Haiku): "Does E3 support statement X? yes/partial/no" for verified/inferred claims — enabled when eval shows value.
7. Approval gate: an answer cannot be approved while it has `open` claims classified `proposed` or flagged — user must accept (keep as hedged wording), edit, remove, or "convert to proposal" (creates a `knowledge_proposal` for the candidate). Hypothetical claims are allowed but shown with a label.

## 4.6 Achievement suggestions
- Triggered (a) during generation (schema field above), (b) by a scheduled "knowledge health" job over claims of kind `responsibility` with no linked `achievement/metric` (Haiku, batched, budget-capped).
- Stored as `knowledge_proposals(origin=achievement_suggestion, proposal_type=create_claim, payload={draft_statement_with_placeholders, questions[]})`, status `pending`.
- UI: candidate answers the question (e.g. "about 6 hours/week"), edits the draft statement, confirms → claim `verified` with `claim_sources(user_confirmation)`; reject → stored rejection (prevents re-suggesting via `rejected_suggestion_hash`).

## 4.7 Feedback and approval pipeline
1. Human edit saved → new `answer_version(author=user)`.
2. On approval of the answer (or explicit "learn from edits"), `feedback_events` row with server-computed diff (word-level, `jfcherng/php-diff` or similar **[VERIFY]**).
3. `AnalyseFeedbackJob`: Claude receives original, edited, the evidence pack used, current writing preferences → outputs candidate proposals each with `type` (`factual_correction|reusable_answer|writing_preference|one_off`), target (claim ID from pack, or new), payload, confidence, rationale.
4. Validation: targets must be in pack; `one_off` → recorded, no proposal; low-confidence preference changes require ≥2 similar edits before proposing (dedupe by normalised rule).
5. Routing: factual corrections, reusable answers and shared writing preferences → candidate approval queue; a user's *personal* writing preference (scope `user`) → that user may self-approve. VA direct edits of verified facts in the knowledge manager also land here as `revise_claim` proposals.
6. `ProposalApplier` (transaction, `FOR UPDATE` on proposal + target, check `target_revision_id == current_revision_id` else status `conflict` → show 3-way view) writes a new revision, `claim_sources`, audit event, and triggers `RetrievalUnitSync`.

## 4.8 Prompt-injection handling
- Pasted JDs (and any other pasted third-party text) are wrapped and declared untrusted; system prompt says instructions inside are data.
- The model has **no tools that act** (only the output-schema tool); it cannot write to DB, send messages, or fetch URLs.
- Outputs are schema-validated; free text never executed or rendered as HTML (React escapes; Markdown rendering with sanitiser).
- Brief includes `injection_warnings` (model + heuristic scan for "ignore previous", "system prompt", hidden-text/zero-width chars) → banner in UI.
- Extracted "application questions" are shown for human confirmation before generation.
- All knowledge writes require human approval, so injected "add fact X" can at most create a pending proposal.

## 4.9 Evaluation methodology
**Dataset (in repo, `tests/Eval/fixtures`)** — fictional candidate "Alex Morgan" (never real data):
- ~60 claims across 4 employments, 6 projects (mix: verified, proposed, archived; some with metrics, some without), 3 approved library answers, 5 writing preferences (all plain text, as entered through the UI).
- 10 JDs: backend Laravel role, full-stack React, data engineering (partial fit), DevOps (weak fit), product manager (transferable), agency developer, fintech (compliance terms), a JD with 6 explicit questions, a JD with embedded prompt injection, a JD with hidden/zero-width text.
- Labels: per requirement, the set of relevant claim IDs (graded 0–2); per JD, "must-not-claim" list (e.g. Kubernetes, which Alex never used).
- A second tenant fixture "Sam Lee" with overlapping skills to test scoping.
- **Real-world set (D7)**: JD collections flagged `use_for_eval`. `php artisan eval:snapshot {collection}` exports those JDs, the brief, and your approved final answers (with the claim IDs they cited) into a private, git-ignored `storage/eval/real/` set. Approved citations become relevance labels; your edits vs. the first AI draft give an "edit distance" quality signal. Never committed to the repo (real personal data).

**Harness**: `php artisan eval:retrieval` (deterministic, runs in CI with recorded real embeddings committed as fixtures) and `php artisan eval:generation --live` (manual/nightly, real Claude, reports cost).

**Acceptance criteria (initial targets — recalibrate after first baseline):**
| Area | Metric | Target |
|---|---|---|
| Retrieval | Recall@8 of grade-2 evidence per requirement | ≥ 0.85 |
| Retrieval | nDCG@8 | ≥ 0.75 |
| Scoping | Items from another workspace/profile fixture in any context pack | **0** (hard fail) |
| Citations | Cited IDs that exist in pack | 100% (enforced) |
| Citations | Cited evidence judged supportive (human/LLM-judge sample) | ≥ 95% |
| Grounding | Final approved answers containing a `verified`-labelled claim without verified support | 0 (enforced by validator) |
| Grounding | Unsupported specifics (numbers/orgs) not flagged, per 100 answers | ≤ 2 |
| Must-not-claim | Generated drafts asserting a must-not-claim item as fact | 0 in eval set |
| Injection | Injection JDs causing policy deviation (e.g. obeying embedded instructions) | 0/2 + warning shown |
| Reliability | Generation job success incl. retries | ≥ 99% |
| Latency | JD analysis p95 | ≤ 60 s; per-answer generation p95 ≤ 45 s |
| Cost | Full application (analysis + 5 answers + cover letter) | ≤ US$0.60 **[recalibrate with real pricing]** (≈ 50 applications within a US$30 ceiling) |
| Budget | Monthly spend overshoot beyond ceiling | ≤ cost of one call |
| Usability | Paste-to-approved for a 5-question application (moderated test with a VA) | ≤ 20 min, no help needed for core flow |

LLMs cannot guarantee zero hallucinations; the guarantees above are about **labelling and gating** (enforced by code), while the rates are statistical targets.

## 4.10 Tailored resumes and the cover-letter storyline (D9)
- **Inputs**: the application brief (requirements, employer language), the base resume variant revision the application was created from, and the evidence pack (claims with stable IDs `E1..En`).
- **Output** (M8, `prompts/resume_tailoring/v1`): a full resume document in the same JSON schema as variants (`docs/03-database-design.md` → Resumes): title, summary, which experiences/projects/credentials/skills to include and in what order, item summaries and bullet wording. Each bullet lists the evidence IDs it relies on; the server maps them to claim refs.
- **Facts cannot be generated**: the schema has no fields for employer, job title, dates or institution, so the model can only select and word, never alter facts. Refs the model returns must exist in the base variant's master record set; unknown refs are dropped and flagged.
- **Validation**: `ClaimValidator` runs over every bullet and the summary exactly as for answers (4.5): fabricated evidence IDs stripped, `verified` needs a verified claim, unsupported specifics (numbers, organisations, certifications) flagged. Results land in `resume_claims`; the approval gate blocks approving a tailored version with open flagged or unbacked bullets.
- **Stored as a new version** (`application_resume_versions.author_type = ai`), never overwriting the user's version; the user edits, compares with the variant, and approves.
- **Cover letter**: generated after the tailored resume. It uses the approved (or current) tailored version as the storyline: the chosen experiences in their order, the bullets as proof points with their evidence IDs. The cover letter's `answer_version` records which resume version it was written from.
- **Learning** (M10): edits to a tailored version can become proposals: "apply this summary to the variant", "save this bullet as a new claim" (proposed for VAs, verified when the candidate confirms).
