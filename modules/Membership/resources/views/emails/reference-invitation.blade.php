<p>Merhaba {{ $reference->referee->display_name }},</p>

<p><strong>{{ $reference->applicant->display_name }}</strong>, {{ $organization->name() }} üyelik başvurusunda ({{ $reference->application->reference_no }}) sizi referans olarak gösterdi.</p>

<p>Referans olmayı kabul ediyor musunuz? Yanıtlamak için aşağıdaki bağlantıya tıklayın ve portala giriş yapın:</p>

<p><a href="{{ $link }}">{{ $link }}</a></p>

<p>Bağlantı {{ $reference->expires_at->format('d.m.Y') }} tarihine kadar geçerlidir. Portalda hesabınız yoksa giriş sayfasındaki "Hesabımı etkinleştir" ile üye kaydınızdaki e-posta adresinizle hesap açabilirsiniz.</p>

<p>{{ $organization->name() }}</p>
