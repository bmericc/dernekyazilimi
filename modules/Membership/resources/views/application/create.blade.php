@extends('layouts.app')

@section('title', 'Üyelik başvurusu')

@php($v = fn (string $key) => old($key, $defaults[$key] ?? null))

@section('content')
<div class="container">
    <div class="page-header mb-3">
        <h2 class="page-title">Üyelik başvurusu</h2>
        <div class="text-secondary mt-1">Bilgilerinizi doldurun. Başvurunuzdan sonra formun PDF hâlini indirip imzalayacaksınız.@if ($settings->referencesRequired() > 0) Referans gösterdiğiniz üyelere teyit için e-posta gönderilir.@endif</div>
    </div>

    <form method="POST" action="{{ route('membership.apply.store') }}" class="row row-cards">
        @csrf

        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="markdown">{!! $settings->letter() !!}</div>
                    @if ($fee = $settings->fees())
                        <div class="text-secondary mt-2">{{ now()->year }} yılı için @if ($settings->chargesEntryFee() && $fee->entry_fee !== null) giriş aidatı {{ \Modules\Membership\Models\MembershipFee::format($fee->entry_fee) }}, @endif yıllık üyelik aidatı {{ \Modules\Membership\Models\MembershipFee::format($fee->annual_fee) }}.</div>
                    @endif
                </div>
            </div>
        </div>

        @include('membership::application.partials.fields')

        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <x-agreement-checkbox key="kvkk" />
                    <button type="submit" class="btn btn-primary">Başvur</button>
                </div>
            </div>
        </div>
    </form>
</div>

@endsection
