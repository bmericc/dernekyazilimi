@extends('layouts.admin')

@php($canManage = Auth::user()->hasPermission('memberships.manage'))
@php($a = $application)

@section('content')
<div class="container-xl">
    <div class="page-header mb-3 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="page-title">Başvuru {{ $a->reference_no }}</h2>
            <div class="text-secondary mt-1">{{ $a->contact?->display_name }} · {{ $a->submitted_at->format('d.m.Y H:i') }}</div>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <span class="badge bg-{{ \Modules\Membership\Models\MembershipApplication::STATUS_COLORS[$a->status] }}-lt">{{ $a->statusLabel() }}</span>
            <a href="{{ route('admin.membership-applications.pdf', $a) }}" target="_blank" class="btn btn-outline-primary">Formu görüntüle (PDF)</a>
            @if (Auth::user()->hasPermission('contacts.view') && $a->contact && ! $a->contact->trashed())
                <a href="{{ route('admin.contacts.show', $a->contact) }}" class="btn btn-outline-secondary">Kişi sayfası</a>
            @endif
        </div>
    </div>

    @include('admin::partials.status')

    <div class="row row-cards">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header"><h3 class="card-title">Formdaki bilgiler</h3></div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-md-4">Cinsiyet</dt><dd class="col-md-8">{{ \Modules\Membership\Models\MembershipApplication::GENDERS[$a->answer('gender')] ?? '—' }}</dd>
                        <dt class="col-md-4">Ad soyad</dt><dd class="col-md-8">{{ $a->answer('first_name') }} {{ $a->answer('last_name') }}</dd>
                        <dt class="col-md-4">Posta adresi</dt><dd class="col-md-8">{{ $a->answer('address') }}</dd>
                        <dt class="col-md-4">E-posta</dt><dd class="col-md-8">{{ $a->answer('email') }}</dd>
                        <dt class="col-md-4">Telefon</dt><dd class="col-md-8">{{ $a->answer('phone') }}</dd>
                        <dt class="col-md-4">Tabiiyet</dt><dd class="col-md-8">{{ $a->answer('nationality') }}</dd>
                        @if ($a->answer('nationality_type') === 'foreign')
                            <dt class="col-md-4">Yabancı kimlik no</dt><dd class="col-md-8">{{ $a->answer('foreign_identity_number') }}</dd>
                            <dt class="col-md-4">Oturma izni</dt><dd class="col-md-8">{{ $a->answer('residence_permit') === 'yes' ? 'Var' : 'Yok' }}</dd>
                            <dt class="col-md-4">Belge</dt><dd class="col-md-8">{{ $a->answer('document_type') === 'other' ? $a->answer('document_type_other') : (\Modules\Membership\Models\MembershipApplication::DOCUMENT_TYPES[$a->answer('document_type')] ?? '—') }} {{ $a->answer('document_number') }}</dd>
                        @else
                            <dt class="col-md-4">TC kimlik no</dt><dd class="col-md-8">{{ $a->answer('identity_number') }}</dd>
                        @endif
                        <dt class="col-md-4">Anne adı</dt><dd class="col-md-8">{{ $a->answer('mother_name') }}</dd>
                        <dt class="col-md-4">Doğum tarihi</dt><dd class="col-md-8">{{ $a->answer('birthday') ? \Illuminate\Support\Carbon::parse($a->answer('birthday'))->format('d.m.Y') : '—' }}</dd>
                        @if ($a->answer('photo_choice'))
                            <dt class="col-md-4">Fotoğraf</dt><dd class="col-md-8">{{ \Modules\Membership\Models\MembershipApplication::PHOTO_CHOICES[$a->answer('photo_choice')] ?? '' }}</dd>
                        @endif
                    </dl>
                </div>
            </div>

            @if ($a->references->isNotEmpty())
                <div class="card mt-3">
                    <div class="card-header"><h3 class="card-title">Referanslar</h3></div>
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table" data-no-datatable>
                            <thead><tr><th>#</th><th>Üye</th><th>Durum</th><th>Davet / yanıt</th><th></th></tr></thead>
                            <tbody>
                                @foreach ($a->references as $reference)
                                    <tr @class(['text-secondary' => $reference->status === \Modules\Membership\Models\MembershipReference::WITHDRAWN])>
                                        <td>{{ $reference->position }}</td>
                                        <td>
                                            {{ $reference->referee?->display_name }}
                                            @if ($reference->response_note)<div class="small text-secondary">“{{ $reference->response_note }}”</div>@endif
                                        </td>
                                        <td><span class="badge bg-{{ $reference->statusColor() }}-lt">{{ $reference->statusLabel() }}</span></td>
                                        <td class="small">{{ $reference->invited_at->format('d.m.Y') }}@if ($reference->responded_at) / {{ $reference->responded_at->format('d.m.Y') }}@endif</td>
                                        <td>
                                            @if ($canManage && $a->isOpen() && $reference->status === \Modules\Membership\Models\MembershipReference::PENDING)
                                                <form method="POST" action="{{ route('admin.membership-references.resend', $reference) }}">@csrf<button type="submit" class="btn btn-sm btn-outline-secondary">Daveti yeniden gönder</button></form>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>

        <div class="col-lg-5">
            <div class="card">
                <div class="card-header"><h3 class="card-title">İmzalı form</h3></div>
                <div class="card-body">
                    <p class="mb-2">{{ $a->signed_form_received_at ? 'Alındı: '.$a->signed_form_received_at->format('d.m.Y') : 'Henüz alınmadı.' }}</p>
                    @if ($canManage)
                        <form method="POST" action="{{ route('admin.membership-applications.signed-form', $a) }}">@csrf @method('PATCH')
                            <button type="submit" class="btn btn-outline-primary btn-sm">{{ $a->signed_form_received_at ? 'İşareti kaldır' : 'Alındı olarak işaretle' }}</button>
                        </form>
                    @endif
                </div>
            </div>

            @if ($a->decided_at)
                <div class="card mt-3">
                    <div class="card-header"><h3 class="card-title">Karar</h3></div>
                    <div class="card-body">
                        <p class="mb-1">{{ $a->statusLabel() }} · {{ $a->decided_at->format('d.m.Y') }}</p>
                        @if ($a->membership?->decision_number)<p class="mb-1">Karar: {{ $a->membership->decision_date?->format('d.m.Y') }} / {{ $a->membership->decision_number }}</p>@endif
                        @if ($a->decision_note)<p class="mb-0 text-secondary">{{ $a->decision_note }}</p>@endif
                    </div>
                </div>
            @endif

            @if ($canManage && $a->isOpen())
                <div class="card mt-3">
                    <div class="card-header"><h3 class="card-title">Kabul et</h3></div>
                    <div class="card-body">
                        @if ($a->status !== \Modules\Membership\Models\MembershipApplication::READY)
                            <div class="alert alert-warning">Referans teyitleri tamamlanmadı.</div>
                        @endif
                        <form method="POST" action="{{ route('admin.membership-applications.approve', $a) }}">
                            @csrf @method('PATCH')
                            <div class="mb-2"><label class="form-label" for="number">Üye no</label><input id="number" name="number" class="form-control @error('number') is-invalid @enderror" value="{{ old('number', $a->membership?->number) }}" placeholder="Boş bırakılırsa {{ $nextNumber }}">@error('number')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                            <div class="mb-2"><label class="form-label required" for="joined_at">Katılma tarihi</label><input id="joined_at" type="date" name="joined_at" class="form-control @error('joined_at') is-invalid @enderror" value="{{ old('joined_at', today()->toDateString()) }}" required>@error('joined_at')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                            <div class="row">
                                <div class="col-6 mb-2"><label class="form-label" for="decision_date">Karar tarihi</label><input id="decision_date" type="date" name="decision_date" class="form-control" value="{{ old('decision_date') }}"></div>
                                <div class="col-6 mb-2"><label class="form-label" for="decision_number">Karar numarası</label><input id="decision_number" name="decision_number" class="form-control" value="{{ old('decision_number') }}"></div>
                            </div>
                            <button type="submit" class="btn btn-success" onclick="return confirm('Başvuru kabul edilip kişi üye yapılacak. Emin misiniz?')">Kabul et, üye yap</button>
                        </form>
                    </div>
                </div>

                <div class="card mt-3">
                    <div class="card-header"><h3 class="card-title">Reddet</h3></div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('admin.membership-applications.reject', $a) }}">
                            @csrf @method('PATCH')
                            <div class="mb-2"><label class="form-label required" for="note">Gerekçe (başvurana e-postayla bildirilir)</label><textarea id="note" name="note" rows="3" class="form-control @error('note') is-invalid @enderror" required>{{ old('note') }}</textarea>@error('note')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                            <button type="submit" class="btn btn-outline-danger" onclick="return confirm('Başvuru reddedilecek. Emin misiniz?')">Reddet</button>
                        </form>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
