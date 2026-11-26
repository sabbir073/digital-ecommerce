<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\ActivityLogger;
use App\Services\PasswordPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Password policy for admin accounts (PRD section 15, sign-in security). */
class SecuritySettingsController extends Controller
{
    public function edit(PasswordPolicy $policy): Response
    {
        return Inertia::render('Staff/Security', ['policy' => $policy->settings()]);
    }

    public function update(Request $request, PasswordPolicy $policy, ActivityLogger $activity): RedirectResponse
    {
        $data = $request->validate([
            'min_length' => ['required', 'integer', 'min:8', 'max:64'],
            'mixed_case' => ['required', 'boolean'],
            'numbers' => ['required', 'boolean'],
            'symbols' => ['required', 'boolean'],
        ]);

        $before = $policy->settings();
        Setting::put('security.password_policy', $data);
        $activity->log('edit', 'staff', null, 'Password policy', ['from' => $before, 'to' => $data]);

        return back()->with('success', 'Password policy saved. It applies to new and changed passwords.');
    }
}
