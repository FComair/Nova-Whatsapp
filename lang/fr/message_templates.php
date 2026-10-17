<?php

/*
 * Super Admin › Messages WhatsApp par secteur (docs/39).
 */
return [
    'singular' => 'modèle de message',
    'plural' => 'Messages WhatsApp',
    'subtitle' => 'Un jeu de messages indépendant par secteur. Seul un modèle approuvé et activé est envoyé aux clients finaux.',
    'copy_suffix' => '(copie)',
    'close' => 'Fermer',
    'edit_title' => 'Modifier « :name »',
    'edit_resets' => 'Ce modèle est en attente ou approuvé : modifier son texte ou son type le remet en brouillon, il devra être soumis et approuvé de nouveau.',

    'columns' => [
        'name' => 'Modèle',
        'sector' => 'Secteur',
        'type' => 'Type',
        'category' => 'Catégorie Meta',
        'status' => 'Statut',
        'enabled' => 'Activé',
        'languages' => 'Langues',
        'updated' => 'Modifié le',
    ],

    'types' => [
        'welcome' => 'Message de bienvenue',
        'confirmation' => 'Confirmation de rendez-vous',
        'reminder' => 'Rappel de rendez-vous',
        'modification' => 'Modification de rendez-vous',
        'cancellation' => 'Annulation de rendez-vous',
        'payment_reminder' => 'Rappel de paiement',
        'payment_confirmation' => 'Confirmation de paiement',
        'follow_up' => 'Suivi après la prestation',
        'review_request' => 'Demande d’avis',
        're_engagement' => 'Relance de client inactif',
        'promotional' => 'Message promotionnel',
        'informational' => 'Message d’information',
        'custom' => 'Message personnalisé',
    ],

    'categories' => ['utility' => 'Utilitaire', 'marketing' => 'Marketing'],

    'statuses' => [
        'draft' => 'Brouillon',
        'pending_approval' => 'En attente d’approbation',
        'approved' => 'Approuvé',
        'rejected' => 'Refusé',
        'archived' => 'Archivé',
    ],

    'languages' => ['fr' => 'Français', 'nl' => 'Néerlandais', 'en' => 'Anglais'],

    'actions' => [
        'create' => 'Nouveau modèle',
        'import' => 'Importer le jeu standard',
        'preview' => 'Aperçu',
        'edit' => 'Modifier',
        'submit' => 'Soumettre pour approbation',
        'approve' => 'Approuver',
        'reject' => 'Refuser',
        'duplicate' => 'Dupliquer',
        'enable' => 'Activer',
        'disable' => 'Désactiver',
        'archive' => 'Archiver',
        'restore' => 'Restaurer en brouillon',
        'history' => 'Historique',
        'delete' => 'Supprimer',
    ],

    'filters' => [
        'sector' => 'Secteur',
        'status' => 'Statut',
        'type' => 'Type de message',
        'category' => 'Catégorie',
        'language' => 'Langue',
        'enabled' => 'Activation',
        'all' => 'Tous',
        'enabled_yes' => 'Activés',
        'enabled_no' => 'Désactivés',
    ],

    'form' => [
        'sector' => 'Secteur',
        'sector_help' => 'Le modèle appartient à ce secteur seulement : il n’apparaît pas dans les autres et n’y est pas utilisable.',
        'sector_locked' => 'Le secteur ne change plus après la création. Pour un autre secteur, dupliquez le modèle.',
        'type' => 'Type de message',
        'type_help' => 'Confirmation, rappel, modification et annulation sont envoyés automatiquement par Rendivoo ; les autres sont prêts à l’emploi.',
        'name' => 'Nom interne',
        'category' => 'Catégorie Meta',
        'category_help' => 'Laissez vide pour la catégorie habituelle du type. « Marketing » est réservé aux offres et relances.',
        'enabled' => 'Modèle activé',
        'enabled_help' => 'Désactiver retire le modèle de l’usage sans le supprimer.',
        'translations' => 'Textes par langue',
        'translations_help' => 'Variables disponibles : :variables. Chacune une seule fois, jamais au début ni à la fin du message.',
        'body' => 'Texte (:language)',
        'body_required' => 'Obligatoire : c’est la langue de repli.',
        'body_optional' => 'Facultatif. Sans texte, la langue n’est pas disponible pour ce modèle.',
    ],

    'confirm' => [
        'submit_title' => 'Soumettre ce modèle ?',
        'submit_body' => 'Tous les textes sont contrôlés. Le modèle passe en attente d’approbation.',
        'approve_title' => 'Approuver ce modèle ?',
        'approve_body' => 'Une fois approuvé et activé, il sera utilisé pour les vrais messages des établissements du secteur « :sector ».',
        'reject_title' => 'Refuser ce modèle',
        'archive_title' => 'Archiver ce modèle ?',
        'archive_body' => 'Il ne sera plus utilisé. Vous pourrez le restaurer en brouillon.',
        'restore_title' => 'Restaurer ce modèle ?',
        'restore_body' => 'Il revient en brouillon : il devra être soumis et approuvé de nouveau.',
        'delete_title' => 'Supprimer « :name » ?',
        'delete_body' => 'Suppression définitive. L’historique du modèle reste consultable dans le journal d’audit.',
    ],

    'reject' => [
        'reason' => 'Motif du refus',
        'reason_help' => 'Visible dans la liste et l’historique ; dites ce qu’il faut corriger.',
    ],

    'duplicate' => [
        'title' => 'Dupliquer ce modèle',
        'help' => 'La copie est un brouillon indépendant. Choisissez le même secteur ou un autre.',
        'target' => 'Secteur de la copie',
    ],

    'import' => [
        'title' => 'Importer le jeu standard',
        'help' => 'Ajoute, en brouillon, les messages du parcours client (bienvenue, confirmation, rappel, déplacement, annulation, paiement, suivi, avis, relance, promotion, information) en français, néerlandais et anglais. Les modèles déjà présents ne sont jamais modifiés.',
        'sector' => 'Secteur',
        'all_sectors' => 'Tous les secteurs',
    ],

    'preview' => [
        'title' => 'Aperçu de « :name »',
        'sample' => 'Aperçu avec des données d’exemple (établissement « Salon Aurore », cliente « Marie »).',
        'meta_text' => 'Texte envoyé à Meta (variables numérotées)',
        'valid' => 'Texte valide.',
    ],

    'history' => [
        'title' => 'Historique de « :name »',
        'empty' => 'Aucun événement.',
        'reason' => 'Motif',
        'by' => 'Par :actor',
        'system' => 'Import automatique',
        'unknown' => 'Administrateur supprimé',
    ],

    'events' => [
        'created' => 'Création',
        'imported' => 'Import du jeu standard',
        'updated' => 'Modification',
        'duplicated' => 'Duplication',
        'submitted' => 'Soumis pour approbation',
        'approved' => 'Approuvé',
        'rejected' => 'Refusé',
        'archived' => 'Archivé',
        'restored' => 'Restauré en brouillon',
        'enabled' => 'Activé',
        'disabled' => 'Désactivé',
        'deleted' => 'Supprimé',
    ],

    'saved' => [
        'created' => 'Modèle créé.',
        'updated' => 'Modèle enregistré.',
        'duplicated' => 'Modèle dupliqué (brouillon).',
        'submitted' => 'Modèle soumis pour approbation.',
        'approved' => 'Modèle approuvé.',
        'rejected' => 'Modèle refusé.',
        'archived' => 'Modèle archivé.',
        'restored' => 'Modèle restauré en brouillon.',
        'enabled' => 'Modèle activé.',
        'disabled' => 'Modèle désactivé.',
        'deleted' => 'Modèle supprimé.',
        'imported' => ':created modèle(s) ajouté(s), :skipped déjà présent(s).',
    ],

    'errors' => [
        'no_translation' => 'Saisissez au moins un texte.',
        'invalid_text' => 'Le texte (:language) n’est pas valide : :problem',
        'reason_required' => 'Indiquez le motif du refus.',
        'wrong_status' => 'Impossible de « :action » : le modèle est « :status ».',
        'not_deletable' => 'Seul un brouillon, un modèle refusé ou archivé peut être supprimé. Archivez-le d’abord.',
        'unknown_sector' => 'Secteur inconnu.',
        'unknown_type' => 'Type de message inconnu.',
    ],

    'problems' => [
        'empty' => 'le texte est vide.',
        'too_long' => 'le texte dépasse :max caractères.',
        'too_many' => 'plus de :count variables.',
        'unknown' => 'la variable {{:name}} n’existe pas.',
        'duplicate' => 'la variable {{:name}} est utilisée plusieurs fois.',
        'starts_with_variable' => 'le message ne peut pas commencer par une variable.',
        'ends_with_variable' => 'le message ne peut pas se terminer par une variable.',
        'malformed' => 'des accolades {{ }} sont mal formées.',
    ],
];
