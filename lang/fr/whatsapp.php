<?php

/*
 * Messages WhatsApp envoyés automatiquement par Rendivoo aux clients finaux (langue du client, français par défaut).
 */
return [
    // Valeur de {{customer_name}} quand le client n'a pas de nom enregistré.
    'customer_fallback' => 'cher client',
    'opt_out_confirmed' => 'Vous ne recevrez plus de messages de notre part. Répondez START pour les reprendre.',
    'opt_in_confirmed' => 'Merci ! Vous recevrez à nouveau nos messages. Répondez STOP pour les arrêter.',

    // Modèles soumis à Meta (docs/15) : variables {{1}} établissement, {{2}} service, {{3}} jour, {{4}} heure.
    // Textes proposés, à valider avant soumission : Meta les approuve tels quels, un changement impose une nouvelle soumission.
    'templates' => [
        'reminder' => 'Bonjour, rappel : vous avez rendez-vous chez {{1}} ({{2}}) le {{3}} à {{4}}. À bientôt !',
        'confirmation' => 'Bonjour, {{1}} a bien enregistré votre rendez-vous ({{2}}) le {{3}} à {{4}}. À bientôt !',
        'rescheduled' => 'Bonjour, {{1}} a déplacé votre rendez-vous ({{2}}) : il a désormais lieu le {{3}} à {{4}}. Merci de votre compréhension.',
        'cancellation' => 'Bonjour, {{1}} doit annuler votre rendez-vous ({{2}}) du {{3}} à {{4}}. Nous sommes désolés pour ce désagrément et vous recontacterons pour en fixer un nouveau.',
    ],

    // Valeurs d'exemple exigées par Meta pour chaque variable.
    'examples' => ['Salon Aurore', 'Coupe', 'mercredi 3 juin', '10:00'],
];
