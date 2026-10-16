<?php

declare(strict_types=1);

/**
 * Paramètres métier et de sécurité de Nova.
 *
 * Les valeurs marquées « à valider » sont des PROPOSITIONS des flowcharts (documents 1A, 2A, 5A) ou des hypothèses
 * techniques du développement : elles doivent être confirmées par le fondateur avant la mise en production.
 * À terme, les durées modifiables par le Super Admin migrent vers la table `platform_settings`.
 */
return [

    'locales' => ['fr', 'nl', 'en'],

    // Adresse du fondateur pour les alertes techniques (suppression RGPD bloquée, erreur E12). Vide : seul le journal est alerté.
    'founder_email' => env('NOVA_FOUNDER_EMAIL'),

    // Proxys de confiance (adresses séparées par des virgules, ou *) : nécessaire pour voir l'IP réelle derrière un proxy.
    'trusted_proxies' => env('TRUSTED_PROXIES'),

    // Sous-domaine du Super Admin (cookies de session distincts du client). Vide en développement local (chemin /admin).
    'admin_domain' => env('NOVA_ADMIN_DOMAIN'),

    'security' => [
        // Vérification de l'e-mail (flowchart 1A) : lien signé valable 24 h. À valider.
        'email_link_ttl_hours' => 24,

        // Code à 6 chiffres envoyé au téléphone professionnel (flowchart 1A).
        'phone_code_length' => 6,
        'phone_code_ttl_minutes' => 10, // hypothèse : non précisé dans les documents. À valider.
        'phone_code_max_attempts' => 5, // flowchart 1A / 5A (E2). À valider.
        'verification_block_minutes' => 15, // blocage après 5 codes faux (5A). À valider.
        'resend_per_hour' => 3, // 5A : 3 envois par heure maximum. À valider.
        'resend_cooldown_seconds' => 60, // 1A : renvoi possible après 60 s. À valider.

        // Connexion (flowcharts 2A, 5A/E3).
        'login_max_attempts' => 5,
        'login_lock_minutes' => 15,

        // Réinitialisation du mot de passe (flowchart 2A) : lien valable 60 min, à usage unique. À valider.
        'password_reset_ttl_minutes' => 60,

        // Invitation d'un praticien par lien (flowchart 1C/E13) : 3 jours (décision du fondateur).
        'invitation_ttl_days' => 3,

        // Robustesse du mot de passe : hypothèse (longueur plutôt que complexité). À valider.
        'password_min_length' => 12,

        // Double authentification obligatoire pour le propriétaire et les responsables de site (décision 18), sans délai de grâce.
        'two_factor_required' => env('NOVA_REQUIRE_2FA', true),
        'two_factor_issuer' => 'Nova',

        // Rappel de renouvellement du mot de passe (décision 18) : recommandé au moins tous les 12 mois.
        'password_max_age_months' => 12,

        // Double authentification du Super Admin : désactivable UNIQUEMENT en développement local (ignoré ailleurs, voir AdminMfa).
        'admin_mfa_required' => env('NOVA_ADMIN_REQUIRE_MFA', true),
        // Inactivité maximale d'une session Super Admin, en minutes (0 = désactivé) : plus courte que la session générale (docs/37).
        'admin_idle_minutes' => (int) env('NOVA_ADMIN_IDLE_MINUTES', 60),

        // Politique de contenu (CSP) : 'report-only' par défaut (signale sans bloquer), 'enforce' après vérification, 'off' pour couper.
        'csp' => env('NOVA_CSP', 'report-only'),
    ],

    // Documents juridiques (docs/28) : les versions et leurs textes vivent en base (legal_document_versions), éditées par le Super Admin.
    // Les textes eux-mêmes sont à faire valider par un juriste. Le DPA est exigé à l'inscription : l'établissement est responsable du traitement
    // des données de ses clients et Nova est son sous-traitant (RGPD art. 28), ce qui impose un accord écrit.
    'legal' => [
        'first_version' => '2026-10-01',
        'dpa_required_at_signup' => true,
    ],

    // Pays proposés pour l'entreprise et ses sites (marché belge et pays limitrophes).
    'countries' => ['BE', 'FR', 'NL', 'LU', 'DE'],

    // Fuseau principal de chaque pays, proposé par défaut (un pays peut en avoir plusieurs : voir TimezoneCatalog).
    'default_timezones' => ['BE' => 'Europe/Brussels', 'FR' => 'Europe/Paris', 'NL' => 'Europe/Amsterdam', 'LU' => 'Europe/Luxembourg', 'DE' => 'Europe/Berlin'],

    // Calendriers externes en lecture seule : synchronisation toutes les 15 min (une heure pour ceux en erreur). À valider.
    'calendars' => [
        'sync_minutes' => 15,
        'error_retry_minutes' => 60,
    ],

    // Facturation (docs/01 §9). Vendeur et TVA : PROVISOIRES, à faire valider par un comptable ; valeurs réelles par l'environnement.
    'billing' => [
        'gateway' => env('BILLING_GATEWAY', 'fake'),
        'seller' => [
            'name' => env('BILLING_SELLER_NAME', 'Nova (raison sociale à renseigner)'),
            'vat_number' => env('BILLING_SELLER_VAT'),
            'address' => env('BILLING_SELLER_ADDRESS'),
        ],
        'vat' => [
            'home_country' => 'BE',
            'home_rate' => 21,
            'eu_countries' => ['BE', 'FR', 'NL', 'LU', 'DE'],
        ],
    ],

    'booking' => [
        // Pas de la grille des créneaux proposés (minutes). Le personnel peut saisir n'importe quelle minute.
        'slot_step_minutes' => 15,
    ],

    // Couleurs proposées pour identifier un praticien dans le planning (contrastes suffisants sur fond blanc).
    'practitioner_colors' => ['#2A5BD7', '#12A594', '#B45309', '#7C3AED', '#DB2777', '#0E7490', '#4D7C0F', '#B91C1C'],

    // Pages publiques d'avis sur Nova (Google, Trustpilot), proposées à tous les clients, sans condition. Adresses https données par
    // l'environnement, jamais écrites en dur ; sans adresse valide, le lien correspondant n'est pas affiché (voir docs/26).
    'reviews' => [
        'google' => env('NOVA_REVIEW_URL_GOOGLE'),
        'trustpilot' => env('NOVA_REVIEW_URL_TRUSTPILOT'),
    ],

    // Envoi du code téléphonique : `log` en développement. Le canal réel (SMS ou modèle WhatsApp) reste à décider.
    'phone_code_driver' => env('NOVA_PHONE_CODE_DRIVER', 'log'),
];
