<?php

/**
 * Retrieval eval fixture (M6): two candidates in separate workspaces and queries written the
 * way job descriptions phrase requirements, each with the facts it should retrieve for Alex.
 * Sam has overlapping topics on purpose: any Sam fact in Alex's results is a leak.
 *
 * Keys are `candidate.item`. Bullet points ("claims") are the units scored; job and project
 * summaries may appear but do not count as relevant unless listed.
 */
return [
    'candidates' => [
        'alex' => [
            'jobs' => [
                'northwind' => [
                    'employer' => 'Northwind Payments', 'title' => 'Senior Backend Developer', 'start' => '2021-03-01', 'end' => null,
                    'location' => 'London (hybrid)',
                    'summary' => 'Backend lead for the merchant billing platform used by 40,000 small businesses.',
                    'claims' => [
                        'invoice-run' => 'Cut the nightly invoice run from 4 hours to 20 minutes by moving it to queued, idempotent jobs.',
                        'mentoring' => 'Mentored two junior developers through their first production releases.',
                        'on-call' => 'Set up the on-call rota and runbooks; reduced pages per week from 15 to 3.',
                        'postgres-tuning' => 'Tuned PostgreSQL indexes and queries, halving p95 API latency.',
                        'pci' => 'Led the PCI DSS scope reduction by tokenising card data with the payment provider.',
                        'code-review' => 'Introduced a code review checklist and pairing sessions for the backend team of six.',
                    ],
                    'projects' => [
                        'billing-api' => [
                            'name' => 'Billing API v2', 'role' => 'Tech lead',
                            'summary' => 'Rebuilt the public billing API with versioning and webhooks.',
                            'claims' => [
                                'webhooks' => 'Designed webhook delivery with retries, signatures and a dead-letter queue.',
                                'api-versioning' => 'Introduced date-based API versioning so integrators could upgrade at their own pace.',
                                'openapi' => 'Published OpenAPI documentation generated from the code, cutting integration support tickets by 30%.',
                            ],
                        ],
                    ],
                ],
                'contoso' => [
                    'employer' => 'Contoso Retail', 'title' => 'PHP Developer', 'start' => '2017-09-01', 'end' => '2021-02-28',
                    'location' => 'Manchester',
                    'summary' => 'Built and maintained the e-commerce platform and its integrations.',
                    'claims' => [
                        'rails-migration' => 'Migrated the order service from a legacy Rails app to Laravel without downtime.',
                        'erp-integration' => 'Integrated the shop with the SAP ERP for stock and orders using nightly and real-time sync.',
                        'black-friday' => 'Prepared the site for Black Friday load: caching and load tests for 10x normal traffic.',
                        'test-coverage' => 'Raised automated test coverage of the checkout from 20% to 75% with PHPUnit.',
                        'react-admin' => 'Built an internal React admin for customer service, replacing three spreadsheets.',
                    ],
                    'projects' => [],
                ],
                'freelance' => [
                    'employer' => 'Self-employed', 'title' => 'Freelance Web Developer', 'start' => '2015-01-01', 'end' => '2017-08-31',
                    'location' => 'Remote',
                    'summary' => 'Websites and small web apps for local businesses.',
                    'claims' => [
                        'wordpress-sites' => 'Delivered 25 WordPress sites for local businesses, from design to hosting.',
                        'booking-app' => 'Built a booking app for a physiotherapy clinic that replaced phone bookings.',
                    ],
                    'projects' => [],
                ],
            ],
            'general' => [
                'german' => 'Speaks fluent German (C1) and works with the Berlin office in German.',
                'aws-cert' => 'Holds the AWS Certified Solutions Architect – Associate certification.',
                'conference-talk' => 'Gave a talk on idempotent job design at PHP UK Conference 2023.',
                'docker' => 'Containerised the development environment with Docker so new developers set up in an hour.',
            ],
            'answers' => [
                'notice' => ['q' => 'What is your notice period?', 'a' => 'One month.'],
                'why-payments' => ['q' => 'Why do you want to work in payments?', 'a' => 'I like work where reliability matters and mistakes are expensive; payments is exactly that.'],
            ],
        ],
        'sam' => [
            'jobs' => [
                'fabrikam' => [
                    'employer' => 'Fabrikam Logistics', 'title' => 'Platform Engineer', 'start' => '2019-01-01', 'end' => null,
                    'location' => 'Berlin',
                    'summary' => 'Runs the Kubernetes platform and the event pipeline.',
                    'claims' => [
                        'kafka' => 'Built the Kafka event pipeline that moves shipment events between services.',
                        'kubernetes' => 'Migrated 40 services to Kubernetes with zero downtime.',
                        'batch-speedup' => 'Reduced the nightly batch processing time from 6 hours to 45 minutes.',
                        'mentoring' => 'Mentored three junior engineers in the platform team.',
                        'postgres' => 'Tuned PostgreSQL replication and query plans for the tracking database.',
                    ],
                    'projects' => [],
                ],
            ],
            'general' => [
                'german' => 'Native German speaker.',
            ],
            'answers' => [],
        ],
    ],

    'queries' => [
        ['q' => 'Experience improving the performance of batch processing and background jobs', 'relevant' => ['alex.invoice-run', 'alex.conference-talk']],
        ['q' => 'You have coached or mentored less experienced engineers', 'relevant' => ['alex.mentoring', 'alex.code-review']],
        ['q' => 'Strong SQL and relational database performance tuning', 'relevant' => ['alex.postgres-tuning']],
        ['q' => 'Designing reliable public APIs and integrations for third-party developers', 'relevant' => ['alex.webhooks', 'alex.api-versioning', 'alex.openapi']],
        ['q' => 'Knowledge of payment card security and compliance', 'relevant' => ['alex.pci']],
        ['q' => 'Experience with incident response, on-call and operational excellence', 'relevant' => ['alex.on-call']],
        ['q' => 'Migrating legacy systems without downtime', 'relevant' => ['alex.rails-migration']],
        ['q' => 'Integrating with ERP systems such as SAP', 'relevant' => ['alex.erp-integration']],
        ['q' => 'Scaling a website for peak traffic events', 'relevant' => ['alex.black-friday']],
        ['q' => 'Writing automated tests and improving code quality', 'relevant' => ['alex.test-coverage', 'alex.code-review']],
        ['q' => 'Frontend experience with React', 'relevant' => ['alex.react-admin']],
        ['q' => 'German language skills are a plus', 'relevant' => ['alex.german']],
        ['q' => 'Cloud certification, ideally AWS', 'relevant' => ['alex.aws-cert']],
        ['q' => 'Comfortable with containers and developer tooling', 'relevant' => ['alex.docker']],
        ['q' => 'Public speaking or sharing knowledge with the community', 'relevant' => ['alex.conference-talk']],
        ['q' => 'Building web applications for small business clients', 'relevant' => ['alex.wordpress-sites', 'alex.booking-app']],
    ],
];
