<?php

/*
 * Namen van de dienstsjablonen per sector (dezelfde sleutels als config/sector_templates.php).
 */
return [
    'hairdressing' => [
        'categories' => ['cut' => 'Knippen en stylen', 'color' => 'Kleuren', 'beard' => 'Baard'],
        'services' => ['cut_woman' => 'Dameskapsel', 'cut_man' => 'Herenkapsel', 'kids_cut' => 'Kinderkapsel', 'brushing' => 'Brushing', 'color' => 'Kleuring', 'beard' => 'Baard trimmen', 'cut_beard' => 'Knippen en baard'],
    ],
    'beauty' => [
        'categories' => ['face' => 'Gezichtsverzorging', 'body' => 'Lichaams- en handverzorging'],
        'services' => ['facial' => 'Gezichtsbehandeling', 'manicure' => 'Manicure', 'waxing' => 'Ontharing', 'relax_massage' => 'Ontspanningsmassage'],
    ],
    'wellness' => [
        'categories' => ['massage' => 'Massages', 'spa' => 'Ontspanningsruimte'],
        'services' => ['massage_60' => 'Massage 60 min', 'massage_90' => 'Massage 90 min', 'sauna' => 'Sauna', 'hammam' => 'Hammam'],
    ],
    'medical' => [
        'categories' => ['consultation' => 'Raadplegingen'],
        'services' => ['consultation' => 'Raadpleging', 'long_consultation' => 'Lange raadpleging', 'follow_up' => 'Opvolging', 'certificate' => 'Attest'],
    ],
    'paramedical' => [
        'categories' => ['session' => 'Zittingen'],
        'services' => ['first_session' => 'Eerste zitting', 'session' => 'Zitting', 'long_session' => 'Lange zitting', 'home_visit' => 'Zitting aan huis'],
    ],
    'dental' => [
        'categories' => ['care' => 'Zorg'],
        'services' => ['new_patient' => 'Nieuwe patiënt', 'check_up' => 'Controle', 'cleaning' => 'Tandsteen verwijderen', 'emergency' => 'Spoed'],
    ],
    'coaching' => [
        'categories' => ['coaching' => 'Begeleiding'],
        'services' => ['discovery_call' => 'Kennismakingsgesprek', 'session_60' => 'Sessie 60 min', 'session_90' => 'Sessie 90 min'],
    ],
    'real_estate' => [
        'categories' => ['appointments' => 'Afspraken'],
        'services' => ['visit' => 'Bezoek van een pand', 'valuation' => 'Schatting', 'signing' => 'Ondertekening'],
    ],
    'garage' => [
        'categories' => ['maintenance' => 'Onderhoud en herstelling'],
        'services' => ['oil_change' => 'Olieverversing', 'inspection' => 'Onderhoudsbeurt', 'tyres' => 'Banden', 'diagnostic' => 'Diagnose'],
    ],
    'driving_school' => [
        'categories' => ['lessons' => 'Lessen'],
        'services' => ['lesson_1h' => 'Rijles 1 uur', 'lesson_2h' => 'Rijles 2 uur', 'exam_prep' => 'Examenvoorbereiding', 'theory' => 'Theorieles'],
    ],
];
