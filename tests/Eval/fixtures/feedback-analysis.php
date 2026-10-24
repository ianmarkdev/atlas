<?php

/**
 * Feedback-analysis eval (M10): synthetic edits of AI drafts, each showing one lesson (or none),
 * with the writing rules in place (`rules`, id => [kind, rule, drafts?], shown as R{id}) and the
 * rules held from earlier edits (`held`, id => rule). `expect` says what the AI should judge:
 *
 * - `rule`: whether the edit shows a writing rule at all (false: a one-off, nothing to learn);
 * - `same_as`: the held (H…) or active (R…) rule it repeats, or null when it is a new rule —
 *   a wrong id promotes a held rule or counts a miss that did not happen;
 * - `conflicts_with`: the active rule it contradicts, or null — a wrong id offers to replace a
 *   rule that should stay;
 * - `drafts`: where the rule belongs (all, cover_letter, question).
 *
 * `category` groups the report: paraphrase, distinct, missed, contradiction, one_off, scope.
 */
return [
    'paraphrase-british-spelling' => [
        'category' => 'paraphrase',
        'question' => 'Tell us about a project you led.',
        'original' => 'I organized the migration of our billing service and optimized the CI pipeline, which our team prioritized for the quarter.',
        'edited' => 'I organised the migration of our billing service and optimised the CI pipeline, which our team prioritised for the quarter.',
        'held' => ['H101.0' => 'Use British spelling'],
        'expect' => ['rule' => true, 'same_as' => 'H101.0', 'conflicts_with' => null],
    ],
    'paraphrase-passionate' => [
        'category' => 'paraphrase',
        'question' => 'Why do you want this role?',
        'original' => 'I am passionate about developer tooling and I am passionate about helping teams ship faster.',
        'edited' => 'I enjoy building developer tooling and helping teams ship faster.',
        'held' => ['H102.0' => 'Never describe the candidate as passionate'],
        'expect' => ['rule' => true, 'same_as' => 'H102.0', 'conflicts_with' => null],
    ],
    'paraphrase-no-bullets' => [
        'category' => 'paraphrase',
        'question' => 'Describe your experience with PostgreSQL.',
        'original' => "- Tuned slow queries\n- Designed schemas for billing\n- Ran upgrades from 12 to 16",
        'edited' => 'I have tuned slow queries, designed the schemas for our billing platform and run upgrades from PostgreSQL 12 to 16.',
        'held' => ['H103.0' => 'Write in full sentences, not bullet points'],
        'expect' => ['rule' => true, 'same_as' => 'H103.0', 'conflicts_with' => null],
    ],
    'paraphrase-active-voice' => [
        'category' => 'paraphrase',
        'question' => 'Tell us about a time you improved a process.',
        'original' => 'I was responsible for the release process. I was in charge of reducing failed deploys.',
        'edited' => 'I ran the release process and cut failed deploys.',
        'held' => ['H104.0' => 'Say what I did with active verbs instead of "I was responsible for"'],
        'expect' => ['rule' => true, 'same_as' => 'H104.0', 'conflicts_with' => null],
    ],
    'distinct-tone-vs-spelling' => [
        'category' => 'distinct',
        'question' => 'Why do you want to work here?',
        'original' => "I'm super excited about this awesome opportunity and I think your team is amazing!",
        'edited' => 'I would welcome the chance to join your team; your work on accessible payments matches what I want to do next.',
        'held' => ['H201.0' => 'Use British spelling'],
        'expect' => ['rule' => true, 'same_as' => null, 'conflicts_with' => null],
    ],
    'distinct-other-banned-word' => [
        'category' => 'distinct',
        'question' => 'How do you work with other teams?',
        'original' => 'I create synergy between product and engineering by leveraging synergy in planning.',
        'edited' => 'I bring product and engineering together in planning, so both agree on what ships first.',
        'held' => ['H202.0' => 'Never use the word passionate'],
        'expect' => ['rule' => true, 'same_as' => null, 'conflicts_with' => null],
    ],
    'distinct-short-paragraphs' => [
        'category' => 'distinct',
        'question' => 'Tell us about your biggest achievement.',
        'original' => 'Last year I led the move of our payments platform to a new processor, which meant planning the cut-over with finance, writing the migration scripts, running a two-week dual-write period, and finally switching traffic over a weekend with no failed payments, which the business had said was the most important result.',
        'edited' => "Last year I led the move of our payments platform to a new processor.\n\nI planned the cut-over with finance, wrote the migration scripts and ran two weeks of dual writes. We switched traffic over a weekend with no failed payments.",
        'held' => ['H203.0' => 'Write in full sentences, not bullet points'],
        'expect' => ['rule' => true, 'same_as' => null, 'conflicts_with' => null],
    ],
    'missed-british-spelling' => [
        'category' => 'missed',
        'question' => 'Tell us about a project you led.',
        'original' => 'I analyzed our error budget and organized a reliability review.',
        'edited' => 'I analysed our error budget and organised a reliability review.',
        'rules' => [11 => ['spelling', 'Use British spelling']],
        'expect' => ['rule' => true, 'same_as' => 'R11', 'conflicts_with' => null],
    ],
    'missed-passionate' => [
        'category' => 'missed',
        'question' => 'What motivates you?',
        'original' => 'I am passionate about clean code.',
        'edited' => 'I care about code that the next person can read.',
        'rules' => [12 => ['banned_phrase', 'Never use the word passionate'], 13 => ['spelling', 'Use British spelling']],
        'expect' => ['rule' => true, 'same_as' => 'R12', 'conflicts_with' => null],
    ],
    'contradiction-length' => [
        'category' => 'contradiction',
        'question' => 'Tell us about a time you handled an incident.',
        'original' => 'I handled a database outage and restored service quickly.',
        'edited' => 'When our primary database ran out of disk at 2 a.m., I failed over to the replica, restored writes within 12 minutes, and then led the review that added disk alerts and weekly capacity checks. Since then we have had no disk-related outages.',
        'rules' => [21 => ['format', 'Keep answers short: two sentences at most']],
        'expect' => ['rule' => true, 'same_as' => null, 'conflicts_with' => 'R21'],
    ],
    'contradiction-formality' => [
        'category' => 'contradiction',
        'question' => 'Why are you a good fit?',
        'original' => 'I am confident that I would be a strong fit. I have worked with Laravel for six years and I have led two teams.',
        'edited' => "I'd be a great fit: I've worked with Laravel for six years and I've led two teams, so I'm used to both the code and the people side.",
        'rules' => [22 => ['tone', 'Write formally, without contractions']],
        'expect' => ['rule' => true, 'same_as' => null, 'conflicts_with' => 'R22'],
    ],
    'unrelated-with-rule-in-place' => [
        'category' => 'distinct',
        'question' => 'Why do you want this role?',
        'original' => 'I love your product! I have used it for years! I would love to help build it!',
        'edited' => 'I have used your product for years and would like to help build it.',
        'rules' => [31 => ['spelling', 'Use British spelling']],
        'expect' => ['rule' => true, 'same_as' => null, 'conflicts_with' => null],
    ],
    'one-off-company-name' => [
        'category' => 'one_off',
        'question' => 'Why do you want to work here?',
        'original' => 'I want to join Contoso because of its work on open banking.',
        'edited' => 'I want to join Fabrikam because of its work on open banking.',
        'rules' => [41 => ['spelling', 'Use British spelling']],
        'expect' => ['rule' => false],
    ],
    'one-off-job-detail' => [
        'category' => 'one_off',
        'question' => 'Are you able to work from our Leeds office?',
        'original' => 'Yes, I can work from your Leeds office three days a week.',
        'edited' => 'Yes, I can work from your Leeds office two days a week, as agreed with the recruiter.',
        'expect' => ['rule' => false],
    ],
    'scope-cover-letter-sign-off' => [
        'category' => 'scope',
        'cover_letter' => true,
        'original' => "Dear Hiring Manager,\n\nI am applying for the Senior Engineer role.\n\nSincerely,\nJane Doe",
        'edited' => "Dear Hiring Manager,\n\nI am applying for the Senior Engineer role.\n\nBest wishes,\nJane",
        'expect' => ['rule' => true, 'same_as' => null, 'drafts' => 'cover_letter'],
    ],
    'scope-spelling-everywhere' => [
        'category' => 'scope',
        'cover_letter' => true,
        'original' => "Dear Hiring Manager,\n\nI have specialized in payment systems and I prioritize reliability.\n\nBest wishes,\nJane",
        'edited' => "Dear Hiring Manager,\n\nI have specialised in payment systems and I prioritise reliability.\n\nBest wishes,\nJane",
        'expect' => ['rule' => true, 'same_as' => null, 'drafts' => 'all'],
    ],
];
