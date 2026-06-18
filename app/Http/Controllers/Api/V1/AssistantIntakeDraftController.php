<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AiIntakeDraft;
use App\Services\Api\IdempotencyService;
use App\Services\GeoFlow\AiIntakeDraftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssistantIntakeDraftController extends BaseApiController
{
    public function validateDraft(Request $request, AiIntakeDraftService $drafts): JsonResponse
    {
        return $this->success($request, $drafts->validatePayload($request->all()));
    }

    public function store(Request $request, AiIntakeDraftService $drafts): JsonResponse
    {
        $routeKey = 'POST /assistant/intake-drafts';
        if ($replay = IdempotencyService::maybeReplayJson($request, $routeKey)) {
            return $replay;
        }

        $draft = $drafts->createDraft($request->all(), $this->auth($request)->auditAdminId);

        return $this->success($request, [
            'draft' => $drafts->draftSummary($draft),
            'actions' => $drafts->actionSummaries($draft),
        ], 201, $routeKey);
    }

    public function show(Request $request, AiIntakeDraft $draft, AiIntakeDraftService $drafts): JsonResponse
    {
        return $this->success($request, [
            'draft' => $drafts->draftSummary($draft),
            'actions' => $drafts->actionSummaries($draft),
        ]);
    }
}
