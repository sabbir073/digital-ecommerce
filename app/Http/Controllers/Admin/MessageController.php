<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Services\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** The inbox for messages sent through a contact form. */
class MessageController extends Controller
{
    public function index(Request $request): Response
    {
        $status = in_array($request->query('status'), ['open', ContactMessage::DONE], true) ? (string) $request->query('status') : 'open';

        return Inertia::render('Content/Messages', [
            'messages' => ContactMessage::query()
                ->when($status === 'open', fn ($q) => $q->where('status', '!=', ContactMessage::DONE), fn ($q) => $q->where('status', ContactMessage::DONE))
                ->latest('id')->paginate(25)->withQueryString()
                ->through(fn (ContactMessage $m) => [
                    'id' => $m->id,
                    'name' => $m->name,
                    'email' => $m->email,
                    'message' => $m->message,
                    'status' => $m->status,
                    'createdAt' => $m->created_at?->toIso8601String(),
                ]),
            'status' => $status,
            'counts' => ['new' => ContactMessage::query()->where('status', ContactMessage::NEW)->count()],
        ]);
    }

    public function update(Request $request, ContactMessage $message): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in([ContactMessage::NEW, ContactMessage::READ, ContactMessage::DONE])]]);
        $message->update($data);

        return back();
    }

    public function destroy(ContactMessage $message, ActivityLogger $activity): RedirectResponse
    {
        $activity->log('delete', 'chat', $message, 'Message from '.$message->name);
        $message->delete();

        return back()->with('success', 'Message deleted.');
    }
}
