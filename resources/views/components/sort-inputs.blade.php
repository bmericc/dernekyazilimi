{{-- Keeps the table's sort when its filter form is submitted. --}}
@if (is_string(request()->query('sort')))
    <input type="hidden" name="sort" value="{{ request()->query('sort') }}">
    <input type="hidden" name="dir" value="{{ \App\Support\TableSort::direction(request()) }}">
@endif
