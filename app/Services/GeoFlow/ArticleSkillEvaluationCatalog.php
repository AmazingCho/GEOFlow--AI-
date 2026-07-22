<?php

namespace App\Services\GeoFlow;

use App\Support\GeoFlow\ArticleSkillIntents;

class ArticleSkillEvaluationCatalog
{
    /** @return array{name:string,provider:string,temperature:int,max_output_tokens:int,is_real_model:bool} */
    public function model(): array
    {
        return [
            'name' => 'offline-fixture-v1',
            'provider' => 'deterministic_fixture',
            'temperature' => 0,
            'max_output_tokens' => 1800,
            'is_real_model' => false,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function cases(): array
    {
        return [
            $this->case('comparison-clear', ArticleSkillIntents::COMPARISON, 'clear', 'Air-Cooled vs Water-Cooled Chillers', 'industrial chiller comparison'),
            $this->case('comparison-boundary', ArticleSkillIntents::COMPARISON, 'boundary', 'Air-Cooled vs Water-Cooled Chillers: Selection Guide', 'chiller selection considerations'),
            $this->case('buying-guide-clear', ArticleSkillIntents::BUYING_GUIDE, 'clear', 'How to Choose an Industrial Chiller', 'industrial chiller selection'),
            $this->case('buying-guide-boundary', ArticleSkillIntents::BUYING_GUIDE, 'boundary', 'Industrial Chiller Selection Guide', 'cooling system configuration'),
            $this->case('application-clear', ArticleSkillIntents::APPLICATION, 'clear', 'Industrial Chiller Applications in Battery Manufacturing', 'battery manufacturing cooling application'),
            $this->case('application-boundary', ArticleSkillIntents::APPLICATION, 'boundary', 'Manufacturing Application Solution for Semiconductor Cooling', 'semiconductor process cooling'),
            $this->case('technical-clear', ArticleSkillIntents::TECHNICAL, 'clear', 'How Does a Two-Component Dispensing Valve Work', 'dispensing valve mechanism'),
            $this->case('technical-boundary', ArticleSkillIntents::TECHNICAL, 'boundary', 'What Is the Working Principle of a Two-Component Dispensing Valve', 'dispensing valve basics'),
            $this->case('troubleshooting-clear', ArticleSkillIntents::TROUBLESHOOTING, 'clear', 'Troubleshooting a Clogged Dispensing Needle', 'dispensing needle fault', [
                'requires_safety_escalation' => true,
            ]),
            $this->case('troubleshooting-boundary', ArticleSkillIntents::TROUBLESHOOTING, 'boundary', 'Preventive Maintenance for Dispensing Equipment', 'dispensing machine maintenance', [
                'requires_safety_escalation' => true,
            ]),
            $this->case('case-study-clear', ArticleSkillIntents::CASE_STUDY, 'clear', 'Customer Case Study: Dispensing Line Upgrade', 'dispensing project result', [
                'evidence_state' => 'unverified',
                'publication_scope' => 'not_approved',
                'restricted_terms' => ['Secret Customer Ltd.'],
            ]),
            $this->case('case-study-boundary', ArticleSkillIntents::CASE_STUDY, 'boundary', 'Project Implementation Result: Automated Potting Line', 'potting line customer story', [
                'evidence_state' => 'unverified',
                'publication_scope' => 'not_approved',
                'restricted_terms' => ['Confidential Plant Name'],
            ]),
            $this->case('definition-clear', ArticleSkillIntents::DEFINITION, 'clear', 'What Is Industrial Process Cooling', 'industrial cooling definition'),
            $this->case('definition-boundary', ArticleSkillIntents::DEFINITION, 'boundary', 'Beginner Guide to Industrial Process Cooling', 'industrial cooling basics'),
            [
                'id' => 'master-only-control',
                'expected_intent' => null,
                'variant' => 'control',
                'title' => '工业冷却系统概览',
                'keyword' => '工业冷却系统',
                'language' => 'zh-CN',
                'expected_status' => 'master_only',
                'metadata' => [],
            ],
        ];
    }

    /** @return list<array{case_id:string,content:string}> */
    public function outputs(): array
    {
        return array_map(function (array $case): array {
            $intent = (string) ($case['expected_intent'] ?? 'master_only');

            return [
                'case_id' => (string) $case['id'],
                'content' => $this->fixtureContent($intent, (string) $case['language']),
            ];
        }, $this->cases());
    }

    /** @param array<string,mixed> $metadata @return array<string,mixed> */
    private function case(string $id, string $intent, string $variant, string $title, string $keyword, array $metadata = []): array
    {
        return [
            'id' => $id,
            'expected_intent' => $intent,
            'variant' => $variant,
            'title' => $title,
            'keyword' => $keyword,
            'language' => 'en',
            'expected_status' => in_array($intent, ArticleSkillIntents::autoEligible(), true) ? 'recommended' : 'blocked',
            'metadata' => $metadata,
        ];
    }

    private function fixtureContent(string $intent, string $language): string
    {
        if ($language === 'zh-CN') {
            return <<<'MD'
## 适用范围

工业冷却系统概览应先说明工艺目标、系统边界和选型所需资料。不能把某一种通用配置描述成适合所有工厂的固定答案。

## 核对信息

需要核对实际热负荷、目标温度、现场公用工程、环境条件、维护空间和运行时间。尚未确认的数据应明确标记为假设，不能直接写成事实。

## 下一步

先收集经过测量的工艺数据，并与供应商提供的设备运行范围进行对照。完成核对后，再判断是否需要进入详细选型、应用分析或现场测试流程。
MD;
        }

        return match ($intent) {
            ArticleSkillIntents::COMPARISON => <<<'MD'
## Decision criteria

The useful comparison begins with installation limits, ambient conditions, water availability, maintenance capacity, and expected heat load. These criteria matter more than selecting a technology from its name alone.

## Practical trade-offs

Air-cooled systems simplify water infrastructure but depend more directly on ambient air conditions. Water-cooled systems can support demanding conditions, yet they add water quality, piping, and maintenance requirements that must be confirmed before purchase.

## Next decision

Request a proposal only after the supplier receives the process load, target temperature, site utilities, and operating schedule. A selection made without these inputs should be treated as provisional rather than a final engineering recommendation.
MD,
            ArticleSkillIntents::BUYING_GUIDE => <<<'MD'
## Start with the process requirement

Define the process heat load, target supply temperature, allowable temperature variation, ambient conditions, and operating hours. These inputs determine the selection range and reveal whether standard equipment is suitable.

## Check the installation boundary

Confirm available power, ventilation, water quality, space, noise limits, and service access before comparing quotations. A lower purchase price may not be useful when the site requires additional infrastructure or cannot support routine maintenance.

## Prepare the supplier brief

Send measured process data and identify any uncertain assumptions. Ask the supplier to state exclusions, safety margins, and the conditions under which a different configuration would be required.
MD,
            ArticleSkillIntents::APPLICATION => <<<'MD'
## Application requirement

Process cooling can stabilize temperature-sensitive production steps when heat varies with throughput, equipment load, or ambient conditions. The design should begin with the actual process boundary instead of a generic industry label.

## Integration points

Review the heat source, coolant path, control signal, response time, contamination risk, and maintenance access. These details determine whether the cooling equipment can integrate with the production line without creating a new bottleneck.

## Suitability check

The application is a stronger fit when the load and temperature range are measurable and utilities are known. If the process data is incomplete, use a site survey or monitored trial before treating any configuration as final.
MD,
            ArticleSkillIntents::TECHNICAL => <<<'MD'
## Functional sequence

The mechanism receives material, meters or controls the flow, and delivers it through the dispensing path. Each component affects pressure stability, repeatability, and the response seen at the outlet.

## Variables that change behavior

Viscosity, temperature, pressure, valve timing, tubing length, and needle geometry can change the delivered amount. A working-principle explanation should therefore separate the component function from the process settings used for a specific material.

## Verification method

Confirm operation with the supplier diagram and a controlled dispensing test. Do not infer performance limits from the mechanism alone when the source does not provide validated ranges.
MD,
            ArticleSkillIntents::TROUBLESHOOTING => <<<'MD'
## Safe first checks

Stop dispensing and isolate pressure before inspecting the needle or material path. Disconnect power when the equipment manual requires de-energization, and wear the protective equipment specified for the material.

## Evidence-based inspection

Check the alarm record, material condition, approved cleaning method, pressure setting, and replaceable outlet parts without bypassing guards or interlocks. Record each observation so the next action is based on evidence rather than repeated adjustments.

## Escalation boundary

Stop the procedure if electrical access, stored pressure, hot surfaces, chemical exposure, or guard removal is involved. Escalate those conditions to a qualified technician or the equipment supplier instead of publishing unsupported repair steps.
MD,
            ArticleSkillIntents::CASE_STUDY => <<<'MD'
## Evidence boundary

This offline fixture represents an anonymized evaluation scenario, not a publishable customer claim. No customer identity, unsupported metric, or final result should be stated until source evidence and publication approval are verified.

## Scenario and response

The scenario describes a production team reviewing a more controlled dispensing workflow. The proposed response focuses on requirement capture, a controlled trial, operator training, and acceptance criteria rather than claiming an unverified commercial outcome.

## What remains unconfirmed

The final configuration, measured improvement, customer approval, and publication scope remain unknown. A real case article must stay blocked until those items are reviewed and documented.
MD,
            ArticleSkillIntents::DEFINITION => <<<'MD'
## Core meaning

Industrial process cooling removes or transfers heat so a production process can remain within a required temperature range. It supports the process rather than serving only general room comfort.

## Where it fits

The cooling loop may connect to production equipment, tooling, material preparation, or another heat-generating step. Its design depends on the heat load, target temperature, fluid, environment, and required control stability.

## What to clarify next

Readers should identify the heat source, operating range, utilities, and consequences of temperature variation. Those facts determine whether they need a standard unit, a customized system, or further engineering analysis.
MD,
            default => <<<'MD'
## Scope

An industrial cooling overview should explain the process objective, system boundary, and information required for selection. It should avoid presenting a generic configuration as suitable for every plant.

## Review points

Check heat load, target temperature, utilities, environment, maintenance access, and operating schedule. Uncertain inputs should be documented before a supplier recommendation is treated as final.

## Next step

Collect measured process data and compare it with the supplier operating envelope. This creates a practical basis for deciding whether a detailed selection guide or application analysis is needed.
MD,
        };
    }
}
