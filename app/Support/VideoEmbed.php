<?php

namespace App\Support;

/** Turns a video link typed in admin into something safe to play: a known player, or a video file. */
class VideoEmbed
{
    /** @return array{kind:'iframe'|'file', src:string}|null */
    public static function resolve(?string $url): ?array
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }
        if (preg_match('~^https://(?:www\.|m\.)?youtube\.com/watch\?(?:.*&)?v=([A-Za-z0-9_-]{6,20})~', $url, $m)
            || preg_match('~^https://youtu\.be/([A-Za-z0-9_-]{6,20})~', $url, $m)
            || preg_match('~^https://(?:www\.)?youtube\.com/(?:embed|shorts)/([A-Za-z0-9_-]{6,20})~', $url, $m)) {
            return ['kind' => 'iframe', 'src' => "https://www.youtube-nocookie.com/embed/{$m[1]}?autoplay=1&rel=0"];
        }
        if (preg_match('~^https://(?:www\.|player\.)?vimeo\.com/(?:video/)?(\d{5,12})~', $url, $m)) {
            return ['kind' => 'iframe', 'src' => "https://player.vimeo.com/video/{$m[1]}?autoplay=1"];
        }
        if (preg_match('~^(https://|/(?!/))[^\s"<>]+\.(mp4|webm|mov)(\?[^\s"<>]*)?$~i', $url)) {
            return ['kind' => 'file', 'src' => $url];
        }

        return null;
    }
}
