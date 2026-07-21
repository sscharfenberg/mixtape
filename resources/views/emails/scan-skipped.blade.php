The MixTape library scan (php artisan app:update) finished, but {{ $total }} file(s)
could not be read and were skipped (the rest of the library imported normally).

Host: {{ $host }}

@foreach ($skipped as $s)
- {{ $s['path'] }}
    {{ $s['reason'] }}

@endforeach
@if (count($skipped) < $total)
… and {{ $total - count($skipped) }} more (see storage/logs/library.log for the full list).
@endif
These are usually malformed files (a bad rip or broken tags). Re-muxing often fixes
them, e.g.: ffmpeg -i "in.mp3" -c copy "fixed.mp3" — then re-run app:update.

--
Automated summary from app:update. Full detail is in storage/logs/library.log on the host.
