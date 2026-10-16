<?php

declare(strict_types=1);

namespace App\Modules\Whatsapp\Support;

/**
 * Bibliothèque de départ des messages WhatsApp (docs/39) : pour chaque secteur, un jeu complet qui couvre le parcours client
 * (bienvenue, confirmation, rappel, déplacement, annulation, paiement, suivi, avis, relance, promotion, information), en français,
 * néerlandais et anglais. Chaque secteur a son vocabulaire (rendez-vous, consultation, visite, séance, soin, intervention, leçon) et
 * ses phrases propres ; un secteur créé plus tard par le Super Admin reçoit le jeu du secteur « Autre ».
 *
 * Les textes sont des PROPOSITIONS importées en brouillon : le Super Admin les relit et les approuve avant tout envoi. Ils suivent les
 * règles de Meta (aucun ne commence ou ne finit par une variable, chaque variable une seule fois) ; un test le vérifie pour chaque texte.
 */
final class MessageTemplateLibrary
{
    public const GENERIC = 'other';

    /** Types importés (le type « message libre » est créé à la main). */
    public const TYPES = [
        MessageTemplateType::WELCOME, MessageTemplateType::CONFIRMATION, MessageTemplateType::REMINDER, MessageTemplateType::MODIFICATION,
        MessageTemplateType::CANCELLATION, MessageTemplateType::PAYMENT_REMINDER, MessageTemplateType::PAYMENT_CONFIRMATION,
        MessageTemplateType::FOLLOW_UP, MessageTemplateType::REVIEW_REQUEST, MessageTemplateType::RE_ENGAGEMENT,
        MessageTemplateType::PROMOTIONAL, MessageTemplateType::INFORMATIONAL,
    ];

    /**
     * Texte d'un type pour un secteur dans une langue ; secteur inconnu → jeu générique.
     */
    public static function body(string $sector, string $type, string $language): string
    {
        $profile = self::profile($sector);
        $words = ['noun' => $profile['noun'][$language], ...array_map(fn (array $line): string => $line[$language], $profile['lines'])];
        $text = self::PATTERNS[$language][$type] ?? throw new \InvalidArgumentException("Type inconnu : {$type}");

        return (string) preg_replace_callback('/<(\w+)>/', fn (array $m): string => $words[$m[1]] ?? $m[0], $text);
    }

    /** Service d'exemple (aperçu) pour un secteur. */
    public static function sampleService(string $sector, string $language): string
    {
        return self::profile($sector)['service'][$language];
    }

    /** Nom lisible du modèle, par type, pour la liste d'administration. */
    public static function name(string $type): string
    {
        return (string) __('message_templates.types.'.$type);
    }

    /**
     * @return list<string> codes des secteurs qui ont un jeu à eux
     */
    public static function sectors(): array
    {
        return array_keys(self::PROFILES);
    }

    /**
     * @return array{noun: array<string, string>, service: array<string, string>, lines: array<string, array<string, string>>}
     */
    private static function profile(string $sector): array
    {
        return self::PROFILES[$sector] ?? self::PROFILES[self::GENERIC];
    }

    /** @var array<string, array<string, string>> */
    private const PATTERNS = [
        'fr' => [
            'welcome' => 'Bonjour {{customer_name}}, bienvenue chez {{business_name}} ! <welcome> Pour réserver, répondez à ce message ou passez par {{booking_link}} À bientôt !',
            'confirmation' => 'Bonjour {{customer_name}}, {{business_name}} confirme votre <noun> ({{service_name}}) le {{date}} à {{time}}. Adresse : {{address}}. À bientôt !',
            'reminder' => 'Bonjour {{customer_name}}, petit rappel : votre <noun> chez {{business_name}} ({{service_name}}) a lieu le {{date}} à {{time}}. À bientôt !',
            'modification' => 'Bonjour {{customer_name}}, {{business_name}} a déplacé votre <noun> ({{service_name}}) : il a désormais lieu le {{date}} à {{time}}. Merci de votre compréhension.',
            'cancellation' => 'Bonjour {{customer_name}}, {{business_name}} doit annuler votre <noun> ({{service_name}}) du {{date}} à {{time}}. Nous sommes désolés pour ce désagrément. Pour en fixer un nouveau : {{booking_link}} Merci de votre compréhension.',
            'payment_reminder' => 'Bonjour {{customer_name}}, un paiement de {{amount}} reste dû à {{business_name}} pour votre <noun> ({{service_name}}). Vous pouvez le régler ici : {{payment_link}} Merci !',
            'payment_confirmation' => 'Bonjour {{customer_name}}, {{business_name}} a bien reçu votre paiement de {{amount}} pour {{service_name}}. Merci !',
            'follow_up' => 'Bonjour {{customer_name}}, merci de votre visite chez {{business_name}} ({{service_name}}). <follow> Répondez à ce message pour toute question.',
            'review_request' => 'Bonjour {{customer_name}}, merci d’avoir choisi {{business_name}}. <review> Votre avis compte beaucoup : {{review_link}} Merci !',
            're_engagement' => 'Bonjour {{customer_name}}, cela fait un moment que nous ne vous avons pas vu chez {{business_name}}. <reengage> Réservez quand vous voulez : {{booking_link}} À très vite !',
            'promotional' => 'Bonjour {{customer_name}}, une offre de {{business_name}} : <promo> Pour en profiter, réservez ici : {{booking_link}} Pour ne plus recevoir nos offres, répondez STOP.',
            'informational' => 'Bonjour {{customer_name}}, une information de {{business_name}} avant votre <noun> du {{date}} à {{time}} : <info> Adresse : {{address}}. À bientôt !',
        ],
        'nl' => [
            'welcome' => 'Beste {{customer_name}}, welkom bij {{business_name}}! <welcome> Om te boeken antwoordt u op dit bericht of gaat u naar {{booking_link}} Tot snel!',
            'confirmation' => 'Beste {{customer_name}}, {{business_name}} bevestigt uw <noun> ({{service_name}}) op {{date}} om {{time}}. Adres: {{address}}. Tot binnenkort!',
            'reminder' => 'Beste {{customer_name}}, een herinnering: uw <noun> bij {{business_name}} ({{service_name}}) vindt plaats op {{date}} om {{time}}. Tot binnenkort!',
            'modification' => 'Beste {{customer_name}}, {{business_name}} heeft uw <noun> ({{service_name}}) verplaatst: die vindt nu plaats op {{date}} om {{time}}. Bedankt voor uw begrip.',
            'cancellation' => 'Beste {{customer_name}}, {{business_name}} moet uw <noun> ({{service_name}}) van {{date}} om {{time}} annuleren. Onze excuses voor het ongemak. Een nieuwe afspraak maakt u hier: {{booking_link}} Bedankt voor uw begrip.',
            'payment_reminder' => 'Beste {{customer_name}}, er staat nog een betaling van {{amount}} open bij {{business_name}} voor uw <noun> ({{service_name}}). U kunt die hier betalen: {{payment_link}} Bedankt!',
            'payment_confirmation' => 'Beste {{customer_name}}, {{business_name}} heeft uw betaling van {{amount}} voor {{service_name}} goed ontvangen. Bedankt!',
            'follow_up' => 'Beste {{customer_name}}, bedankt voor uw bezoek aan {{business_name}} ({{service_name}}). <follow> Antwoord gerust op dit bericht als u vragen hebt.',
            'review_request' => 'Beste {{customer_name}}, bedankt dat u voor {{business_name}} koos. <review> Uw mening telt: {{review_link}} Bedankt!',
            're_engagement' => 'Beste {{customer_name}}, we hebben u al een tijdje niet gezien bij {{business_name}}. <reengage> Boek wanneer u wilt: {{booking_link}} Tot snel!',
            'promotional' => 'Beste {{customer_name}}, een aanbieding van {{business_name}}: <promo> Boek hier om ervan te profiteren: {{booking_link}} Antwoord STOP om geen aanbiedingen meer te ontvangen.',
            'informational' => 'Beste {{customer_name}}, een mededeling van {{business_name}} voor uw <noun> op {{date}} om {{time}}: <info> Adres: {{address}}. Tot binnenkort!',
        ],
        'en' => [
            'welcome' => 'Hello {{customer_name}}, welcome to {{business_name}}! <welcome> To book, reply to this message or go to {{booking_link}} See you soon!',
            'confirmation' => 'Hello {{customer_name}}, {{business_name}} confirms your <noun> ({{service_name}}) on {{date}} at {{time}}. Address: {{address}}. See you soon!',
            'reminder' => 'Hello {{customer_name}}, a quick reminder: your <noun> at {{business_name}} ({{service_name}}) is on {{date}} at {{time}}. See you soon!',
            'modification' => 'Hello {{customer_name}}, {{business_name}} has moved your <noun> ({{service_name}}): it now takes place on {{date}} at {{time}}. Thank you for your understanding.',
            'cancellation' => 'Hello {{customer_name}}, {{business_name}} has to cancel your <noun> ({{service_name}}) on {{date}} at {{time}}. We apologise for the inconvenience. To book a new one: {{booking_link}} Thank you for your understanding.',
            'payment_reminder' => 'Hello {{customer_name}}, a payment of {{amount}} is still due to {{business_name}} for your <noun> ({{service_name}}). You can pay it here: {{payment_link}} Thank you!',
            'payment_confirmation' => 'Hello {{customer_name}}, {{business_name}} has received your payment of {{amount}} for {{service_name}}. Thank you!',
            'follow_up' => 'Hello {{customer_name}}, thank you for visiting {{business_name}} ({{service_name}}). <follow> Reply to this message if you have any questions.',
            'review_request' => 'Hello {{customer_name}}, thank you for choosing {{business_name}}. <review> Your opinion matters: {{review_link}} Thank you!',
            're_engagement' => 'Hello {{customer_name}}, it has been a while since we last saw you at {{business_name}}. <reengage> Book whenever you like: {{booking_link}} See you soon!',
            'promotional' => 'Hello {{customer_name}}, an offer from {{business_name}}: <promo> To take advantage of it, book here: {{booking_link}} Reply STOP to stop receiving our offers.',
            'informational' => 'Hello {{customer_name}}, some information from {{business_name}} before your <noun> on {{date}} at {{time}}: <info> Address: {{address}}. See you soon!',
        ],
    ];

    /**
     * Vocabulaire et phrases propres à chaque secteur. Clés des phrases : welcome, follow, review, reengage, promo, info.
     *
     * @var array<string, array{noun: array<string, string>, service: array<string, string>, lines: array<string, array<string, string>>}>
     */
    private const PROFILES = [
        'hairdressing' => [
            'noun' => ['fr' => 'rendez-vous', 'nl' => 'afspraak', 'en' => 'appointment'],
            'service' => ['fr' => 'Coupe', 'nl' => 'Knippen', 'en' => 'Haircut'],
            'lines' => [
                'welcome' => ['fr' => 'Coupe, couleur, barbe : nous prenons soin de votre style.', 'nl' => 'Knippen, kleuren, baard: wij zorgen voor uw stijl.', 'en' => 'Cut, colour, beard: we take care of your style.'],
                'follow' => ['fr' => 'Nous espérons que votre coiffure vous plaît ; demandez-nous conseil pour l’entretenir à la maison.', 'nl' => 'We hopen dat uw kapsel u bevalt; vraag ons gerust advies om het thuis te onderhouden.', 'en' => 'We hope you love your new look; ask us for tips to keep it up at home.'],
                'review' => ['fr' => 'Votre coiffure vous a plu ? Dites-le en une minute.', 'nl' => 'Beviel uw kapsel? Laat het ons weten in een minuutje.', 'en' => 'Did you like your new look? Tell us in a minute.'],
                'reengage' => ['fr' => 'Il est peut-être temps de rafraîchir votre coupe.', 'nl' => 'Misschien is het tijd om uw kapsel op te frissen.', 'en' => 'It may be time to refresh your cut.'],
                'promo' => ['fr' => 'soin offert pour toute coloration réservée ce mois-ci.', 'nl' => 'gratis verzorging bij elke kleuring die u deze maand boekt.', 'en' => 'a free treatment with any colour booked this month.'],
                'info' => ['fr' => 'venez les cheveux propres et secs si possible, et prévenez-nous en cas de retard.', 'nl' => 'kom indien mogelijk met schoon, droog haar en verwittig ons bij vertraging.', 'en' => 'please come with clean, dry hair if possible and let us know if you are running late.'],
            ],
        ],
        'beauty' => [
            'noun' => ['fr' => 'soin', 'nl' => 'behandeling', 'en' => 'treatment'],
            'service' => ['fr' => 'Soin du visage', 'nl' => 'Gezichtsbehandeling', 'en' => 'Facial'],
            'lines' => [
                'welcome' => ['fr' => 'Soins du visage, du corps et beauté des ongles : prenez un moment pour vous.', 'nl' => 'Gezichts-, lichaams- en nagelverzorging: neem een moment voor uzelf.', 'en' => 'Facials, body care and nails: take a moment for yourself.'],
                'follow' => ['fr' => 'Hydratez bien votre peau et évitez le soleil direct pendant 24 heures.', 'nl' => 'Hydrateer uw huid goed en vermijd 24 uur lang direct zonlicht.', 'en' => 'Moisturise your skin well and avoid direct sun for 24 hours.'],
                'review' => ['fr' => 'Votre soin vous a plu ? Partagez votre impression.', 'nl' => 'Beviel uw behandeling? Deel uw ervaring.', 'en' => 'Did you enjoy your treatment? Share your impression.'],
                'reengage' => ['fr' => 'Votre peau mérite un nouveau moment de détente.', 'nl' => 'Uw huid verdient weer een moment van ontspanning.', 'en' => 'Your skin deserves another moment of relaxation.'],
                'promo' => ['fr' => 'un soin des mains offert pour tout soin du visage réservé ce mois-ci.', 'nl' => 'een gratis handverzorging bij elke gezichtsbehandeling die u deze maand boekt.', 'en' => 'a free hand treatment with any facial booked this month.'],
                'info' => ['fr' => 'venez sans maquillage si possible et signalez-nous toute allergie ou changement de santé.', 'nl' => 'kom indien mogelijk zonder make-up en meld ons allergieën of gezondheidswijzigingen.', 'en' => 'please come without make-up if possible and tell us about any allergy or health change.'],
            ],
        ],
        'wellness' => [
            'noun' => ['fr' => 'séance', 'nl' => 'sessie', 'en' => 'session'],
            'service' => ['fr' => 'Massage relaxant', 'nl' => 'Ontspanningsmassage', 'en' => 'Relaxing massage'],
            'lines' => [
                'welcome' => ['fr' => 'Massages, spa et détente : un moment de calme vous attend.', 'nl' => 'Massages, spa en ontspanning: een moment van rust wacht op u.', 'en' => 'Massages, spa and relaxation: a moment of calm awaits you.'],
                'follow' => ['fr' => 'Buvez de l’eau et accordez-vous du repos pour prolonger les bienfaits de la séance.', 'nl' => 'Drink water en rust wat uit om de voordelen van de sessie te verlengen.', 'en' => 'Drink water and rest to make the benefits of your session last.'],
                'review' => ['fr' => 'Votre moment de détente vous a plu ? Racontez-nous.', 'nl' => 'Genoot u van uw ontspanning? Vertel het ons.', 'en' => 'Did you enjoy your relaxing moment? Tell us about it.'],
                'reengage' => ['fr' => 'Un nouveau moment de détente vous ferait du bien.', 'nl' => 'Een nieuw moment van ontspanning zou u deugd doen.', 'en' => 'Another moment of relaxation would do you good.'],
                'promo' => ['fr' => 'une demi-heure de spa offerte pour tout massage réservé ce mois-ci.', 'nl' => 'een half uur gratis spa bij elke massage die u deze maand boekt.', 'en' => 'half an hour of spa free with any massage booked this month.'],
                'info' => ['fr' => 'arrivez dix minutes à l’avance, évitez un repas copieux avant la séance et apportez un maillot si un accès au spa est prévu.', 'nl' => 'kom tien minuten vroeger, vermijd een zware maaltijd en neem zwemkleding mee als spatoegang voorzien is.', 'en' => 'arrive ten minutes early, avoid a heavy meal beforehand and bring swimwear if spa access is included.'],
            ],
        ],
        'medical' => [
            'noun' => ['fr' => 'consultation', 'nl' => 'consult', 'en' => 'consultation'],
            'service' => ['fr' => 'Consultation générale', 'nl' => 'Algemeen consult', 'en' => 'General consultation'],
            'lines' => [
                'welcome' => ['fr' => 'Ce numéro vous permet de gérer vos rendez-vous médicaux. En cas d’urgence, appelez le 112.', 'nl' => 'Met dit nummer beheert u uw medische afspraken. Bij een noodgeval belt u 112.', 'en' => 'This number lets you manage your medical appointments. In an emergency, call 112.'],
                'follow' => ['fr' => 'Suivez les conseils donnés pendant la consultation et contactez le cabinet si les symptômes persistent.', 'nl' => 'Volg de raad uit het consult en neem contact op met de praktijk als de klachten aanhouden.', 'en' => 'Follow the advice given during the consultation and contact the practice if symptoms persist.'],
                'review' => ['fr' => 'Votre retour nous aide à améliorer l’accueil des patients.', 'nl' => 'Uw feedback helpt ons de patiëntenzorg te verbeteren.', 'en' => 'Your feedback helps us improve patient care.'],
                'reengage' => ['fr' => 'Un suivi régulier est important pour votre santé.', 'nl' => 'Regelmatige opvolging is belangrijk voor uw gezondheid.', 'en' => 'Regular follow-up matters for your health.'],
                'promo' => ['fr' => 'les créneaux de bilan de santé annuel sont ouverts.', 'nl' => 'de plaatsen voor de jaarlijkse gezondheidscheck zijn geopend.', 'en' => 'annual health check slots are now open.'],
                'info' => ['fr' => 'apportez votre carte d’identité, votre carte de mutuelle et la liste de vos médicaments.', 'nl' => 'breng uw identiteitskaart, uw ziekenfondskaart en de lijst van uw geneesmiddelen mee.', 'en' => 'please bring your ID, your insurance card and a list of your medication.'],
            ],
        ],
        'paramedical' => [
            'noun' => ['fr' => 'séance', 'nl' => 'sessie', 'en' => 'session'],
            'service' => ['fr' => 'Séance de kinésithérapie', 'nl' => 'Kinesitherapiesessie', 'en' => 'Physiotherapy session'],
            'lines' => [
                'welcome' => ['fr' => 'Vous pouvez ici gérer vos séances de soin et de rééducation.', 'nl' => 'Hier beheert u uw zorg- en revalidatiesessies.', 'en' => 'Here you can manage your care and rehabilitation sessions.'],
                'follow' => ['fr' => 'Pensez à faire vos exercices à la maison et signalez-nous toute douleur inhabituelle.', 'nl' => 'Vergeet uw oefeningen thuis niet en meld ons ongewone pijn.', 'en' => 'Remember your exercises at home and tell us about any unusual pain.'],
                'review' => ['fr' => 'Votre ressenti nous aide à adapter votre prise en charge.', 'nl' => 'Uw ervaring helpt ons uw behandeling af te stemmen.', 'en' => 'Your feedback helps us adapt your care.'],
                'reengage' => ['fr' => 'Un point d’étape peut vous aider à consolider vos progrès.', 'nl' => 'Een tussentijdse opvolging kan uw vooruitgang bestendigen.', 'en' => 'A check-in can help you consolidate your progress.'],
                'promo' => ['fr' => 'des ateliers de prévention du dos sont organisés ce mois-ci.', 'nl' => 'deze maand organiseren we rugpreventiesessies.', 'en' => 'back-care prevention workshops are running this month.'],
                'info' => ['fr' => 'venez en tenue souple, apportez une serviette et votre prescription si vous en avez une.', 'nl' => 'draag soepele kleding en neem een handdoek en uw voorschrift mee als u er een hebt.', 'en' => 'wear loose clothing and bring a towel and your prescription if you have one.'],
            ],
        ],
        'dental' => [
            'noun' => ['fr' => 'rendez-vous', 'nl' => 'afspraak', 'en' => 'appointment'],
            'service' => ['fr' => 'Détartrage', 'nl' => 'Tandsteen verwijderen', 'en' => 'Dental cleaning'],
            'lines' => [
                'welcome' => ['fr' => 'Gérez ici vos rendez-vous dentaires. En cas de douleur forte, appelez-nous directement.', 'nl' => 'Beheer hier uw tandartsafspraken. Bij hevige pijn belt u ons rechtstreeks.', 'en' => 'Manage your dental appointments here. In case of severe pain, call us directly.'],
                'follow' => ['fr' => 'Évitez de manger pendant deux heures si une anesthésie a été faite, et brossez-vous les dents avec douceur.', 'nl' => 'Eet twee uur niet als u verdoofd werd en poets uw tanden voorzichtig.', 'en' => 'Avoid eating for two hours if you had an anaesthetic, and brush gently.'],
                'review' => ['fr' => 'Votre avis aide d’autres patients à choisir leur dentiste.', 'nl' => 'Uw mening helpt andere patiënten hun tandarts te kiezen.', 'en' => 'Your opinion helps other patients choose their dentist.'],
                'reengage' => ['fr' => 'Un contrôle tous les six mois protège vos dents.', 'nl' => 'Een controle om de zes maanden beschermt uw tanden.', 'en' => 'A check-up every six months protects your teeth.'],
                'promo' => ['fr' => 'un détartrage complet à tarif préférentiel pour tout contrôle réservé ce mois-ci.', 'nl' => 'een volledige tandsteenverwijdering aan voordelig tarief bij elke controle die u deze maand boekt.', 'en' => 'a full cleaning at a preferential rate with any check-up booked this month.'],
                'info' => ['fr' => 'brossez-vous les dents avant de venir et apportez votre carte d’identité.', 'nl' => 'poets uw tanden voor u komt en neem uw identiteitskaart mee.', 'en' => 'brush your teeth before coming and bring your ID.'],
            ],
        ],
        'coaching' => [
            'noun' => ['fr' => 'séance', 'nl' => 'sessie', 'en' => 'session'],
            'service' => ['fr' => 'Séance de coaching', 'nl' => 'Coachingsessie', 'en' => 'Coaching session'],
            'lines' => [
                'welcome' => ['fr' => 'Réservez et gérez ici vos séances d’accompagnement.', 'nl' => 'Boek en beheer hier uw begeleidingssessies.', 'en' => 'Book and manage your coaching sessions here.'],
                'follow' => ['fr' => 'Prenez un moment pour noter ce que vous retenez de la séance et vos prochaines actions.', 'nl' => 'Noteer wat u uit de sessie meeneemt en uw volgende acties.', 'en' => 'Take a moment to note your takeaways from the session and your next actions.'],
                'review' => ['fr' => 'Votre témoignage aide d’autres personnes à franchir le pas.', 'nl' => 'Uw getuigenis helpt anderen de stap te zetten.', 'en' => 'Your testimonial helps others take the step.'],
                'reengage' => ['fr' => 'Faisons le point sur vos objectifs.', 'nl' => 'Laten we uw doelen evalueren.', 'en' => 'Let’s review your goals together.'],
                'promo' => ['fr' => 'un bilan de progression offert avec un pack de trois séances.', 'nl' => 'een gratis voortgangsgesprek bij een pakket van drie sessies.', 'en' => 'a free progress review with a pack of three sessions.'],
                'info' => ['fr' => 'préparez les points que vous souhaitez aborder ; la séance peut aussi se faire en visioconférence.', 'nl' => 'bereid de punten voor die u wilt bespreken; de sessie kan ook via videogesprek.', 'en' => 'prepare the points you would like to discuss; the session can also take place by video call.'],
            ],
        ],
        'real_estate' => [
            'noun' => ['fr' => 'visite', 'nl' => 'bezichtiging', 'en' => 'viewing'],
            'service' => ['fr' => 'Visite d’un bien', 'nl' => 'Bezichtiging van een pand', 'en' => 'Property viewing'],
            'lines' => [
                'welcome' => ['fr' => 'Planifiez ici vos visites et vos rendez-vous avec nos conseillers.', 'nl' => 'Plan hier uw bezichtigingen en afspraken met onze adviseurs.', 'en' => 'Plan your viewings and meetings with our advisers here.'],
                'follow' => ['fr' => 'Qu’avez-vous pensé du bien ? Dites-nous ce qui compte pour vous afin d’affiner nos propositions.', 'nl' => 'Wat vond u van het pand? Laat weten wat belangrijk is zodat we onze voorstellen kunnen verfijnen.', 'en' => 'What did you think of the property? Tell us what matters to you so we can refine our suggestions.'],
                'review' => ['fr' => 'Comment s’est passée votre visite avec notre équipe ?', 'nl' => 'Hoe verliep uw bezichtiging met ons team?', 'en' => 'How was your viewing with our team?'],
                'reengage' => ['fr' => 'De nouveaux biens correspondent peut-être à votre recherche.', 'nl' => 'Er zijn misschien nieuwe panden die bij uw zoektocht passen.', 'en' => 'New properties may match your search.'],
                'promo' => ['fr' => 'estimation gratuite de votre bien ce mois-ci.', 'nl' => 'gratis schatting van uw pand deze maand.', 'en' => 'a free valuation of your property this month.'],
                'info' => ['fr' => 'munissez-vous d’une pièce d’identité et prévenez-nous si vous venez accompagné.', 'nl' => 'neem een identiteitsbewijs mee en laat ons weten of u vergezeld komt.', 'en' => 'bring a photo ID and let us know if someone is joining you.'],
            ],
        ],
        'garage' => [
            'noun' => ['fr' => 'rendez-vous atelier', 'nl' => 'afspraak in het atelier', 'en' => 'workshop appointment'],
            'service' => ['fr' => 'Entretien annuel', 'nl' => 'Jaarlijks onderhoud', 'en' => 'Annual service'],
            'lines' => [
                'welcome' => ['fr' => 'Entretien, réparation et contrôle : gérez ici les rendez-vous de votre véhicule.', 'nl' => 'Onderhoud, herstelling en keuring: beheer hier de afspraken voor uw voertuig.', 'en' => 'Servicing, repairs and inspection: manage your vehicle appointments here.'],
                'follow' => ['fr' => 'Signalez-nous tout bruit ou voyant inhabituel dans les jours qui viennent.', 'nl' => 'Meld ons ongewone geluiden of controlelampjes de komende dagen.', 'en' => 'Let us know about any unusual noise or warning light in the coming days.'],
                'review' => ['fr' => 'Votre véhicule est-il comme neuf ? Dites-nous ce que vous avez pensé de l’intervention.', 'nl' => 'Rijdt uw voertuig weer perfect? Vertel ons wat u van de werkzaamheden vond.', 'en' => 'Is your vehicle running like new? Tell us what you thought of the work.'],
                'reengage' => ['fr' => 'Votre prochain entretien ou contrôle technique approche peut-être.', 'nl' => 'Uw volgende onderhoud of keuring komt misschien dichterbij.', 'en' => 'Your next service or inspection may be coming up.'],
                'promo' => ['fr' => 'contrôle gratuit de 15 points pour tout entretien réservé ce mois-ci.', 'nl' => 'gratis controle van 15 punten bij elk onderhoud dat u deze maand boekt.', 'en' => 'a free 15-point check with any service booked this month.'],
                'info' => ['fr' => 'apportez la carte grise et les clés de rechange, et videz le coffre si possible.', 'nl' => 'neem het inschrijvingsbewijs en de reservesleutels mee en maak de koffer indien mogelijk leeg.', 'en' => 'bring the registration document and spare keys, and empty the boot if possible.'],
            ],
        ],
        'driving_school' => [
            'noun' => ['fr' => 'leçon de conduite', 'nl' => 'rijles', 'en' => 'driving lesson'],
            'service' => ['fr' => 'Leçon de conduite', 'nl' => 'Rijles', 'en' => 'Driving lesson'],
            'lines' => [
                'welcome' => ['fr' => 'Réservez et suivez ici vos leçons de conduite jusqu’à l’examen.', 'nl' => 'Boek en volg hier uw rijlessen tot aan het examen.', 'en' => 'Book and follow your driving lessons here, all the way to the exam.'],
                'follow' => ['fr' => 'Bravo pour cette leçon ! Pensez à revoir les points abordés avant la prochaine.', 'nl' => 'Goed gedaan! Herbekijk de besproken punten voor de volgende les.', 'en' => 'Well done! Review the points covered before your next lesson.'],
                'review' => ['fr' => 'Votre moniteur vous a-t-il bien accompagné ? Donnez-nous votre avis.', 'nl' => 'Begeleidde uw instructeur u goed? Geef ons uw mening.', 'en' => 'Did your instructor support you well? Give us your opinion.'],
                'reengage' => ['fr' => 'Reprenez la route : quelques leçons suffisent pour retrouver vos repères.', 'nl' => 'Neem de weg weer op: enkele lessen volstaan om uw routine terug te vinden.', 'en' => 'Get back on the road: a few lessons are enough to regain your confidence.'],
                'promo' => ['fr' => 'un pack de dix leçons à tarif réduit ce mois-ci.', 'nl' => 'een pakket van tien lessen aan verlaagd tarief deze maand.', 'en' => 'a pack of ten lessons at a reduced rate this month.'],
                'info' => ['fr' => 'apportez votre permis provisoire ou votre carte d’identité et portez des chaussures fermées.', 'nl' => 'neem uw voorlopig rijbewijs of identiteitskaart mee en draag gesloten schoenen.', 'en' => 'bring your provisional licence or ID and wear closed shoes.'],
            ],
        ],
        'other' => [
            'noun' => ['fr' => 'rendez-vous', 'nl' => 'afspraak', 'en' => 'appointment'],
            'service' => ['fr' => 'Prestation', 'nl' => 'Dienst', 'en' => 'Service'],
            'lines' => [
                'welcome' => ['fr' => 'Vous pouvez ici réserver et gérer vos rendez-vous facilement.', 'nl' => 'Hier boekt en beheert u eenvoudig uw afspraken.', 'en' => 'Here you can easily book and manage your appointments.'],
                'follow' => ['fr' => 'Nous espérons que tout s’est bien passé.', 'nl' => 'We hopen dat alles goed verlopen is.', 'en' => 'We hope everything went well.'],
                'review' => ['fr' => 'Votre expérience nous intéresse.', 'nl' => 'Uw ervaring interesseert ons.', 'en' => 'We would love to hear about your experience.'],
                'reengage' => ['fr' => 'Nous serions ravis de vous accueillir de nouveau.', 'nl' => 'We verwelkomen u graag opnieuw.', 'en' => 'We would be delighted to welcome you again.'],
                'promo' => ['fr' => 'une surprise vous attend pour toute réservation ce mois-ci.', 'nl' => 'een verrassing wacht op u bij elke boeking deze maand.', 'en' => 'a surprise awaits you with any booking this month.'],
                'info' => ['fr' => 'merci d’arriver quelques minutes à l’avance et de nous prévenir en cas d’empêchement.', 'nl' => 'kom enkele minuten vroeger en verwittig ons bij verhindering.', 'en' => 'please arrive a few minutes early and let us know if you cannot make it.'],
            ],
        ],
    ];
}
