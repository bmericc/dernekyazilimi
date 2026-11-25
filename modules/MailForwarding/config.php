<?php

return [

    // Fallback domain of the forwarding addresses (name.surname@<domain>) when the
    // mail_forwarding_domains setting is not saved yet. No default: it is the organization's.
    'domain' => env('MAIL_FORWARDING_DOMAIN'),

];
