# Nova : registre des décisions et des écarts de conception

Ce document tient à jour ce qui a été **confirmé par le fondateur**, ce qui relève d'une **hypothèse de développement** (à valider) et les **écarts** entre la conception initiale (`docs/01` à `03`) et le code.

## 1. Décisions confirmées par le fondateur

| # | Sujet | Décision |
|---|---|---|
| 1 | Périmètre du bot | Dashboards + API interne pour n8n. Le moteur WhatsApp reste dans n8n. |
| 2 | Stack | Laravel + PostgreSQL + Redis (Laravel 13, PHP 8.4). |
| 3 | Migration | Aucune : on repart de zéro. |
| 4 | Paiement des abonnements | Non choisi. Interface d'abstraction `PaymentGateway`, prestataire branché plus tard. |
| 5 | WhatsApp | Meta Cloud API en direct. |
| 6 | Super Admin | Fondateur seul, sans impersonation. |
| 7 | Suppression par le Super Admin | J+30 par défaut ; immédiate uniquement pour fraude ou abus, avec motif. |
| 8 | Pause et suspension | Pause 90 j non facturée ; suspension 60 j ; valeurs paramétrables. |
| 9 | Dépassement de volume | Alerte et palier supérieur proposé, sans coupure. |
| 10 | Hébergement | VPS européen, UE obligatoire. |
| 11 | Scan de carte tarifaire | Modèle de langage avec vision. |
| 12 | Charte graphique | Identité proposée par nous et validée (docs/03 partie A). |
| 13 | Calendriers externes | Synchronisation continue en lecture seule. |
| 14 | Fiches clients issues de l'import | Désactivé par défaut, activable par calendrier avec avertissement RGPD. |
| 15 | Plans concernés par l'import | Basic et Business. |
| 16 | Sources en premier | ICS + CalDAV. |
| 17 | Doctena / Rosa | Aucun contact : import de fichier ou lien ICS uniquement. |
| 18 | Super Admin : Filament | Oui. |
| 19 | Accès du Super Admin aux données d'un client | Niveaux 1 et 2 visibles ; niveau 3 exceptionnel (motif, durée limitée, journal, propriétaire notifié) ; niveau 4 jamais. |
| 21 | Canal du code de vérification du téléphone | **SMS** (fournisseur à choisir ; le pilote `log` reste réservé au développement) |
| 20 | Plans initiaux | **Starter 29,99 €** et **Business 49,99 €** (hors TVA). |

## 2. Hypothèses de développement (à valider)

Valeurs regroupées dans `config/nova.php`. Les flowcharts les marquent « à valider » ou ne les précisent pas.

| Paramètre | Valeur retenue | Source |
|---|---|---|
| Validité du lien de vérification e-mail | 24 h | Flowchart 1A (proposition) |
| Validité du code téléphone | 10 min | **Hypothèse** (non précisé) |
| Essais de code avant blocage | 5, blocage 15 min | Flowcharts 1A/5A (proposition) |
| Renvoi de lien ou de code | 60 s entre deux envois, 3 par heure | Flowcharts 1A/5A (proposition) |
| Échecs de connexion avant verrouillage | 5, verrouillage 15 min | Flowcharts 2A/5A (proposition) |
| Validité du lien de réinitialisation | 60 min, usage unique | Flowchart 2A (proposition) |
| Mot de passe | 12 caractères minimum, lettres et chiffres | **Hypothèse** |
| Mot de passe Super Admin | 16 caractères minimum, avec symboles | **Hypothèse** |
| Nom de l'entreprise à l'inscription | Non demandé ; renseigné à l'étape 1 de l'onboarding | Flowchart 1A |
| Canal d'envoi du code téléphonique | **SMS** (décision n° 21). Fournisseur **non choisi** : l'interface `PhoneCodeSender` est prête, il reste à brancher un pilote SMS ; `log` interdit en production | Question ouverte : fournisseur |

## 3. Écarts par rapport à `docs/01` à `03`

| Sujet | Conception initiale | Réalisé | Raison |
|---|---|---|---|
| Table `verification_codes` | Table SQL | Codes en **cache Redis** (empreinte HMAC, durée de vie courte) | Pas de donnée à conserver ; moins de données personnelles au repos. |
| Table `login_attempts` | Table SQL | Colonnes `failed_login_count` / `locked_until` sur `users` + limiteur Redis pour les e-mails inconnus | Suffisant, et plus simple. |
| `platform_admins` : colonnes 2FA | `totp_secret_enc`, `recovery_codes_enc`, `totp_confirmed_at` | `app_authentication_secret`, `app_authentication_recovery_codes` (chiffrés) | Convention de Filament (2FA natif). |
| Table `users` | Pas de précision sur la RLS | **Sans RLS** (un utilisateur peut appartenir à plusieurs organisations) ; recherche des rattachements via la fonction `auth_memberships` | Nécessaire pour se connecter avant qu'une organisation soit sélectionnée. À durcir : restreindre la lecture des colonnes par le rôle client. |
| Tables ajoutées | n/a | `user_consents`, `password_reset_tokens`, colonne `organizations.signup_plan` | Flowchart 1A (consentements, formule choisie). |
| Erreur E15 | n/a | À ajouter au catalogue (synchronisation de calendrier échouée) | Import de calendriers. |

## 3 bis. Assistant d'onboarding (étape 2, sous-partie 1) : choix de conception

| Sujet | Choix | Pourquoi |
|---|---|---|
| Accès | Propriétaire uniquement | Matrice de rôles (docs/02 §1). |
| Reprise | À la connexion, le propriétaire arrive à l'étape enregistrée ; il peut quitter à tout moment (« Continuer plus tard »), et le tableau de bord lui propose de reprendre | Flowcharts 1B à 1D et 2A. |
| Étapes 4 à 9 | Visibles dans la progression, écran « bientôt disponible » | Modules services, praticiens, politiques, WhatsApp pas encore construits. |
| Horaires | Jusqu'à 3 plages par jour ; une plage ne traverse pas minuit (fin au plus tard 23:59) ; un jour sans plage est fermé | **Hypothèse** : un commerce ouvert après minuit n'est pas prévu à ce stade. |
| Chevauchements | Interdits **par la base** (contrainte d'exclusion sur un type intervalle d'heures), en plus de la validation | Garantie qui ne dépend pas de l'application. |
| Étape « Horaires » | Terminée seulement quand **chaque** site a au moins une plage | Un site sans horaires ne pourrait pas proposer de créneaux. |
| Suppression d'un site | Suppression logique ; jamais le dernier site ; si c'est le site principal, le plus ancien restant le devient | Spec §9. |
| Limite de sites du plan | **Non appliquée** pour l'instant | Nécessite la table des plans (étape facturation, erreur E8). |
| Fuseau horaire | Liste des fuseaux d'Europe ; un changement se répercute sur les sites qui suivaient celui de l'entreprise | Marché belge et pays limitrophes. |
| Modification de l'entreprise après l'onboarding | Pas de confirmation « rendez-vous touchés » | Aucun rendez-vous n'existe encore (flowchart 3A, à traiter avec le module réservations). |

## 3 ter. Catalogue de services (étape 2, sous-partie 2) : choix de conception

| Sujet | Choix | Pourquoi |
|---|---|---|
| Prix | Stocké en centimes ; saisi **TVA comprise** (prix affiché au client) | **Hypothèse à valider** : la spec stocke prix et taux de TVA sans préciser si le prix est HT ou TTC. |
| TVA | 0, 6, 12 ou 21 % ; le modèle propose 0 % pour les soins de santé et 21 % ailleurs | **À confirmer avec un comptable** : les taux applicables dépendent de l'activité. |
| Modèles sectoriels | Fichier `config/sector_templates.php` + traductions, **pas de table `sector_templates`** | Données globales versionnées avec le code, testables ; une table apporterait une administration inutile à ce stade. |
| Valeurs des modèles | Durées, prix et TVA **indicatifs**, rappel affiché à l'écran | Les prix varient fortement d'un commerce à l'autre. |
| Langue des intitulés du modèle | Langue de l'entreprise (celle des messages aux clients) | Les services sont montrés aux clients finaux. |
| Application du modèle | Uniquement sur un catalogue vide, jamais de doublons | Un double clic ne doit pas dupliquer les services. |
| Catégories | Saisie libre, retrouvées sans tenir compte de la casse ; unicité par organisation garantie en base | Simple pour un utilisateur non technique. |
| Suppression | Logique (historique conservé) | Les réservations passées référenceront le service. |
| Accès | Propriétaire et responsable de site | Matrice de rôles (docs/02 §1). |
| Non inclus | Questions personnalisées par service (`service_fields`), durée/prix par praticien, scan de carte par IA | Étapes suivantes. |

## 3 quater. Offres et praticiens (étape 2, sous-partie 3a) : choix de conception

| Sujet | Choix | Pourquoi |
|---|---|---|
| Offres | Table `plans` créée avec **Starter 29,99 €** et **Business 49,99 €** (hors TVA), lecture seule pour l'application cliente | Décision n° 20. Le prix annuel n'est pas fixé (colonne vide). |
| Limites | Praticiens : Starter 1, Business 5 (plan de tarification). Conversations et rendez-vous : 150/90 et 600/380 | Les volumes sont des **hypothèses** du plan de tarification. |
| Offre d'un essai sans choix | **Business** | **Hypothèse à valider** : l'essai doit permettre de tester le multi-praticiens. |
| Limite de praticiens (E8) | Comptée sur les praticiens **actifs**. Au-delà : créé en **brouillon** avec le message E8 ; activable dès qu'une place se libère ou que l'offre change | Flowchart 1C/5B : « l'élément ajouté reste en brouillon ». |
| Limite de sites | **Non appliquée** | Les sites supplémentaires seront des options payantes (24,99 €/mois) : dépend des abonnements. |
| Planning initial | Copie des horaires d'ouverture du site principal, modifiable | Cas courant, gain de temps. |
| Deux sites | Un praticien ne peut pas être sur deux sites à la même heure (contrainte d'exclusion en base) | Cohérence du planning. |
| Praticien réservable | Actif + au moins un service + au moins une plage de disponibilité | Sans cela, aucun créneau ne peut être proposé. |
| Fin de l'étape | Au moins un praticien réservable | Flowchart 1C. |
| « M'ajouter comme praticien » | Lie le compte du propriétaire (un seul praticien par compte) | Cas du praticien solo (offre Starter). |
| Invitation par lien (3b) | Lien valable **3 jours** (décision du fondateur, les flowcharts ne précisent pas). Une nouvelle invitation invalide l'ancienne (E13) | Flowchart 5B/E13. |
| Sécurité du lien | Jeton de 48 caractères, **jamais stocké** (empreinte SHA-256 seule) ; lecture publique via une fonction SQL étroite ; même écran E13 pour un lien inconnu, expiré, utilisé ou remplacé | Ne rien révéler sur les jetons. |
| Compte du praticien invité | Créé à l'acceptation ; e-mail considéré vérifié (lien reçu) ; **pas de téléphone** ni de vérification SMS ; langue = celle de l'entreprise | Le SMS de vérification n'est demandé qu'à l'inscription du propriétaire. |
| Adresse déjà connue | Une invitation est **refusée** si un compte Nova existe déjà pour l'adresse | **Limite connue** : le rattachement d'un compte existant (praticien dans deux entreprises) n'est pas géré à ce stade. |
| Espace praticien | `/mon-profil` : le praticien règle ses services et son planning ; sa fiche est retrouvée par son compte, jamais par un identifiant | Il ne peut pas toucher aux fiches des autres. |
| Praticien invité et limite du plan | Créé en brouillon si la limite est atteinte, l'invitation part quand même | Cohérent avec E8. |

## 3 quinquies. Politiques de réservation (étape 2, sous-partie 4) : choix de conception

| Paramètre | Défaut | Bornes | Source |
|---|---|---|---|
| Délai minimum avant un rendez-vous | 2 h | 0 à 168 h | Spec §4.5 (« délai minimum ») ; **valeur par défaut = hypothèse** |
| Réservation possible jusqu'à | 60 jours | 1 à 365 | Spec §4.5 (« horizon maximum ») ; défaut = hypothèse |
| Annulation ou report possible jusqu'à | 24 h avant | 0 à 168 h (0 = jusqu'au dernier moment) | Spec §4.5 et flowchart 2D (« fenêtre d'annulation ») ; défaut = hypothèse |
| Frais de rendez-vous manqué | 0 € | 0 à 500 € | Spec §4.5. **Indicatif** : le paiement se fait sur place, aucun prélèvement |
| Surbooking | Non | oui/non | Spec §4.5 |
| Blocage d'un créneau pendant la réservation | 10 min | 5 à 10 min | Spec §6 et flowchart 2D |
| Rappel avant le rendez-vous | 24 h | 0 à 72 h (0 = aucun) | Business plan (rappel 24 h) ; ajouté aux politiques par commodité |

Choix : une ligne de politiques **par organisation** (pas par site), créée à la demande avec ces défauts ; le délai minimum doit rester inférieur à l'horizon (vérifié en base) ; propriétaire uniquement ; l'étape est terminée à l'enregistrement, même sans modification, pour que le propriétaire valide explicitement. Les politiques par site ou par service pourront être ajoutées plus tard.

## 3 sexies. Moteur de disponibilité et cœur de réservation (étape 3a) : choix de conception

| Sujet | Choix | Pourquoi / à valider |
|---|---|---|
| Écrivain unique | `BookingCore` est le seul code qui écrit `bookings` ; dashboard, bot, commandes WhatsApp du personnel et API l'appellent | Règle non négociable n° 2 |
| Double réservation | Contrainte d'exclusion PostgreSQL par praticien sur `[blocked_from, blocked_until[` (statuts `pending`, `confirmed`) ; conflit converti en erreur E10 avec les 3 prochains créneaux | Sûr même en cas de requêtes simultanées. Deux rendez-vous bout à bout sont acceptés |
| Marges de service | Le créneau bloqué = rendez-vous élargi des marges avant/après du service | Spec §4.3 |
| Capacité > 1 et surbooking | **Non effectifs en v1** : un praticien = un rendez-vous à la fois. L'option de surbooking est enregistrée mais sans effet | Cours collectifs et surbooking exigent un autre modèle (à arbitrer avec le fondateur avant de les activer) |
| Créneaux proposés | Grille de 15 min (`nova.booking.slot_step_minutes`) ; jamais dans le passé, même pour le personnel | Hypothèse : pas de 15 min |
| Disponibilité exceptionnelle | Ajoute du temps au praticien mais reste bornée par l'ouverture du site | Hypothèse prudente ; un site fermé ne reçoit pas de rendez-vous |
| Exceptions du site | Fermeture (jour férié) ou plages qui **remplacent** les horaires du jour | Docs/01 §4.4 |
| Personnel vs client | Le personnel ignore délai minimum et horizon, pas les horaires ni la contrainte anti double réservation ; le client respecte la fenêtre d'annulation, le commerce peut toujours annuler | Flowcharts 2B/2D |
| Blocage temporaire | Réservation `pending` avec échéance (10 min par défaut) ; un blocage expiré est ignoré par le calcul et libéré (statut `expired`) avant toute nouvelle insertion et par `bookings:release-holds` chaque minute | Spec §6 |
| Heure d'été/hiver | Calculs en UTC, conversion des heures locales du site par fuseau IANA ; testé pour le 29 mars et le 25 octobre 2026 (Europe/Brussels) | — |
| Idempotence | `idempotency_key` unique par organisation : un rejeu de webhook renvoie la même réservation | Pour n8n / Meta |
| Notes | Chiffrées au repos ; le journal ne garde que leur longueur | RGPD (données de santé possibles) |
| Journal `booking_events` | Ajout seul (droits retirés + triggers) | **Point à traiter** : la suppression RGPD d'une organisation devra passer par un chemin privilégié dédié |
| Cache Redis des créneaux | Reporté : le calcul direct suffit à ce stade | À mesurer avant d'optimiser |
## 3 septies. Planning et fiches clients du dashboard (étape 3b) : choix de conception

| Sujet | Choix | Pourquoi / à valider |
|---|---|---|
| Planning | Vue « jour » : une colonne par praticien actif ayant un planning sur le site, plages de travail en clair, rendez-vous cliquables (aucun glisser-déposer en v1) | Simple, accessible au clavier ; vues semaine/mois à ajouter selon vos retours |
| Droits (docs/02 §1) | Propriétaire : tout. Responsable de site et réceptionniste : leurs sites. Praticien : ses propres rendez-vous et clients | **Hypothèse** : un responsable ou réceptionniste sans site attribué voit tous les sites (l'attribution des sites arrivera avec la gestion d'équipe) |
| Notes de rendez-vous | Masquées aux réceptionnistes (docs/02 : « sans notes santé ») | Les notes sont chiffrées au repos |
| Saisie par le personnel | Ignore délai minimum et horizon ; respecte horaires, congés et anti double réservation ; jamais de créneau proposé dans le passé | Flowchart 2B |
| Créneau pris (E10) | Message clair + 3 créneaux libres cliquables qui préremplissent le formulaire | Flowchart 2B |
| Client saisi par téléphone | Numéro national accepté pour BE, FR, NL, LU, DE, GB (comme à l'inscription), converti en E.164 ; client retrouvé ou créé | — |
| Fiche client | Nom, e-mail, langue modifiables ; **le numéro n'est pas modifiable** (il identifie le client dans WhatsApp) ; historique limité aux 50 derniers rendez-vous | — |
| Blocage d'un client | Journalisé (`customer.blocked` / `customer.unblocked`) ; empêche toute nouvelle réservation | Le journal d'une modification de fiche ne contient que les noms des champs, jamais leurs valeurs |
| Hors périmètre de 3b | Suppression / anonymisation d'un client (pipeline RGPD), notes libres sur la fiche client, fusion de doublons, vue semaine | À planifier |
## 3 octies. API interne pour n8n (étape 4a) : choix de conception

Détail des points d'accès : `docs/05-api-interne.md`.

| Sujet | Choix | Pourquoi / à valider |
|---|---|---|
| Authentification | Clé publique + signature HMAC-SHA256 du corps, du chemin et de l'horodatage (tolérance 5 min), périmètres par client | Docs/02 §5 |
| Secret | Chiffré au repos (il doit rester lisible pour vérifier la signature), affiché une seule fois | Un simple hachage rendrait le HMAC impossible |
| Isolation | Recherche de la clé par fonction SECURITY DEFINER étroite, puis contexte d'organisation posé pour la requête et vidé ensuite : la RLS s'applique comme au dashboard | Règle n° 1 |
| Réponses d'échec | Identiques pour clé inconnue, révoquée ou signature fausse | Pas d'oracle |
| Écritures | Uniquement par `BookingCore` ; acteur « client » (fenêtre d'annulation appliquée) ou « établissement » | Règle n° 2 |
| Idempotence | En-tête obligatoire ; clé préfixée par le client d'API ; demande différente = 409 | Rejeu de webhooks |
| Émission des clés | Commande en ligne pour l'instant (`api-clients:create`) ; écran propriétaire à venir | **Émission en production : à valider par vous** |
| Compte suspendu / en pause | Lecture seule / 503, comme le dashboard | Docs/02 §4 |
## 3 nonies. Passerelle WhatsApp (étape 4b) : choix de conception

Détail : `docs/06-passerelle-whatsapp.md`.

| Sujet | Choix | Pourquoi / à valider |
|---|---|---|
| Numéro | Un numéro WhatsApp Business par établissement, jeton chiffré en base | Meta Cloud API en direct (décision du fondateur) |
| Simulation | Pilote `fake` par défaut : aucun appel réseau en développement et en test | Les identifiants Meta réels restent à fournir par vous |
| Texte des messages | Chiffré au repos ; ne transite pas par la file Redis | RGPD (données parfois de santé) |
| STOP / START | Mots exacts, tracés, confirmation dans la langue du client | **Textes et mots-clés à valider** |
| Client désabonné qui écrit | Aucune réponse automatique tant qu'il n'a pas dit START | **Hypothèse prudente** : à confirmer |
| Fenêtre de 24 h | Réponse libre seulement dans la fenêtre ; hors fenêtre, modèle Meta obligatoire | Règle de Meta |
| Rappels (étape 4c) | Modèle Meta à 4 variables, délai des politiques (24 h par défaut), un seul rappel, aucun si le rendez-vous est pris dans le délai, 3 tentatives, jamais après STOP | **Modèles à créer et faire approuver par Meta (fr/nl/en)** ; le texte exact est à valider par vous |
| Conservation | Illimitée pour l'instant | **Durée à décider avec votre juriste**, purge à développer |
## 3 decies. Super Admin : actions sensibles (étape 5a)

Détail : `docs/07-super-admin.md`.

| Sujet | Choix | Pourquoi / à valider |
|---|---|---|
| Re-authentification | **Supprimée** (décision du fondateur, 2026-10-07) : mot de passe et 2FA à la connexion seulement ; motif, IP et agent journalisés | Docs/02 §3.3 |
| « Bloquer » un compte | **Fusionné avec « suspendre »** : le schéma n'a pas d'état « bloqué » distinct | **À confirmer** : voulez-vous un état séparé (fraude) ? |
| Levée d'une suspension | Retour au statut d'avant ; un essai échu devient « actif » | Hypothèse ; la suite relève de la facturation |
| Délai de suppression | 30 jours, dans `platform_settings` (modifiable) | Décision Q7 |
| Pause 90 j / suspension 60 j | Enregistrées dans `platform_settings` mais **sans effet automatique** | **Règle de fin de période à décider** (que se passe-t-il au 61e jour ?) |
| Suppression réelle et immédiate | Non incluses : pipeline RGPD à construire | Étape suivante |
| E-mail au propriétaire | Envoyé à chaque action, dans sa langue, avec le motif pour une suspension | Le motif saisi est visible du propriétaire : à valider |
| Droits du rôle plateforme | Lecture transverse des tables métier au niveau base, mais aucune écriture et pas d'accès aux utilisateurs ; le panel n'expose que les niveaux 1 et 2 | Test automatisé |
## 3 undecies. Calendriers externes (étape 6)

Détail : `docs/08-calendriers-externes.md`.

| Sujet | Choix | Pourquoi / à valider |
|---|---|---|
| Formats | ICS (abonnement) et CalDAV, lecture seule | Décisions Q13 et Q16 |
| Doctena, Rosa | Aucun contact : lien ICS de leur outil seulement | Décision Q17 |
| Contenu conservé | Horaires uniquement | Minimisation RGPD |
| Bibliothèque iCalendar | **Lecteur interne**, sans dépendance | Le téléchargement de `sabre/vobject` a échoué (le réseau du conteneur ne fait pas confiance au certificat de Packagist) ; non contourné par sécurité. Règles rares non prises en charge (voir doc) |
| Échec de synchronisation | Dernières plages conservées, erreur après 5 échecs | Prudence : ne jamais libérer un créneau par erreur |
| Fréquence | 15 minutes | **Hypothèse à valider** |
| Fiches clients « au choix par calendrier » (Q14) | **Non implémenté** | Les événements contiennent rarement un numéro de téléphone exploitable ; **à préciser** : que doit-on importer, et à quelle condition ? |
| Événements « libres » et journées entières | Suivent le champ TRANSP (une journée entière « libre » ne bloque pas) | Évite qu'un anniversaire bloque tout un jour ; à valider |
| Flux ICS sortant | Horaire + nom du service, sans donnée de client | Docs/02 (jeton) |
## 3 duodecies. Pipeline RGPD (étape 7)

Détail : `docs/09-rgpd-suppression-et-export.md`.

| Sujet | Choix | Pourquoi / à valider |
|---|---|---|
| Délai | 30 jours (`platform_settings`), immédiate seulement pour fraude ou abus | Décision Q7 |
| Ce qui reste | Ligne d'organisation pseudonymisée + actions du Super Admin + suivi | Preuve de la suppression ; **durée de conservation de cette preuve à valider** |
| Journaux du client | Effacés (IP, agents) | Données personnelles ; **à valider avec votre juriste** |
| Annulation par le propriétaire | Possible jusqu'au début de l'effacement | Après, la suppression est irréversible |
| Annuler une suppression d'un compte suspendu | Le compte reste suspendu | Sécurité : éviter de contourner une suspension |
| Confirmation finale | E-mail au propriétaire, puis adresse oubliée | Minimisation |
| Échec d'une étape | 3 essais, alerte fondateur (E12), relance manuelle | `NOVA_FOUNDER_EMAIL` **à renseigner** en production |
| Effacement d'un client final | Anonymisation (numéro fictif +999…), refusé s'il reste un rendez-vous à venir ; **débloque aussi le client** | À valider : faut-il garder un client abusif bloqué ? (intérêt légitime vs droit à l'effacement) |
| Conservation automatique | **Non faite** (durées non décidées) | Ex. clients inactifs, conversations : à décider |
| Prestataires externes | Non couverts (Meta, e-mail) | Suppression du numéro dans la console Meta à faire à la main |
| Sauvegardes | Non traitées | À fixer avec l'hébergeur (durée d'expiration) |
## 3 terdecies. Facturation (étape 8)

Détail : `docs/10-facturation.md`.

| Sujet | Choix | Pourquoi / à valider |
|---|---|---|
| Prestataire de paiement | Interface `PaymentGateway`, prestataire **simulé** | **Choix du fondateur toujours ouvert** ; aucun débit réel possible |
| Pilotage | Nova facture et débite un jeton enregistré | Indépendant du prestataire ; aucune donnée de carte chez Nova |
| Durée de l'essai | 14 jours | **Hypothèse à valider** (`platform_settings`) |
| Fin d'essai sans abonnement | Compte suspendu (flowchart 4A) | Données conservées |
| Relances | 3 tentatives, une tous les 3 jours, puis suspension | **Hypothèse** (flowchart E7 sans chiffres) |
| Fréquence | Mensuelle uniquement | Remise annuelle non arrêtée |
| Montée / descente | Montée immédiate au prorata, descente à l'échéance | Flowchart 3C ; **prorata à valider** |
| Annulation | Fin de période payée, puis suspension | **À valider** : suspension ou suppression programmée ? |
| TVA | 21 % BE ; autoliquidation UE avec n° de TVA ; sinon 21 % | **À faire valider par un comptable**, VIES à ajouter |
| Identité du vendeur | Variables d'environnement, **non renseignées** | **Raison sociale, TVA et adresse de Nova à fournir** avant toute facture réelle |
| Suspension par la plateforme | Jamais levée par un paiement | Sécurité |
| Conservation | Factures pseudonymisées 7 ans | Obligation comptable ; durée à confirmer |

## 3 quaterdecies. Décisions du fondateur suite à la relecture (docs/11)

Numérotation de la partie 3 de `docs/11`.

| N° | Sujet | Décision |
|---|---|---|
| 5 | Essai et relances | **Validé** : essai 14 jours ; 3 tentatives espacées de 3 jours |
| 7 | Fins de pause et de suspension, « bloquer » / « suspendre », motif dans l'e-mail | **Validé** (règles du flowchart : pause 90 jours, suspension 60 jours) |
| 8 | Nom de la formule d'entrée | **Basic** (code `basic`, ancien « Starter » renommé, migration `2026_10_16_000002`) |
| 10 | Effacement d'un client bloqué et du journal d'audit côté client | **Validé** (proposition de `docs/09`) |
| 12 | Confirmation de la suppression de compte | **Validé** : saisie de « SUPPRIMER » + lien e-mail valable 24 h (à implémenter, écart avec l'état actuel) |
| 14 | Modèles Meta et déconnexion du numéro | **Validé** (à implémenter avec l'étape WhatsApp) |
| 15 | Calendriers : 15 min, 5 par praticien, journées entières / `TRANSP` | **Validé** (état actuel) |
| 17 | Rappels : minimum 24 h, 3 tentatives | **Validé** (état actuel) |
| 18 | Mots de passe et 2FA des clients | **Validé**, voir ci-dessous |
| 1, 2, 3, 4, 6, 11, 13, 16 | Paiement, SMS, vendeur/TVA, e-mail fondateur, prorata et fin d'abonnement, rôle de Nova, STOP/START, sites et capacité | **À revoir plus tard** |
| 9 | Durées de conservation | **Validé** (propositions de docs/09 : conversations, preuves d'audit, factures 7 ans, clients inactifs) ; la purge automatique reste à construire |

### Pause : fin de période et rendez-vous (décisions du fondateur)
- Fin de pause : **suspension** du compte puis proposition de suppression (docs/13).
- Pause avec rendez-vous à venir : le propriétaire peut **annuler et prévenir les clients** par WhatsApp (modèle appointment_cancelled, textes à valider).
- Suspension de fin de pause qui dure : **suppression programmée automatiquement** après 60 jours (rappel 7 jours avant).
- Tableau de bord : « résumé du jour » (docs/14) ; notifications WhatsApp des changements faits depuis le planning (case « prévenir le client », docs/15) ; textes des trois modèles Meta **validés comme base** (soumission à Meta à faire).

### WhatsApp : décisions du fondateur
- **Embedded Signup** : démarches Meta à lancer par le fondateur ; le code côté Nova est prêt derrière la configuration (META_APP_ID, META_EMBEDDED_SIGNUP_CONFIG_ID, WHATSAPP_APP_SECRET), docs/17.
- **SDK JavaScript de Meta** : validé (point RGPD : Meta reçoit l'adresse IP) ; chargé uniquement au clic, avec avertissement, et autorisé par la politique de contenu seulement sur les pages de connexion WhatsApp.
- **Facturation des conversations WhatsApp** : **Nova refacture** ; mesure mensuelle par catégorie et pays en place (whatsapp_usage) ; **prix refacturé et lignes de facture à décider** (docs/16).

### Politique de mot de passe (décision 18) : implémentée
- 12 caractères minimum, avec majuscule, minuscule, chiffre et caractère spécial (`StrongPassword`, appliqué à l'inscription, à l'invitation et à la réinitialisation).
- Refus des mots de passe évidents : liste de mots courants, même décorés (`P@ssw0rd2026`), partie locale de l'e-mail.
- Pas de réutilisation des 5 derniers (table `password_histories`, empreintes seules ; le mot de passe actuel compte parmi les 5).
- `users.password_changed_at` mémorise le dernier changement (base du rappel « tous les 12 mois », à afficher avec l'écran Sécurité).
- Non fait : nom de l'entreprise refusé à l'inscription (il n'est pas encore connu à ce moment), changement forcé en cas de suspicion de compromission, écran de changement de mot de passe.

### 2FA des clients (décision 18) : implémentée (docs/12-securite-du-compte.md)
TOTP + codes de secours, **obligatoire sans délai de grâce** pour le propriétaire et les responsables de site (« administrateur » = ces deux rôles, validé). Non fait : clés FIDO2/WebAuthn, code par SMS.
Perte de l'appareil **et** des codes de secours : **déblocage d'accès par le support Nova après vérification d'identité** (validé) ; action « Débloquer l'accès » du Super Admin, implémentée.

## 4. Points de vigilance connus

- **Rôle propriétaire de la base en production** : il doit avoir `BYPASSRLS` (utilisé par la fonction `auth_memberships`) mais **jamais** servir à l'application. En développement il est superuser.
- **Inscription** : le message E1 (« e-mail déjà associé à un compte ») révèle qu'un compte existe. C'est le comportement voulu par le flowchart 5A ; il est limité par débit. À arbitrer si vous préférez une réponse neutre.
- **E-mail dans l'URL de réinitialisation** : le lien de réinitialisation contient l'adresse e-mail en paramètre (usage standard de Laravel). Alternative possible : jeton seul, e-mail ressaisi.
- **Validation juridique** : textes des CGU, de la confidentialité et du DPA ; durées de rétention ; rôle sous-traitant / responsable de traitement.
- **Assistant de conversation natif (proposition, docs/18)** : reprend le flux n8n « MASTER ROUTER V2 » dans Nova (`CONVERSATION_DRIVER=native`). Il **déplace la décision n° 1** (le moteur WhatsApp reste dans n8n) : n8n reste le pilote par défaut tant que le fondateur n'a pas validé. Textes nl/en, modèle de langage (RGPD) et comportements inventés listés dans docs/18.

## 5. Fuseau horaire unique du prestataire (décision du fondateur, 2026-10-16)

- **Référence unique** : `organizations.timezone`, choisi à l'inscription parmi les fuseaux du **pays** sélectionné (`TimezoneCatalog`, base IANA ; un pays à plusieurs fuseaux, comme la France et l'outre-mer, laisse choisir). Tous les sites (`locations.timezone`) le suivent : `CompanyUpdater` les aligne à chaque enregistrement, la migration `2026_11_19_000001` a aligné l'existant.
- **Stockage** : toutes les heures en UTC (`bookings.starts_at`, `ends_at`, etc.). Aucun rendez-vous n'est modifié par la migration : seul l'affichage local des sites qui s'écartaient de leur entreprise change.
- **Lecture / affichage** : agenda, créneaux, messages WhatsApp, calendriers et interface Super Admin (pages d'un client) utilisent ce fuseau. Le client final ne choisit pas de fuseau : l'heure est celle du prestataire (annule le dispositif « fuseau du visiteur » de `feat/timezone-display`).
- **Changement d'heure** : une heure locale inexistante (saut du printemps) est refusée (`LocalTime::exists`) ; une heure ambiguë (retour d'hiver) retient la première occurrence.
- **À savoir** : changer le fuseau d'un compte qui a déjà des rendez-vous décale leur heure locale affichée (les instants UTC ne bougent pas). Les horaires d'ouverture, enregistrés en heure locale, sont relus dans le nouveau fuseau.
