<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CrmCustomer;
use App\Models\CrmCustomerContact;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CrmContactController extends Controller
{
    public function store(Request $request, int $customerId): RedirectResponse
    {
        $customer = CrmCustomer::query()->findOrFail($customerId);
        $data = $this->validated($request, true);
        DB::transaction(function () use ($customer, $data): void {
            if ((bool) ($data['is_primary'] ?? false)) $customer->contacts()->update(['is_primary'=>false]);
            $contact = $customer->contacts()->create($data);
            if ($contact->is_primary || $customer->contacts()->count() === 1) $this->makePrimary($customer, $contact);
        });
        return back()->with('message', '联系人已添加');
    }

    public function update(Request $request, int $customerId, int $contactId): RedirectResponse
    {
        $customer = CrmCustomer::query()->findOrFail($customerId);
        $contact = $customer->contacts()->findOrFail($contactId);
        $data = $this->validated($request, false);
        DB::transaction(function () use ($customer, $contact, $data): void {
            if ((bool) ($data['is_primary'] ?? false)) $customer->contacts()->where('id', '<>', $contact->id)->update(['is_primary'=>false]);
            $contact->update($data);
            if ($contact->fresh()->is_primary) $this->syncLegacy($customer, $contact->fresh());
        });
        return back()->with('message', '联系人已更新');
    }

    public function primary(int $customerId, int $contactId): RedirectResponse
    {
        $customer = CrmCustomer::query()->findOrFail($customerId);
        $this->makePrimary($customer, $customer->contacts()->findOrFail($contactId));
        return back()->with('message', '主联系人已更新');
    }

    public function destroy(int $customerId, int $contactId): RedirectResponse
    {
        $customer = CrmCustomer::query()->findOrFail($customerId);
        $contact = $customer->contacts()->findOrFail($contactId);
        if ($contact->is_primary && $customer->contacts()->where('id', '<>', $contact->id)->exists()) return back()->withErrors(['contact'=>'请先将其他联系人设为主联系人']);
        $contact->delete();
        return back()->with('message', '联系人已归档');
    }

    private function validated(Request $request, bool $forCreate): array
    {
        $data = $request->validate(['name'=>['required','string','max:160'],'title'=>['nullable','string','max:160'],'department'=>['nullable','string','max:160'],'phone'=>['nullable','string','max:120'],'email'=>['nullable','email','max:200'],'decision_role'=>['nullable',Rule::in(['decision_maker','influencer','technical','procurement','finance','user','other'])],'is_primary'=>['nullable','boolean'],'status'=>['nullable',Rule::in(['active','inactive'])],'notes'=>['nullable','string','max:5000']]);

        $data['name'] = trim((string) $data['name']);
        foreach (['title', 'department', 'phone', 'email', 'decision_role'] as $field) {
            if ($forCreate || array_key_exists($field, $data)) {
                $data[$field] = trim((string) ($data[$field] ?? ''));
            }
        }
        if ($forCreate || array_key_exists('status', $data)) {
            $data['status'] = trim((string) ($data['status'] ?? '')) ?: 'active';
        }

        return $data;
    }

    private function makePrimary(CrmCustomer $customer, CrmCustomerContact $contact): void
    {
        DB::transaction(function () use ($customer, $contact): void { $customer->contacts()->update(['is_primary'=>false]); $contact->update(['is_primary'=>true]); $this->syncLegacy($customer, $contact); });
    }

    private function syncLegacy(CrmCustomer $customer, CrmCustomerContact $contact): void
    {
        $customer->update([
            'contact_person' => (string) $contact->name,
            'contact_title' => (string) ($contact->title ?? ''),
            'phone' => (string) ($contact->phone ?? ''),
            'email' => (string) ($contact->email ?? ''),
        ]);
    }
}
