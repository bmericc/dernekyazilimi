{{-- Dues on the admin contact page (slot admin.contacts.show). $contact --}}
@if (Auth::user()->hasPermission('dues.view') && \Modules\Membership\Models\Membership::where('contact_id', $contact->id)->exists())
@php($account = app(\Modules\Dues\Support\DuesService::class)->account($contact))
@php($exemptions = \Modules\Dues\Models\DuesExemption::where('contact_id', $contact->id)->orderByDesc('from_year')->get())
@php($canManage = Auth::user()->hasPermission('dues.manage'))
@php($money = fn ($amount) => \Modules\Membership\Models\MembershipFee::format($amount))

<div class="card mb-3" id="dues">
    <div class="card-header">
        <h3 class="card-title">Aidat</h3>
        <div class="card-actions d-flex align-items-center gap-2">
            <span class="fw-bold {{ $account->owes() ? 'text-danger' : 'text-success' }}">{{ $account->balance() < 0 ? 'Alacaklı: '.$money(-$account->balance()) : 'Borç: '.$money($account->balance()) }}</span>
            @if ($canManage && $account->owes() && $contact->email)
                <form method="POST" action="{{ route('admin.dues.remind', $contact) }}">@csrf<button type="submit" class="btn btn-sm btn-outline-secondary">Hatırlatma gönder</button></form>
            @endif
        </div>
    </div>

    @if ($exemptions->isNotEmpty() || $canManage)
        <div class="card-body border-bottom">
            @foreach ($exemptions as $exemption)
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <div><span class="badge bg-azure-lt">Muaf {{ $exemption->period() }}</span> {{ $exemption->reason }}</div>
                    @if ($canManage)
                        <form method="POST" action="{{ route('admin.dues.exemptions.destroy', $exemption) }}" onsubmit="return confirm('Muafiyet kaldırılsın mı?')">@csrf @method('DELETE')<button type="submit" class="btn btn-sm btn-ghost-danger">Kaldır</button></form>
                    @endif
                </div>
            @endforeach
            @if ($canManage)
                <form method="POST" action="{{ route('admin.dues.exemptions.store', $contact) }}" class="row g-2 mt-1">
                    @csrf
                    <div class="col-md-2"><input type="number" name="from_year" class="form-control form-control-sm" value="{{ now()->year }}" required title="Başlangıç yılı"></div>
                    <div class="col-md-2"><input type="number" name="until_year" class="form-control form-control-sm" placeholder="Bitiş (boş: süresiz)"></div>
                    <div class="col-md-5"><input name="reason" class="form-control form-control-sm" maxlength="255" required placeholder="Muafiyet nedeni (ör. öğrenci, YK kararı 2026/4)"></div>
                    <div class="col-md-3"><button type="submit" class="btn btn-sm btn-outline-secondary w-100">Muafiyet ekle</button></div>
                </form>
            @endif
        </div>
    @endif

    <div class="table-responsive">
        <table class="table table-vcenter card-table" data-no-datatable>
            <thead><tr><th>Borç</th><th class="text-end">Tutar</th><th class="text-end">Ödenen</th><th>Durum</th><th></th></tr></thead>
            <tbody>
                @forelse ($account->charges as $charge)
                    <tr class="{{ $charge->isCancelled() ? 'text-secondary' : '' }}">
                        <td>
                            @if ($charge->isCancelled())<s>{{ $charge->label() }}</s>@else{{ $charge->label() }}@endif
                            <div class="small text-secondary">{{ $charge->created_at->format('d.m.Y') }}@if ($charge->cancel_note) · {{ $charge->cancel_note }}@endif</div>
                        </td>
                        <td class="text-end">{{ $money($charge->amount) }}</td>
                        <td class="text-end">{{ $charge->isCancelled() ? '—' : $money($charge->paid) }}</td>
                        <td>
                            @if ($charge->isCancelled())<span class="badge bg-secondary-lt">İptal</span>
                            @elseif ($charge->remaining() <= 0)<span class="badge bg-green-lt">Ödendi</span>
                            @elseif ($charge->paid > 0)<span class="badge bg-yellow-lt">Kısmen</span>
                            @else<span class="badge bg-red-lt">Ödenmedi</span>@endif
                        </td>
                        <td class="text-end">
                            @if ($canManage)
                                @if ($charge->isCancelled())
                                    <form method="POST" action="{{ route('admin.dues.charges.restore', $charge) }}">@csrf @method('PATCH')<button type="submit" class="btn btn-sm btn-ghost-secondary">Geri al</button></form>
                                @else
                                    <form method="POST" action="{{ route('admin.dues.charges.cancel', $charge) }}" onsubmit="var n = prompt('İptal nedeni'); if (n === null) return false; this.note.value = n;">@csrf @method('PATCH')<input type="hidden" name="note"><button type="submit" class="btn btn-sm btn-ghost-danger">İptal</button></form>
                                @endif
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-secondary">Borç yok.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($account->payments->isNotEmpty())
        <div class="card-header border-top"><h3 class="card-title">Ödemeler</h3></div>
        <div class="table-responsive">
            <table class="table table-vcenter card-table" data-no-datatable>
                <thead><tr><th>Tarih</th><th>Yöntem</th><th>Referans</th><th class="text-end">Tutar</th><th>Durum</th></tr></thead>
                <tbody>
                    @foreach ($account->payments as $payment)
                        <tr>
                            <td class="text-secondary">{{ ($payment->paid_at ?? $payment->created_at)->format('d.m.Y') }}</td>
                            <td>{{ $payment->methodLabel() }}</td>
                            <td>@if (Auth::user()->hasPermission('payments.view'))<a href="{{ route('admin.payments.show', $payment) }}"><code>{{ $payment->reference }}</code></a>@else<code>{{ $payment->reference }}</code>@endif</td>
                            <td class="text-end">{{ $payment->formattedAmount() }}</td>
                            <td><span class="badge bg-{{ \App\Models\Payment::STATUS_COLORS[$payment->status] ?? 'secondary' }}-lt">{{ $payment->statusLabel() }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($canManage)
        <div class="card-footer">
            <div class="fw-bold mb-2">Borç ekle</div>
            <form method="POST" action="{{ route('admin.dues.charges.store', $contact) }}" class="row g-2 mb-3">
                @csrf
                <div class="col-md-3"><select name="kind" class="form-select form-select-sm">@foreach (\Modules\Dues\Models\DuesCharge::KINDS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></div>
                <div class="col-md-2"><input type="number" name="year" class="form-control form-control-sm" value="{{ now()->year }}" placeholder="Yıl" required></div>
                <div class="col-md-2"><input type="number" step="0.01" min="0.01" name="amount" class="form-control form-control-sm" placeholder="Tutar (TL)" required></div>
                <div class="col-md-3"><input name="description" class="form-control form-control-sm" maxlength="255" placeholder="Açıklama (diğer için)"></div>
                <div class="col-md-2"><button type="submit" class="btn btn-sm btn-outline-primary w-100">Ekle</button></div>
            </form>

            <div class="fw-bold mb-2">Ödeme kaydet</div>
            <form method="POST" action="{{ route('admin.dues.payments.store', $contact) }}" class="row g-2">
                @csrf
                <div class="col-md-2"><input type="number" step="0.01" min="0.01" name="amount" class="form-control form-control-sm" placeholder="Tutar (TL)" value="{{ $account->owes() ? $account->balance() : '' }}" required></div>
                <div class="col-md-2"><input type="date" name="paid_at" class="form-control form-control-sm" value="{{ today()->toDateString() }}" required></div>
                <div class="col-md-2"><select name="method" class="form-select form-select-sm"><option value="transfer">Havale / EFT</option><option value="cash">Elden</option></select></div>
                <div class="col-md-2"><select name="bank_account_id" class="form-select form-select-sm"><option value="">Gelen hesap</option>@foreach (\App\Models\BankAccount::active()->get() as $bank)<option value="{{ $bank->id }}">{{ $bank->bank_name }} · {{ substr($bank->iban, -4) }}</option>@endforeach</select></div>
                <div class="col-md-2"><input name="note" class="form-control form-control-sm" maxlength="1000" placeholder="Not"></div>
                <div class="col-md-2"><button type="submit" class="btn btn-sm btn-primary w-100">Kaydet</button></div>
            </form>
        </div>
    @endif
</div>
@endif
