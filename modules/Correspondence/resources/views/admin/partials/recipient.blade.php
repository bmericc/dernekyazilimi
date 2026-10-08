@php use Modules\Correspondence\Models\LetterRecipient; @endphp
<div class="row g-2 mb-2 align-items-end" data-recipient>
    <div class="col-md-2"><label class="form-label">Tür</label><select name="recipients[{{ $index }}][kind]" class="form-select">@foreach (LetterRecipient::KINDS as $key => $label)<option value="{{ $key }}" @selected(($row['kind'] ?? LetterRecipient::INSTITUTION) === $key)>{{ $label }}</option>@endforeach</select></div>
    <div class="col-md-3"><label class="form-label required">Ad</label><input name="recipients[{{ $index }}][name]" class="form-control" value="{{ $row['name'] ?? '' }}" maxlength="255"></div>
    <div class="col-md-2"><label class="form-label">DETSİS / MERSİS / T.C. no</label><input name="recipients[{{ $index }}][identifier]" class="form-control" value="{{ $row['identifier'] ?? '' }}" maxlength="30"></div>
    <div class="col-md-3"><label class="form-label">Adres</label><input name="recipients[{{ $index }}][address]" class="form-control" value="{{ $row['address'] ?? '' }}" maxlength="500"></div>
    <div class="col-md-1"><label class="form-label">Dağıtım</label><select name="recipients[{{ $index }}][delivery]" class="form-select">@foreach (LetterRecipient::DELIVERIES as $key => $label)<option value="{{ $key }}" @selected(($row['delivery'] ?? LetterRecipient::ACTION) === $key)>{{ $label }}</option>@endforeach</select></div>
    <div class="col-md-1"><button type="button" class="btn btn-outline-danger w-100" data-remove-recipient title="Alıcıyı kaldır"><i class="ti ti-trash"></i></button></div>
</div>
