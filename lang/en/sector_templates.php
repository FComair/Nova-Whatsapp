<?php

/*
 * Service template names by sector (same keys as config/sector_templates.php).
 */
return [
    'hairdressing' => [
        'categories' => ['cut' => 'Cutting and styling', 'color' => 'Colouring', 'beard' => 'Beard'],
        'services' => ['cut_woman' => 'Women\'s cut', 'cut_man' => 'Men\'s cut', 'kids_cut' => 'Kids\' cut', 'brushing' => 'Blow-dry', 'color' => 'Colouring', 'beard' => 'Beard trim', 'cut_beard' => 'Haircut and beard'],
    ],
    'beauty' => [
        'categories' => ['face' => 'Facial care', 'body' => 'Body and hand care'],
        'services' => ['facial' => 'Facial', 'manicure' => 'Manicure', 'waxing' => 'Waxing', 'relax_massage' => 'Relaxing massage'],
    ],
    'wellness' => [
        'categories' => ['massage' => 'Massages', 'spa' => 'Relaxation area'],
        'services' => ['massage_60' => '60-min massage', 'massage_90' => '90-min massage', 'sauna' => 'Sauna', 'hammam' => 'Hammam'],
    ],
    'medical' => [
        'categories' => ['consultation' => 'Consultations'],
        'services' => ['consultation' => 'Consultation', 'long_consultation' => 'Long consultation', 'follow_up' => 'Follow-up', 'certificate' => 'Certificate'],
    ],
    'paramedical' => [
        'categories' => ['session' => 'Sessions'],
        'services' => ['first_session' => 'First session', 'session' => 'Session', 'long_session' => 'Long session', 'home_visit' => 'Home visit'],
    ],
    'dental' => [
        'categories' => ['care' => 'Care'],
        'services' => ['new_patient' => 'New patient', 'check_up' => 'Check-up', 'cleaning' => 'Scale and polish', 'emergency' => 'Emergency'],
    ],
    'coaching' => [
        'categories' => ['coaching' => 'Coaching'],
        'services' => ['discovery_call' => 'Discovery call', 'session_60' => '60-min session', 'session_90' => '90-min session'],
    ],
    'real_estate' => [
        'categories' => ['appointments' => 'Appointments'],
        'services' => ['visit' => 'Property viewing', 'valuation' => 'Valuation', 'signing' => 'Signing'],
    ],
    'garage' => [
        'categories' => ['maintenance' => 'Maintenance and repair'],
        'services' => ['oil_change' => 'Oil change', 'inspection' => 'Service', 'tyres' => 'Tyres', 'diagnostic' => 'Diagnostic'],
    ],
    'driving_school' => [
        'categories' => ['lessons' => 'Lessons'],
        'services' => ['lesson_1h' => '1-hour driving lesson', 'lesson_2h' => '2-hour driving lesson', 'exam_prep' => 'Exam preparation', 'theory' => 'Theory class'],
    ],
];
