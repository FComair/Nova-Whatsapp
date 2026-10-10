<?php

return [
    'greeting' => 'Hallo,',
    'ignore_if_not_you' => 'Als u deze aanvraag niet hebt gedaan, kunt u deze e-mail gewoon negeren.',

    'invitation' => [
        'subject' => ':organization nodigt u uit op Nova',
        'greeting' => 'Hallo :name,',
        'intro' => ':organization nodigt u uit om het team op Nova te vervoegen. Maak uw account aan om uw diensten en beschikbaarheid in te vullen.',
        'intro_assistant' => ':organization nodigt u uit om het team op Nova te vervoegen. Maak uw account aan om de agenda en de klanten te helpen beheren.',
        'action' => 'Uitnodiging aanvaarden',
        'expires' => 'Deze link is :days dagen geldig. Vraag daarna een nieuwe uitnodiging aan.',
    ],

    'verify_email' => [
        'subject' => 'Bevestig uw e-mailadres',
        'intro' => 'Welkom bij Nova. Bevestig uw e-mailadres om uw account te activeren.',
        'action' => 'Mijn e-mailadres bevestigen',
        'expires' => 'Deze link is :hours uur geldig.',
    ],

    'reset_password' => [
        'subject' => 'Uw wachtwoord opnieuw instellen',
        'intro' => 'U hebt gevraagd om uw Nova-wachtwoord opnieuw in te stellen.',
        'action' => 'Een nieuw wachtwoord kiezen',
        'expires' => 'Deze link is :minutes minuten geldig en kan maar één keer worden gebruikt.',
    ],

    'confirm_new_email' => [
        'subject' => 'Bevestig uw nieuwe e-mailadres',
        'intro' => 'U hebt gevraagd dit adres voor uw Nova-account te gebruiken.',
        'action' => 'Dit adres bevestigen',
        'expires' => 'Deze link is :hours uur geldig.',
    ],

    'email_change' => [
        'requested' => [
            'subject' => 'Wijziging van e-mailadres aangevraagd',
            'intro' => 'Er is een wijziging van het e-mailadres naar :email aangevraagd voor uw Nova-account. Er verandert niets zolang het nieuwe adres niet is bevestigd.',
        ],
        'done' => [
            'subject' => 'Uw e-mailadres is gewijzigd',
            'intro' => 'Het e-mailadres van uw Nova-account is nu :email. Dit oude adres kan niet meer worden gebruikt om aan te melden.',
        ],
        'not_you' => 'Als u deze wijziging niet zelf hebt gedaan, stel dan onmiddellijk uw wachtwoord opnieuw in en neem contact op met de Nova-ondersteuning.',
    ],
    'access_restored' => [
        'subject' => 'Uw Nova-toegang is gedeblokkeerd',
        'intro' => 'Op uw verzoek heeft de Nova-ondersteuning de tweestapsverificatie van uw account gereset.',
        'next_login' => 'Bij uw volgende aanmelding moet u de tweestapsverificatie opnieuw instellen en nieuwe herstelcodes bewaren.',
        'password_reset' => 'Uw open sessies zijn gesloten: via een andere e-mail kiest u een nieuw wachtwoord.',
        'not_you' => 'Als u dit verzoek niet zelf hebt gedaan, neem dan onmiddellijk contact op met de Nova-ondersteuning.',
    ],
    'pause' => [
        'started' => [
            'subject' => 'Het Nova-account “:name” staat op pauze',
            'intro' => 'Uw Nova-account staat op pauze tot :date. De WhatsApp-assistent neemt geen afspraken meer aan en er wordt niets afgeschreven.',
            'hint' => 'U kunt uw account op elk moment heractiveren vanuit Nova.',
        ],
        'ending' => [
            'subject' => 'De pauze van “:name” eindigt binnenkort',
            'intro' => 'De pauze van uw Nova-account eindigt op :date: op die datum wordt het account geschorst.',
            'hint' => 'Heractiveer het vóór die datum, of verwijder uw account vanuit Nova als u niet wilt hervatten.',
        ],
        'ended' => [
            'subject' => 'Het Nova-account “:name” is opnieuw geactiveerd',
            'intro' => 'De pauze is voorbij: uw Nova-account is geschorst en de WhatsApp-assistent blijft onbeschikbaar.',
            'hint' => 'Heractiveer het vanuit Nova om te hervatten, of vraag de verwijdering aan. Uw gegevens blijven bewaard.',
        ],
        'suspension_ending' => [
            'subject' => 'Het Nova-account “:name” wordt binnenkort verwijderd',
            'intro' => 'Uw Nova-account is geschorst sinds het einde van de pauze. Als u niets doet, wordt de verwijdering op :date gepland.',
            'hint' => 'Heractiveer uw account vanuit Nova om het te behouden. Een geplande verwijdering kan tijdens de gebruikelijke termijn nog worden geannuleerd.',
        ],
    ],

    'deletion_confirm' => [
        'subject' => 'Bevestig de verwijdering van uw Nova-account',
        'intro' => 'U hebt gevraagd het Nova-account “:name” te verwijderen. Bevestig met onderstaande knop om dit te plannen.',
        'action' => 'Verwijdering bevestigen',
        'expires' => 'Deze link is :hours uur geldig en kan maar één keer worden gebruikt.',
        'not_you' => 'Hebt u dit verzoek niet gedaan, negeer dan deze e-mail en wijzig uw wachtwoord.',
    ],
    'password_changed' => [
        'subject' => 'Uw wachtwoord is gewijzigd',
        'intro' => 'Het wachtwoord van uw Nova-account is zojuist gewijzigd.',
        'other_sessions' => 'Voor uw veiligheid zijn alle andere sessies gesloten.',
        'not_you' => 'Hebt u deze wijziging niet zelf uitgevoerd, stel dan onmiddellijk uw wachtwoord opnieuw in en neem contact op met de Nova-support.',
    ],

    'suspicious_login' => [
        'subject' => 'Ongebruikelijke aanmeldpogingen',
        'intro' => 'Er zijn meerdere mislukte aanmeldpogingen op uw account vastgesteld. Aanmelden is :minutes minuten geblokkeerd.',
        'advice' => 'Was u dit niet, dan raden we u aan uw wachtwoord opnieuw in te stellen.',
    ],

    'account_status' => [
        'suspended' => [
            'subject' => 'Uw Nova-account « :name » is opgeschort',
            'intro' => 'Het Nova-account van « :name » is zojuist opgeschort: uw dashboard is alleen-lezen en de WhatsApp-assistent is niet beschikbaar.',
        ],
        'suspension_lifted' => [
            'subject' => 'De opschorting van uw Nova-account « :name » is opgeheven',
            'intro' => 'Het Nova-account van « :name » is weer volledig bruikbaar.',
        ],
        'deletion_scheduled' => [
            'subject' => 'De verwijdering van uw Nova-account « :name » is gepland',
            'intro' => 'De verwijdering van het Nova-account van « :name » is gepland.',
        ],
        'deletion_cancelled' => [
            'subject' => 'De verwijdering van uw Nova-account « :name » is geannuleerd',
            'intro' => 'De geplande verwijdering van het Nova-account van « :name » is geannuleerd: uw gegevens blijven bewaard.',
        ],
        'trial_extended' => [
            'subject' => 'De proefperiode van uw Nova-account « :name » is verlengd',
            'intro' => 'Nova heeft de gratis proefperiode van « :name » verlengd: uw account is volledig bruikbaar.',
        ],
        'trial_end' => 'Uw proefperiode eindigt nu op :date. Kies vóór die datum een formule om zonder onderbreking verder te gaan.',
        'reason' => 'Reden: :reason',
        'deletion_date' => 'Uw gegevens worden gewist op :date. Tot dan kunt u uw gegevens exporteren.',
        'contact' => 'Neem voor vragen contact op met de Nova-support door op deze e-mail te antwoorden.',
    ],

    'deletion_completed' => [
        'subject' => 'De gegevens van « :name » zijn verwijderd',
        'intro' => 'Zoals gevraagd zijn het Nova-account van « :name » en de bijbehorende gegevens (klanten, afspraken, gesprekken, team) definitief verwijderd.',
        'retention' => 'Alleen de gegevens die strikt nodig zijn voor boekhoudkundige en veiligheidsverplichtingen blijven bewaard, zolang de wet dat vereist.',
    ],

    'deletion_failed' => [
        'subject' => 'Nova: een accountverwijdering zit vast',
        'intro' => 'De verwijdering van organisatie :org zit vast bij de stap « :step » (fout :error) na 3 pogingen.',
        'action' => 'Open de Super Admin, rubriek Verwijderingen, en start de verwijdering opnieuw na herstel.',
    ],

    'billing' => [
        'button' => 'Facturatie openen',
        'payment_failed' => [
            'subject' => 'Uw Nova-betaling is geweigerd',
            'intro' => 'De betaling van :amount voor uw Nova-abonnement is geweigerd (poging :attempt van :max).',
            'action' => 'We proberen het opnieuw op :date. U kunt uw betaalmiddel ook nu al bijwerken.',
        ],
        'payment_failed_final' => [
            'subject' => 'Uw Nova-account is opgeschort: betaling mislukt',
            'intro' => 'De betaling van :amount is :max keer geweigerd. Uw account is opgeschort: het dashboard is alleen-lezen en de WhatsApp-assistent is niet beschikbaar.',
            'action' => 'Werk uw betaalmiddel bij om dit te regelen: het account wordt hersteld zodra de betaling lukt.',
        ],
        'trial_ending' => [
            'subject' => 'Uw Nova-proefperiode loopt bijna af',
            'intro' => 'Uw proefperiode eindigt op :date.',
            'action' => 'Kies vóór die datum een formule om zonder onderbreking verder te gaan.',
        ],
        'trial_ended' => [
            'subject' => 'Uw Nova-proefperiode is afgelopen',
            'intro' => 'Uw proefperiode is afgelopen: uw account is opgeschort (alleen-lezen, WhatsApp-assistent niet beschikbaar). Uw gegevens blijven bewaard.',
            'action' => 'Kies een formule om uw account meteen te herstellen.',
        ],
        'subscription_ended' => [
            'subject' => 'Uw Nova-abonnement is beëindigd',
            'intro' => 'Uw abonnement is beëindigd zoals gevraagd: uw account is opgeschort (alleen-lezen). Uw gegevens blijven bewaard.',
            'action' => 'U kunt zich op elk moment opnieuw abonneren om het account te herstellen.',
        ],
    ],

    'support' => [
        'action' => 'Mijn ticket bekijken',
        'created' => [
            'subject' => 'Uw aanvraag is verzonden (#:reference)',
            'intro' => 'We hebben uw aanvraag ontvangen. Zo vindt u ze terug.',
            'reference' => 'Referentie: :reference',
            'summary' => 'Onderwerp: :subject. Categorie: :category. Urgentie: :urgency.',
            'response_time' => 'We streven naar een antwoord binnen 1 werkdag. Complexe zaken kunnen langer duren.',
        ],
        'reply' => [
            'subject' => 'Nieuw antwoord op uw aanvraag #:reference',
            'intro' => 'Ons team antwoordde op uw aanvraag “:subject”.',
            'how' => 'Om te antwoorden gaat u naar de pagina met uw tickets: antwoorden op deze e-mail werkt niet.',
        ],
        'resolved' => [
            'subject' => 'Uw aanvraag #:reference is opgelost',
            'intro' => 'We denken uw aanvraag “:subject” te hebben afgehandeld.',
            'reopen' => 'Is dat niet zo, antwoord dan binnen :days dagen: het ticket wordt heropend. Anders sluit het vanzelf.',
        ],
        'status' => [
            'subject' => 'Uw aanvraag #:reference is bijgewerkt',
            'intro' => 'De status van uw aanvraag “:subject” is gewijzigd.',
            'open' => 'Uw aanvraag is opnieuw open en ons team pakt ze weer op.',
            'in_progress' => 'Ons team werkt eraan.',
            'waiting_on_you' => 'We hebben meer informatie of een actie van u nodig. Antwoord via de pagina met uw tickets.',
        ],
        'closed' => [
            'subject' => 'Uw aanvraag #:reference is gesloten',
            'intro' => 'Ons team heeft uw aanvraag “:subject” gesloten.',
            'new_ticket' => 'U kunt niet meer antwoorden op dit ticket. Hebt u nog hulp nodig, dien dan een nieuwe aanvraag in.',
        ],
    ],

    'team_access' => [
        'contact' => 'Verwacht u dit niet, neem dan contact op met de accounteigenaar.',
        'access_changed' => ['subject' => 'Uw toegang tot :organization is gewijzigd', 'intro' => 'De eigenaar van :organization heeft gewijzigd wat u op Nova kunt zien en doen. Meld u aan om uw toegang te zien.'],
        'suspended' => ['subject' => 'Uw toegang tot :organization is opgeschort', 'intro' => 'Uw toegang tot :organization op Nova is opgeschort. U kunt niet inloggen tot ze wordt geheractiveerd.'],
        'reactivated' => ['subject' => 'Uw toegang tot :organization is hersteld', 'intro' => 'Uw toegang tot :organization op Nova is geheractiveerd. U kunt opnieuw inloggen.'],
        'removed' => ['subject' => 'Uw toegang tot :organization is ingetrokken', 'intro' => 'Uw toegang tot :organization op Nova is ingetrokken. U kunt niet meer inloggen op dit account.'],
    ],
];
