{{-- Payment gateway inputs. $gateway (?PaymentGateway), $driver (class), $purposes --}}
@php($new = $gateway === null)
<div class="row">
    <div class="col-md-4 mb-2"><label class="form-label required">Ad</label><input name="name" class="form-control" value="{{ $new ? $driver::label() : $gateway->name }}" required></div>
    @foreach ($driver::credentialFields() as $key => $field)
        <div class="col-md-4 mb-2">
            <label class="form-label @if ($new) required @endif">{{ $field['label'] }}</label>
            <input type="{{ ($field['secret'] ?? false) ? 'password' : 'text' }}" name="credentials[{{ $key }}]" class="form-control" autocomplete="off"
                   placeholder="{{ ! $new && filled($gateway->credential($key)) ? (($field['secret'] ?? false) ? '•••••• (kayıtlı)' : $gateway->credential($key)) : '' }}" @if ($new) required @endif>
        </div>
    @endforeach
    <div class="col-md-2 mb-2"><label class="form-label">Sıra</label><input type="number" name="sort" class="form-control" value="{{ $new ? 0 : $gateway->sort }}"></div>
    <div class="col-md-5 mb-2 d-flex align-items-end gap-3">
        <label class="form-check"><input type="checkbox" class="form-check-input" name="is_active" value="1" @checked($new ? true : $gateway->is_active)><span class="form-check-label">Aktif</span></label>
        <label class="form-check"><input type="checkbox" class="form-check-input" name="test_mode" value="1" @checked($new ? true : $gateway->test_mode)><span class="form-check-label">Test ortamı (sandbox)</span></label>
    </div>
    @if ($purposes)
        <div class="col-12 mb-2">
            <span class="form-label d-inline me-2">Kullanıldığı ödemeler:</span>
            @foreach ($purposes as $key => $label)
                <label class="form-check form-check-inline"><input type="checkbox" class="form-check-input" name="purposes[]" value="{{ $key }}" @checked(in_array($key, $new ? [] : ($gateway->purposes ?? []), true))><span class="form-check-label">{{ $label }}</span></label>
            @endforeach
        </div>
    @endif
</div>
