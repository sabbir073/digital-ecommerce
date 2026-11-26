<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\MediaFolder;
use App\Services\ActivityLogger;
use App\Services\MediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class MediaController extends Controller
{
    public function __construct(private MediaService $media, private ActivityLogger $activity) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Media/Index', $this->listing($request));
    }

    /** JSON listing for the media picker dialog used across admin. */
    public function browse(Request $request): JsonResponse
    {
        return response()->json($this->listing($request));
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $maxKb = (int) config('store.media_max_kb');
        $data = $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:20'],
            'files.*' => ['file', 'max:'.$maxKb, 'mimetypes:'.implode(',', [...MediaService::IMAGE_MIMES, ...MediaService::VIDEO_MIMES])],
            'folder_id' => ['nullable', 'integer', Rule::exists('media_folders', 'id')],
        ], [
            'files.*.mimetypes' => 'Upload JPG, PNG, WebP or GIF images, or MP4 or WebM videos.',
            'files.*.max' => 'Each file can be up to '.round($maxKb / 1024).' MB.',
        ]);

        $created = [];
        foreach ($data['files'] as $file) {
            try {
                $item = $this->media->store($file, $data['folder_id'] ?? null);
            } catch (RuntimeException $e) {
                throw ValidationException::withMessages(['files' => $e->getMessage()]);
            }
            $this->activity->log('create', 'media', $item, $item->filename);
            $created[] = $item->toAdminArray();
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['items' => $created]);
        }

        return back()->with('success', count($created) === 1 ? 'File uploaded.' : count($created).' files uploaded.');
    }

    public function update(Request $request, Media $media): RedirectResponse
    {
        $data = $request->validate([
            'alt' => ['nullable', 'string', 'max:255'],
            'folder_id' => ['nullable', 'integer', Rule::exists('media_folders', 'id')],
        ]);

        $media->update($data);
        $this->activity->log('edit', 'media', $media, $media->filename);

        return back()->with('success', 'File details saved.');
    }

    public function destroy(Media $media): RedirectResponse
    {
        $name = $media->filename;
        $this->activity->log('delete', 'media', $media, $name);
        $this->media->delete($media);

        return back()->with('success', "{$name} was deleted.");
    }

    public function storeFolder(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);
        $folder = MediaFolder::create($data);
        $this->activity->log('create', 'media', $folder, "Folder: {$folder->name}");

        return back()->with('success', "Folder {$folder->name} created.");
    }

    public function destroyFolder(MediaFolder $folder): RedirectResponse
    {
        // Files in the folder are kept and move back to "All files".
        $this->activity->log('delete', 'media', $folder, "Folder: {$folder->name}");
        $folder->delete();

        return back()->with('success', 'Folder deleted. Its files were kept.');
    }

    /** @return array<string, mixed> */
    private function listing(Request $request): array
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'folder' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::in(['image', 'video'])],
        ]);

        $items = Media::query()
            ->when($filters['search'] ?? null, function ($q, $search) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
                $q->where(fn ($w) => $w->where('filename', 'like', $like)->orWhere('alt', 'like', $like));
            })
            ->when($filters['folder'] ?? null, fn ($q, $folder) => $q->where('folder_id', $folder))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->latest('id')
            ->paginate(36)
            ->withQueryString()
            ->through(fn (Media $m) => $m->toAdminArray());

        return [
            'items' => $items,
            'folders' => MediaFolder::query()->orderBy('name')->get(['id', 'name']),
            'filters' => (object) array_filter($filters, fn ($v) => $v !== null && $v !== ''),
            'maxUploadMb' => round(config('store.media_max_kb') / 1024),
        ];
    }
}
