@extends('layouts.admin')

@section('content')
<div class="container-xl">
    <div class="page-header mb-3">
        <h2 class="page-title">E-posta şablonları</h2>
        <div class="text-secondary mt-1">Sistemin gönderdiği e-postalar. Her şablonun anahtarı ve kullanabileceği kısa kodlar sabittir; konusu ve içeriği düzenlenebilir. Düzenlenmemiş şablon varsayılan metinle gider. SMS'i olan şablonlarda SMS ayrıca açılır; açıkken e-postayla birlikte kişinin kayıtlı telefonuna gider.</div>
    </div>

    @include('admin::partials.status')

    @foreach ($groups as $group)
        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">{{ $group['label'] }}</h3></div>
            <div class="table-responsive">
                <table class="table table-vcenter card-table" data-no-datatable>
                    <thead><tr><th>E-posta</th><th>Alıcı</th><th>Anahtar</th><th>Durum</th><th class="w-1"></th></tr></thead>
                    <tbody>
                        @foreach ($group['templates'] as $template)
                            <tr>
                                <td>{{ $template['label'] }}</td>
                                <td class="text-secondary small">{{ $template['recipient'] }}</td>
                                <td><code>{{ $template['key'] }}</code></td>
                                <td>
                                    @if ($template['sms'] !== null)
                                        <span class="badge {{ in_array($template['key'], $smsOn, true) ? 'bg-green-lt' : 'bg-secondary-lt' }}">SMS {{ in_array($template['key'], $smsOn, true) ? 'açık' : 'kapalı' }}</span>
                                    @endif
                                    @if (isset($edited[$template['key']]))
                                        <span class="badge bg-blue-lt" title="{{ $edited[$template['key']]?->format('d.m.Y H:i') }}">Düzenlendi</span>
                                    @else
                                        <span class="badge bg-secondary-lt">Varsayılan</span>
                                    @endif
                                </td>
                                <td><a href="{{ route('admin.mail-templates.edit', $template['key']) }}" class="btn btn-sm btn-outline-primary">Düzenle</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach
</div>
@endsection
