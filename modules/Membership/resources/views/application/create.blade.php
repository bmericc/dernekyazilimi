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

        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><h3 class="card-title">İletişim bilgileri</h3></div>
                <div class="card-body">
                    <div class="mb-3">
                        <div class="form-label required">Cinsiyet</div>
                        @foreach (\Modules\Membership\Models\MembershipApplication::GENDERS as $key => $label)
                            <label class="form-check form-check-inline"><input type="radio" class="form-check-input" name="gender" value="{{ $key }}" @checked($v('gender') === $key) required><span class="form-check-label">{{ $label }}</span></label>
                        @endforeach
                        @error('gender')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3"><label class="form-label required" for="first_name">Ad</label><input id="first_name" name="first_name" class="form-control @error('first_name') is-invalid @enderror" value="{{ $v('first_name') }}" required>@error('first_name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div class="col-md-6 mb-3"><label class="form-label required" for="last_name">Soyad</label><input id="last_name" name="last_name" class="form-control @error('last_name') is-invalid @enderror" value="{{ $v('last_name') }}" required>@error('last_name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    </div>
                    <div class="mb-3"><label class="form-label required" for="address">Posta adresi</label><textarea id="address" name="address" rows="2" maxlength="500" class="form-control @error('address') is-invalid @enderror" required>{{ $v('address') }}</textarea>@error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    <div class="row">
                        <div class="col-md-6 mb-3"><label class="form-label required" for="email">E-posta</label><input id="email" type="email" name="email" class="form-control @error('email') is-invalid @enderror" value="{{ $v('email') }}" required>@error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div class="col-md-6 mb-3"><label class="form-label required" for="phone">Telefon</label><input id="phone" name="phone" class="form-control @error('phone') is-invalid @enderror" value="{{ $v('phone') }}" required>@error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><h3 class="card-title">Nüfus bilgileri</h3></div>
                <div class="card-body">
                    <div class="mb-3">
                        @foreach (['tr' => 'T.C. vatandaşıyım', 'foreign' => 'Yabancı uyrukluyum'] as $key => $label)
                            <label class="form-check form-check-inline"><input type="radio" class="form-check-input" name="nationality_type" value="{{ $key }}" @checked($v('nationality_type') === $key) data-nationality><span class="form-check-label">{{ $label }}</span></label>
                        @endforeach
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3" data-tr-only><label class="form-label" for="identity_number">TC kimlik no</label><input id="identity_number" name="identity_number" class="form-control @error('identity_number') is-invalid @enderror" value="{{ $v('identity_number') }}" maxlength="11">@error('identity_number')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div class="col-md-6 mb-3"><label class="form-label required" for="nationality">Tabiiyet</label><input id="nationality" name="nationality" class="form-control @error('nationality') is-invalid @enderror" value="{{ $v('nationality') }}" required>@error('nationality')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div class="col-md-6 mb-3"><label class="form-label required" for="mother_name">Anne adı</label><input id="mother_name" name="mother_name" class="form-control @error('mother_name') is-invalid @enderror" value="{{ $v('mother_name') }}" required>@error('mother_name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div class="col-md-6 mb-3"><label class="form-label required" for="birthday">Doğum tarihi</label><input id="birthday" type="date" name="birthday" class="form-control @error('birthday') is-invalid @enderror" value="{{ $v('birthday') }}" required>@error('birthday')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    </div>
                    <div class="row" data-foreign-only>
                        <div class="col-md-6 mb-3"><label class="form-label" for="foreign_identity_number">Yabancı kimlik no</label><input id="foreign_identity_number" name="foreign_identity_number" class="form-control @error('foreign_identity_number') is-invalid @enderror" value="{{ $v('foreign_identity_number') }}">@error('foreign_identity_number')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div class="col-md-6 mb-3"><div class="form-label">Oturma izni</div>@foreach (['yes' => 'Var', 'no' => 'Yok'] as $key => $label)<label class="form-check form-check-inline"><input type="radio" class="form-check-input" name="residence_permit" value="{{ $key }}" @checked($v('residence_permit') === $key)><span class="form-check-label">{{ $label }}</span></label>@endforeach @error('residence_permit')<div class="text-danger small">{{ $message }}</div>@enderror</div>
                        <div class="col-md-6 mb-3"><label class="form-label" for="document_type">Belge türü</label><select id="document_type" name="document_type" class="form-select"><option value="">—</option>@foreach (\Modules\Membership\Models\MembershipApplication::DOCUMENT_TYPES as $key => $label)<option value="{{ $key }}" @selected($v('document_type') === $key)>{{ $label }}</option>@endforeach</select>@error('document_type')<div class="text-danger small">{{ $message }}</div>@enderror</div>
                        <div class="col-md-6 mb-3"><label class="form-label" for="document_number">Belge no</label><input id="document_number" name="document_number" class="form-control" value="{{ $v('document_number') }}">@error('document_number')<div class="text-danger small">{{ $message }}</div>@enderror</div>
                        <div class="col-12 mb-3"><input name="document_type_other" class="form-control" value="{{ $v('document_type_other') }}" placeholder="Belge türü diğer ise yazın">@error('document_type_other')<div class="text-danger small">{{ $message }}</div>@enderror</div>
                    </div>
                </div>
            </div>
        </div>

        @if ($settings->referencesRequired() > 0)
            <div class="col-12">
                <div class="card">
                    <div class="card-header"><h3 class="card-title">Referanslarınız</h3></div>
                    <div class="card-body">
                        <p class="text-secondary">Referansınız derneğin üyesi olmalıdır. Referans gösterdiğiniz üyenin <strong>üye numarasını</strong> ve <strong>soyadını</strong> yazın; teyit için kendisine e-posta gönderilir.</p>
                        @for ($i = 0; $i < $settings->referencesRequired(); $i++)
                            <div class="row g-2 mb-2">
                                <div class="col-md-1 col-form-label">{{ $i + 1 }}.</div>
                                <div class="col-md-4"><input name="references[{{ $i }}][number]" class="form-control @error("references.$i.number") is-invalid @enderror" value="{{ old("references.$i.number") }}" placeholder="Üye no" required>@error("references.$i.number")<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                                <div class="col-md-5"><input name="references[{{ $i }}][surname]" class="form-control @error("references.$i.surname") is-invalid @enderror" value="{{ old("references.$i.surname") }}" placeholder="Soyadı" required></div>
                            </div>
                        @endfor
                    </div>
                </div>
            </div>
        @endif

        @if ($settings->askPhotoChoice())
            <div class="col-12">
                <div class="card">
                    <div class="card-header"><h3 class="card-title">Üye kartı ve dernek kullanımı için dijital fotoğraf</h3></div>
                    <div class="card-body">
                        @foreach (\Modules\Membership\Models\MembershipApplication::PHOTO_CHOICES as $key => $label)
                            <label class="form-check"><input type="radio" class="form-check-input" name="photo_choice" value="{{ $key }}" @checked($v('photo_choice') === $key) required><span class="form-check-label">{{ $label }}</span></label>
                        @endforeach
                        @error('photo_choice')<div class="text-danger small">{{ $message }}</div>@enderror
                        <div class="form-hint">Fotoğrafınızı profil sayfanızdan yükleyebilirsiniz.</div>
                    </div>
                </div>
            </div>
        @endif

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

<script>
    // Show the TC or the foreigner fields for the chosen nationality.
    (function () {
        function toggle() {
            var foreign = document.querySelector('[data-nationality][value="foreign"]').checked;
            document.querySelectorAll('[data-foreign-only]').forEach(function (el) { el.style.display = foreign ? '' : 'none'; });
            document.querySelectorAll('[data-tr-only]').forEach(function (el) { el.style.display = foreign ? 'none' : ''; });
        }
        document.querySelectorAll('[data-nationality]').forEach(function (el) { el.addEventListener('change', toggle); });
        toggle();
    })();
</script>
@endsection
