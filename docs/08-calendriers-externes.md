# Calendriers externes (étape 6)

Décisions du fondateur : import **en lecture seule**, synchronisation continue, formats **ICS et CalDAV** ; aucun contact avec Doctena ni Rosa (leur calendrier est lu uniquement par le lien ICS qu'ils fournissent, s'ils en fournissent un).

## Principe

Le temps occupé d'un calendrier externe bloque les créneaux du praticien, comme un rendez-vous. Rendivoo n'écrit jamais dans l'agenda d'origine. **Seuls des horaires sont conservés** : ni titre, ni lieu, ni participants (testé). L'adresse ICS (souvent un secret qui donne accès à tout le calendrier) et les identifiants CalDAV sont chiffrés au repos et jamais renvoyés au navigateur.

## Lecture

- **ICS** : abonnement par adresse `https://` ou `webcal://`. **CalDAV** : requête `REPORT calendar-query` sur la période utile, identifiants en Basic Auth.
- Période lue : de la veille jusqu'à l'horizon de réservation (politiques) + 7 jours (30 jours au minimum).
- Lecteur iCalendar interne (aucune dépendance) : fuseaux IANA, chemins de fuseau et noms Windows d'Outlook ; récurrences DAILY, WEEKLY, MONTHLY, YEARLY avec INTERVAL, COUNT, UNTIL, BYDAY (avec rang), BYMONTHDAY, BYMONTH ; EXDATE ; occurrences modifiées ou annulées ; heure d'été/hiver ; événements « libres » (TRANSP) et annulés ignorés. Règles non prises en charge (BYSETPOS…) : seule la première occurrence est comptée et le calendrier l'indique à l'écran.
- Fréquence : toutes les 15 minutes par calendrier (`rendivoo.calendars.sync_minutes`), une heure pour un calendrier en erreur. Bouton « synchroniser maintenant » (une fois par minute).
- Échec : les dernières plages connues restent appliquées (on bloque plutôt que de risquer un double rendez-vous) ; après 5 échecs de suite le calendrier passe en « erreur ». Codes d'erreur affichés dans la langue du compte, jamais l'adresse.

## Sécurité (SSRF)

Les adresses sont saisies par des utilisateurs et lues par le serveur : HTTPS seulement, port 443 seulement, pas d'identifiants dans l'adresse, aucune adresse privée ou réservée (toutes les adresses du nom d'hôte sont vérifiées), connexion épinglée sur l'adresse vérifiée, redirections revalidées (3 au plus), taille limitée à 5 Mo, délai de 15 s, réponse XML refusée si elle contient un DOCTYPE.

## Flux ICS sortant

Adresse secrète par praticien (`/ics/nf_….ics`) à ajouter dans ses autres agendas : rendez-vous confirmés (7 jours passés, 180 à venir), horaire et nom du service seulement, **aucune donnée de client**. Renouvelable (l'ancienne adresse cesse aussitôt). Les événements de ce flux sont ignorés s'ils reviennent par un calendrier importé.

## Droits (docs/02 §1)

Propriétaire : tous les praticiens. Responsable de site : les praticiens de ses sites. Praticien : les siens. Réceptionniste : aucun accès. 5 calendriers par praticien au plus (hypothèse).

## Pas encore fait

Import de **fiches clients** depuis un calendrier (« au choix par calendrier », décision Q14 : à préciser, voir docs/04), écriture vers un agenda externe (hors décision), notification au propriétaire quand un calendrier passe en erreur, OAuth Google/Microsoft (l'ICS secret suffit pour l'instant).