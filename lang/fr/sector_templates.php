<?php

/*
 * Intitulés des modèles de services par secteur (mêmes clés que config/sector_templates.php).
 */
return [
    'hairdressing' => [
        'categories' => ['cut' => 'Coupe et coiffage', 'color' => 'Coloration', 'beard' => 'Barbe'],
        'services' => ['cut_woman' => 'Coupe femme', 'cut_man' => 'Coupe homme', 'kids_cut' => 'Coupe enfant', 'brushing' => 'Brushing', 'color' => 'Coloration', 'beard' => 'Taille de barbe', 'cut_beard' => 'Coupe et barbe'],
    ],
    'beauty' => [
        'categories' => ['face' => 'Soins du visage', 'body' => 'Soins du corps et des mains'],
        'services' => ['facial' => 'Soin du visage', 'manicure' => 'Manucure', 'waxing' => 'Épilation', 'relax_massage' => 'Massage relaxant'],
    ],
    'wellness' => [
        'categories' => ['massage' => 'Massages', 'spa' => 'Espace détente'],
        'services' => ['massage_60' => 'Massage 60 min', 'massage_90' => 'Massage 90 min', 'sauna' => 'Sauna', 'hammam' => 'Hammam'],
    ],
    'medical' => [
        'categories' => ['consultation' => 'Consultations'],
        'services' => ['consultation' => 'Consultation', 'long_consultation' => 'Consultation longue', 'follow_up' => 'Suivi', 'certificate' => 'Certificat'],
    ],
    'paramedical' => [
        'categories' => ['session' => 'Séances'],
        'services' => ['first_session' => 'Première séance', 'session' => 'Séance', 'long_session' => 'Séance longue', 'home_visit' => 'Séance à domicile'],
    ],
    'dental' => [
        'categories' => ['care' => 'Soins'],
        'services' => ['new_patient' => 'Nouveau patient', 'check_up' => 'Contrôle', 'cleaning' => 'Détartrage', 'emergency' => 'Urgence'],
    ],
    'coaching' => [
        'categories' => ['coaching' => 'Accompagnement'],
        'services' => ['discovery_call' => 'Appel découverte', 'session_60' => 'Séance 60 min', 'session_90' => 'Séance 90 min'],
    ],
    'real_estate' => [
        'categories' => ['appointments' => 'Rendez-vous'],
        'services' => ['visit' => 'Visite de bien', 'valuation' => 'Estimation', 'signing' => 'Signature'],
    ],
    'garage' => [
        'categories' => ['maintenance' => 'Entretien et réparation'],
        'services' => ['oil_change' => 'Vidange', 'inspection' => 'Révision', 'tyres' => 'Pneus', 'diagnostic' => 'Diagnostic'],
    ],
    'driving_school' => [
        'categories' => ['lessons' => 'Leçons'],
        'services' => ['lesson_1h' => 'Leçon de conduite 1 h', 'lesson_2h' => 'Leçon de conduite 2 h', 'exam_prep' => 'Préparation à l\'examen', 'theory' => 'Cours théorique'],
    ],
];
