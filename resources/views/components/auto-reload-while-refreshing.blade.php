@props([
    'enabled' => false,
    'seconds' => 8,
    'maxAttempts' => 30,
])

@if($enabled)
    <p class="portal-body-muted text-xs mt-1" data-auto-reload-notice>
        Updating automatically every {{ (int) $seconds }} seconds…
    </p>
    <script>
        (function () {
            var seconds = {{ (int) $seconds }};
            var maxAttempts = {{ (int) $maxAttempts }};
            var key = 'auto_reload_attempts:' + window.location.pathname;
            var attempts = 0;
            try {
                attempts = parseInt(sessionStorage.getItem(key) || '0', 10) || 0;
            } catch (e) {}
            if (attempts >= maxAttempts) {
                return;
            }
            setTimeout(function () {
                try {
                    sessionStorage.setItem(key, String(attempts + 1));
                } catch (e) {}
                window.location.reload();
            }, seconds * 1000);
        })();
    </script>
@else
    <script>
        try {
            sessionStorage.removeItem('auto_reload_attempts:' + window.location.pathname);
        } catch (e) {}
    </script>
@endif
