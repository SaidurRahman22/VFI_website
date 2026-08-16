<?php

/*
| Phase 3D — server-side page-visibility catalogue (docs §3). setPage may ONLY
| target a filename in this allow-list (the legacy VFI.setPage accepted anything).
| `locked` pages render without a toggle. The two sign-in pages are `signin` and
| can NEVER be switched off (business-wide DoS lever). This is a MENU-level
| toggle, not access control — a switched-off page's HTML is still served unless
| the edge additionally 410s it.
*/
return [

    /*
    | Pages that may never be switched off, because hiding one breaks the way
    | people get INTO the product — and it fails silently, days later, for
    | someone who never touched the admin.
    |
    | This deliberately covers the whole authentication flow, not just the two
    | login screens. Switching off student-verify.html would let people register
    | and then never confirm their email; switching off a forgot/reset page
    | would strand anyone locked out of their account.
    */
    'signin' => [
        'login.html', 'student-verify.html', 'student-forgot.html', 'student-reset.html',
        'vfi-partner-login.html', 'vfi-partner-verify.html',
        'vfi-partner-forgot.html', 'vfi-partner-reset.html',
        'qr-register.html',   // the QR landing a partner's students register through
    ],

    'catalogue' => [
        'Main pages' => [
            'index.html' => ['label' => 'Home', 'locked' => true],
            'about.html' => ['label' => 'About Us'],
            'contact.html' => ['label' => 'Contact Us'],
            'gallery.html' => ['label' => 'Photo Gallery'],
            'events.html' => ['label' => 'Upcoming Events'],
            'blogs.html' => ['label' => 'Blog'],
            'blog-post.html' => ['label' => 'Blog Article (template)', 'locked' => true],
            'login.html' => ['label' => 'Student Login', 'locked' => true],
        ],
        'Student account' => [
            'student-profile.html' => ['label' => 'Student Profile'],
            'student-tracking.html' => ['label' => 'Application Tracking'],
            'student-forgot.html' => ['label' => 'Student · Reset Password', 'locked' => true],
            'student-verify.html' => ['label' => 'Student · Email Verification', 'locked' => true],
        ],
        'Services' => [
            'services.html' => ['label' => 'Services'],
            'test-preparation.html' => ['label' => 'Test Preparation'],
            'scholarships.html' => ['label' => 'Scholarships'],
            'internships.html' => ['label' => 'Internships'],
            'allied-services.html' => ['label' => 'Allied Services'],
            'universities.html' => ['label' => 'Search Universities'],
            'vfi-partner.html' => ['label' => 'VFI Partner'],
            'vfi-partner-login.html' => ['label' => 'VFI Partner Login', 'locked' => true],
            'vfi-partner-forgot.html' => ['label' => 'VFI Partner · Reset Password', 'locked' => true],
            'vfi-partner-verify.html' => ['label' => 'VFI Partner · Email Verification', 'locked' => true],
        ],
        'Study destinations' => [
            'destinations.html' => ['label' => 'Study Destinations'],
            'study-in-usa.html' => ['label' => 'Study in USA'],
            'study-in-canada.html' => ['label' => 'Study in Canada'],
            'study-in-uk.html' => ['label' => 'Study in the UK'],
            'study-in-ireland.html' => ['label' => 'Study in Ireland'],
            'study-in-australia.html' => ['label' => 'Study in Australia'],
            'study-in-new-zealand.html' => ['label' => 'Study in New Zealand'],
            'europe.html' => ['label' => 'Study in Europe'],
            'asia.html' => ['label' => 'Study in Asia'],
        ],
        'Company' => [
            'careers.html' => ['label' => 'Careers'],
            'news.html' => ['label' => 'News & Press'],
            'csr.html' => ['label' => 'Corporate Social Responsibility'],
        ],
        'Partners & institutions' => [
            'for-institutions.html' => ['label' => 'For Institutions'],
            'for-partners.html' => ['label' => 'For Partners'],
            'for-franchisee.html' => ['label' => 'For Franchisee'],
        ],
        'Legal' => [
            'terms.html' => ['label' => 'Terms & Conditions'],
            'privacy.html' => ['label' => 'Privacy Policy'],
            'payment-terms.html' => ['label' => 'Payment Terms'],
        ],
    ],
];
