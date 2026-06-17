@php
    $crmTabs = [
        'dashboard' => ['route' => 'admin.crm.dashboard', 'label' => '工作台'],
        'customers' => ['route' => 'admin.crm.customers.index', 'label' => '客户'],
        'inquiries' => ['route' => 'admin.crm.inquiries.index', 'label' => '询盘'],
        'opportunities' => ['route' => 'admin.crm.opportunities.index', 'label' => '商机'],
        'opportunities_kanban' => ['route' => 'admin.crm.opportunities.kanban', 'label' => '商机看板'],
        'tasks' => ['route' => 'admin.crm.tasks.index', 'label' => '待办'],
        'quotes' => ['route' => 'admin.crm.quotes.index', 'label' => '单据制作'],
        'orders' => ['route' => 'admin.crm.orders.index', 'label' => '订单'],
        'tickets' => ['route' => 'admin.crm.tickets.index', 'label' => '售后'],
        'proposals' => ['route' => 'admin.crm.proposals.index', 'label' => '内容候选'],
    ];
    $currentCrmTab = $currentCrmTab ?? 'dashboard';
@endphp

<div class="mb-6 flex flex-wrap gap-2 border-b border-gray-200">
    @foreach ($crmTabs as $tabKey => $tab)
        <a href="{{ route($tab['route']) }}" class="-mb-px inline-flex items-center border-b-2 px-4 py-3 text-sm font-medium {{ $currentCrmTab === $tabKey ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' }}">
            {{ $tab['label'] }}
        </a>
    @endforeach
</div>
