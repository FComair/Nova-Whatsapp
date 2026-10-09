<?php

/*
 * Programme de fidélité (docs/27) : messages WhatsApp (textes par défaut, valeurs d'exemple pour l'aperçu, modèles Meta).
 * Variables : {name} {business} {program} {progress} {stamps} {required} {remaining} {reward} {expiry_date}.
 */
return [
    'messages' => [
        'default' => [
            'joined' => 'Bonjour {name}, bienvenue dans « {program} » chez {business} ! Après {required} visites, vous recevez : {reward}. Répondez STOP pour ne plus recevoir nos messages.',
            'progress' => 'Merci pour votre visite, {name} ! Votre progression : {progress}. Il reste {remaining} visite(s) avant votre récompense : {reward}.',
            'reward_earned' => 'Félicitations {name} ! Votre récompense chez {business} : {reward}. Elle vous attend lors de votre prochain rendez-vous.',
            'reward_redeemed' => 'Merci {name} ! Votre récompense « {reward} » a bien été utilisée chez {business}.',
            'expiry_reminder' => 'Bonjour {name}, votre récompense « {reward} » chez {business} expire le {expiry_date}. Pensez à prendre rendez-vous avant cette date !',
        ],
        'samples' => [
            'name' => 'Camille', 'business' => 'Salon Aurore', 'program' => 'Programme fidélité', 'progress' => '6/8', 'stamps' => '6',
            'required' => '8', 'remaining' => '2', 'reward' => 'Une coupe offerte', 'expiry_date' => '31/12/2026',
        ],
    ],

    // Modèles à faire approuver par Meta (catégorie marketing) : {{1}} établissement, {{2}} prénom, {{3}} progression, {{4}} récompense, {{5}} date d'expiration.
    'templates' => [
        'joined' => 'Bonjour {{2}}, bienvenue dans le programme de fidélité de {{1}} ! Votre progression : {{3}}. Récompense : {{4}}. Répondez STOP pour ne plus recevoir nos messages.',
        'progress' => 'Merci pour votre visite chez {{1}}, {{2}} ! Votre progression : {{3}}. Récompense à gagner : {{4}}.',
        'reward_earned' => 'Félicitations {{2}} ! Votre récompense chez {{1}} : {{4}}. Elle vous attend lors de votre prochain rendez-vous.',
        'reward_redeemed' => 'Merci {{2}} ! Votre récompense « {{4}} » a bien été utilisée chez {{1}}.',
        'expiry_reminder' => 'Bonjour {{2}}, votre récompense « {{4}} » chez {{1}} expire le {{5}}. Pensez à prendre rendez-vous avant cette date !',
    ],
];
