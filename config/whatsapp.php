<?php

declare(strict_types=1);

/**
 * Passerelle WhatsApp (Meta Cloud API, décision du fondateur) et lien avec n8n.
 *
 * Aucune valeur secrète ici : tout vient de l'environnement. Les jetons d'accès par établissement sont chiffrés en base.
 */
return [

    // `meta` en production ; `fake` (aucun appel réseau, envois consignés en mémoire) en développement et en test.
    'driver' => env('WHATSAPP_DRIVER', 'fake'),

    'graph_url' => env('WHATSAPP_GRAPH_URL', 'https://graph.facebook.com'),
    'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v21.0'),

    // Jeton de vérification de l'URL du webhook (choisi par Nova, saisi dans la console Meta) et secret de l'application Meta
    // (signature X-Hub-Signature-256 des notifications).
    'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
    'app_secret' => env('WHATSAPP_APP_SECRET'),

    // Inscription intégrée de Meta (Embedded Signup, docs/17) : identifiants PUBLICS de l'application Meta de Nova. Le secret de l'application est pp_secret.
    // Vide tant que l'application Meta n'est pas créée : le bouton « Se connecter avec Meta » n'est alors pas proposé.
    'embedded_signup' => [
        'app_id' => env('META_APP_ID'),
        'config_id' => env('META_EMBEDDED_SIGNUP_CONFIG_ID'),
    ],

    // Fenêtre de service WhatsApp : réponse libre autorisée 24 h après le dernier message du client ; au-delà, un modèle approuvé
    // est obligatoire (modèles : étape suivante).
    'service_window_hours' => 24,

    // Modèles de messages approuvés par Meta (le texte vit chez Meta ; ici seulement leur nom). Rappel : 4 variables dans l'ordre,
    // {{1}} établissement, {{2}} service, {{3}} date (ex. « jeudi 01/10/2026 », format JJ/MM/AAAA), {{4}} heure. À créer en fr, nl et en dans la console Meta.
    'templates' => [
        'reminder' => env('WHATSAPP_TEMPLATE_REMINDER', 'appointment_reminder'),
        'confirmation' => env('WHATSAPP_TEMPLATE_CONFIRMATION', 'appointment_confirmation'),
        'rescheduled' => env('WHATSAPP_TEMPLATE_RESCHEDULED', 'appointment_rescheduled'),
        'cancellation' => env('WHATSAPP_TEMPLATE_CANCELLATION', 'appointment_cancelled'),
    ],

    // Bibliothèque de messages par secteur (docs/39). Tant qu'aucun modèle du secteur n'est approuvé pour un déclencheur, les quatre modèles
    // globaux ci-dessus restent utilisés (transition). Mettre à false pour n'envoyer QUE des modèles approuvés dans la bibliothèque.
    'catalog' => [
        'legacy_fallback' => env('WHATSAPP_LEGACY_FALLBACK', true),
    ],

    // Modèles du programme de fidélité (docs/27), catégorie marketing chez Meta : variables {{1}} établissement, {{2}} prénom, {{3}} progression (ex. 6/8), {{4}} récompense, {{5}} date d'expiration.
    // À créer et faire approuver séparément (non soumis automatiquement : ils ne concernent que les établissements qui activent leurs messages).
    'loyalty_templates' => [
        'joined' => env('WHATSAPP_TEMPLATE_LOYALTY_JOINED', 'loyalty_joined'),
        'progress' => env('WHATSAPP_TEMPLATE_LOYALTY_PROGRESS', 'loyalty_progress'),
        'reward_earned' => env('WHATSAPP_TEMPLATE_LOYALTY_REWARD', 'loyalty_reward_earned'),
        'reward_redeemed' => env('WHATSAPP_TEMPLATE_LOYALTY_REDEEMED', 'loyalty_reward_redeemed'),
        'expiry_reminder' => env('WHATSAPP_TEMPLATE_LOYALTY_EXPIRY', 'loyalty_expiry_reminder'),
    ],

    // n8n reçoit chaque message entrant (hors STOP/START) et répond via l'API interne. URL vide : aucun transfert.
    'n8n' => [
        'url' => env('N8N_INBOUND_URL'),
        'signing_secret' => env('N8N_SIGNING_SECRET'),
        'timeout_seconds' => 10,
    ],

    // Mots-clés de désabonnement / réabonnement (comparaison exacte, sans casse ni accents superflus).
    'opt_out_keywords' => ['stop', 'arret', 'arrêt', 'stoppen', 'unsubscribe', 'desabonner', 'désabonner'],
    'opt_in_keywords' => ['start', 'commencer', 'starten', 'begin'],
];
