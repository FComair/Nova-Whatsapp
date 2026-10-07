<?php

declare(strict_types=1);

/**
 * Assistant conversationnel WhatsApp de Nova (docs/18) : le parcours de réservation du client final, venu du flux n8n
 * « MASTER ROUTER V2 », exécuté dans Nova.
 *
 * Aucune valeur secrète ici : tout vient de l'environnement.
 */
return [

    // `n8n` : chaque message entrant est transmis à n8n (comportement historique, docs/06). `native` : Nova répond lui-même.
    'driver' => env('CONVERSATION_DRIVER', 'n8n'),

    // Nombre d'éléments par page dans les menus numérotés (services, praticiens, créneaux), comme dans le flux n8n.
    'page_size' => 5,

    // Une conversation inactive depuis plus longtemps repart de zéro (état conservé dans le cache, jamais en base).
    'session_ttl_minutes' => 120,

    // « Parler à quelqu'un » : l'assistant se tait pendant ce délai pour laisser l'équipe répondre.
    'handoff_hours' => 12,

    // Modèle de langage (compréhension de secours et FAQ). Sans clé : aucun appel, l'assistant reste sur ses menus.
    'ai' => [
        'api_key' => env('CONVERSATION_AI_API_KEY'),
        'base_url' => env('CONVERSATION_AI_BASE_URL', 'https://api.openai.com/v1'),
        'model' => env('CONVERSATION_AI_MODEL', 'gpt-4.1-mini'),
        'timeout_seconds' => 15,
        // Sous ce seuil de confiance, l'intention est jugée « incertaine » et le menu de clarification est proposé.
        'min_confidence' => 0.70,
    ],
];
