<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Services\ActivityLogger;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CouponController extends Controller
{
    public function __construct(private ActivityLogger $activity) {}

    public function index(): Response
    {
        return Inertia::render('Coupons/Index', [
            'coupons' => Coupon::query()->orderByDesc('id')->get()->map(fn (Coupon $c) => [
                'id' => $c->id,
                'code' => $c->code,
                'type' => $c->type,
                // Percent coupons store whole percents; fixed coupons store cents.
                'value' => $c->type === 'percent' ? (string) $c->value : number_format($c->value / 100, 2, '.', ''),
                'minSpend' => $c->min_spend_cents > 0 ? number_format($c->min_spend_cents / 100, 2, '.', '') : '',
                'startsAt' => $c->starts_at?->format('Y-m-d\TH:i'),
                'endsAt' => $c->ends_at?->format('Y-m-d\TH:i'),
                'usageLimit' => $c->usage_limit,
                'usedCount' => $c->used_count,
                'sellingPages' => $c->applies_to_selling_pages,
                'active' => $c->is_active,
                'state' => match (true) {
                    ! $c->is_active => 'Off',
                    $c->starts_at?->isFuture() => 'Scheduled',
                    $c->ends_at?->isPast() => 'Expired',
                    $c->usage_limit !== null && $c->used_count >= $c->usage_limit => 'Used up',
                    default => 'Active',
                },
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $coupon = Coupon::create($this->validated($request));
        $this->activity->log('create', 'coupons', $coupon, $coupon->code);

        return back()->with('success', "Coupon {$coupon->code} created.");
    }

    public function update(Request $request, Coupon $coupon): RedirectResponse
    {
        $coupon->update($this->validated($request, $coupon));
        $this->activity->log('edit', 'coupons', $coupon, $coupon->code);

        return back()->with('success', "Coupon {$coupon->code} saved.");
    }

    public function destroy(Coupon $coupon): RedirectResponse
    {
        $this->activity->log('delete', 'coupons', $coupon, $coupon->code);
        $coupon->delete(); // past orders keep the code they used as text

        return back()->with('success', "Coupon {$coupon->code} deleted.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Coupon $coupon = null): array
    {
        $request->merge(['code' => mb_strtoupper(trim((string) $request->input('code')))]);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('coupons', 'code')->ignore($coupon?->id)],
            'type' => ['required', Rule::in(Coupon::TYPES)],
            'value' => ['required', 'numeric', 'gt:0', 'max:999999.99'],
            'min_spend' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'usage_limit' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'applies_to_selling_pages' => ['boolean'],
            'is_active' => ['boolean'],
        ], [
            'code.regex' => 'Use letters, numbers, hyphens and underscores only.',
            'ends_at.after' => 'The end must be after the start.',
        ]);

        if ($data['type'] === 'percent' && ((float) $data['value'] > 100 || floor((float) $data['value']) != (float) $data['value'])) {
            throw ValidationException::withMessages(['value' => 'A percentage must be a whole number from 1 to 100.']);
        }

        return [
            'code' => $data['code'],
            'type' => $data['type'],
            'value' => $data['type'] === 'percent' ? (int) $data['value'] : Money::toCents($data['value']),
            'min_spend_cents' => Money::toCents($data['min_spend'] ?? null) ?? 0,
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'usage_limit' => $data['usage_limit'] ?? null,
            'applies_to_selling_pages' => (bool) ($data['applies_to_selling_pages'] ?? true),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }
}
