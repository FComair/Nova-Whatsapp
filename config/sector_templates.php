<?php

declare(strict_types=1);

/**
 * Modèles de services par secteur (spec §9, flowchart 1B « part d'un modèle du secteur et ajuste noms, durées, prix »).
 *
 * ATTENTION : durées, prix et taux de TVA sont INDICATIFS. Le propriétaire les ajuste avant de continuer ; l'interface le
 * rappelle. Les taux de TVA sont à confirmer avec le comptable de l'entreprise (soins de santé : 0 % proposé, autres : 21 %).
 * Les intitulés sont traduits dans lang/{fr,nl,en}/sector_templates.php (mêmes clés).
 *
 * Structure : secteur => [categories => [clé], services => [clé => [category, duration (min), price (centimes), vat (%)]]]
 */
return [
    'hairdressing' => [
        'categories' => ['cut', 'color', 'beard'],
        'services' => [
            'cut_woman' => ['category' => 'cut', 'duration' => 45, 'price' => 4000, 'vat' => 21],
            'cut_man' => ['category' => 'cut', 'duration' => 30, 'price' => 2500, 'vat' => 21],
            'kids_cut' => ['category' => 'cut', 'duration' => 20, 'price' => 1800, 'vat' => 21],
            'brushing' => ['category' => 'cut', 'duration' => 30, 'price' => 2500, 'vat' => 21],
            'color' => ['category' => 'color', 'duration' => 90, 'price' => 7000, 'vat' => 21],
            'beard' => ['category' => 'beard', 'duration' => 20, 'price' => 1500, 'vat' => 21],
            'cut_beard' => ['category' => 'cut', 'duration' => 45, 'price' => 3500, 'vat' => 21],
        ],
    ],
    'beauty' => [
        'categories' => ['face', 'body'],
        'services' => [
            'facial' => ['category' => 'face', 'duration' => 60, 'price' => 6000, 'vat' => 21],
            'manicure' => ['category' => 'body', 'duration' => 45, 'price' => 3500, 'vat' => 21],
            'waxing' => ['category' => 'body', 'duration' => 30, 'price' => 2500, 'vat' => 21],
            'relax_massage' => ['category' => 'body', 'duration' => 60, 'price' => 6500, 'vat' => 21],
        ],
    ],
    'wellness' => [
        'categories' => ['massage', 'spa'],
        'services' => [
            'massage_60' => ['category' => 'massage', 'duration' => 60, 'price' => 7000, 'vat' => 21],
            'massage_90' => ['category' => 'massage', 'duration' => 90, 'price' => 9500, 'vat' => 21],
            'sauna' => ['category' => 'spa', 'duration' => 60, 'price' => 3000, 'vat' => 21],
            'hammam' => ['category' => 'spa', 'duration' => 45, 'price' => 2500, 'vat' => 21],
        ],
    ],
    'medical' => [
        'categories' => ['consultation'],
        'services' => [
            'consultation' => ['category' => 'consultation', 'duration' => 20, 'price' => 3000, 'vat' => 0],
            'long_consultation' => ['category' => 'consultation', 'duration' => 30, 'price' => 4000, 'vat' => 0],
            'follow_up' => ['category' => 'consultation', 'duration' => 15, 'price' => 2500, 'vat' => 0],
            'certificate' => ['category' => 'consultation', 'duration' => 10, 'price' => 2000, 'vat' => 0],
        ],
    ],
    'paramedical' => [
        'categories' => ['session'],
        'services' => [
            'first_session' => ['category' => 'session', 'duration' => 60, 'price' => 4500, 'vat' => 0],
            'session' => ['category' => 'session', 'duration' => 30, 'price' => 3000, 'vat' => 0],
            'long_session' => ['category' => 'session', 'duration' => 45, 'price' => 4000, 'vat' => 0],
            'home_visit' => ['category' => 'session', 'duration' => 45, 'price' => 4500, 'vat' => 0],
        ],
    ],
    'dental' => [
        'categories' => ['care'],
        'services' => [
            'new_patient' => ['category' => 'care', 'duration' => 30, 'price' => 4500, 'vat' => 0],
            'check_up' => ['category' => 'care', 'duration' => 30, 'price' => 5000, 'vat' => 0],
            'cleaning' => ['category' => 'care', 'duration' => 45, 'price' => 8000, 'vat' => 0],
            'emergency' => ['category' => 'care', 'duration' => 30, 'price' => 7000, 'vat' => 0],
        ],
    ],
    'coaching' => [
        'categories' => ['coaching'],
        'services' => [
            'discovery_call' => ['category' => 'coaching', 'duration' => 30, 'price' => 0, 'vat' => 21],
            'session_60' => ['category' => 'coaching', 'duration' => 60, 'price' => 8000, 'vat' => 21],
            'session_90' => ['category' => 'coaching', 'duration' => 90, 'price' => 11000, 'vat' => 21],
        ],
    ],
    'real_estate' => [
        'categories' => ['appointments'],
        'services' => [
            'visit' => ['category' => 'appointments', 'duration' => 30, 'price' => 0, 'vat' => 21],
            'valuation' => ['category' => 'appointments', 'duration' => 60, 'price' => 0, 'vat' => 21],
            'signing' => ['category' => 'appointments', 'duration' => 60, 'price' => 0, 'vat' => 21],
        ],
    ],
    'garage' => [
        'categories' => ['maintenance'],
        'services' => [
            'oil_change' => ['category' => 'maintenance', 'duration' => 60, 'price' => 9000, 'vat' => 21],
            'inspection' => ['category' => 'maintenance', 'duration' => 90, 'price' => 12000, 'vat' => 21],
            'tyres' => ['category' => 'maintenance', 'duration' => 45, 'price' => 6000, 'vat' => 21],
            'diagnostic' => ['category' => 'maintenance', 'duration' => 60, 'price' => 8000, 'vat' => 21],
        ],
    ],
    'driving_school' => [
        'categories' => ['lessons'],
        'services' => [
            'lesson_1h' => ['category' => 'lessons', 'duration' => 60, 'price' => 5000, 'vat' => 21],
            'lesson_2h' => ['category' => 'lessons', 'duration' => 120, 'price' => 9500, 'vat' => 21],
            'exam_prep' => ['category' => 'lessons', 'duration' => 60, 'price' => 5500, 'vat' => 21],
            'theory' => ['category' => 'lessons', 'duration' => 90, 'price' => 3000, 'vat' => 21],
        ],
    ],
    // « other » : pas de modèle, saisie manuelle.
];
