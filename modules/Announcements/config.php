<?php

return [

    // Mailgun list that receives announcements marked "send e-mail", and its test list.
    // No default: the lists belong to the organization (see .env.example). Unset, nothing is sent.
    'mailing_list' => env('ANNOUNCEMENTS_MAILING_LIST'),
    'test_mailing_list' => env('ANNOUNCEMENTS_TEST_MAILING_LIST'),

];
