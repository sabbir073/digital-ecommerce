<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AdminUser;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\SellingPage;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user('admin');

        return Inertia::render('Dashboard', [
            'sales' => $user->allows('orders', 'view') ? $this->sales() : null,
            'lowStock' => $user->allows('products', 'view') ? $this->lowStock() : null,
            'topPages' => $user->allows('selling_pages', 'view') ? $this->topPages() : null,
            'team' => $user->allows('staff', 'view') ? [
                'active' => AdminUser::query()->where('status', AdminUser::STATUS_ACTIVE)->count(),
                'invited' => AdminUser::query()->where('status', AdminUser::STATUS_INVITED)->count(),
                'suspended' => AdminUser::query()->where('status', AdminUser::STATUS_SUSPENDED)->count(),
                'roles' => Role::query()->count(),
                'withTwoFactor' => AdminUser::query()->whereNotNull('two_factor_confirmed_at')->count(),
            ] : null,
            'recentActivity' => $user->allows('activity', 'view')
                ? ActivityLog::query()->latest('id')->limit(8)->get()->map(fn (ActivityLog $log) => [
                    'id' => $log->id,
                    'actor' => $log->actor_name,
                    'action' => $log->action,
                    'module' => $log->module,
                    'label' => $log->subject_label,
                    'at' => $log->created_at?->toIso8601String(),
                ])
                : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function sales(): array
    {
        // Revenue is what was paid minus what was refunded, counted on the day of payment.
        $since = fn (Carbon $from) => (array) DB::table('orders')->whereNotNull('paid_at')->where('paid_at', '>=', $from)
            ->selectRaw('COUNT(*) as orders, COALESCE(SUM(total_cents - refunded_cents), 0) as revenue')->first();
        $today = $since(now()->startOfDay());
        $month = $since(now()->startOfMonth());

        return [
            'today' => ['orders' => (int) $today['orders'], 'revenue' => (int) $today['revenue']],
            'month' => ['orders' => (int) $month['orders'], 'revenue' => (int) $month['revenue']],
            'latest' => Order::query()->whereNotNull('paid_at')->latest('paid_at')->latest('id')->limit(5)->get()->map(fn (Order $o) => [
                'id' => $o->id,
                'number' => $o->number,
                'customer' => $o->name ?: $o->email,
                'status' => $o->status,
                'statusLabel' => $o->statusLabel(),
                'total' => (int) $o->total_cents,
                'paidAt' => $o->paid_at?->toIso8601String(),
            ]),
        ];
    }

    /** @return array{threshold:int, items:list<array<string, mixed>>} */
    private function lowStock(): array
    {
        $threshold = max(0, (int) Setting::get('inventory.low_stock_threshold', 5));
        $items = [];

        $products = Product::query()->where('status', 'active')->where('track_stock', true)->with('variants.optionValues')
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->whereDoesntHave('variants')->where('stock', '<=', $threshold))
                ->orWhereHas('variants', fn ($v) => $v->where('stock', '<=', $threshold)))
            ->orderBy('name')->limit(50)->get();

        foreach ($products as $product) {
            if ($product->variants->isEmpty()) {
                $items[] = ['id' => $product->id, 'name' => $product->name, 'variant' => null, 'stock' => (int) $product->stock];

                continue;
            }
            foreach ($product->variants->where('stock', '<=', $threshold) as $variant) {
                $items[] = ['id' => $product->id, 'name' => $product->name, 'variant' => $variant->label(), 'stock' => (int) $variant->stock];
            }
        }
        usort($items, fn ($a, $b) => $a['stock'] <=> $b['stock']);

        return ['threshold' => $threshold, 'items' => array_slice($items, 0, 8)];
    }

    /** Published selling pages, best conversion first. @return list<array<string, mixed>> */
    private function topPages(): array
    {
        $views = DB::table('page_views')->selectRaw('selling_page_id, SUM(views) as views')->groupBy('selling_page_id')->pluck('views', 'selling_page_id');
        $orders = DB::table('orders')->whereNotNull('selling_page_id')->whereNotNull('paid_at')
            ->selectRaw('selling_page_id, COUNT(*) as orders')->groupBy('selling_page_id')->pluck('orders', 'selling_page_id');

        return SellingPage::query()->where('status', SellingPage::PUBLISHED)->get(['id', 'name'])
            ->map(function (SellingPage $page) use ($views, $orders) {
                $v = (int) ($views[$page->id] ?? 0);
                $o = (int) ($orders[$page->id] ?? 0);

                return ['id' => $page->id, 'name' => $page->name, 'views' => $v, 'orders' => $o, 'conversion' => $v > 0 ? round($o / $v * 100, 1) : null];
            })
            ->filter(fn ($p) => $p['views'] > 0)
            ->sortByDesc('conversion')->take(5)->values()->all();
    }
}
