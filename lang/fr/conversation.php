<?php

declare(strict_types=1);

/*
 * Assistant de réservation WhatsApp — textes envoyés au client final (français).
 * Reprise du wording du flux n8n « MASTER ROUTER V2 » ; les textes marqués « (nouveau) » n'existaient pas dans n8n.
 * Les mots reconnus dans les messages du client sont dans « keywords » (comparés sans accents ni casse, toutes langues fusionnées).
 */

return [
    'common' => [
        'more' => 'Voir plus ➡️',
        'more_services' => 'Voir plus de services ➡️',
        'more_staff' => 'Voir plus de professionnels ➡️',
        'more_times' => 'Voir plus de créneaux ➡️',
        'previous_page' => 'Page précédente ⬅️',
        'no_preference' => 'Aucune préférence',
        'not_provided' => 'Non renseigné',
        'no_email' => 'non renseigné',
        'appointment' => 'Rendez-vous',
        'invalid_choice' => "⚠️ Choix invalide.\n\nVeuillez répondre avec l'un des numéros proposés.",
        'invalid_choice_menu' => "⚠️ Choix invalide.\n\n:menu\n\nRépondez simplement avec le numéro souhaité.",
        'invalid_day' => "Je n'ai pas compris le jour 😕\n\nExemples : aujourd'hui, demain, lundi, mardi ou 12/09.",
        'session_expired' => "⚠️ Votre demande a expiré.\n\nSi vous souhaitez prendre un rendez-vous, envoyez-nous simplement un message.",
        'error' => "😕 Une erreur est survenue de notre côté.\n\nMerci de réessayer dans quelques instants.",
    ],

    'booking' => [
        'no_service' => "😕 Aucun service n'est actuellement disponible.",
        'service_menu' => "Parfait 👍\n\nVous avez choisi de prendre un rendez-vous.\n\nQuel service souhaitez-vous ?\n\n:lines\n\nRépondez simplement avec le numéro souhaité.",
        'service_page' => "Voici les autres services disponibles :\n\n:lines\n\nRépondez simplement avec le numéro souhaité.",
        'invalid_service' => "❌ Choix invalide.\n\nVeuillez répondre avec l’un des numéros proposés dans la liste des services.",
        'day_prompt' => "Parfait 👍\n\nVous avez choisi :\n\n💈 :service\n⏱️ Durée : :duration min\n💰 Prix : :price\n\nQuel jour souhaitez-vous prendre rendez-vous ?",
        'no_slot_day' => "Parfait 👍\n\nVoici votre rendez-vous jusqu'à présent :\n\n💈 Service : :service\n⏱️ Durée : :duration min\n📅 Jour : :date\n👤 Praticien : :staff\n\n❌ Aucun créneau disponible pour cette journée.\n\nVeuillez choisir un autre jour.",
        'staff_menu' => "Parfait 👍\n\nVoici votre rendez-vous jusqu'à présent :\n\n💈 Service : :service\n💰 Prix : :price\n\n📅 Jour : :date\n\nAvez-vous une préférence pour un professionnel ?\n\n:menu\n\nRépondez simplement avec le numéro souhaité.",
        'invalid_staff' => "Je n'ai pas compris votre choix.\n\nMerci de répondre avec l'un des numéros proposés :\n\n:menu",
        'no_slot_staff' => "Parfait 👍\n\nVoici votre rendez-vous jusqu'à présent :\n\n💈 Service : :service\n⏱️ Durée : :duration min\n📅 Jour : :date\n👤 Praticien : :staff\n\n❌ Aucun créneau disponible pour cette journée.\n\nVeuillez choisir un autre jour.",
        'time_menu' => "Parfait 👍\n\nVoici votre rendez-vous jusqu'à présent :\n\n💈 Service : :service\n⏱️ Durée : :duration min\n💰 Prix : :price\n\n📅 Jour : :date\n\n👤 Professionnel : :staff\n\nChoisissez l'heure qui vous convient :\n\n:slots\n\nRépondez simplement avec le numéro souhaité.",
        'time_page' => "🕐 Voici les créneaux suivants\n\n📅 :date\n💈 Service : :service\n👤 Praticien : :staff\n\n:slots\n\nRépondez simplement avec le numéro souhaité.",
        'name_prompt' => "Parfait 👍\n\nVotre rendez-vous est presque confirmé.\n\n📋 Récapitulatif :\n\n💈 Service : :service\n⏱️ Durée : :duration min\n👤 Praticien : :staff\n\n📅 Rendez-vous : :date\n🕐 Heure : :time\n\n👤 Pour qui est ce rendez-vous ?\n\nMerci d'indiquer le nom et le prénom de la personne qui viendra au rendez-vous.",
        'invalid_name' => "Je n'ai pas compris le nom 😕\n\nMerci d'indiquer le nom et le prénom de la personne qui viendra au rendez-vous.", // (nouveau)
        'email_prompt' => "Parfait 👍\n\nMerci :name.\n\n📧 *Souhaitez-vous recevoir votre rendez-vous dans votre calendrier ?*\n\nSi oui, indiquez simplement votre adresse e-mail.\n\nExemple :\n📩 nom@gmail.com\n\n0️⃣ Continuer sans e-mail\n\n_Votre adresse sera uniquement utilisée pour l'invitation liée à ce rendez-vous._",
        'invalid_email' => "Cette adresse e-mail ne semble pas valide 😕\n\nExemple : nom@gmail.com\n\nOu répondez 0️⃣ pour continuer sans e-mail.", // (nouveau)
        'summary' => "📋 *Veuillez vérifier votre rendez-vous :*\n\n👤 Personne : :name\n\n💈 Service : :service\n⏱️ Durée : :duration min\n💰 Prix : :price\n\n👤 Praticien : :staff\n\n📅 Rendez-vous : :date\n🕐 Heure : :time\n\n📧 E-mail : :email\n\nQue souhaitez-vous faire ?\n\n1️⃣ Confirmer ✅\n2️⃣ Modifier ✏️\n3️⃣ Annuler ❌",
        'abandoned' => "❌ Votre demande de rendez-vous a été annulée.\n\nSi vous souhaitez prendre un nouveau rendez-vous, envoyez-nous simplement un message.", // (nouveau)
        'refused' => "😕 Je n'ai pas pu enregistrer ce rendez-vous.\n\nMerci de nous contacter directement chez :business.", // (nouveau)
        'confirmed' => "🎉 Parfait :name !\n\nVotre rendez-vous est confirmé avec succès.\n\n📅 Date : :date\n🕐 Heure : :start - :end\n💼 Service : :service\n⏱️ Durée : :duration min\n👤 Praticien : :staff\n💰 Prix : :price\n\n📍 :business\n:address\n\nMerci pour votre confiance 🙏\n\nÀ très bientôt chez :business ! 😊",
    ],

    'conflict' => [
        'slots' => "😔 Désolé:name,\n\nLe créneau de :time n'est malheureusement plus disponible.\n\n🏢 :business\n💈 Service : :service\n👤 Praticien : :staff\n\n📅 :date\n\nVoici les horaires encore disponibles :\n\n:slots\n\n💬 Répondez avec le numéro ou directement avec l'heure qui vous convient.\n\nPar exemple : 1 ou :example",
        'none' => "😔 Désolé:name,\n\nLe créneau de :time n'est malheureusement plus disponible.\n\n🏢 :business\n💈 Service : :service\n👤 Praticien : :staff\n\n📅 :date\n\nIl n'y a malheureusement plus aucun autre créneau disponible pour cette journée.\n\nVeuillez choisir un autre jour.",
    ],

    'modify' => [
        'none' => "Je n’ai trouvé aucun rendez-vous à venir pouvant être modifié.\n\nSi vous souhaitez prendre un nouveau rendez-vous, dites simplement par exemple :\n\n« Je voudrais prendre un rendez-vous ».",
        'too_late' => "⏰ Ce rendez-vous ne peut plus être modifié en ligne.\n\nMerci de contacter directement :business.", // (nouveau)
        'refused' => "😕 Je n'ai pas pu enregistrer cette modification.\n\nMerci de contacter directement :business.", // (nouveau)
        'choose' => "Vous avez plusieurs rendez-vous à venir.\n\nLequel souhaitez-vous modifier ?\n\n:lines\n\nRépondez simplement avec le numéro du rendez-vous.",
        'choose_line' => ":number :name\n💼 :service\n👤 :staff\n📅 :date\n🕐 :time",
        'invalid_appointment' => "Le choix indiqué n'est pas valide.\n\nVeuillez répondre avec le numéro du rendez-vous que vous souhaitez modifier.",
        'menu_single' => "Voici le rendez-vous que vous souhaitez modifier :\n\n:details\n\nQue souhaitez-vous modifier ?\n\n1️⃣ Le service\n2️⃣ Le praticien\n3️⃣ Le jour\n4️⃣ L'heure\n5️⃣ Annuler la modification\n\nRépondez simplement avec le numéro de votre choix.",
        'menu_chosen' => "✏️ Quel élément souhaitez-vous modifier ?\n\n:details\n\nRépondez simplement avec le numéro souhaité :\n\n1️⃣ Service\n2️⃣ Praticien\n3️⃣ Jour\n4️⃣ Heure\n5️⃣ Annuler la modification",
        'menu_again' => "✏️ Modification de votre rendez-vous\n\nBonjour:spaced,\n\nVoici votre rendez-vous actuel :\n\n:details\n\nQue souhaitez-vous modifier ?\n\n1️⃣ Service\n2️⃣ Praticien\n3️⃣ Date\n4️⃣ Heure\n5️⃣ Conserver le rendez-vous\n\nRépondez avec le numéro correspondant.",
        'menu_draft' => "✏️ Modification de votre réservation\n\nBonjour:spaced,:selection\n\nQue souhaitez-vous modifier ?\n\n1️⃣ Service\n2️⃣ Praticien\n3️⃣ Date\n4️⃣ Heure\n5️⃣ Retour\n\nRépondez avec le numéro correspondant.",
        'detail_service' => '💈 Service : :value',
        'detail_staff' => '👤 Praticien : :value',
        'detail_date' => '📅 Date : :value',
        'detail_time' => '🕐 Heure : :value',
        'service_menu' => "Parfait 👍\n\n:whoVous avez choisi de modifier le service.\n\nSélectionnez le nouveau service :\n\n:lines\n\nRépondez avec le numéro correspondant.",
        'service_page' => "🔄 Voici les autres services disponibles\n\n:whoSélectionnez le nouveau service :\n\n:lines\n\nRépondez avec le numéro correspondant.",
        'no_service' => "😕 Aucun service n'est actuellement disponible.",
        'no_other_staff' => "ℹ️ Modification du praticien\n\n:who:currentAucun autre praticien n’est disponible actuellement.\n\nQue souhaitez-vous faire ?\n\n1️⃣ Modifier un autre élément\n2️⃣ Conserver la réservation",
        'current_staff' => "Praticien actuellement sélectionné :\n👤 :staff\n\n",
        'staff_menu' => "👤 Modification du praticien\n\n:who:currentSélectionnez votre nouveau praticien :\n\n:menu\n\nRépondez avec le numéro correspondant.",
        'day_prompt' => "📅 Modification de la date\n\nBonjour:spaced,:selection\n\nQuel nouveau jour souhaitez-vous ?\n\nExemples : aujourd'hui, demain, lundi, mardi ou 12/09.",
        'choose_time' => "🕐 Choisissez votre nouvelle heure\n\nVoici les créneaux disponibles pour :\n\n📅 :date\n\n:slots\n\n💬 Répondez avec le numéro ou directement avec l'heure qui vous convient.\n\nPar exemple : 1 ou :example",
        'no_slot_day' => "😕 Aucun créneau disponible\n\nIl n'y a malheureusement plus de créneau disponible pour :\n\n📅 :date\n💈 :service\n\n📅 Veuillez choisir un autre jour.",
        'slots_page' => "🕒 Voici les horaires suivants :\n\n:slots\n\n💬 Répondez avec le numéro ou directement avec l'heure qui vous convient.\n\nPar exemple : 1 ou :example",
        'invalid_slot' => "⚠️ Désolé:name,\n\nJe n'ai pas compris votre choix.\n\nVeuillez répondre avec l'un des numéros proposés ou directement avec l'heure souhaitée.\n\n:slots\n\n💬 Par exemple : 1 ou :example",
        'prepared' => "✅ Modification préparée.\n\nVoici votre rendez-vous mis à jour :\n\n👤 Personne : :name\n\n💈 Service : :service\n⏱️ Durée : :duration min\n💰 Prix : :price\n\n👤 Praticien : :staff\n\n📅 Rendez-vous : :date\n🕐 Heure : :time\n\nSouhaitez-vous confirmer cette modification ?\n\n1️⃣ Confirmer ✅\n2️⃣ Modifier ✏️\n3️⃣ Annuler ❌",
        'staff_done' => "✅ Votre rendez-vous a été mis à jour.\n\nVoici votre rendez-vous mis à jour :\n\n👤 Personne : :name\n\n💈 Service : :service\n⏱️ Durée : :duration min\n💰 Prix : :price\n\n👤 Praticien : :staff\n\n📅 Rendez-vous : :date\n🕐 Heure : :time\n\nSouhaitez-vous confirmer ce rendez-vous ?\n\n1️⃣ Confirmer ✅\n2️⃣ Modifier ✏️\n3️⃣ Annuler ❌",
        'keep_time' => "🎉 Parfait:spaced !\n\nVotre ancienne heure est toujours disponible.\n\nVoici votre nouveau rendez-vous :\n\n📅 :date\n🕐 Heure : :time\n💈 Service : :service\n👤 Praticien : :staff\n\nQue souhaitez-vous faire ?\n\n1️⃣ Confirmer le rendez-vous\n2️⃣ Modifier à nouveau",
        'time_done' => "👍 Parfait:spaced !\n\nVous avez choisi un nouvel horaire.\n\nVoici le récapitulatif de votre rendez-vous :\n\n📅 Date : :date\n🕒 Heure : :time\n💼 Service : :service\n👤 Praticien : :staff\n\nMerci de vérifier ces informations.\n\n1️⃣ Confirmer le rendez-vous\n2️⃣ Modifier à nouveau",
        'staff_busy' => "❌ :staff n'est pas disponible à :time le :date.\n\nVoici les créneaux encore disponibles :\n\n:slots\n\n💬 Répondez avec le numéro ou directement avec l'heure qui vous convient.",
        'no_availability_staff' => "Parfait 👍\n\nMalheureusement, :staff n'a plus de disponibilité le :date.\n\nSouhaitez-vous consulter les disponibilités des prochains jours ?\n\nRépondez simplement :\n\n1️⃣ Oui\n2️⃣ Non",
        'staff_unavailable' => "😔 Désolé:name,\n\n:reason\n\n🏢 :business\n\nVoici les prochaines disponibilités de :staff :\n\n:suggestions\n\n💬 Répondez simplement avec le jour qui vous convient.",
        'reason_closed' => "Il n'y a plus de disponibilité le :date.",
        'reason_none_left' => ":staff n'a plus de disponibilité le :date.",
        'nothing_found' => "😕 Je n'ai trouvé aucune disponibilité prochainement.\n\nMerci de contacter directement :business.", // (nouveau)
        'done' => "✅ Votre rendez-vous a bien été mis à jour et confirmé.\n\n👤 Personne : :name\n\n💈 Service : :service\n💰 Prix : :price\n\n👤 Praticien : :staff\n\n📅 Rendez-vous : :date\n🕐 Heure : :time\n\nÀ bientôt 👋",
        'kept' => "✅ Votre rendez-vous est conservé.\n\n📅 Date : :date\n🕒 Heure : :time\n💼 Service : :service\n👤 Praticien : :staff\n\nNous vous attendons chez :business.\n\nÀ bientôt 😊",
    ],

    'cancel' => [
        'none' => "❌ Je n'ai trouvé aucun rendez-vous à venir à votre nom.\n\nSi vous souhaitez prendre un nouveau rendez-vous, envoyez-nous simplement un message.",
        'choose' => "📅 *J'ai trouvé plusieurs rendez-vous.*\n\n:lines\n\nLequel souhaitez-vous annuler ?\n\nRépondez simplement avec le numéro du rendez-vous.",
        'choose_line' => ":number *Rendez-vous :index*\n📅 :date\n🕐 :time\n💼 :service\n👤 :staff",
        'invalid_appointment' => "Le choix indiqué n'est pas valide.\n\nVeuillez répondre avec le numéro du rendez-vous que vous souhaitez annuler.",
        'confirm' => "⚠️ *Confirmation d'annulation*\n\nVous souhaitez annuler ce rendez-vous :\n\n📅 Date : :date\n🕐 Heure : :start - :end\n💼 Service : :service\n👤 Praticien : :staff\n\n*Confirmez-vous l'annulation ?*\n\n1️⃣ Oui, annuler mon rendez-vous ❌\n2️⃣ Non, conserver mon rendez-vous ✅",
        'done' => "✅ Votre rendez-vous a bien été annulé.\n\n📅 :date\n🕒 :time\n💇 :service\n👤 :staff\n\nCe créneau est désormais disponible pour une nouvelle réservation.\n\nMerci de votre confiance.\n\nSi vous souhaitez prendre un nouveau rendez-vous, envoyez-nous simplement un message.",
        'too_late' => "⏰ Ce rendez-vous ne peut plus être annulé en ligne.\n\nMerci de contacter directement :business.", // (nouveau)
    ],

    'menu' => [
        'greeting_new' => "Bonjour:spaced 👋\n\nBienvenue chez :business.\n\nQue souhaitez-vous faire ?\n\n1️⃣ Prendre un rendez-vous\n2️⃣ Poser une question",
        'greeting_existing' => "Bonjour:spaced 👋\n\nVous avez un rendez-vous prévu :\n\n💈 :service:staff_line\n📅 :date\n🕐 :time_h\n\nQue souhaitez-vous faire ?\n\n1️⃣ Confirmer ma présence ✅\n2️⃣ Modifier mon rendez-vous ✏️\n3️⃣ Annuler mon rendez-vous ❌\n4️⃣ Prendre un nouveau rendez-vous ➕\n5️⃣ Poser une question",
        'existing_appointment' => "ℹ️ Vous avez déjà un rendez-vous prévu.\n\n👤 Personne : :name\n💈 Service : :service\n👤 Praticien : :staff\n📅 Rendez-vous : :date\n🕐 Heure : :time\n\nQue souhaitez-vous faire ?\n\n1️⃣ Modifier ce rendez-vous ✏️\n2️⃣ Prendre un nouveau rendez-vous ➕\n3️⃣ Annuler ❌",
        'with_staff' => '👤 :staff',
        'clarify' => "Je n’ai pas bien compris votre demande 😊\n\nQue souhaitez-vous faire ?\n\n1️⃣ Prendre un rendez-vous\n2️⃣ Modifier un rendez-vous\n3️⃣ Annuler un rendez-vous\n4️⃣ Poser une question\n5️⃣ Parler à quelqu’un\n6️⃣ Quitter",
        'quit' => "Pas de souci 👋\n\nLa conversation est terminée. Écrivez-nous quand vous avez besoin de nous.",
        'handoff' => "Bien sûr 🙏\n\nJe transmets votre demande à l'équipe de :business. Quelqu'un vous répondra dès que possible.", // (nouveau)
        'presence_confirmed' => "✅ Présence confirmée\n\nBonjour :name,\n\nMerci, votre présence a bien été confirmée.\n\n📅 Date : :date\n🕒 Heure : :time\n💼 Service : :service\n👤 Praticien : :staff\n\n🏢 :business\n\nNous vous attendons. À bientôt !",
        'reminder_invalid' => "Je n'ai pas compris votre réponse.\n\n1️⃣ Confirmer ma présence ✅\n2️⃣ Annuler mon rendez-vous ❌", // (nouveau)
    ],

    'faq' => [
        'ask' => "Bien sûr 😊\n\nQuelle est votre question ?",
        'menu' => "Que souhaitez-vous faire ?\n\n1️⃣ Prendre un rendez-vous\n2️⃣ Poser une question",
        'unavailable' => "Je n'ai pas cette information pour le moment 🙏\n\nMerci de contacter directement :business.", // (nouveau)
    ],

    'names' => [
        'weekdays' => ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'],
        'months' => ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'],
    ],

    // Texte enregistré dans le rendez-vous / l'historique (visible par l'établissement).
    'booking_note' => 'Réservé par WhatsApp pour :name',
    'service_change_reason' => 'Changement de service demandé par le client (WhatsApp)',
    'cancel_reason' => 'Annulé par le client (WhatsApp)',

    // Consignes envoyées au modèle de langage (toujours en français ; il répond dans la langue du client).
    'prompts' => [
        'intent' => "Tu classes le message d'un client d'un établissement de services (coiffeur, spa, cabinet, garage…) qui écrit sur WhatsApp.\n\nRéponds UNIQUEMENT avec un objet JSON : {\"intent\": \"...\", \"confidence\": 0.0}\n\nIntentions possibles :\n- book_appointment : prendre un rendez-vous\n- modify_booking : modifier un rendez-vous existant\n- cancel_booking : annuler un rendez-vous\n- faq : poser une question (horaires, prix, adresse, services…)\n- human_handoff : parler à une personne\n- greeting : simple salutation\n- unknown : autre ou incompréhensible\n\n« confidence » est ta certitude entre 0 et 1.",
        'intent_user' => "État de la conversation : :state\nDernière intention : :intent\nMessage du client : \":message\"",
        'faq' => "Tu es l'assistant WhatsApp de :business.\n\nTon rôle est de répondre aux questions FAQ des clients.\n\nRÈGLES ABSOLUES :\n- Réponds uniquement avec les informations fournies dans le contexte.\n- N'invente jamais une information.\n- Si une information n'est pas disponible, dis clairement que tu ne disposes pas de cette information.\n- Réponds dans la langue du client.\n- Réponds de manière courte, naturelle et professionnelle.\n- Ne mentionne jamais que tu es une IA.\n- Ne montre jamais les données internes, IDs ou données techniques.\n- Ne crée, ne modifie et n'annule aucun rendez-vous depuis ce flow FAQ.\n- Si le client demande une action sur un rendez-vous, indique simplement qu'il peut demander à réserver, modifier ou annuler son rendez-vous.\n\nINFORMATIONS ENTREPRISE :\n\nNom :\n:business\n\nAdresse :\n:address\n\nPays :\n:country\n\nHoraires :\n:hours\n\nPolitique d'annulation :\n:cancellation\n\nSERVICES :\n\n:services",
        'faq_user' => "Question du client :\n\":question\"\n\nRéponds directement au client.",
        'none' => 'Non renseigné',
        'closed' => 'fermé',
        'cancellation' => 'Annulation ou modification possible jusqu\'à :hours h avant le rendez-vous.',
    ],

    'keywords' => [
        'today' => ['aujourd\'hui', 'aujourdhui', 'ce jour', 'today', 'vandaag'],
        'tomorrow' => ['demain', 'tomorrow', 'morgen'],
        'after_tomorrow' => ['apres-demain', 'apres demain', 'day after tomorrow', 'overmorgen'],
        'next' => ['prochain', 'prochaine', 'next', 'volgende'],
        'weekdays' => ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'],
        'human' => ['parler a quelqu', 'parler a un humain', 'parler a une personne', 'un humain', 'un conseiller', 'quelqu\'un', 'human', 'agent', 'iemand spreken', 'een mens'],
        'quit' => ['quitter', 'quit', 'exit', 'sortir', 'terminer', 'afsluiten', 'stoppen'],
        'cancel' => ['annuler', 'annulation', 'supprimer mon rdv', 'cancel', 'annuleren', 'afzeggen'],
        'modify' => ['modifier', 'modification', 'changer', 'deplacer', 'decaler', 'reporter', 'reschedule', 'change', 'wijzigen', 'verzetten', 'verplaatsen'],
        'book' => ['prendre rendez', 'prendre un rdv', 'reserver', 'reservation', 'je voudrais un rendez', 'je veux un rendez', 'book', 'appointment', 'afspraak maken', 'boeken', 'reserveren'],
        'book_extra' => ['rdv', 'rendez-vous', 'rendez vous', 'disponibilite', 'creneau'],
        'faq' => ['horaire', 'ouvert', 'ferme', 'adresse', 'ou etes', 'prix', 'tarif', 'combien', 'parking', 'telephone', 'opening hours', 'price', 'address', 'openingsuren', 'prijs', 'adres'],
        'greeting' => ['bonjour', 'bonsoir', 'salut', 'coucou', 'hello', 'hi', 'hey', 'hallo', 'goedendag', 'goedemorgen'],
        'yes' => ['oui', 'ok', 'yes', 'ja', 'd\'accord', 'volontiers'],
        'no' => ['non', 'no', 'nee', 'pas maintenant'],
        'skip_email' => ['0', 'sans e-mail', 'sans email', 'non merci', 'no email', 'zonder e-mail'],
        'confirm' => ['confirmer', 'confirme', 'je confirme', 'valider', 'confirm', 'bevestigen'],
        'modify_again' => ['modifier', 'modifier a nouveau', 'changer', 'wijzigen'],
        'abandon' => ['annuler', 'abandonner', 'laisser tomber', 'stop', 'cancel', 'annuleren'],
        'new_booking' => ['nouveau rendez', 'nouveau rdv', 'un autre rendez', 'new appointment', 'nieuwe afspraak'],
        'question' => ['poser une question', 'question', 'vraag'],
        'keep' => ['conserver', 'garder', 'non', 'keep', 'bewaren'],
        'confirm_presence' => ['confirmer ma presence', 'je serai la', 'present', 'je viens', 'ik kom'],
        'cancel_yes' => ['oui', 'oui annuler', 'annuler', 'yes', 'ja'],
        'rdv' => ['rendez-vous', 'rendez vous', 'rdv', 'appointment', 'afspraak'],
        'pick_service' => ['service', 'le service', 'dienst'],
        'pick_staff' => ['praticien', 'le praticien', 'professionnel', 'coiffeur', 'employe', 'medewerker', 'staff'],
        'pick_day' => ['jour', 'le jour', 'date', 'la date', 'dag', 'day'],
        'pick_time' => ['heure', 'l\'heure', 'horaire', 'uur', 'time'],
        'pick_back' => ['retour', 'annuler la modification', 'conserver', 'terug', 'back'],
        'pick_menu' => ['modifier un autre element', 'autre element'],
    ],
];
