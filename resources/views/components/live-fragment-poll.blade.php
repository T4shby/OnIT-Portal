@props([
    'url',
    'targetId' => 'live-fragment',
    'seconds' => 5,
    'active' => false,
])

{{--
  Polls an HTML fragment and replaces #targetId only (no full page reload).
  Continues while the fragment root has data-should-poll="1".
--}}
<script>
    (function () {
        var url = @json($url);
        var targetId = @json($targetId);
        var seconds = {{ (int) $seconds }};
        var active = @json((bool) $active);
        var timer = null;

        function root() {
            return document.getElementById(targetId);
        }

        function shouldPoll(el) {
            if (!el || !el.dataset) {
                return false;
            }
            return el.dataset.shouldPoll === '1';
        }

        function stop() {
            if (timer) {
                clearInterval(timer);
                timer = null;
            }
        }

        function start() {
            if (timer) {
                return;
            }
            timer = setInterval(poll, seconds * 1000);
        }

        function poll() {
            var current = root();
            if (!current || !shouldPoll(current)) {
                stop();
                return;
            }

            fetch(url, {
                headers: {
                    'Accept': 'text/html',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            })
                .then(function (res) {
                    if (!res.ok) {
                        throw new Error('live poll failed');
                    }
                    return res.text();
                })
                .then(function (html) {
                    var wrap = document.createElement('div');
                    wrap.innerHTML = html.trim();
                    var next = wrap.firstElementChild;
                    var el = root();
                    if (!next || !el || !el.parentNode) {
                        return;
                    }
                    el.parentNode.replaceChild(next, el);
                    if (window.Alpine && typeof window.Alpine.initTree === 'function') {
                        window.Alpine.initTree(next);
                    }
                    if (!shouldPoll(next)) {
                        stop();
                    }
                })
                .catch(function () {
                    /* keep last good snapshot */
                });
        }

        if (active) {
            start();
        }

        document.addEventListener('visibilitychange', function () {
            if (document.hidden) {
                stop();
            } else if (shouldPoll(root())) {
                poll();
                start();
            }
        });
    })();
</script>
