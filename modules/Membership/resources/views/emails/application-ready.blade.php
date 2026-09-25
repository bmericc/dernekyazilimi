<p>{{ $application->reference_no }} numaralı üyelik başvurusu ({{ $application->contact->display_name }}) karar için hazır: gerekli referans teyitleri tamamlandı.</p>

<p><a href="{{ route('admin.membership-applications.show', $application) }}">{{ route('admin.membership-applications.show', $application) }}</a></p>
