<?php

$context = (int) env('AI_OLLAMA_CONTEXT', 8192);

return [

    /*
    |--------------------------------------------------------------------------
    | Text generation (M4, D12)
    |--------------------------------------------------------------------------
    |
    | Local-first: every task runs on Ollama unless configured otherwise, so the
    | app works without an Anthropic API key. Embeddings are separate
    | (config/embeddings.php). See docs/ai-setup.md.
    |
    */

    'default_provider' => env('AI_PROVIDER', 'ollama'),

    /*
    | Private career data (library facts, job descriptions, answers) is only
    | sent to a provider outside this machine when this is true. With it false,
    | a task routed to Claude is refused before anything is sent.
    */
    'allow_external' => (bool) env('AI_ALLOW_EXTERNAL', false),

    /*
    | How an AI answer's claims are labelled. "separate": the writer writes only
    | the text and the faster claim_check task labels its claims afterwards (on
    | a 4 GB GPU the 7B writer runs at ~5 tokens/s, the 3B checker at ~70, so
    | this roughly halves a draft). "inline": the writer lists and labels its
    | claims itself (answer_writing v4).
    */
    'answer_claims' => env('AI_ANSWER_CLAIMS', 'separate'),

    'providers' => [
        'ollama' => [
            'url' => env('OLLAMA_URL', 'http://127.0.0.1:11434'),
            // Keep the model loaded between calls (Ollama keep_alive).
            'keep_alive' => env('OLLAMA_KEEP_ALIVE', '10m'),
            'external' => false,
        ],
        'claude' => [
            'url' => env('ANTHROPIC_URL', 'https://api.anthropic.com'),
            'api_key' => env('ANTHROPIC_API_KEY'),
            'version' => env('ANTHROPIC_VERSION', '2023-06-01'),
            'external' => true,
        ],
    ],

    /*
    | Per task: provider (null = default), model, output limit, temperature,
    | timeout and the local model's context window (prompt + output; Ollama's
    | own default of 4096 silently cuts longer prompts). Defaults suit an RTX
    | 3050 laptop GPU (4 GB VRAM): 3B models fit entirely; the 7B writer partly
    | runs on the CPU. One window size for all tasks keeps Ollama from reloading
    | a model to resize it.
    */
    'tasks' => [
        'jd_analysis' => [
            'provider' => env('AI_JD_ANALYSIS_PROVIDER'),
            'models' => [
                'ollama' => env('AI_JD_ANALYSIS_OLLAMA_MODEL', 'qwen2.5:3b'),
                'claude' => env('AI_JD_ANALYSIS_CLAUDE_MODEL', 'claude-haiku-5-5'),
            ],
            'max_tokens' => 2000,
            'temperature' => 0.0,
            'timeout' => 180,
            'context' => $context,
        ],
        'answer_writing' => [
            'provider' => env('AI_ANSWER_WRITING_PROVIDER'),
            'models' => [
                'ollama' => env('AI_ANSWER_WRITING_OLLAMA_MODEL', 'qwen2.5:7b'),
                'claude' => env('AI_ANSWER_WRITING_CLAUDE_MODEL', 'claude-sonnet-5-5'),
            ],
            'max_tokens' => 2500,
            'temperature' => 0.4,
            'timeout' => 300,
            'context' => $context,
        ],
        'resume_tailoring' => [
            'provider' => env('AI_RESUME_TAILORING_PROVIDER'),
            'models' => [
                'ollama' => env('AI_RESUME_TAILORING_OLLAMA_MODEL', 'qwen2.5:7b'),
                'claude' => env('AI_RESUME_TAILORING_CLAUDE_MODEL', 'claude-sonnet-5-5'),
            ],
            'max_tokens' => 4000,
            'temperature' => 0.2,
            'timeout' => 420,
            'context' => $context,
        ],
        'achievement_suggestions' => [
            'provider' => env('AI_ACHIEVEMENT_SUGGESTIONS_PROVIDER'),
            'models' => [
                'ollama' => env('AI_ACHIEVEMENT_SUGGESTIONS_OLLAMA_MODEL', 'qwen2.5:3b'),
                'claude' => env('AI_ACHIEVEMENT_SUGGESTIONS_CLAUDE_MODEL', 'claude-haiku-5-5'),
            ],
            'max_tokens' => 2000,
            'temperature' => 0.2,
            'timeout' => 180,
            'context' => $context,
        ],
        'feedback_analysis' => [
            'provider' => env('AI_FEEDBACK_ANALYSIS_PROVIDER'),
            'models' => [
                'ollama' => env('AI_FEEDBACK_ANALYSIS_OLLAMA_MODEL', 'qwen2.5:3b'),
                'claude' => env('AI_FEEDBACK_ANALYSIS_CLAUDE_MODEL', 'claude-sonnet-5-5'),
            ],
            'max_tokens' => 1500,
            'temperature' => 0.0,
            'timeout' => 180,
            'context' => $context,
        ],
        'claim_check' => [
            'provider' => env('AI_CLAIM_CHECK_PROVIDER'),
            'models' => [
                'ollama' => env('AI_CLAIM_CHECK_OLLAMA_MODEL', 'qwen2.5:3b'),
                'claude' => env('AI_CLAIM_CHECK_CLAUDE_MODEL', 'claude-haiku-5-5'),
            ],
            'max_tokens' => 1500,
            'temperature' => 0.0,
            'timeout' => 180,
            'context' => $context,
        ],
    ],

    'retries' => [
        // Attempts per call, including the first (429 / 5xx / overloaded / connection errors).
        'max_attempts' => (int) env('AI_MAX_ATTEMPTS', 3),
        'base_delay_ms' => 1000,
        'max_delay_ms' => 20000,
    ],

    /*
    | Prices in micro-dollars per million tokens, for the budget guard.
    | [VERIFY] against the provider's current price list before relying on it.
    | Local models cost nothing.
    */
    'prices' => [
        'claude-haiku-5-5' => ['input' => 1_000_000, 'output' => 5_000_000],
        'claude-sonnet-5-5' => ['input' => 3_000_000, 'output' => 15_000_000],
    ],

    // generation_payloads (encrypted prompt + response bodies, for debugging) are kept this long.
    'payload_retention_days' => 14,

];
