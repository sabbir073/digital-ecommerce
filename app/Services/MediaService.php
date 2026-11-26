<?php

namespace App\Services;

use App\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stores uploads in the media library. Images are resized and compressed
 * automatically into WebP at several widths (PRD section 9).
 */
class MediaService
{
    public const WIDTHS = [320, 640, 960, 1440, 1920];

    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public const VIDEO_MIMES = ['video/mp4', 'video/webm'];

    private const QUALITY = 80;

    public function store(UploadedFile $file, ?int $folderId = null, ?string $alt = null): Media
    {
        return $this->storeFromPath($file->getRealPath(), $file->getClientOriginalName(), (string) $file->getMimeType(), $folderId, $alt);
    }

    public function storeFromPath(string $sourcePath, string $originalName, string $mime, ?int $folderId = null, ?string $alt = null): Media
    {
        $isImage = in_array($mime, self::IMAGE_MIMES, true);
        if (! $isImage && ! in_array($mime, self::VIDEO_MIMES, true)) {
            throw new RuntimeException('This file type is not supported.');
        }

        $disk = Storage::disk('public');
        $directory = 'media/'.date('Y/m').'/'.Str::lower(Str::random(12));
        $base = Str::slug(pathinfo($originalName, PATHINFO_FILENAME)) ?: 'file';
        $extension = Str::lower(pathinfo($originalName, PATHINFO_EXTENSION)) ?: ($isImage ? 'jpg' : 'mp4');
        $path = "{$directory}/{$base}.{$extension}";

        $disk->put($path, file_get_contents($sourcePath));

        $width = $height = null;
        $sizes = [];
        if ($isImage) {
            [$width, $height, $sizes] = $this->makeSizes($sourcePath, $mime, $directory, $base);
        }

        return Media::create([
            'folder_id' => $folderId,
            'disk' => 'public',
            'path' => $path,
            'filename' => $originalName,
            'type' => $isImage ? 'image' : 'video',
            'mime' => $mime,
            'size_bytes' => filesize($sourcePath) ?: 0,
            'alt' => $alt,
            'width' => $width,
            'height' => $height,
            'sizes' => $sizes ?: null,
        ]);
    }

    public function delete(Media $media): void
    {
        // Every file of one upload lives in its own directory.
        Storage::disk($media->disk)->deleteDirectory(dirname($media->path));
        $media->delete();
    }

    /** @return array{0:int, 1:int, 2:array<int, string>} width, height, [width => path] */
    private function makeSizes(string $sourcePath, string $mime, string $directory, string $base): array
    {
        $source = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($sourcePath),
            'image/png' => @imagecreatefrompng($sourcePath),
            'image/webp' => @imagecreatefromwebp($sourcePath),
            'image/gif' => @imagecreatefromgif($sourcePath),
            default => false,
        };

        if ($source === false) {
            throw new RuntimeException('The image could not be read. Try saving it again as JPG or PNG.');
        }

        if ($mime === 'image/jpeg') {
            $source = $this->applyOrientation($source, $sourcePath);
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $sizes = [];

        $targets = array_filter(self::WIDTHS, fn (int $w) => $w < $width);
        $targets[] = min($width, max(self::WIDTHS)); // never upscale

        foreach (array_unique($targets) as $target) {
            $targetHeight = (int) round($height * ($target / $width));
            $canvas = imagecreatetruecolor($target, $targetHeight);
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagecopyresampled($canvas, $source, 0, 0, 0, 0, $target, $targetHeight, $width, $height);

            ob_start();
            imagewebp($canvas, null, self::QUALITY);
            $data = ob_get_clean();
            imagedestroy($canvas);

            $sizePath = "{$directory}/{$base}-{$target}.webp";
            Storage::disk('public')->put($sizePath, $data);
            $sizes[$target] = $sizePath;
        }

        imagedestroy($source);

        return [$width, $height, $sizes];
    }

    /** Phone photos carry their rotation in EXIF data; apply it so the image is upright. */
    private function applyOrientation(\GdImage $image, string $path): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }
        $orientation = (@exif_read_data($path))['Orientation'] ?? 1;
        $angle = match ($orientation) {
            3 => 180, 6 => -90, 8 => 90, default => 0,
        };

        return $angle === 0 ? $image : (imagerotate($image, $angle, 0) ?: $image);
    }
}
