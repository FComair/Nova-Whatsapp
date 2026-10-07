<?php

/*
 * Automatische WhatsApp-berichten van Nova aan eindklanten (taal van de klant, standaard Frans).
 */
return [
    'opt_out_confirmed' => 'U ontvangt geen berichten meer van ons. Antwoord START om ze opnieuw te ontvangen.',
    'opt_in_confirmed' => 'Bedankt! U ontvangt onze berichten opnieuw. Antwoord STOP om ze te stoppen.',

    // Sjablonen die bij Meta worden ingediend (docs/15): variabelen {{1}} bedrijf, {{2}} dienst, {{3}} dag, {{4}} uur.
    // Voorgestelde teksten, eerst te valideren: Meta keurt ze ongewijzigd goed, een wijziging vereist een nieuwe indiening.
    'templates' => [
        'reminder' => 'Hallo, herinnering: u hebt een afspraak bij {{1}} ({{2}}) op {{3}} om {{4}}. Tot binnenkort!',
        'confirmation' => 'Hallo, {{1}} heeft uw afspraak ({{2}}) op {{3}} om {{4}} geregistreerd. Tot binnenkort!',
        'rescheduled' => 'Hallo, {{1}} heeft uw afspraak ({{2}}) verplaatst: die vindt nu plaats op {{3}} om {{4}}. Bedankt voor uw begrip.',
        'cancellation' => 'Hallo, {{1}} moet uw afspraak ({{2}}) op {{3}} om {{4}} annuleren. Onze excuses voor het ongemak; wij nemen contact met u op voor een nieuwe afspraak.',
    ],

    // Voorbeeldwaarden die Meta voor elke variabele eist.
    'examples' => ['Salon Aurore', 'Knippen', 'woensdag 3 juni', '10:00'],
];
