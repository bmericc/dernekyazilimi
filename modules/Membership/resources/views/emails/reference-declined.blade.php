<p>Merhaba {{ $reference->applicant->display_name }},</p>

<p>{{ $reference->application->reference_no }} numaralı üyelik başvurunuzda referans gösterdiğiniz <strong>{{ $reference->referee->display_name }}</strong> referans olmayı kabul etmedi.</p>

<p>Portalda "Üyelik başvurum" sayfasından yerine başka bir üyeyi referans gösterebilirsiniz: <a href="{{ route('membership.application') }}">{{ route('membership.application') }}</a></p>

<p>{{ $organization->name() }}</p>
