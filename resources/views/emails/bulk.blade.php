{{-- A bulk message (App\Mail\BulkMail), placeholders already filled. --}}
{!! $body !!}
@if ($informational)
<p style="margin-top:24px;font-size:12px;color:#888">Bu iletiyi, e-postayla bilgilendirme almayı kabul ettiğiniz için aldınız. İletişim izinlerinizi <a href="{{ route('my-infos') }}#tab-privacy">profil sayfanızdan</a> değiştirebilirsiniz.</p>
@endif
