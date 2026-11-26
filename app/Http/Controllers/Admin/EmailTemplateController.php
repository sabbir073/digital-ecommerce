<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\ChatReply;
use App\Mail\OrderConfirmation;
use App\Mail\PasswordReset;
use App\Mail\RefundIssued;
use App\Mail\ReviewRequestMail;
use App\Models\ChatConversation;
use App\Models\DigitalAsset;
use App\Models\DownloadGrant;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ReviewRequest;
use App\Services\ActivityLogger;
use App\Services\EmailTemplates;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Mail\Mailable;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** The wording of every email a customer gets, with a preview built from sample data. */
class EmailTemplateController extends Controller
{
    public function __construct(private readonly EmailTemplates $templates, private readonly ActivityLogger $activity) {}

    public function index(): Response
    {
        return Inertia::render('Settings/Emails', [
            'templates' => collect(EmailTemplates::definitions())->map(fn (array $definition, string $key) => [
                'key' => $key,
                'name' => $definition['name'],
                'when' => $definition['when'],
                'variables' => $definition['variables'],
                'blocks' => $definition['blocks'],
                'required' => $definition['required'],
                'defaults' => ['subject' => $definition['subject'], 'body' => $definition['body']],
            ] + $this->templates->get($key))->values(),
        ]);
    }

    public function update(Request $request, string $key): RedirectResponse
    {
        $data = $this->text($request, $key);
        $this->templates->save($key, $data['subject'], $data['body']);
        $this->activity->log('edit', 'settings', null, 'Email: '.EmailTemplates::definitions()[$key]['name']);

        return back()->with('success', 'Email saved. The next one sent uses this text.');
    }

    public function reset(string $key): RedirectResponse
    {
        abort_unless(isset(EmailTemplates::definitions()[$key]), 404);
        $this->templates->reset($key);
        $this->activity->log('edit', 'settings', null, 'Email reset: '.EmailTemplates::definitions()[$key]['name']);

        return back()->with('success', 'Back to the original text.');
    }

    /** The email as a customer would get it, from the text in the editor and made-up sample data. */
    public function preview(Request $request, string $key): JsonResponse
    {
        abort_unless(isset(EmailTemplates::definitions()[$key]), 404);
        try {
            $data = $this->text($request, $key);
            $this->templates->check($key, $data['subject'], $data['body']);
        } catch (ValidationException $e) {
            // The editor shows this beside the preview while the text is being written.
            return response()->json(['error' => collect($e->errors())->flatten()->first()]);
        }
        $mail = $this->sample($key)->withTemplate($data);

        return response()->json(['subject' => $mail->envelope()->subject, 'html' => $mail->render()]);
    }

    /** @return array{subject:string, body:string} */
    private function text(Request $request, string $key): array
    {
        abort_unless(isset(EmailTemplates::definitions()[$key]), 404);

        return $request->validate([
            'subject' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:10000'],
        ], ['subject.required' => 'Write the subject.', 'body.required' => 'Write the email.']);
    }

    /** Nothing here is saved: the sample order exists only for the preview. */
    private function sample(string $key): Mailable
    {
        $asset = new DigitalAsset(['filename' => 'starter-kit.zip']);
        $grant = new DownloadGrant(['token' => 'sample-link', 'max_downloads' => 5, 'expires_at' => now()->addDays(30)]);
        $grant->setRelation('asset', $asset);
        $item = new OrderItem(['name' => 'Starter template kit', 'quantity' => 1, 'line_total_cents' => 4900]);
        $item->setRelation('downloads', collect([$grant]));
        $order = new Order(['number' => '261126-SAMPLE', 'name' => 'Alex Morgan', 'email' => 'alex@example.com', 'status' => Order::PAID,
            'subtotal_cents' => 4900, 'discount_cents' => 500, 'tax_cents' => 352, 'total_cents' => 4752]);
        $order->setRelation('items', collect([$item]));

        $request = new ReviewRequest(['token' => 'sample-link']);
        $request->setRelation('orderItem', $item);

        return match ($key) {
            'order_confirmation' => new OrderConfirmation($order),
            'refund_issued' => new RefundIssued($order->fill(['status' => Order::REFUNDED]), 4752),
            'review_request' => new ReviewRequestMail($order, new Collection([$request])),
            'chat_reply' => new ChatReply(new ChatConversation(['name' => 'Alex Morgan']), 'Yes, the kit includes the Figma files. You can download them from your order page.'),
            'password_reset' => new PasswordReset('Alex Morgan', url('/reset-password/sample-link'), (int) config('auth.passwords.users.expire', 60)),
        };
    }
}
