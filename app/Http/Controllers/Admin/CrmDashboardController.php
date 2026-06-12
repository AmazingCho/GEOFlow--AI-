<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CrmAfterSalesTicket;
use App\Models\CrmInquiry;
use App\Models\CrmOpportunity;
use App\Models\CrmSalesOrder;
use App\Models\CrmTask;
use App\Support\AdminWeb;
use Illuminate\View\View;

class CrmDashboardController extends Controller
{
    public function index(): View
    {
        $adminId = (int) (auth('admin')->id() ?? 0);
        $openTasks = CrmTask::query()->with(['customer', 'inquiry', 'opportunity'])
            ->where('status', 'open')->where(fn ($q) => $q->whereNull('assigned_admin_id')->orWhere('assigned_admin_id', $adminId));

        return view('admin.crm.dashboard', [
            'pageTitle' => 'CRM 工作台', 'activeMenu' => 'crm', 'adminSiteName' => AdminWeb::siteName(),
            'overdueTasks' => (clone $openTasks)->where('due_at', '<', now())->orderBy('due_at')->limit(8)->get(),
            'todayTasks' => (clone $openTasks)->whereBetween('due_at', [now()->startOfDay(), now()->endOfDay()])->orderBy('due_at')->limit(8)->get(),
            'recentInquiries' => CrmInquiry::query()->with('customer')->latest()->limit(6)->get(),
            'pipeline' => CrmOpportunity::query()->whereNotIn('stage', ['won', 'lost'])->selectRaw('stage, count(*) as total, sum(amount) as amount')->groupBy('stage')->get()->keyBy('stage'),
            'stats' => [
                'open_tasks' => (clone $openTasks)->count(),
                'overdue_tasks' => (clone $openTasks)->where('due_at', '<', now())->count(),
                'open_opportunities' => CrmOpportunity::query()->whereNotIn('stage', ['won', 'lost'])->count(),
                'open_orders' => CrmSalesOrder::query()->where('order_status', 'open')->count(),
                'open_tickets' => CrmAfterSalesTicket::query()->whereNotIn('status', ['resolved', 'closed'])->count(),
            ],
        ]);
    }
}
