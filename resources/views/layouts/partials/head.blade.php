    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- CSRF Token -->
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $organization->name() }} - @yield('title')</title>
    @if ($organization->faviconUrl())
        <link rel="icon" href="{{ $organization->faviconUrl() }}">
    @endif

    <!-- Scripts -->
    <script src="{{ asset('js/app.js?v=').time() }}" defer></script>

    <!-- Styles -->
    <link href="{{ asset('css/app.css?v=').time() }}" rel="stylesheet">
    @if ($theme = $organization->themeCss())
        <style>{!! $theme !!}</style>
    @endif

    <script type="text/javascript">
		var _globalToken = {!! json_encode(array('_token'=> csrf_token())) !!}

        @if(env('APP_ENV')!="local")
            if (location.protocol !== 'https:') {
                location.replace(`https:${location.href.substring(location.protocol.length)}`);
            }
        @endif
	</script>

    @include('layouts.partials.google-tags')

    <x-head.tinymce-config/>
