The MixTape library scan (php artisan app:update) found one or more configured
areas EMPTY while the database still has entries for them. Pruning was SKIPPED to
avoid wiping data — this is almost always a dropped mount or a permissions
problem, not a real deletion.

Host: {{ $host }}

Affected areas:
@foreach ($areas as $a)
  - {{ $a['area'] }}: found 0 files; {{ $a['rows'] }} row(s) left intact
@endforeach

Check the share/mount for these areas, then re-run app:update.

--
Automated alert from app:update. Full detail is in storage/logs/library.log on the host.
