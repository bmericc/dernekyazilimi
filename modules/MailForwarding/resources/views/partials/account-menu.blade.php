@if (app(\Modules\MailForwarding\Support\ForwardingPolicy::class)->eligible(Auth::user()))
    <a class="dropdown-item" href="{{ route('email-redirects') }}"><i class="ti ti-mail-forward icon dropdown-item-icon"></i>{{ trans('panel.email_forwarding') }}</a>
@endif
