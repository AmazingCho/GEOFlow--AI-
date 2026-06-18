<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\GeoFlow\AssistantContextSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API v1 AI 助手上下文查询。
 *
 * 当前阶段仅提供只读搜索，供 Codex / AI 在创建录入草稿前查找客户、CRM 链路、
 * Entity、知识库和 Case 候选对象。不得在这里写入业务数据。
 */
class AssistantContextController extends BaseApiController
{
    public function search(Request $request, AssistantContextSearchService $search): JsonResponse
    {
        $collectionId = $request->query('collection_id');
        $limit = $request->query('limit');

        return $this->success($request, $search->search(
            is_string($request->query('q')) ? $request->query('q') : '',
            is_numeric($collectionId) ? (int) $collectionId : null,
            is_numeric($limit) ? (int) $limit : null,
        ));
    }
}
