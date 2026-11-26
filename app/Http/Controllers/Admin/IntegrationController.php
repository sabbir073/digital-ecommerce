<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContentPage;
use App\Models\EventLog;
use App\Models\SellingPage;
use App\Models\Setting;
use App\Services\ActivityLogger;
use App\Services\Integrations\Consent;
use App\Services\Integrations\CustomScripts;
use App\Services\Integrations\Integrations;
use App\Services\Integrations\MailSettings;
use App\Services\Integrations\ServerEvents;
use App\Services\Integrations\Tracking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/** Admin > Integrations: a setup card per service, the cookie banner, email sending, custom scripts and the event log (PRD section 14). */
class IntegrationController extends Controller
{
    public function __construct(private readonly Integrations $integrations, private readonly ActivityLogger $activity) {}

    public function index(): Response
    {
        $all = $this->integrations->all();

        return Inertia::render('Integrations/Index', [
            'services' => collect(Integrations::SERVICES)->map(fn (array $definition, string $key) => [
                'key' => $key,
                'name' => $definition['name'],
                'about' => $definition['about'],
                'category' => $definition['category'],
                'needs' => isset($definition['needs']) ? Integrations::SERVICES[$definition['needs']]['name'] : null,
                'needsMet' => ! isset($definition['needs']) || $this->integrations->active($definition['needs']),
                'server' => (bool) ($definition['server'] ?? false),
                'enabled' => $all[$key]['enabled'],
                'active' => $this->integrations->active($key),
                'status' => $all[$key]['enabled'] ? ($all[$key]['status'] === 'error' ? 'error' : 'on') : 'off',
                'error' => $all[$key]['last_error'],
                'lastEventAt' => $all[$key]['last_event_at'],
                'canTestFromServer' => $key === 'meta_capi' || ($key === 'tiktok' && $this->integrations->setting('tiktok', 'access_token') !== ''),
                'fields' => collect($definition['fields'])->map(fn (array $field, string $name) => [
                    'name' => $name,
                    'label' => $field['label'],
                    'hint' => $field['hint'] ?? null,
                    'type' => $field['type'] ?? (($field['secret'] ?? false) ? 'secret' : 'text'),
                    'required' => (bool) ($field['required'] ?? false),
                    // A secret is never sent back: only enough to recognise which one is saved.
                    'value' => ($field['secret'] ?? false) ? '' : ($all[$key]['settings'][$name] ?? (($field['type'] ?? '') === 'switch' ? false : '')),
                    'saved' => ($field['secret'] ?? false) ? self::mask((string) ($all[$key]['settings'][$name] ?? '')) : null,
                ])->values(),
            ])->values(),
            'feeds' => [
                ['name' => 'Google Merchant Center', 'url' => route('feeds.google'), 'about' => 'Add this address in Merchant Center under Products, Feeds, as a scheduled fetch.'],
                ['name' => 'Meta catalog', 'url' => route('feeds.meta'), 'about' => 'Add this address in Commerce Manager under Catalog, Data sources, Data feed, as a scheduled feed.'],
            ],
            'logBrowserEvents' => (bool) Setting::get('integrations.log_browser_events', true),
            'storeUrl' => url('/'),
        ]);
    }

    public function update(Request $request, string $service): RedirectResponse
    {
        abort_unless(isset(Integrations::SERVICES[$service]), 404);
        $definition = Integrations::SERVICES[$service];
        $enabled = $request->boolean('enabled');
        $saved = $this->integrations->all()[$service]['settings'];

        $rules = ['enabled' => ['required', 'boolean']];
        $messages = [];
        foreach ($definition['fields'] as $name => $field) {
            if (($field['type'] ?? 'text') === 'switch') {
                $rules["settings.$name"] = ['boolean'];

                continue;
            }
            // A saved secret counts as filled in when the field is left empty.
            $needed = $enabled && ($field['required'] ?? false) && ! (($field['secret'] ?? false) && ($saved[$name] ?? '') !== '');
            $rules["settings.$name"] = array_values(array_filter([$needed ? 'required' : 'nullable', 'string', 'max:'.($field['max'] ?? 120), isset($field['pattern']) ? 'regex:'.$field['pattern'] : null]));
            $messages["settings.$name.required"] = 'Enter the '.Str::lower($field['label']).' before switching this on.';
            $messages["settings.$name.regex"] = 'This does not look like a valid '.Str::lower($field['label']).'.'.(isset($field['hint']) ? ' '.$field['hint'].'.' : '');
        }
        $request->merge(['settings' => array_map(fn ($value) => is_string($value) ? trim($value) : $value, (array) $request->input('settings', []))]);
        $data = $request->validate($rules, $messages);

        if ($enabled && isset($definition['needs']) && ! $this->integrations->active($definition['needs'])) {
            throw ValidationException::withMessages(['enabled' => 'Switch on '.Integrations::SERVICES[$definition['needs']]['name'].' first: this service uses its ID.']);
        }

        $row = $this->integrations->save($service, $data['settings'] ?? [], $enabled);
        $this->activity->log('edit', 'integrations', $row, $definition['name'].($enabled ? ' switched on' : ' switched off'));

        return back()->with('success', $definition['name'].' saved.');
    }

    /** Remove a saved secret, such as an access token. */
    public function forget(string $service, string $field): RedirectResponse
    {
        abort_unless((Integrations::SERVICES[$service]['fields'][$field]['secret'] ?? false) === true, 404);
        $this->integrations->forgetSecret($service, $field);
        $this->activity->log('edit', 'integrations', null, Integrations::SERVICES[$service]['name'].': '.Str::lower(Integrations::SERVICES[$service]['fields'][$field]['label']).' removed');

        return back()->with('success', 'Removed.');
    }

    /** Send a test event from the server and say what the service answered. */
    public function test(Request $request, string $service, ServerEvents $server): JsonResponse
    {
        abort_unless(isset(Integrations::SERVICES[$service]), 404);
        if (! $this->integrations->active($service)) {
            return response()->json(['ok' => false, 'message' => 'Switch this service on and save it first.']);
        }
        if (! in_array($service, ['meta_capi', 'tiktok'], true) || ($service === 'tiktok' && $this->integrations->setting('tiktok', 'access_token') === '')) {
            return response()->json(['ok' => false, 'message' => 'This service only takes events from a browser. Use "Open the store to test".']);
        }

        $event = Tracking::event('view_item', ['currency' => 'USD', 'value' => 1.0, 'items' => [['id' => 'test', 'name' => 'Test event from admin', 'price' => 1.0, 'quantity' => 1]]], 'test-'.Str::lower(Str::random(12)));
        $result = $server->send($event, ['ip' => $request->ip(), 'user_agent' => (string) $request->userAgent(), 'url' => url('/'), 'email' => Auth::guard('admin')->user()?->email], test: true, only: [$service])[$service]
            ?? ['ok' => false, 'response' => 'Nothing was sent.'];

        return response()->json(['ok' => $result['ok'], 'message' => $result['ok'] ? 'The service accepted the test event. It is in the event log.' : $result['response']]);
    }

    public function updateLogging(Request $request): RedirectResponse
    {
        $data = $request->validate(['log_browser_events' => ['required', 'boolean']]);
        Setting::put('integrations.log_browser_events', (bool) $data['log_browser_events']);
        $this->activity->log('edit', 'integrations', null, 'Event log: browser events '.($data['log_browser_events'] ? 'recorded' : 'not recorded'));

        return back()->with('success', 'Saved.');
    }

    public function log(Request $request): Response
    {
        $service = (string) $request->query('service', '');
        $only = $request->query('result');

        return Inertia::render('Integrations/Log', [
            'events' => EventLog::query()
                ->when($service !== '', fn ($q) => $q->where('service', $service))
                ->when($only === 'failed', fn ($q) => $q->where('ok', false))
                ->orderByDesc('id')->paginate(50)->withQueryString()
                ->through(fn (EventLog $log) => [
                    'id' => $log->id, 'event' => $log->event, 'service' => $log->service,
                    'serviceName' => Integrations::SERVICES[$log->service]['name'] ?? ($log->service === 'browser' ? 'Browser' : $log->service),
                    'ok' => $log->ok, 'payload' => $log->payload, 'response' => $log->response, 'at' => $log->created_at?->toIso8601String(),
                ]),
            'filters' => ['service' => $service, 'result' => $only === 'failed' ? 'failed' : ''],
            'services' => ['browser' => 'Browser'] + collect(Integrations::SERVICES)->filter(fn ($d) => $d['server'] ?? false)->map(fn ($d) => $d['name'])->all(),
            'keep' => EventLog::KEEP,
        ]);
    }

    public function clearLog(): RedirectResponse
    {
        EventLog::query()->delete();
        $this->activity->log('delete', 'integrations', null, 'Cleared the event log');

        return back()->with('success', 'The event log is empty.');
    }

    // ---- Cookie banner ----

    public function consent(Consent $consent): Response
    {
        return Inertia::render('Integrations/Consent', [
            'consent' => $consent->settings(),
            'regions' => Consent::REGIONS,
            'defaults' => Consent::DEFAULTS,
            'pages' => ContentPage::query()->orderBy('title')->get(['id', 'title', 'status'])->map(fn (ContentPage $p) => ['id' => $p->id, 'label' => $p->title.($p->isPublished() ? '' : ' (draft)')]),
            'anyActive' => collect(array_keys(Integrations::SERVICES))->contains(fn (string $s) => $this->integrations->active($s)),
        ]);
    }

    public function updateConsent(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'region' => ['required', Rule::in(array_keys(Consent::REGIONS))],
            'title' => ['required', 'string', 'max:80'],
            'text' => ['required', 'string', 'max:600'],
            'accept' => ['required', 'string', 'max:40'],
            'reject' => ['required', 'string', 'max:40'],
            'choose' => ['required', 'string', 'max:40'],
            'save' => ['required', 'string', 'max:40'],
            'policy_page_id' => ['nullable', Rule::exists('content_pages', 'id')],
            'categories' => ['required', 'array:necessary,analytics,marketing'],
            'categories.*.label' => ['required', 'string', 'max:40'],
            'categories.*.text' => ['required', 'string', 'max:300'],
        ]);

        Setting::put('consent', $data);
        $this->activity->log('edit', 'integrations', null, 'Cookie banner'.($data['enabled'] ? '' : ' switched off'));

        return back()->with('success', 'Cookie banner saved.');
    }

    // ---- Email sending ----

    public function email(MailSettings $mail): Response
    {
        $settings = $mail->all();

        return Inertia::render('Integrations/Email', [
            'mail' => ['password' => ''] + $settings,
            'savedPassword' => self::mask($settings['password']),
            'encryption' => MailSettings::ENCRYPTION,
            'serverMailer' => (string) config('mail.mailers.'.env('MAIL_MAILER', 'log').'.transport', env('MAIL_MAILER', 'log')),
            'state' => $mail->status(),
            'testTo' => Auth::guard('admin')->user()?->email,
        ]);
    }

    public function updateEmail(Request $request, MailSettings $mail): RedirectResponse
    {
        abort_unless(Auth::guard('admin')->user()?->isSuper(), 403);
        $smtp = $request->input('driver') === 'smtp';
        $data = $request->validate([
            'driver' => ['required', Rule::in(['server', 'smtp'])],
            'host' => [$smtp ? 'required' : 'nullable', 'string', 'max:190', 'regex:/^[A-Za-z0-9.-]+$/'],
            'port' => [$smtp ? 'required' : 'nullable', 'integer', 'min:1', 'max:65535'],
            'encryption' => ['required', Rule::in(array_keys(MailSettings::ENCRYPTION))],
            'username' => ['nullable', 'string', 'max:190'],
            'password' => ['nullable', 'string', 'max:500'],
            'from_address' => [$smtp ? 'required' : 'nullable', 'email', 'max:190'],
            'from_name' => ['nullable', 'string', 'max:80'],
        ], ['host.regex' => 'Enter the server name only, for example smtp.example.com.', 'from_address.required' => 'Enter the address emails are sent from.']);

        $mail->save(array_map(fn ($value) => $value ?? '', $data));
        $this->activity->log('edit', 'integrations', null, 'Email sending');

        return back()->with('success', 'Email settings saved. Send a test email to check them.');
    }

    public function testEmail(Request $request, MailSettings $mail): JsonResponse
    {
        $data = $request->validate(['to' => ['required', 'email', 'max:190']]);
        $store = (string) Setting::get('store.name', config('app.name'));

        try {
            // Sent now, not queued, so a wrong password or host is reported here.
            Mail::raw("This is a test email from {$store}.\n\nIf you can read it, the store can send email.", fn ($message) => $message->to($data['to'])->subject("Test email from {$store}"));
            $mail->report(true);

            return response()->json(['ok' => true, 'message' => config('mail.default') === 'log'
                ? 'The server is set to write emails to its log file instead of sending them, so nothing was delivered. Enter an SMTP account to send real email.'
                : "Sent to {$data['to']}. Check the inbox, and the spam folder."]);
        } catch (Throwable $e) {
            $mail->report(false, $e->getMessage());

            return response()->json(['ok' => false, 'message' => 'The email could not be sent: '.Str::limit($e->getMessage(), 300)]);
        }
    }

    // ---- Custom scripts ----

    public function scripts(CustomScripts $scripts): Response
    {
        return Inertia::render('Integrations/Scripts', [
            'scripts' => $scripts->all(),
            'placements' => CustomScripts::PLACEMENTS,
            'max' => CustomScripts::MAX,
            'canEdit' => (bool) Auth::guard('admin')->user()?->isSuper(),
        ]);
    }

    /** Code that runs for visitors. Reserved for Super Admins in code, not in the permission matrix. */
    public function updateScripts(Request $request, CustomScripts $scripts): RedirectResponse
    {
        abort_unless(Auth::guard('admin')->user()?->isSuper(), 403);
        // The addresses come from a text box, one per line: empty lines are dropped before checking.
        $request->merge(['scripts' => array_map(function ($script) {
            if (is_array($script) && is_array($script['paths'] ?? null)) {
                $script['paths'] = array_values(array_filter(array_map(fn ($path) => trim((string) $path), $script['paths']), fn (string $path) => $path !== ''));
            }

            return $script;
        }, array_values((array) $request->input('scripts', [])))]);
        $data = $request->validate([
            'scripts' => ['array', 'max:'.CustomScripts::MAX],
            'scripts.*.id' => ['nullable', 'string', 'max:40'],
            'scripts.*.name' => ['required', 'string', 'max:80'],
            'scripts.*.placement' => ['required', Rule::in(array_keys(CustomScripts::PLACEMENTS))],
            'scripts.*.scope' => ['required', Rule::in(['all', 'paths'])],
            'scripts.*.paths' => ['array', 'max:30'],
            'scripts.*.paths.*' => ['string', 'max:200', 'regex:/^\/[^\s]*$/'],
            'scripts.*.code' => ['required', 'string', 'max:20000'],
            'scripts.*.enabled' => ['required', 'boolean'],
        ], [
            'scripts.*.name.required' => 'Give the script a name.',
            'scripts.*.code.required' => 'Paste the code, or remove this script.',
            'scripts.*.paths.*.regex' => 'An address starts with / and has no spaces, for example /p/summer-sale or /blog/*.',
        ]);

        $clean = [];
        foreach ($data['scripts'] ?? [] as $index => $script) {
            $paths = array_values(array_unique(array_filter(array_map('trim', $script['paths'] ?? []))));
            if ($script['scope'] === 'paths' && $paths === []) {
                throw ValidationException::withMessages(["scripts.$index.paths" => 'Add at least one address, or choose "Every page".']);
            }
            $clean[] = ['id' => ($script['id'] ?? '') ?: (string) Str::uuid(), 'name' => trim($script['name']), 'placement' => $script['placement'], 'scope' => $script['scope'],
                'paths' => $script['scope'] === 'paths' ? $paths : [], 'code' => $script['code'], 'enabled' => (bool) $script['enabled']];
        }
        $scripts->save($clean);
        $this->activity->log('edit', 'integrations', null, 'Custom scripts ('.count($clean).')');

        return back()->with('success', 'Custom scripts saved.');
    }

    // ---- Extra pixel IDs of one selling page ----

    public function updatePagePixels(Request $request, SellingPage $page): RedirectResponse
    {
        $rules = [];
        $messages = [];
        foreach (Integrations::PAGE_PIXELS as $service => $field) {
            $rules["pixels.$service"] = ['nullable', 'string', 'max:60', 'regex:'.Integrations::SERVICES[$service]['fields'][$field]['pattern']];
            $messages["pixels.$service.regex"] = 'This does not look like a valid '.Str::lower(Integrations::SERVICES[$service]['fields'][$field]['label']).'.';
        }
        $request->merge(['pixels' => array_map(fn ($value) => trim((string) $value), (array) $request->input('pixels', []))]);
        $data = $request->validate($rules, $messages);

        $page->update(['pixel_ids' => array_filter($data['pixels'] ?? []) ?: null]);
        $this->activity->log('edit', 'selling_pages', $page, 'Extra tracking IDs: '.$page->name);

        return back()->with('success', 'Tracking IDs saved.');
    }

    private static function mask(string $value): ?string
    {
        return $value === '' ? null : (mb_strlen($value) > 12 ? mb_substr($value, 0, 4).'…'.mb_substr($value, -4) : 'Saved');
    }
}
