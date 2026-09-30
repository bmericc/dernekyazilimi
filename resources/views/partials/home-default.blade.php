{{-- The home page shown while no content is written in Kurum ayarları; the settings editor starts from it. --}}
@if ($organization->logoUrl())
    <div style="text-align: center;">
        <img src="{{ $organization->logoUrl() }}" alt="{{ $organization->name() }}" style="width:100%; max-width: 450px;">
    </div>
@endif

@moduleSlot('welcome.intro')
