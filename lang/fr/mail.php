<?php

return [
    'greeting' => 'Bonjour,',
    'ignore_if_not_you' => 'Si vous n\'êtes pas à l\'origine de cette demande, ignorez simplement cet e-mail.',

    'invitation' => [
        'subject' => ':organization vous invite sur Nova',
        'greeting' => 'Bonjour :name,',
        'intro' => ':organization vous invite à rejoindre son équipe sur Nova. Créez votre compte pour renseigner vos services et vos disponibilités.',
        'action' => 'Accepter l\'invitation',
        'expires' => 'Ce lien est valable :days jours. Passé ce délai, demandez une nouvelle invitation.',
    ],

    'verify_email' => [
        'subject' => 'Vérifiez votre adresse e-mail',
        'intro' => 'Bienvenue sur Nova. Confirmez votre adresse e-mail pour activer votre compte.',
        'action' => 'Vérifier mon adresse e-mail',
        'expires' => 'Ce lien est valable :hours heures.',
    ],

    'reset_password' => [
        'subject' => 'Réinitialisation de votre mot de passe',
        'intro' => 'Vous avez demandé à réinitialiser votre mot de passe Nova.',
        'action' => 'Choisir un nouveau mot de passe',
        'expires' => 'Ce lien est valable :minutes minutes et ne peut être utilisé qu\'une seule fois.',
    ],

    'confirm_new_email' => [
        'subject' => 'Confirmez votre nouvelle adresse e-mail',
        'intro' => 'Vous avez demandé à utiliser cette adresse pour votre compte Nova.',
        'action' => 'Confirmer cette adresse',
        'expires' => 'Ce lien est valable :hours heures.',
    ],

    'email_change' => [
        'requested' => [
            'subject' => 'Changement d\'adresse e-mail demandé',
            'intro' => 'Un changement d\'adresse e-mail vers :email a été demandé pour votre compte Nova. Rien ne change tant que la nouvelle adresse n\'est pas confirmée.',
        ],
        'done' => [
            'subject' => 'Votre adresse e-mail a été modifiée',
            'intro' => 'L\'adresse e-mail de votre compte Nova est maintenant :email. Cette ancienne adresse ne sert plus à vous connecter.',
        ],
        'not_you' => 'Si vous n\'êtes pas à l\'origine de ce changement, réinitialisez votre mot de passe immédiatement et contactez le support Nova.',
    ],
    'access_restored' => [
        'subject' => 'Votre accès Nova a été débloqué',
        'intro' => 'À votre demande, le support Nova a réinitialisé la double authentification de votre compte.',
        'next_login' => 'À votre prochaine connexion, vous devrez configurer à nouveau la double authentification et enregistrer de nouveaux codes de secours.',
        'password_reset' => 'Vos sessions ouvertes ont été fermées : un autre e-mail vous permet de choisir un nouveau mot de passe.',
        'not_you' => 'Si vous n\'êtes pas à l\'origine de cette demande, contactez immédiatement le support Nova.',
    ],
    'pause' => [
        'started' => [
            'subject' => 'Le compte Nova « :name » est en pause',
            'intro' => 'Votre compte Nova est en pause jusqu\'au :date. L\'assistant WhatsApp ne prend plus de rendez-vous et aucun paiement n\'est prélevé.',
            'hint' => 'Vous pouvez réactiver votre compte à tout moment depuis Nova.',
        ],
        'ending' => [
            'subject' => 'La pause de « :name » se termine bientôt',
            'intro' => 'La pause de votre compte Nova prend fin le :date : à cette date, le compte sera suspendu.',
            'hint' => 'Réactivez-le avant cette date, ou supprimez votre compte depuis Nova si vous ne souhaitez pas reprendre.',
        ],
        'ended' => [
            'subject' => 'Le compte Nova « :name » est réactivé',
            'intro' => 'La durée de pause est écoulée : votre compte Nova est suspendu et l\'assistant WhatsApp reste indisponible.',
            'hint' => 'Réactivez-le depuis Nova pour reprendre, ou demandez sa suppression. Vos données sont conservées.',
        ],
        'suspension_ending' => [
            'subject' => 'Le compte Nova « :name » sera bientôt supprimé',
            'intro' => 'Votre compte Nova est suspendu depuis la fin de sa pause. Sans action de votre part, sa suppression sera programmée le :date.',
            'hint' => 'Réactivez votre compte depuis Nova pour le conserver. La suppression programmée reste annulable pendant le délai habituel.',
        ],
    ],

    'deletion_confirm' => [
        'subject' => 'Confirmez la suppression de votre compte Nova',
        'intro' => 'Vous avez demandé la suppression du compte Nova « :name ». Pour la programmer, confirmez avec le bouton ci-dessous.',
        'action' => 'Confirmer la suppression',
        'expires' => 'Ce lien est valable :hours heures et ne peut être utilisé qu\'une seule fois.',
        'not_you' => 'Si vous n\'êtes pas à l\'origine de cette demande, ignorez cet e-mail et changez votre mot de passe.',
    ],
    'password_changed' => [
        'subject' => 'Votre mot de passe a été modifié',
        'intro' => 'Le mot de passe de votre compte Nova vient d\'être modifié.',
        'other_sessions' => 'Pour votre sécurité, toutes les autres sessions ont été fermées.',
        'not_you' => 'Si vous n\'êtes pas à l\'origine de ce changement, réinitialisez votre mot de passe immédiatement et contactez le support Nova.',
    ],

    'suspicious_login' => [
        'subject' => 'Tentatives de connexion inhabituelles',
        'intro' => 'Plusieurs tentatives de connexion échouées ont été détectées sur votre compte. La connexion est verrouillée pendant :minutes minutes.',
        'advice' => 'Si ce n\'était pas vous, nous vous conseillons de réinitialiser votre mot de passe.',
    ],

    'account_status' => [
        'suspended' => [
            'subject' => 'Votre compte Nova « :name » a été suspendu',
            'intro' => 'Le compte Nova de « :name » vient d\'être suspendu : votre tableau de bord est en lecture seule et l\'assistant WhatsApp est indisponible.',
        ],
        'suspension_lifted' => [
            'subject' => 'La suspension de votre compte Nova « :name » est levée',
            'intro' => 'Le compte Nova de « :name » est de nouveau pleinement utilisable.',
        ],
        'deletion_scheduled' => [
            'subject' => 'La suppression de votre compte Nova « :name » est programmée',
            'intro' => 'La suppression du compte Nova de « :name » a été programmée.',
        ],
        'deletion_cancelled' => [
            'subject' => 'La suppression de votre compte Nova « :name » est annulée',
            'intro' => 'La suppression programmée du compte Nova de « :name » a été annulée : vos données sont conservées.',
        ],
        'reason' => 'Motif : :reason',
        'deletion_date' => 'Vos données seront effacées le :date. Vous pouvez exporter vos données d\'ici là.',
        'contact' => 'Pour toute question, contactez le support Nova en répondant à cet e-mail.',
    ],

    'deletion_completed' => [
        'subject' => 'Les données de « :name » ont été supprimées',
        'intro' => 'Comme demandé, le compte Nova de « :name » et ses données (clients, rendez-vous, conversations, équipe) ont été définitivement supprimés.',
        'retention' => 'Seules subsistent, le temps imposé par la loi, les données strictement nécessaires aux obligations comptables et de sécurité.',
    ],

    'deletion_failed' => [
        'subject' => 'Nova : une suppression de compte est bloquée',
        'intro' => 'La suppression de l\'organisation :org est bloquée à l\'étape « :step » (erreur :error) après 3 tentatives.',
        'action' => 'Ouvrez le Super Admin, rubrique Suppressions, puis relancez la suppression après correction.',
    ],

    'billing' => [
        'button' => 'Ouvrir la facturation',
        'payment_failed' => [
            'subject' => 'Votre paiement Nova a été refusé',
            'intro' => 'Le paiement de :amount pour votre abonnement Nova a été refusé (tentative :attempt sur :max).',
            'action' => 'Nous réessaierons le :date. Vous pouvez aussi mettre à jour votre moyen de paiement dès maintenant.',
        ],
        'payment_failed_final' => [
            'subject' => 'Votre compte Nova est suspendu : paiement impossible',
            'intro' => 'Le paiement de :amount a été refusé :max fois. Votre compte est suspendu : le tableau de bord est en lecture seule et l\'assistant WhatsApp est indisponible.',
            'action' => 'Mettez à jour votre moyen de paiement pour régulariser : le compte est rétabli dès que le paiement passe.',
        ],
        'trial_ending' => [
            'subject' => 'Votre essai Nova se termine bientôt',
            'intro' => 'Votre période d\'essai se termine le :date.',
            'action' => 'Choisissez une formule avant cette date pour continuer sans interruption.',
        ],
        'trial_ended' => [
            'subject' => 'Votre essai Nova est terminé',
            'intro' => 'Votre période d\'essai est terminée : votre compte est suspendu (lecture seule, assistant WhatsApp indisponible). Vos données sont conservées.',
            'action' => 'Choisissez une formule pour rétablir immédiatement votre compte.',
        ],
        'subscription_ended' => [
            'subject' => 'Votre abonnement Nova est terminé',
            'intro' => 'Votre abonnement a pris fin comme demandé : votre compte est suspendu (lecture seule). Vos données sont conservées.',
            'action' => 'Vous pouvez vous réabonner à tout moment pour rétablir le compte.',
        ],
    ],

    'support' => [
        'action' => 'Voir mon ticket',
        'created' => [
            'subject' => 'Votre demande a bien été envoyée (#:reference)',
            'intro' => 'Nous avons bien reçu votre demande. Voici de quoi la retrouver.',
            'reference' => 'Référence : :reference',
            'summary' => 'Objet : :subject. Catégorie : :category. Urgence : :urgency.',
            'response_time' => 'Nous visons une réponse sous 1 jour ouvrable. Les cas complexes peuvent demander plus de temps.',
        ],
        'reply' => [
            'subject' => 'Nouvelle réponse sur votre demande #:reference',
            'intro' => 'Notre équipe a répondu à votre demande « :subject ».',
            'how' => 'Pour répondre, rendez-vous sur la page de vos tickets : répondre à cet e-mail ne fonctionne pas.',
        ],
        'resolved' => [
            'subject' => 'Votre demande #:reference est résolue',
            'intro' => 'Nous pensons avoir réglé votre demande « :subject ».',
            'reopen' => 'Si ce n’est pas le cas, répondez dans les :days jours : le ticket sera rouvert. Sinon il se fermera tout seul.',
        ],
        'status' => [
            'subject' => 'Votre demande #:reference a évolué',
            'intro' => 'L’état de votre demande « :subject » a changé.',
            'open' => 'Votre demande est de nouveau ouverte et notre équipe va la reprendre.',
            'in_progress' => 'Notre équipe s’en occupe.',
            'waiting_on_you' => 'Nous avons besoin d’informations ou d’une action de votre part. Merci de répondre depuis la page de vos tickets.',
        ],
        'closed' => [
            'subject' => 'Votre demande #:reference est fermée',
            'intro' => 'Notre équipe a fermé votre demande « :subject ».',
            'new_ticket' => 'Vous ne pouvez plus répondre à ce ticket. Si vous avez encore besoin d’aide, ouvrez une nouvelle demande.',
        ],
    ],
];
