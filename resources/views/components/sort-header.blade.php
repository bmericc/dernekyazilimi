{{-- A table header that sorts on the server: <x-sort-header column="amount">Tutar</x-sort-header>.
     The controller orders with App\Support\TableSort; first is the direction of the first click. --}}
@props(['column', 'first' => 'asc'])
@php
    $active = request()->query('sort') === $column;
    $direction = \App\Support\TableSort::direction(request());
    $next = $active ? ($direction === 'asc' ? 'desc' : 'asc') : $first;
@endphp
<th {{ $attributes }} @if ($active) aria-sort="{{ $direction === 'asc' ? 'ascending' : 'descending' }}" @endif>
    <a href="{{ request()->fullUrlWithQuery(['sort' => $column, 'dir' => $next, 'page' => null]) }}" class="text-reset text-decoration-none text-nowrap">{{ $slot }}<i class="ti ti-{{ $active ? ($direction === 'asc' ? 'arrow-up' : 'arrow-down') : 'arrows-sort opacity-25' }} ms-1"></i></a>
</th>
