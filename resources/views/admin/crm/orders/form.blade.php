@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8"><a href="{{ route('admin.crm.orders.show', ['orderId' => (int) $order->id]) }}" class="text-sm font-medium text-gray-500 hover:text-gray-700">返回订单详情</a><h1 class="mt-2 text-2xl font-bold text-gray-900">编辑订单</h1></div>
        @include('admin.crm.partials.nav', ['currentCrmTab' => 'orders'])
        <div class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
            @if($errors->any())<div class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif
            <form method="POST" action="{{ route('admin.crm.orders.update', ['orderId' => (int) $order->id]) }}" class="space-y-6">@csrf @method('PUT')
                <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    @include('admin.partials.collection-select', ['selectedId' => (string) ($order->collection_id ?? ''), 'collectionOptions' => $collectionOptions ?? [], 'label' => '业务容器', 'help' => '', 'class' => 'block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500'])
                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">客户</label>
                        <select name="customer_id" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="">请选择客户</option>
                            @foreach (($customerOptions ?? []) as $customer)
                                <option value="{{ (int) $customer['id'] }}" @selected(old('customer_id', (string) ($order->customer_id ?? '')) === (string) $customer['id'])>{{ $customer['label'] }} @if (($customer['meta'] ?? '') !== '') · {{ $customer['meta'] }} @endif</option>
                            @endforeach
                        </select>
                    </div>
                    <div><label class="mb-2 block text-sm font-medium text-gray-700">标题</label><input name="title" required value="{{ old('title', (string) $order->title) }}" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"></div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">负责人</label>
                        <select name="owner" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="">未指定</option>
                            @foreach (($employeeOptions ?? []) as $value => $label)
                                <option value="{{ $value }}" @selected(old('owner', (string) ($order->owner ?? '')) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-1 gap-6 lg:grid-cols-4">
                    @foreach(['payment_status' => ['pending'=>'待付款','partial'=>'部分付款','paid'=>'已付款','refunded'=>'已退款'], 'production_status' => ['not_started'=>'未开始','in_progress'=>'生产中','paused'=>'暂停','completed'=>'已完成'], 'delivery_status' => ['pending'=>'待交付','ready'=>'可发货','shipped'=>'已发货','delivered'=>'已送达'], 'order_status' => ['open'=>'打开','confirmed'=>'已确认','in_progress'=>'进行中','completed'=>'已完成','cancelled'=>'已取消']] as $field => $options)
                        <div><label class="mb-2 block text-sm font-medium text-gray-700">{{ $field }}</label><select name="{{ $field }}" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">@foreach($options as $value => $label)<option value="{{ $value }}" @selected(old($field, (string) $order->{$field}) === $value)>{{ $label }}</option>@endforeach</select></div>
                    @endforeach
                </div>
                <div><label class="mb-2 block text-sm font-medium text-gray-700">备注</label><textarea name="notes" rows="5" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">{{ old('notes', (string) ($order->notes ?? '')) }}</textarea></div>
                <div class="flex justify-end"><button class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">保存订单</button></div>
            </form>
        </div>
    </div>
@endsection
