The MixTape library scan (php artisan app:update) failed and was aborted.

Host:    {{ $host }}
Error:   {{ $exceptionClass }}
Message: {{ $summary }}
Where:   {{ $location }}

Trace (truncated):
{{ $trace }}

--
Automated alert from app:update. Full detail is in storage/logs/library.log on the host.
