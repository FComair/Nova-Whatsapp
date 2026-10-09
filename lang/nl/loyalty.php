<?php

/*
 * Loyaliteitsprogramma (docs/27): WhatsApp-berichten (standaardteksten, voorbeeldwaarden voor het voorbeeld, Meta-sjablonen).
 * Variabelen: {name} {business} {program} {progress} {stamps} {required} {remaining} {reward} {expiry_date}.
 */
return [
    'messages' => [
        'default' => [
            'joined' => 'Hallo {name}, welkom bij "{program}" bij {business}! Na {required} bezoeken ontvangt u: {reward}. Antwoord STOP om geen berichten meer te ontvangen.',
            'progress' => 'Bedankt voor uw bezoek, {name}! Uw voortgang: {progress}. Nog {remaining} bezoek(en) tot uw beloning: {reward}.',
            'reward_earned' => 'Gefeliciteerd {name}! Uw beloning bij {business}: {reward}. Ze wacht op u bij uw volgende afspraak.',
            'reward_redeemed' => 'Bedankt {name}! Uw beloning "{reward}" is gebruikt bij {business}.',
            'expiry_reminder' => 'Hallo {name}, uw beloning "{reward}" bij {business} vervalt op {expiry_date}. Maak vóór die datum een afspraak!',
        ],
        'samples' => [
            'name' => 'Camille', 'business' => 'Salon Aurore', 'program' => 'Loyaliteitsprogramma', 'progress' => '6/8', 'stamps' => '6',
            'required' => '8', 'remaining' => '2', 'reward' => 'Een gratis knipbeurt', 'expiry_date' => '31/12/2026',
        ],
    ],

    // Sjablonen die Meta moet goedkeuren (categorie marketing): {{1}} bedrijf, {{2}} voornaam, {{3}} voortgang, {{4}} beloning, {{5}} vervaldatum.
    'templates' => [
        'joined' => 'Hallo {{2}}, welkom bij het loyaliteitsprogramma van {{1}}! Uw voortgang: {{3}}. Beloning: {{4}}. Antwoord STOP om geen berichten meer te ontvangen.',
        'progress' => 'Bedankt voor uw bezoek aan {{1}}, {{2}}! Uw voortgang: {{3}}. Te verdienen beloning: {{4}}.',
        'reward_earned' => 'Gefeliciteerd {{2}}! Uw beloning bij {{1}}: {{4}}. Ze wacht op u bij uw volgende afspraak.',
        'reward_redeemed' => 'Bedankt {{2}}! Uw beloning "{{4}}" is gebruikt bij {{1}}.',
        'expiry_reminder' => 'Hallo {{2}}, uw beloning "{{4}}" bij {{1}} vervalt op {{5}}. Maak vóór die datum een afspraak!',
    ],
];
