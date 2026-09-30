<?php

$address = <<<'TXT'
MANILA BRANCH — Room 202 2nd Floor, Espana Place Bldg., 1139 Adelina St. Sampaloc Manila

BAGUIO BRANCH — BSUCMPC Building Baguio 95 Lower Bonifacio St, Baguio, 2600 Benguet

LEGAZPI BRANCH — 3rd Floor CCJ Bldg. Tahao Road Legazpi City (near BDO and SM Legazpi)
TXT;

return [
    [
        'section_key' => 'details',
        'title' => 'Contact details & map',
        'sort_order' => 10,
        'status' => 'published',
        'content_json' => [
            'visible' => true,
            'contactInfoTitle' => 'Contact Info:',
            'locationTitle' => 'Location Info:',
            'address' => trim($address),
            'phone' => '09364999263',
            'email' => 'eercinstructor@gmail.com',
            'facebookDisplay' => 'facebook.com/eerclearning',
            'facebookUrl' => 'https://www.facebook.com/eerclearning/',
            'mapEmbedUrl' => 'https://www.google.com/maps?q=Espana+Place+1139+Adelina+St+Sampaloc+Manila&z=16&output=embed',
            'mapIframeTitle' => 'EERC office location',
        ],
    ],
    [
        'section_key' => 'feedback',
        'title' => 'Feedback form',
        'sort_order' => 20,
        'status' => 'published',
        'content_json' => [
            'visible' => true,
            'feedbackTitle' => 'Feedback:',
            'placeholderName' => 'Your Name',
            'placeholderEmail' => 'Your Email',
            'placeholderPhone' => 'Your phone number',
            'placeholderMessage' => 'Tell us how we can help with EERC LMS, your program setup, learner support, or course delivery needs.',
            'submitButtonLabel' => 'Submit',
        ],
    ],
    [
        'section_key' => 'representative',
        'title' => 'Sidebar contact card',
        'sort_order' => 30,
        'status' => 'published',
        'content_json' => [
            'visible' => true,
            'sidebarTitle' => 'Your Contact',
            'name' => 'EERC Learning',
            'role' => 'Inquiries & learner support',
            'phone' => '09364999263',
            'email' => 'eercinstructor@gmail.com',
            'extra' => 'Message us on Facebook for updates and announcements.',
            'avatar' => [
                'alt' => 'EERC Learning',
                'url' => null,
                'mediaId' => null,
            ],
        ],
    ],
];
