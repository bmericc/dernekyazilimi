@extends('layouts.app')

@section('title', 'Üyelik başvurum')

@section('content')
<div class="container">
    <div class="page-header mb-3">
        <h2 class="page-title">Üyelik başvurum</h2>
    </div>

    @include('admin::partials.status')

    @if (! $application)
        <div class="card">
            <div class="card-body">
                @if ($blocker)
                    <p class="text-secondary mb-0">{{ $blocker }}</p>
                @else
                    <p>Henüz bir üyelik başvurunuz yok.</p>
                    <a href="{{ route('membership.apply') }}" class="btn btn-primary">Üyelik başvurusu yap</a>
                @endif
            </div>
        </div>
    @else
        <div class="row row-cards">
            <div class="col-lg-7">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Başvuru {{ $application->reference_no }}</h3>
                        <div class="card-actions"><span class="badge bg-{{ \Modules\Membership\Models\MembershipApplication::STATUS_COLORS[$application->status] }}-lt">{{ $application->statusLabel() }}</span></div>
                    </div>
                    <div class="card-body">
                        <dl class="row mb-3">
                            <dt class="col-md-5">Başvuru tarihi</dt><dd class="col-md-7">{{ $application->submitted_at->format('d.m.Y H:i') }}</dd>
                            <dt class="col-md-5">İmzalı form</dt><dd class="col-md-7">{{ $application->signed_form_received_at ? 'Dernek tarafından alındı ('.$application->signed_form_received_at->format('d.m.Y').')' : 'Henüz alınmadı' }}</dd>
                            @if ($application->decided_at)<dt class="col-md-5">Karar</dt><dd class="col-md-7">{{ $application->decided_at->format('d.m.Y') }}@if ($application->decision_note) — {{ $application->decision_note }}@endif</dd>@endif
                        </dl>
                        <a href="{{ route('membership.application.pdf') }}" class="btn btn-primary">Başvuru formunu indir (PDF)</a>
                        @if ($application->isOpen())
                            <form method="POST" action="{{ route('membership.application.withdraw') }}" class="d-inline" onsubmit="return confirm('Başvurunuzu geri çekmek istediğinize emin misiniz?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-outline-danger">Başvuruyu geri çek</button>
                            </form>
                        @endif
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card">
                    <div class="card-header"><h3 class="card-title">Sonraki adımlar</h3></div>
                    <div class="card-body">
                        @if ($settings->instructions())
                            <div class="markdown">{!! $settings->instructions() !!}</div>
                        @else
                            <p class="mb-0">Formu indirip imzalayın ve derneğe ulaştırın. Başvurunuz yönetim kurulunda değerlendirildikten sonra size e-posta ile bilgi verilir.</p>
                        @endif
                    </div>
                </div>
            </div>

            @if ($application->references->isNotEmpty())
                <div class="col-12">
                    <div class="card">
                        <div class="card-header"><h3 class="card-title">Referanslarım</h3></div>
                        <div class="table-responsive">
                            <table class="table table-vcenter card-table" data-no-datatable>
                                <thead><tr><th>#</th><th>Üye</th><th>Durum</th><th>Tarih</th><th></th></tr></thead>
                                <tbody>
                                    @foreach ($application->references->where('status', '!=', \Modules\Membership\Models\MembershipReference::WITHDRAWN) as $reference)
                                        <tr>
                                            <td>{{ $reference->position }}</td>
                                            <td>{{ $reference->referee?->display_name }}</td>
                                            <td><span class="badge bg-{{ $reference->statusColor() }}-lt">{{ $reference->statusLabel() }}</span></td>
                                            <td class="text-secondary">{{ ($reference->responded_at ?? $reference->invited_at)->format('d.m.Y') }}</td>
                                            <td>
                                                @if ($application->isOpen() && $reference->status !== \Modules\Membership\Models\MembershipReference::ACCEPTED)
                                                    <form method="POST" action="{{ route('membership.references.replace', $reference) }}" class="d-flex gap-2">
                                                        @csrf @method('PUT')
                                                        <input name="number" class="form-control form-control-sm" placeholder="Üye no" required>
                                                        <input name="surname" class="form-control form-control-sm" placeholder="Soyadı" required>
                                                        <button type="submit" class="btn btn-sm btn-outline-primary">Değiştir</button>
                                                    </form>
                                                    @error('replace_'.$reference->id)<div class="text-danger small">{{ $message }}</div>@enderror
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="card-footer text-secondary small">Kabul etmeyen ya da süresinde yanıt vermeyen referansın yerine başka bir üye gösterebilirsiniz.</div>
                    </div>
                </div>
            @endif
        </div>

        @if (! $application->isOpen() && ! $blocker)
            <a href="{{ route('membership.apply') }}" class="btn btn-primary mt-3">Yeni başvuru yap</a>
        @endif
    @endif
</div>
@endsection
