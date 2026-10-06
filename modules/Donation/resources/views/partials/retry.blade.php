@if (app(\App\Support\Embed::class)->active())
    {{-- Inside the web site's frame the site shows its own form again. --}}
    <button type="button" class="btn btn-primary" onclick="window.parent.postMessage({ type: 'dernekyazilimi:restart' }, '*')">Yeniden dene</button>
@else
    <a href="{{ route('donations.create') }}" class="btn btn-primary">Yeniden dene</a>
@endif
