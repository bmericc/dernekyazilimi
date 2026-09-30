<p>Merhaba {{ $contact->display_name }},</p>

@if ($reminder)
<p>{{ $organization->name() }} üyelik aidatınızdan ödenmemiş <strong>{{ $balance }}</strong> bulunuyor.</p>
@else
<p>Aidat bakiyeniz sorgulandı. Güncel borcunuz: <strong>{{ $balance }}</strong>.</p>
@endif

@if ($open)
<ul>
@foreach ($open as $item)
    <li>{{ $item['label'] }}: {{ $item['remaining'] }}</li>
@endforeach
</ul>
@endif

<p>Hareketlerinizi görmek ve ödeme yapmak için: <a href="{{ $link }}">{{ $link }}</a></p>
<p>Bu bağlantı size özeldir ve {{ \Modules\Dues\Support\DuesService::LINK_DAYS }} gün geçerlidir; başkasıyla paylaşmayın.</p>

@unless ($reminder)
<p>Bu sorgulamayı siz yapmadıysanız bu e-postayı dikkate almayın.</p>
@endunless

<p>{{ $organization->name() }}</p>
