{{-- Input for an API key, secret or service password. Deliberately not type="password": browsers
     ignore autocomplete="off" there and fill the saved portal login into it and the field before it,
     which then gets saved over the real value. Masked with CSS instead. --}}
<input type="text" {{ $attributes->merge(['class' => 'form-control']) }} style="-webkit-text-security: disc; text-security: disc"
       autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false"
       data-1p-ignore data-lpignore="true" data-bwignore data-form-type="other">
