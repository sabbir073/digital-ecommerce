<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use App\Services\UiText;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Every button label, message and empty state of the storefront, reworded in one place. */
class InterfaceTextController extends Controller
{
    public function index(): Response
    {
        $overrides = UiText::overrides();

        return Inertia::render('Settings/Text', [
            'areas' => collect(UiText::registry())->map(fn (array $strings, string $area) => [
                'name' => $area,
                'strings' => array_map(fn (string $text) => ['default' => $text, 'text' => (string) ($overrides[$text] ?? '')], $strings),
            ])->values(),
        ]);
    }

    public function update(Request $request, ActivityLogger $activity): RedirectResponse
    {
        $data = $request->validate([
            'changes' => ['present', 'array', 'max:1000'],
            'changes.*.default' => ['required', 'string', 'max:500'],
            'changes.*.text' => ['nullable', 'string', 'max:500'],
        ]);

        $changes = [];
        foreach ($data['changes'] as $index => $change) {
            $text = trim((string) ($change['text'] ?? ''));
            // Words like :count are filled in by the store; a rewording has to keep them.
            preg_match_all('/:[a-z_0-9]+/', $change['default'], $needed);
            $missing = array_filter($needed[0], fn (string $token) => $text !== '' && ! str_contains($text, $token));
            if ($missing !== []) {
                throw ValidationException::withMessages(["changes.{$index}.text" => 'Keep '.implode(' and ', $missing).' in the text. The store fills it in.']);
            }
            $changes[$change['default']] = $text;
        }

        UiText::save($changes);
        $activity->log('edit', 'settings', null, 'Interface text', ['changed' => count($changes)]);

        return back()->with('success', 'Interface text saved.');
    }
}
