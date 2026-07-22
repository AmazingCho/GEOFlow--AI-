<?php

namespace App\Services\GeoFlow;

use App\Support\GeoFlow\ArticleSkillIntents;

final class ArticleIntentPlanningPolicy
{
    /** @return list<string> */
    public function constraints(?string $intentKey): array
    {
        return match (ArticleSkillIntents::normalize($intentKey)) {
            ArticleSkillIntents::COMPARISON => [
                'compare only dimensions supported for the relevant subjects',
                'keep asymmetric evidence visible instead of inferring symmetry',
            ],
            ArticleSkillIntents::BUYING_GUIDE => [
                'turn supported buyer constraints into decision criteria',
                'treat missing thresholds or requirements as verification items',
            ],
            ArticleSkillIntents::APPLICATION => [
                'start from the process need before mentioning a solution',
                'map only evidence-supported capabilities',
                'treat missing readiness or integration details as verification items',
            ],
            ArticleSkillIntents::TECHNICAL => [
                'explain only mechanisms supported by evidence or safe general explanation',
                'do not manufacture component interactions or performance effects',
            ],
            ArticleSkillIntents::TROUBLESHOOTING => [
                'separate observation from permission to act',
                'keep unsupported operational procedures out of the plan',
            ],
            ArticleSkillIntents::CASE_STUDY => [
                'respect evidence state, identity boundary, and publication scope',
                'do not convert an inquiry, forecast, or proposal into a completed result',
            ],
            ArticleSkillIntents::DEFINITION => [
                'define the concept boundary before adding supported practical context',
                'do not manufacture technical depth absent from the source material',
            ],
            default => [
                'answer the reader question using only supported facts and safe general explanation',
            ],
        };
    }
}
