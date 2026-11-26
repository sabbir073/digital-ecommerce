@php
    $file = $media[$d['video_id']] ?? null;
    $video = $file && $file->type === 'video' ? ['kind' => 'file', 'src' => $file->url()] : App\Support\VideoEmbed::resolve($d['url']);
@endphp
@if ($video)
    @include('selling.blocks._open')
        <div class="container-selling">
            <div class="mx-auto max-w-4xl">
                @include('selling.blocks._player', ['video' => $video, 'poster' => $media[$d['poster_id']] ?? null, 'title' => $d['title']])
            </div>
        </div>
    </section>
@endif
