<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DataDeletionRequest;
use App\Models\Order;
use App\Models\Review;
use App\Services\ActivityLogger;
use App\Services\Customers\CustomerEraser;
use App\Support\Csv;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Customers are people, known by their email: everyone who paid for an order (most check out
 * as guests) and everyone who made an account.
 */
class CustomerController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));
        $sort = in_array($request->query('sort'), ['spent', 'orders', 'recent'], true) ? (string) $request->query('sort') : 'recent';

        $customers = $this->query($search)
            ->orderByDesc(match ($sort) {
                'spent' => 'spent',
                'orders' => 'orders',
                default => 'last_seen',
            })->orderBy('c.email')
            ->paginate(25)->withQueryString()
            ->through(fn ($row) => $this->present($row));

        return Inertia::render('Customers/Index', [
            'customers' => $customers,
            'filters' => ['search' => $search, 'sort' => $sort],
            'deletionRequests' => DataDeletionRequest::query()->where('status', DataDeletionRequest::PENDING)->oldest()->limit(50)->get()
                ->map(fn (DataDeletionRequest $r) => ['email' => $r->email, 'at' => $r->created_at?->toIso8601String()]),
        ]);
    }

    public function show(Request $request): Response
    {
        $email = Str::lower(trim((string) $request->query('email', '')));
        $row = $email !== '' ? $this->query('')->where('c.email', $email)->first() : null;
        abort_unless($row, 404);

        $orders = Order::query()->where('email', $email)->whereNotIn('status', [Order::PENDING, Order::CANCELLED])->withCount('items')->latest('id')->limit(200)->get();

        return Inertia::render('Customers/Show', [
            'customer' => $this->present($row) + [
                'refunded' => (int) $orders->sum('refunded_cents'),
                'reviews' => Review::query()->where('email', $email)->count(),
                'deletionRequestedAt' => DataDeletionRequest::query()->where('email', $email)->where('status', DataDeletionRequest::PENDING)->value('created_at')?->toIso8601String(),
            ],
            'orders' => $orders->map(fn (Order $o) => [
                'id' => $o->id,
                'number' => $o->number,
                'status' => $o->status,
                'statusLabel' => $o->statusLabel(),
                'source' => $o->source,
                'items' => $o->items_count,
                'total' => (int) $o->total_cents,
                'createdAt' => $o->created_at?->toIso8601String(),
            ]),
        ]);
    }

    /** Delete what identifies this person. Order records stay, without the name or email. */
    public function erase(Request $request, CustomerEraser $eraser, ActivityLogger $activity): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $email = Str::lower($data['email']);
        abort_unless($this->query('')->where('c.email', $email)->exists(), 404);

        $done = $eraser->erase($email);
        // The log says that it happened, not who it was.
        $activity->log('delete', 'customers', null, 'Personal data of one customer', ['orders' => $done['orders'], 'account' => $done['account']]);

        return redirect()->route('admin.customers.index')->with('success', 'The personal data was deleted. '.$done['orders'].' order '.($done['orders'] === 1 ? 'record was' : 'records were').' kept without a name or email.');
    }

    public function export(ActivityLogger $activity): StreamedResponse
    {
        $activity->log('export', 'customers', null, 'Customers CSV');
        $query = $this->query('')->orderBy('c.email');

        return Csv::download('customers', ['email', 'name', 'has_account', 'orders', 'total_spent', 'first_order', 'last_order'], (function () use ($query) {
            foreach ($query->cursor() as $row) {
                $c = $this->present($row);
                yield [$c['email'], $c['name'], $c['hasAccount'] ? 'yes' : 'no', $c['orders'], Csv::money($c['spent']), $row->first_order_at, $row->last_order_at];
            }
        })());
    }

    private function query(string $search): Builder
    {
        // Orders of a deleted customer no longer belong to a person, so they are not listed here.
        $emails = DB::table('orders')->select('email')->whereNotNull('paid_at')->where('email', 'not like', '%@'.CustomerEraser::DOMAIN)->union(DB::table('users')->select('email'));

        return DB::query()->fromSub($emails, 'c')
            ->leftJoin('orders as o', fn ($join) => $join->on('o.email', '=', 'c.email')->whereNotNull('o.paid_at'))
            ->leftJoin('users as u', 'u.email', '=', 'c.email')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('c.email', 'like', "%{$search}%")->orWhere('u.name', 'like', "%{$search}%")->orWhere('o.name', 'like', "%{$search}%")))
            ->groupBy('c.email', 'u.id', 'u.name', 'u.created_at')
            ->selectRaw('c.email, u.id as user_id, u.name as user_name, u.created_at as joined_at, COUNT(o.id) as orders,
                COALESCE(SUM(o.total_cents - o.refunded_cents), 0) as spent, MIN(o.paid_at) as first_order_at, MAX(o.paid_at) as last_order_at,
                MAX(o.name) as order_name, COALESCE(MAX(o.paid_at), u.created_at) as last_seen');
    }

    /** @return array<string, mixed> */
    private function present(object $row): array
    {
        return [
            'email' => $row->email,
            'name' => $row->user_name ?: $row->order_name,
            'hasAccount' => $row->user_id !== null,
            'orders' => (int) $row->orders,
            'spent' => (int) $row->spent,
            'lastOrderAt' => $row->last_order_at ? Carbon::parse($row->last_order_at)->toIso8601String() : null,
            'joinedAt' => $row->joined_at ? Carbon::parse($row->joined_at)->toIso8601String() : null,
        ];
    }
}
