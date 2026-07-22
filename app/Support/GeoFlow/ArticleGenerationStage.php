<?php

namespace App\Support\GeoFlow;

enum ArticleGenerationStage: string
{
    case Plan = 'plan';
    case PlanRepair = 'plan_repair';
    case Draft = 'draft';
    case Review = 'review';
    case Revision = 'revision';
    case FinalReview = 'final_review';

    public function usesStructuredOutput(): bool
    {
        return in_array($this, [self::Plan, self::PlanRepair, self::Review, self::FinalReview], true);
    }
}
