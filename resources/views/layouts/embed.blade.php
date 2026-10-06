{{-- layouts.app inside a frame on the association's web site: the page alone,
     on a transparent background, reporting its height to the site. --}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('layouts.partials.head')
    <style>
        html, body, .page, .page-wrapper { background: transparent !important; min-height: 0 !important; }
        .page-body { margin: 0; padding: 4px 0; }
        .page-body > .container, .page-body > .container-xl, .page-body > .container-narrow { max-width: none; padding-left: 4px; padding-right: 4px; }
        .page-header { display: none; }
    </style>
    <script>
        // Script requests of a framed page say so (App\Support\Embed).
        document.addEventListener('DOMContentLoaded', function () {
            if (window.jQuery) { jQuery.ajaxSetup({ headers: { 'X-Embed': '1' } }); }
            if (window.axios) { window.axios.defaults.headers.common['X-Embed'] = '1'; }
        });
        (function () {
            var send = function () {
                window.parent.postMessage({ type: 'dernekyazilimi:height', height: Math.ceil(document.documentElement.getBoundingClientRect().height) }, '*');
            };
            window.addEventListener('load', send);
            document.addEventListener('DOMContentLoaded', function () {
                send();
                if (window.ResizeObserver) { new ResizeObserver(send).observe(document.documentElement); }
            });
        })();
    </script>
</head>
<body>
    @include('layouts.partials.google-tags-noscript')

    <div class="page" id="app">
        <div class="page-wrapper">
            <div class="page-body">
                @yield('content')
            </div>
        </div>
    </div>

    @include('layouts.partials.scripts')
</body>
</html>
