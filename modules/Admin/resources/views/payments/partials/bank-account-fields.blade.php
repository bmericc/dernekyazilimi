{{-- Bank account inputs. $account (?BankAccount), $purposes --}}
@php($new = $account === null)
<div class="row">
    <div class="col-md-4 mb-2"><label class="form-label required">Banka</label><input name="bank_name" class="form-control @if ($new) @error('bank_name') is-invalid @enderror @endif" value="{{ $new ? old('bank_name') : $account->bank_name }}" required>@if ($new)@error('bank_name')<div class="invalid-feedback">{{ $message }}</div>@enderror @endif</div>
    <div class="col-md-3 mb-2"><label class="form-label">Şube</label><input name="branch" class="form-control" value="{{ $new ? old('branch') : $account->branch }}"></div>
    <div class="col-md-5 mb-2"><label class="form-label required">Hesap sahibi</label><input name="account_holder" class="form-control" value="{{ $new ? old('account_holder') : $account->account_holder }}" required></div>
    <div class="col-md-6 mb-2"><label class="form-label required">IBAN</label><input name="iban" class="form-control font-monospace @if ($new) @error('iban') is-invalid @enderror @endif" value="{{ $new ? old('iban') : $account->formattedIban() }}" placeholder="TR00 0000 0000 0000 0000 0000 00" required>@if ($new)@error('iban')<div class="invalid-feedback">{{ $message }}</div>@enderror @endif</div>
    <div class="col-md-2 mb-2"><label class="form-label">Para birimi</label><select name="currency" class="form-select">@foreach (['TRY', 'USD', 'EUR'] as $currency)<option @selected(($new ? old('currency', 'TRY') : $account->currency) === $currency)>{{ $currency }}</option>@endforeach</select></div>
    <div class="col-md-2 mb-2"><label class="form-label">Sıra</label><input type="number" name="sort" class="form-control" value="{{ $new ? old('sort', 0) : $account->sort }}"></div>
    <div class="col-md-2 mb-2 d-flex align-items-end"><label class="form-check"><input type="checkbox" class="form-check-input" name="is_active" value="1" @checked($new ? true : $account->is_active)><span class="form-check-label">Aktif</span></label></div>
    @if ($purposes)
        <div class="col-12 mb-2">
            <span class="form-label d-inline me-2">Gösterildiği ödemeler:</span>
            @foreach ($purposes as $key => $label)
                <label class="form-check form-check-inline"><input type="checkbox" class="form-check-input" name="purposes[]" value="{{ $key }}" @checked(in_array($key, $new ? (old('purposes') ?? []) : ($account->purposes ?? []), true))><span class="form-check-label">{{ $label }}</span></label>
            @endforeach
        </div>
    @endif
</div>
