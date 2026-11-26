<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\ContentPage;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\SellingPage;
use App\Models\Setting;
use App\Services\ActivityLogger;
use App\Services\Content\Navigation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** The header menu, the footer menus and the announcement bar. */
class MenuController extends Controller
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function index(): Response
    {
        $menus = Menu::query()->with('items.children')->get()->keyBy('location');
        $item = fn (MenuItem $i) => ['label' => $i->label, 'link_type' => $i->link_type, 'link_id' => $i->link_id, 'url' => $i->url ?? ''];

        return Inertia::render('Content/Navigation', [
            'menus' => collect(Navigation::LOCATIONS)->map(fn (string $label, string $location) => [
                'location' => $location,
                'label' => $label,
                'name' => $menus->get($location)?->name ?? ($location === 'header' ? 'Main menu' : ''),
                'items' => ($menus->get($location)?->items ?? collect())->map(fn (MenuItem $i) => $item($i) + ['children' => $i->children->map($item)->values()])->values(),
            ])->values(),
            'targets' => [
                'category' => Category::query()->orderBy('name')->get(['id', 'name'])->map(fn ($c) => ['id' => $c->id, 'label' => $c->name]),
                'page' => ContentPage::query()->orderBy('title')->get(['id', 'title', 'status'])->map(fn ($p) => ['id' => $p->id, 'label' => $p->title.($p->status === ContentPage::PUBLISHED ? '' : ' (draft, hidden until published)')]),
                'selling_page' => SellingPage::query()->orderBy('name')->get(['id', 'name', 'status'])->map(fn ($p) => ['id' => $p->id, 'label' => $p->name.($p->status === SellingPage::PUBLISHED ? '' : ' (draft, hidden until published)')]),
            ],
            'announcement' => $this->announcement(),
        ]);
    }

    public function update(Request $request, string $location): RedirectResponse
    {
        abort_unless(isset(Navigation::LOCATIONS[$location]), 404);

        $link = [
            'label' => ['required', 'string', 'max:60'],
            'link_type' => ['required', Rule::in(MenuItem::TYPES)],
            'link_id' => ['nullable', 'integer'],
            'url' => ['nullable', 'string', 'max:500', 'regex:~^(https?://|mailto:|tel:|/(?!/))~i'],
        ];
        $rules = ['name' => [$location === 'header' ? 'nullable' : 'required', 'string', 'max:60'], 'items' => ['present', 'array', 'max:20']];
        foreach ($link as $key => $rule) {
            $rules["items.*.{$key}"] = $rule;
            $rules["items.*.children.*.{$key}"] = $rule;
        }
        $rules['items.*.children'] = ['array', $location === 'header' ? 'max:12' : 'max:0'];

        $data = $request->validate($rules, [
            'name.required' => 'Give this footer column a heading.',
            'items.*.label.required' => 'Every link needs a label.',
            'items.*.children.*.label.required' => 'Every link needs a label.',
            'items.*.url.regex' => 'A link address starts with https:// or with / for a page of this store.',
            'items.*.children.*.url.regex' => 'A link address starts with https:// or with / for a page of this store.',
        ]);

        $tables = ['category' => 'categories', 'page' => 'content_pages', 'selling_page' => 'selling_pages'];
        $check = function (array $item, string $path) use ($tables): void {
            if ($item['link_type'] === 'url' && empty($item['url'])) {
                throw ValidationException::withMessages(["{$path}.url" => 'Enter the link address.']);
            }
            if (isset($tables[$item['link_type']]) && ! DB::table($tables[$item['link_type']])->where('id', $item['link_id'] ?? 0)->exists()) {
                throw ValidationException::withMessages(["{$path}.link_id" => 'Choose what this link opens.']);
            }
        };

        DB::transaction(function () use ($data, $location, $check) {
            $menu = Menu::query()->updateOrCreate(['location' => $location], ['name' => ($data['name'] ?? '') ?: 'Main menu']);
            MenuItem::query()->where('menu_id', $menu->id)->delete();

            $row = fn (array $item, int $position, ?int $parent) => [
                'menu_id' => $menu->id, 'parent_id' => $parent, 'label' => $item['label'], 'link_type' => $item['link_type'],
                'link_id' => in_array($item['link_type'], ['url', 'blog'], true) ? null : (int) $item['link_id'],
                'url' => $item['link_type'] === 'url' ? $item['url'] : null, 'position' => $position,
            ];
            foreach (array_values($data['items']) as $i => $item) {
                $check($item, "items.{$i}");
                $parent = MenuItem::create($row($item, $i, null));
                foreach (array_values($item['children'] ?? []) as $j => $child) {
                    $check($child, "items.{$i}.children.{$j}");
                    MenuItem::create($row($child, $j, $parent->id));
                }
            }
        });

        $this->activity->log('edit', 'content', null, Navigation::LOCATIONS[$location]);

        return back()->with('success', Navigation::LOCATIONS[$location].' saved.');
    }

    public function saveAnnouncement(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'text' => ['required_if:enabled,true', 'nullable', 'string', 'max:160'],
            'link' => ['nullable', 'string', 'max:500', 'regex:~^(https://|/(?!/))~i'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ], [
            'text.required_if' => 'Write the text of the bar, or switch it off.',
            'link.regex' => 'A link address starts with https:// or with / for a page of this store.',
            'ends_at.after' => 'The end must be after the start.',
        ]);

        Setting::put('announcement', [
            'enabled' => (bool) $data['enabled'],
            'text' => $data['text'] ?? '',
            'link' => $data['link'] ?? null,
            'starts_at' => ($data['starts_at'] ?? null) ? Carbon::parse($data['starts_at'])->toIso8601String() : null,
            'ends_at' => ($data['ends_at'] ?? null) ? Carbon::parse($data['ends_at'])->toIso8601String() : null,
        ]);
        $this->activity->log('edit', 'content', null, 'Announcement bar');

        return back()->with('success', 'Announcement bar saved.');
    }

    /** @return array<string, mixed> */
    private function announcement(): array
    {
        $bar = (array) Setting::get('announcement', []);

        return [
            'enabled' => (bool) ($bar['enabled'] ?? false),
            'text' => (string) ($bar['text'] ?? ''),
            'link' => (string) ($bar['link'] ?? ''),
            'starts_at' => $bar['starts_at'] ?? null,
            'ends_at' => $bar['ends_at'] ?? null,
            'demo' => (bool) ($bar['demo'] ?? false),
        ];
    }
}
