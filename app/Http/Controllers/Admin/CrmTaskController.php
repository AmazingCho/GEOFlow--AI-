<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CrmCustomer;
use App\Models\CrmInquiry;
use App\Models\CrmOpportunity;
use App\Models\CrmTask;
use App\Support\AdminWeb;
use App\Support\GeoFlow\CrmOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CrmTaskController extends Controller
{
    public function index(Request $request): View
    {
        $scope = (string) $request->query('scope', 'mine');
        $status = (string) $request->query('status', 'open');
        $query = CrmTask::query()->with(['customer', 'inquiry', 'opportunity', 'assignee'])->orderByRaw('due_at IS NULL')->orderBy('due_at')->latest('id');
        if ($scope === 'mine') $query->where('assigned_admin_id', (int) auth('admin')->id());
        if ($scope === 'unassigned') $query->whereNull('assigned_admin_id');
        if ($status !== '') $query->where('status', $status);
        return view('admin.crm.tasks.index', ['pageTitle'=>'CRM 待办','activeMenu'=>'crm','adminSiteName'=>AdminWeb::siteName(),'tasks'=>$query->paginate(20)->withQueryString(),'scope'=>$scope,'status'=>$status,'employeeOptions'=>CrmOptions::employeeOptionsById()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'customer_id'=>['nullable','integer','exists:crm_customers,id'], 'inquiry_id'=>['nullable','integer','exists:crm_inquiries,id'],
            'opportunity_id'=>['nullable','integer','exists:crm_opportunities,id'], 'title'=>['required','string','max:240'],
            'description'=>['nullable','string','max:10000'], 'priority'=>['nullable',Rule::in(['low','normal','high','urgent'])],
            'due_at'=>['nullable','date'], 'assigned_admin_id'=>['nullable','integer','exists:admins,id'],
        ]);
        $data['created_by_admin_id'] = (int) auth('admin')->id();
        $data['assigned_admin_id'] = (int) ($data['assigned_admin_id'] ?? auth('admin')->id()) ?: null;
        $this->completeSalesChain($data);
        $data['status'] = 'open';
        CrmTask::query()->create($data);
        return back()->with('message', '待办已创建');
    }

    public function complete(int $taskId): RedirectResponse
    {
        CrmTask::query()->findOrFail($taskId)->update(['status'=>'done','completed_at'=>now()]);
        return back()->with('message', '待办已完成');
    }

    public function reopen(int $taskId): RedirectResponse
    {
        CrmTask::query()->findOrFail($taskId)->update(['status'=>'open','completed_at'=>null]);
        return back()->with('message', '待办已重新打开');
    }

    public function destroy(int $taskId): RedirectResponse
    {
        CrmTask::query()->findOrFail($taskId)->delete();
        return back()->with('message', '待办已归档');
    }

    /** @param array<string, mixed> $data */
    private function completeSalesChain(array &$data): void
    {
        $inquiryId = (int) ($data['inquiry_id'] ?? 0);
        $opportunityId = (int) ($data['opportunity_id'] ?? 0);

        $inquiry = $inquiryId > 0 ? CrmInquiry::query()->findOrFail($inquiryId) : null;
        $opportunity = $opportunityId > 0 ? CrmOpportunity::query()->findOrFail($opportunityId) : null;

        if ($inquiryId > 0 && $opportunityId <= 0) {
            $data['opportunity_id'] = CrmOpportunity::query()
                ->where('source_inquiry_id', $inquiryId)
                ->value('id');
            $opportunity = $data['opportunity_id']
                ? CrmOpportunity::query()->find((int) $data['opportunity_id'])
                : null;
        }
        if ($opportunityId > 0 && $inquiryId <= 0) {
            $data['inquiry_id'] = (int) ($opportunity->source_inquiry_id ?? 0) ?: null;
            $inquiry = $data['inquiry_id']
                ? CrmInquiry::query()->find((int) $data['inquiry_id'])
                : null;
        }
        if ($inquiry && $opportunity && (int) $opportunity->source_inquiry_id !== (int) $inquiry->id) {
            throw ValidationException::withMessages([
                'opportunity_id' => '待办选择的询盘与商机来源不一致。',
            ]);
        }

        $expectedCustomerId = (int) ($opportunity?->customer_id ?: $inquiry?->customer_id ?: 0);
        if ($expectedCustomerId > 0 && (int) ($data['customer_id'] ?? 0) > 0 && (int) $data['customer_id'] !== $expectedCustomerId) {
            throw ValidationException::withMessages([
                'customer_id' => '待办客户与询盘或商机客户不一致。',
            ]);
        }
        if ($expectedCustomerId > 0) {
            $data['customer_id'] = $expectedCustomerId;
        }
    }
}
