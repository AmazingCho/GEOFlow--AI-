<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CrmCustomer;
use App\Models\CrmInquiry;
use App\Models\CrmOpportunity;
use App\Support\AdminWeb;
use App\Support\GeoFlow\CollectionOptions;
use App\Support\GeoFlow\CrmOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CrmOpportunityController extends Controller
{
    public const STAGES = ['qualified'=>'已确认','discovery'=>'需求梳理','solution'=>'方案制定','proposal'=>'报价方案','negotiation'=>'商务谈判','won'=>'赢单','lost'=>'输单'];

    public function index(Request $request): View
    {
        $collectionId = (int) $request->query('collection_id', 0);
        $query = CrmOpportunity::query()->with(['customer','owner','primaryContact'])->latest('updated_at');
        if ($collectionId > 0) $query->where('collection_id', $collectionId);
        $opportunities = $query->get()->groupBy('stage');
        return view('admin.crm.opportunities.index', ['pageTitle'=>'商机管道','activeMenu'=>'crm','adminSiteName'=>AdminWeb::siteName(),'opportunities'=>$opportunities,'stages'=>self::STAGES,'collectionId'=>$collectionId,'collectionOptions'=>CollectionOptions::all()]);
    }

    public function create(Request $request): View
    {
        $inquiry = (int) $request->query('inquiry_id', 0) > 0 ? CrmInquiry::query()->with('customer.contacts')->find((int) $request->query('inquiry_id')) : null;
        return view('admin.crm.opportunities.form', $this->formData(null, $inquiry));
    }

    public function store(Request $request): RedirectResponse
    {
        $opportunity = CrmOpportunity::query()->create($this->validated($request));
        return redirect()->route('admin.crm.opportunities.edit', ['opportunityId'=>$opportunity->id])->with('message','商机已创建');
    }

    public function edit(int $opportunityId): View
    {
        return view('admin.crm.opportunities.form', $this->formData(CrmOpportunity::query()->with(['customer.contacts','tasks.assignee','quotes'])->findOrFail($opportunityId), null));
    }

    public function update(Request $request, int $opportunityId): RedirectResponse
    {
        $opportunity = CrmOpportunity::query()->findOrFail($opportunityId);
        $data = $this->validated($request);
        $data['won_at'] = $data['stage'] === 'won' ? ($opportunity->won_at ?: now()) : null;
        $data['lost_at'] = $data['stage'] === 'lost' ? ($opportunity->lost_at ?: now()) : null;
        $opportunity->update($data);
        return back()->with('message','商机已更新');
    }

    public function destroy(int $opportunityId): RedirectResponse
    {
        CrmOpportunity::query()->findOrFail($opportunityId)->delete();
        return redirect()->route('admin.crm.opportunities.index')->with('message','商机已归档');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'collection_id'=>['nullable','integer','exists:collections,id'],'customer_id'=>['required','integer','exists:crm_customers,id'],
            'primary_contact_id'=>['nullable','integer',Rule::exists('crm_customer_contacts','id')->where('customer_id',(int)$request->input('customer_id'))],'source_inquiry_id'=>['nullable','integer','exists:crm_inquiries,id'],
            'owner_admin_id'=>['nullable','integer','exists:admins,id'],'name'=>['required','string','max:200'],'stage'=>['required',Rule::in(array_keys(self::STAGES))],
            'amount'=>['nullable','numeric','min:0'],'currency'=>['nullable','string','max:10'],'probability'=>['nullable','integer','between:0,100'],
            'expected_close_date'=>['nullable','date'],'next_step'=>['nullable','string','max:500'],'next_step_at'=>['nullable','date'],
            'competitor'=>['nullable','string','max:200'],'lost_reason'=>[Rule::requiredIf(fn () => $request->input('stage') === 'lost'),'nullable','string','max:5000'],'notes'=>['nullable','string','max:20000'],
        ]);
        foreach (['collection_id','primary_contact_id','source_inquiry_id','owner_admin_id'] as $key) $data[$key] = (int) ($data[$key] ?? 0) ?: null;
        return $data;
    }

    private function formData(?CrmOpportunity $opportunity, ?CrmInquiry $inquiry): array
    {
        $customerId = (int) ($opportunity?->customer_id ?: $inquiry?->customer_id ?: 0);
        return ['pageTitle'=>$opportunity?'编辑商机':'新增商机','activeMenu'=>'crm','adminSiteName'=>AdminWeb::siteName(),'opportunity'=>$opportunity,'inquiry'=>$inquiry,'stages'=>self::STAGES,'customers'=>CrmCustomer::query()->with('contacts')->orderBy('company_name')->get(),'selectedCustomerId'=>$customerId,'collectionOptions'=>CollectionOptions::all(true),'employeeOptions'=>CrmOptions::employeeOptionsById()];
    }
}
