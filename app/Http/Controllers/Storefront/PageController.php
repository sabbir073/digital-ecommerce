<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Mail\ContactMessageReceived;
use App\Models\ContactMessage;
use App\Models\ContentPage;
use App\Models\Setting;
use App\Services\Selling\PagePresenter;
use App\Support\Blocks;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/** Content pages made in admin, and the contact form some of them carry. */
class PageController extends Controller
{
    public function show(Request $request, string $slug, PagePresenter $presenter): View
    {
        $page = ContentPage::query()->where('slug', $slug)->firstOrFail();

        // Staff who may see content can preview the unpublished draft.
        $preview = $request->boolean('preview') && Auth::guard('admin')->user()?->allows('content', 'view');
        abort_unless($preview || $page->isPublished(), 404);

        $blocks = Blocks::normalize($preview ? ($page->draft_blocks ?? $page->published_blocks) : $page->published_blocks, 'content');

        return view('storefront.page', $presenter->present(null, $blocks) + ['page' => $page, 'preview' => $preview, 'builder' => false, 'blocksFor' => 'content']);
    }

    public function contact(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:255'],
            'message' => ['required', 'string', 'min:5', 'max:3000'],
        ], [
            'name.required' => t('Enter your name.'),
            'email.required' => t('Enter your email so we can reply.'),
            'email.email' => t('Enter a valid email address.'),
            'message.required' => t('Write your message.'),
            'message.min' => t('Write a few more words so we can help.'),
        ]);

        // A filled hidden field means a bot: answer as if it worked and keep nothing.
        if (trim((string) $request->input('website')) === '') {
            $message = ContactMessage::create(['name' => $data['name'], 'email' => Str::lower($data['email']), 'message' => $data['message'], 'status' => ContactMessage::NEW]);

            $to = (string) Setting::get('store.contact_email', '');
            if ($to !== '') {
                try {
                    Mail::to($to)->queue(new ContactMessageReceived($message));
                } catch (Throwable $e) {
                    Log::warning('Contact email could not be queued', ['message' => $e->getMessage()]);
                }
            }
        }

        return back()->with('success', t('Thank you. Your message was sent and we will reply by email.'))->withFragment('contact');
    }
}
