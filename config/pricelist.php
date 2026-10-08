<?php

declare(strict_types=1);

/**
 * Import d'un catalogue depuis une liste de prix photographiée ou téléversée.
 *
 * La lecture passe par le même service d'IA compatible OpenAI que l'assistant (clé et adresse : voir config/conversation.php) ;
 * seul le modèle peut être réglé à part. Sans clé, l'import n'est pas proposé.
 */
return [

    // `openai` : lecture par le service d'IA (par défaut, nécessite la clé). `fake` : liste d'exemple, pour le développement uniquement.
    'driver' => env('PRICELIST_DRIVER', 'openai'),

    // Modèle capable de lire des images (par défaut celui de l'assistant, gpt-4.1-mini).
    'model' => env('PRICELIST_AI_MODEL'),

    // Une liste de plusieurs pages peut être longue à lire.
    'timeout_seconds' => 60,

    'max_files' => 10,

    // 10 Mo par fichier ; côté minimal de l'image en pixels (en dessous, le texte est illisible).
    'max_file_kb' => 10240,
    'min_side_px' => 500,
    'max_side_px' => 8000,

    // Les images envoyées au modèle sont réduites à ce côté maximal (moins de données, lecture aussi bonne).
    'send_side_px' => 2000,

    // Imports lancés par heure et par établissement (chaque page est un appel payant).
    'per_hour' => 5,

    // Les fichiers d'un import non terminé sont effacés après ce délai ; ceux d'un import terminé le sont tout de suite.
    'retention_hours' => 24,

    // Un import peut être annulé pendant ce nombre de jours.
    'undo_days' => 7,

    // Seuils de rapprochement avec les services existants (voir PriceListMatcher).
    'match_auto' => 0.90,
    'match_suggest' => 0.70,

    // Durée proposée quand la liste n'en indique pas (minutes).
    'default_duration' => 30,

    // Prix jugés suspects (en euros).
    'price_low' => 1.0,
    'price_high' => 1000.0,
];
