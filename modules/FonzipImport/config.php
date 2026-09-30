<?php

return [

    // Fonzip API v2 (https://fonzip.com/api/v2/docs). The key is created in
    // Fonzip under Settings > Advanced > Fonzip API.
    'base_url' => env('FONZIP_BASE_URL', 'https://fonzip.com/api/v2'),
    'client_id' => env('FONZIP_CLIENT_ID'),
    'client_secret' => env('FONZIP_CLIENT_SECRET'),

    // Cloudflare in front of Fonzip blocks requests without a user agent.
    'user_agent' => env('FONZIP_USER_AGENT', 'DernekYazilimi-FonzipImport/1.0'),

    // Fonzip allows 60 requests a minute; requests are spaced this far apart.
    'min_interval_ms' => (int) env('FONZIP_MIN_INTERVAL_MS', 1100),

    // Payments and donations are listed by date range; the range starts here.
    'history_from' => env('FONZIP_HISTORY_FROM', '2000-01-01'),

    // The association's own Fonzip custom fields with a meaning here (their
    // Fonzip keys). All of them are copied into custom fields as well.
    'fields' => [
        // Year the person became a member: the joining date when none is known.
        'member_since' => env('FONZIP_FIELD_MEMBER_SINCE'),
        // Yes/no "registered in DERBİS": marks the membership as registered.
        'derbis' => env('FONZIP_FIELD_DERBIS'),
        // Mail alias ("ad.soyad"): becomes the forwarding on forwarding_domain.
        'alias' => env('FONZIP_FIELD_ALIAS'),
    ],

    // Domain of the aliases above (ad.soyad@<domain>). Empty: no forwardings.
    'forwarding_domain' => env('FONZIP_FORWARDING_DOMAIN'),

];
