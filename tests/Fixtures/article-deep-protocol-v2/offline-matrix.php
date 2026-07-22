<?php

$evidenceId = 'KB:9001:CHUNK:0:0123456789abcdef';
$fixtures = [];

for ($index = 1; $index <= 10; $index++) {
    $language = $index % 2 === 0 ? 'zh' : 'en';
    $fixtures[] = [
        'key' => 'sufficient_'.$language.'_'.$index,
        'expected_outcome' => 'sufficient',
        'allowed_evidence_ids' => [$evidenceId],
        'plan' => [
            'reader_question' => $language === 'zh'
                ? '现有证据支持读者做出什么判断？'
                : 'What decision can the reader make from the available evidence?',
            'answer_mode' => 'direct',
            'evidence_sufficiency' => 'sufficient',
            'supported_sections' => [[
                'purpose' => $language === 'zh'
                    ? '解释证据直接支持的选择条件'
                    : 'Explain the selection condition directly supported by evidence',
                'support_type' => 'evidence',
                'evidence_refs' => [$evidenceId],
            ]],
            'evidence_mapping' => [[
                'claim_scope' => $language === 'zh' ? '已验证的选择条件' : 'Verified selection condition',
                'evidence_refs' => [$evidenceId],
            ]],
            'optional_modules' => $index % 3 === 0 ? ['comparison_table'] : [],
            'unsupported_claims_to_avoid' => ['Unsupported capacity claim'],
            'verification_items' => [],
        ],
    ];
}

for ($index = 1; $index <= 10; $index++) {
    $language = $index % 2 === 0 ? 'zh' : 'en';
    $fixtures[] = [
        'key' => 'limited_'.$language.'_'.$index,
        'expected_outcome' => 'limited',
        'allowed_evidence_ids' => [$evidenceId],
        'plan' => [
            'reader_question' => $language === 'zh'
                ? '现有资料能回答哪些部分，哪些条件仍需确认？'
                : 'What can be answered now and which conditions still need confirmation?',
            'answer_mode' => $index % 2 === 0 ? 'conditional' : 'evidence_limited',
            'evidence_sufficiency' => 'limited',
            'supported_sections' => [[
                'purpose' => $language === 'zh'
                    ? '说明当前证据能够支持的有限结论'
                    : 'State the limited conclusion supported by current evidence',
                'support_type' => 'evidence',
                'evidence_refs' => [$evidenceId],
            ]],
            'evidence_mapping' => [[
                'claim_scope' => $language === 'zh' ? '当前可确认范围' : 'Currently verifiable scope',
                'evidence_refs' => [$evidenceId],
            ]],
            'optional_modules' => [],
            'unsupported_claims_to_avoid' => ['Unverified compatibility conclusion'],
            'verification_items' => [[
                'question' => $language === 'zh'
                    ? '实际材料兼容性是否已经测试？'
                    : 'Has compatibility with the actual material been tested?',
                'category' => 'compatibility',
                'required_for_draft' => false,
            ]],
        ],
    ];
}

for ($index = 1; $index <= 10; $index++) {
    $language = $index % 2 === 0 ? 'zh' : 'en';
    $categories = ['specification', 'compatibility', 'process', 'integration', 'safety', 'commercial', 'case_evidence', 'other'];
    $fixtures[] = [
        'key' => 'insufficient_'.$language.'_'.$index,
        'expected_outcome' => 'insufficient',
        'allowed_evidence_ids' => [$evidenceId],
        'plan' => [
            'reader_question' => $language === 'zh'
                ? '回答标题前必须补充什么资料？'
                : 'What information is required before the title can be answered?',
            'answer_mode' => 'stop',
            'evidence_sufficiency' => 'insufficient',
            'supported_sections' => [],
            'evidence_mapping' => [],
            'optional_modules' => [],
            'unsupported_claims_to_avoid' => ['Any unsupported recommendation'],
            'verification_items' => [[
                'question' => $language === 'zh'
                    ? '缺失的关键事实能否由可信来源确认？'
                    : 'Can the missing critical fact be confirmed by an eligible source?',
                'category' => $categories[($index - 1) % count($categories)],
                'required_for_draft' => true,
            ]],
        ],
    ];
}

return $fixtures;
