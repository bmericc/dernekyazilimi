@extends('layouts.app')

@section('title', 'Referans daveti')

@section('content')
<div class="container container-narrow">
    <div class="page-header mb-3"><h2 class="page-title">Referans daveti</h2></div>

    @include('admin::partials.status')

    <div class="card">
        <div class="card-body">
            @if ($problem)
                <div class="alert alert-warning mb-0">{{ $problem }}</div>
            @elseif ($reference->status !== \Modules\Membership\Models\MembershipReference::PENDING)
                <p class="mb-0">Bu davete yanıt verdiniz: <strong>{{ $reference->statusLabel() }}</strong> ({{ $reference->responded_at?->format('d.m.Y') }}).</p>
            @else
                <p><strong>{{ $reference->applicant?->display_name }}</strong>, {{ $reference->application->submitted_at->format('d.m.Y') }} tarihli üyelik başvurusunda ({{ $reference->application->reference_no }}) sizi referans olarak gösterdi.</p>
                <p>Referans olmak, bu kişiyi tanıdığınızı ve derneğe üye olmasını desteklediğinizi beyan etmektir. Davetin son günü: {{ $reference->expires_at->format('d.m.Y') }}.</p>
                <form method="POST" action="{{ route('membership.references.respond', [$reference, $token]) }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label" for="note">Not (isteğe bağlı, yönetim kurulu görür)</label>
                        <textarea id="note" name="note" rows="2" maxlength="500" class="form-control">{{ old('note') }}</textarea>
                    </div>
                    <button type="submit" name="answer" value="accept" class="btn btn-success">Kabul ediyorum</button>
                    <button type="submit" name="answer" value="decline" class="btn btn-outline-danger">Referans olmak istemiyorum</button>
                </form>
            @endif
        </div>
    </div>
</div>
@endsection
