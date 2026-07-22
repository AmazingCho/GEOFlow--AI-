<?php

$scores = static fn (int $value = 5): array => array_fill_keys([
    'factual_support',
    'clarity',
    'buyer_decision_value',
    'structure_naturalness',
    'uncertainty_and_negative_fit',
    'privacy_and_safety',
    'style_fitness',
    'non_template_naturalness',
], $value);

$artifact = static fn (string $id, string $cohort, string $pairKey, array $artifactScores): array => [
    'artifact_id' => $id,
    'cohort' => $cohort,
    'pair_key' => $pairKey,
    'workflow_mode' => 'single_turn',
    'scores' => $artifactScores,
];

return [
    'grounding' => [
        'supported measurement' => [
            'content' => 'The verified travel is 300 mm.',
            'evidence' => 'The verified travel is 300 mm.',
            'outcome' => 'pass',
            'issue_code' => null,
        ],
        'limited evidence remains pending even when its numeric claim is supported' => [
            'content' => 'The verified travel is 300 mm.',
            'evidence' => 'The verified travel is 300 mm.',
            'claim_analysis' => ['evidence_sufficiency' => 'limited'],
            'outcome' => 'pending_review',
            'issue_code' => 'limited_evidence_delivery',
        ],
        'unsupported measurement' => [
            'content' => 'The system handles 500 kg.',
            'evidence' => 'The verified travel is 300 mm.',
            'outcome' => 'blocked',
            'issue_code' => 'unsupported_numeric_unit',
        ],
        'decimal and thousands separators stay distinct' => [
            'content' => 'The machine weighs 1.200 kg.',
            'evidence' => 'The verified machine weight is 1,200 kg.',
            'outcome' => 'blocked',
            'issue_code' => 'unsupported_numeric_unit',
        ],
        'multi-group thousands stay one number' => [
            'content' => 'The machine handles 200,000 kg.',
            'evidence' => 'The documented limit is 1,200,000 kg.',
            'outcome' => 'blocked',
            'issue_code' => 'unsupported_numeric_unit',
        ],
        'model date list and page numbers are harmless' => [
            'content' => "1. Check the process.\nDate: 2026-07-21.\nPage 2 of 3.\nModel MX-100.",
            'evidence' => 'General selection guidance.',
            'outcome' => 'pass',
            'issue_code' => null,
        ],
        'labeled contact number is private' => [
            'content' => 'Customer WhatsApp number: 13800138000.',
            'evidence' => 'General selection guidance.',
            'outcome' => 'blocked',
            'issue_code' => 'privacy_contact_exposure',
        ],
        'unsafe instruction is blocked' => [
            'content' => 'Disable the safety interlock before servicing.',
            'evidence' => 'General maintenance safety guidance.',
            'outcome' => 'blocked',
            'issue_code' => 'unsafe_operational_instruction',
        ],
        'negated warning is allowed' => [
            'content' => 'Never disable the safety interlock before servicing.',
            'evidence' => 'General maintenance safety guidance.',
            'outcome' => 'pass',
            'issue_code' => null,
        ],
        'earlier negation does not hide later unsafe clause' => [
            'content' => 'Do not touch the display, then disable the safety interlock before servicing.',
            'evidence' => 'General maintenance safety guidance.',
            'outcome' => 'blocked',
            'issue_code' => 'unsafe_operational_instruction',
        ],
        'while clause does not inherit unrelated negation' => [
            'content' => 'Do not disable the display while you remove the safety guard.',
            'evidence' => 'General maintenance safety guidance.',
            'outcome' => 'blocked',
            'issue_code' => 'unsafe_operational_instruction',
        ],
    ],
    'markers' => [
        'valid' => [
            'article' => "The verified travel is 300 mm.\n<!-- evidence:{EVIDENCE_ID} -->",
            'coverage_status' => 'complete',
            'throws' => false,
        ],
        'missing' => [
            'article' => 'The machine can process abrasive material.',
            'coverage_status' => 'partial',
            'throws' => false,
        ],
        'unknown' => [
            'article' => "The verified travel is 300 mm.\n<!-- evidence:{UNKNOWN_ID} -->",
            'coverage_status' => null,
            'throws' => true,
        ],
    ],
    'publication' => [
        'revoked approval' => [
            'review_status' => 'pending',
            'grounding_outcome' => 'pass',
            'allowed' => false,
            'reason_code' => 'review_required',
        ],
        'automatic approval cannot override grounding review' => [
            'review_status' => 'auto_approved',
            'grounding_outcome' => 'pending_review',
            'allowed' => false,
            'reason_code' => 'grounding_review_required',
        ],
        'explicit approval resolves grounding review' => [
            'review_status' => 'approved',
            'grounding_outcome' => 'pending_review',
            'approval_bound_to_current_revision' => true,
            'allowed' => true,
            'reason_code' => null,
        ],
    ],
    'release' => [
        'paired cohort with style-only diagnostic row' => [
            'artifacts' => [
                $artifact('candidate-a', 'candidate', 'pair-a', $scores()),
                $artifact('control-a', 'control', 'pair-a', $scores()),
                [
                    'artifact_id' => 'style-a',
                    'cohort' => 'style',
                    'style_matrix_key' => 'technical_clarity',
                    'workflow_mode' => 'single_turn',
                    'scores' => $scores(1),
                ],
            ],
            'expected_pair_keys' => ['pair-a'],
            'valid' => true,
            'decision' => 'manual_approval_still_required',
            'issue' => null,
        ],
        'unpaired candidate' => [
            'artifacts' => [
                $artifact('candidate-a', 'candidate', 'pair-a', $scores()),
            ],
            'expected_pair_keys' => ['pair-a'],
            'valid' => false,
            'decision' => 'no_go',
            'issue' => 'incomplete_pair:pair-a',
        ],
    ],
    'prompt_changed_keys' => [
        'article.master.trust_based',
        'article.skill.application',
        'article.skill.case_study',
        'article.skill.comparison',
    ],
];
