<p>Merhaba {{ $name }},</p>

<p>{{ $organization->name() }} portalında hesabınız açıldı. Portala girebilmek için aşağıdaki bağlantıdan parolanızı belirleyin:</p>

<p><a href="{{ $link }}">{{ $link }}</a></p>

<p>Bağlantı {{ $minutes }} dakika geçerlidir. Süresi dolarsa <a href="{{ route('password.request') }}">{{ route('password.request') }}</a> adresinden e-postanızı yazarak yeni bir bağlantı isteyebilirsiniz.</p>

<p>{{ $organization->name() }}</p>
