# 27. Programme de fidélité configurable

Chaque établissement configure son propre programme : « après **N** visites éligibles terminées, le client reçoit **une** récompense ». Module `app/Modules/Loyalty`, écrans sous **Clients → Fidélité** (`/clients/fidelite`). Ce document est la spécification de ce qui est construit ; les décisions qui sortent du cadre du brief sont signalées « **Décision** ».

## 0. Principes

- **Progression, récompense, utilisation et marketing sont quatre choses distinctes** : un *tampon* est gagné par une visite ; une *récompense* est attribuée quand le solde atteint N ; son *utilisation* est un acte du personnel sur un rendez-vous ; un *message marketing* n'est jamais déduit de l'inscription.
- **Rien n'est silencieux** : toute modification de règle touchant des clients qui ont déjà progressé est d'abord présentée (effet chiffré), jamais remise à zéro ni retirée en silence.
- **Aucun chiffre existant ne bouge** : utiliser une récompense n'écrit ni le prix ni l'encaissé du rendez-vous (même logique que les pourboires, docs/24). L'écran indique le montant à encaisser ; le paiement se saisit comme d'habitude.
- **Le cœur de réservation reste seul à écrire les réservations** : la fidélité *lit* les rendez-vous terminés (appel après transaction dans `BookingCore`), ne les modifie jamais.
- Textes fr / nl / en partout (`lang/*/ui.php` groupe `loyalty`, `lang/*/loyalty.php` pour les messages WhatsApp). Aucun prix ni durée en dur.

## 1. Configuration du programme (propriétaire)

Écran **Programme** (`/clients/fidelite/programme`) : cinq étapes courtes, résumé de la règle toujours visible à droite, valeurs par défaut (8 visites, tous les services, service offert, à partir de la visite suivante, non cumulable, sur rendez-vous, sans expiration, inscription automatique).

| Étape | Champs |
|---|---|
| 1 Programme | Nom (par langue fr/nl/en, 80 car.) |
| 2 Gagner | Nombre de visites (1–100) ; tous les services ou certains ; montant minimum (facultatif) ; paiement exigé (oui/non) ; inscription automatique (oui/non) |
| 3 Récompense | Type (service offert, pourcentage, montant fixe, personnalisée) ; valeur ; plafond (facultatif) ; description (par langue, 200 car.) ; services visés (tous / certains) |
| 4 Utilisation | Moment (à la visite suivante / dès qu'elle est gagnée) ; cumulable ; sur rendez-vous (sinon utilisable au comptoir) ; expiration (jours, rappel en jours) ; conditions (par langue, 2000 car.) |
| 5 Aperçu | Liste de ce qui manque, aperçu **côté client** dans chaque langue, activation |

- **Statuts** : Brouillon → Actif ⇄ En pause → Archivé. Un seul programme non archivé par établissement (index unique en base).
- **On n'active qu'un programme valide** : nom, récompense et conditions renseignés (au moins en français), nombre de visites 1–100, valeur de réduction cohérente, services choisis si « certains ». Le brouillon incomplet s'enregistre ; l'activation est refusée avec la liste de ce qui manque (écran et serveur).
- **Langues** : une langue vide affiche le texte français (puis la première langue renseignée) ; l'écran signale les langues incomplètes.
- **Montants** : saisis en euros (« 12,50 »), stockés en centimes.

### Règles de cycle de vie (§7 du brief)

| Situation | Comportement |
|---|---|
| Modifier le programme après des tampons | Texte seul : appliqué directement. Changement **matériel** (visites, services qui comptent, minimum, paiement, inscription, récompense, utilisation, expiration) : écran « Avant d'appliquer ce changement » (clients inscrits, en cours, récompenses déjà obtenues, effet), puis confirmation. |
| **Baisser** le nombre de visites | Les clients qui ont déjà assez de tampons reçoivent leur récompense **tout de suite** (programme actif) ; surplus conservé. |
| **Augmenter** le nombre de visites | Tampons conservés, la cible s'éloigne ; aucune récompense obtenue n'est retirée. |
| Changer ou remplacer la récompense | Chaque récompense obtenue garde un **instantané** (type, valeur, plafond, description et conditions par langue, services, règles d'utilisation, expiration) : elle reste ce qui a été promis. Seules les récompenses futures suivent la nouvelle règle. |
| **Pause** | Plus aucun tampon ; tampons et récompenses déjà obtenus conservés ; les récompenses restent utilisables. À la réactivation, les récompenses dues (solde ≥ N) sont attribuées. |
| **Archivage** | Plus de tampon, plus d'inscription ; récompenses obtenues utilisables jusqu'à leur expiration. Un nouveau programme **repart de zéro** ; l'ancien reste consultable (rapports, historique). |
| Annulation, absent, non terminé | Aucun tampon (seul un rendez-vous **terminé** en donne). |
| Remboursement / correction | Si le paiement est exigé et qu'il est ensuite annulé (non payé, perte, partiel, offert) : le tampon est retiré (une fois). Si ce tampon avait fait gagner une récompense **non utilisée**, elle est annulée et les tampons rendus ; si elle est **déjà utilisée**, le solde est ramené à zéro et le manque est inscrit au journal (« à vérifier »). Un rendez-vous dont le tampon a été retiré ne le regagne pas (l'équipe peut en ajouter un à la main, avec motif). Autre correction : ajustement manuel. |
| Fiches clients en double / fusionnées | Il n'existe pas encore de fonction de fusion dans Rendivoo. `LoyaltyEngine::mergeCustomers()` est prête : solde, récompenses disponibles et dernière visite passent sur la fiche conservée, la fiche absorbée sort du programme, deux lignes « ajustement » expliquent le transfert (le journal n'est jamais réécrit). |
| Multi-sites, services différents selon le site | Le programme est **commun à l'établissement** : un tampon se gagne sur n'importe quel site ; la règle de services porte sur le service, pas sur le site. Les rapports se filtrent par site et par service. |
| Client effacé (RGPD) | Il quitte le programme, ses récompenses disponibles sont annulées ; le journal ne contient que l'identifiant. |
| Expiration | Tâche quotidienne `loyalty:maintain` (06:00) : passe en « expirée » les récompenses échues (les tampons ne sont pas touchés) ; un rappel facultatif part une fois, `expiry_reminder_days` avant. Une utilisation refusée dès l'échéance, même avant le passage de la tâche. |

## 2. Gagner des tampons (cœur : `LoyaltyEngine`)

- **Quand** : quand un rendez-vous passe à *Terminé* (`BookingCore::markCompleted`, après la transaction) et que le programme est **actif**. Jamais pour annulé / absent / en attente / expiré.
- **Éligibilité** : service compté (tous ou liste), montant minimum (prix du rendez-vous), et — si l'établissement l'exige — statut de paiement **« payé sur place »** (« offert » ne vaut pas paiement). Le paiement saisi plus tard peut déclencher le gain (`BookingCore::recordPayment`).
- **Pas de doublon** : verrou de la ligne du client + **index unique (programme, rendez-vous) pour le gain et pour l'annulation** : un rejeu d'événement, deux onglets ou deux processus ne donnent jamais deux tampons.
- **Inscription** : automatique à la première visite éligible (réglable) ; sinon seuls les clients inscrits par le personnel avancent. Un client qui a quitté le programme n'avance plus.
- **Récompense** : atteindre N attribue une récompense et retire N du solde (surplus conservé, plusieurs récompenses possibles d'un coup).
- **Solde = somme du journal** : `loyalty_members.stamps` est un cache toujours égal à la somme de `loyalty_ledger.stamps_delta` (testé).
- **Ajustement manuel** (propriétaire, responsable de site) : ± 1 à 50 tampons, **motif obligatoire**, solde jamais négatif ; journal + audit ; peut attribuer une récompense.
- Un incident de fidélité **ne bloque jamais** un rendez-vous (exception rapportée, rendez-vous inchangé).

## 3. Côté client

Le client final n'a pas d'écran Rendivoo ; il rencontre le programme **par WhatsApp** et à l'accueil.

- **Carte de fidélité** (aperçu à l'étape 5, mêmes textes que l'envoi) : nom du programme, rangée de tampons (jusqu'à 12 ; barre au-delà), « 6 sur 8 visites », « Encore 2 visites éligibles avant votre récompense », récompense, conditions, expiration, usage des données, mention que le programme n'inscrit pas au marketing. Tampon obtenu : plein avec une coche ; à venir : vide ; l'ensemble se lit comme une seule image (« Progression : 6 sur 8 »).
- **Bot WhatsApp (n8n)** : `GET /api/v1/loyalty?customer_phone=+32…` (périmètre `loyalty:read`) renvoie, dans la langue du client, programme, tampons, visites restantes, récompense, conditions et récompenses disponibles avec expiration ; `enrolled: false` sans autre détail pour un client inconnu, non inscrit, parti ou un programme brouillon. Lecture seule, par numéro.
- **Données** : seules les visites terminées et leur service servent au programme ; pas de donnée supplémentaire collectée. Le client peut demander à quitter le programme (le personnel l'enregistre, avec ou sans motif) : tampons et récompenses conservés, il n'avance plus et ne reçoit plus de messages de fidélité. La participation **ne vaut jamais consentement marketing** (statut de consentement indépendant, aucune écriture dans `customer_consents`).

## 4. Messages WhatsApp (propriétaire)

Écran **Messages WhatsApp** : cinq messages, **tous désactivés au départ** — bienvenue, progression, récompense obtenue, récompense utilisée, rappel d'expiration.

- Texte par langue, variables `{name} {business} {program} {progress} {stamps} {required} {remaining} {reward} {expiry_date}` (boutons d'insertion), **aperçu en direct** avec un client fictif, texte par défaut proposé par Rendivoo. Une variable inconnue est refusée (écran et serveur) : on n'envoie jamais « {nom} » tel quel.
- **Consentement** : tous ces messages sont promotionnels par nature ; ils ne partent que si le client a donné son **accord marketing WhatsApp** (`ConsentRecorder::canSendMarketing`), n'est ni bloqué, ni archivé, ni parti du programme, et n'a pas répondu STOP. Sinon rien n'est envoyé, sans erreur.
- **Fenêtre de service de 24 h** : ouverte, le texte de l'établissement est envoyé tel quel ; fermée, WhatsApp impose un modèle approuvé : Rendivoo envoie son modèle standard (mêmes informations, texte fixe, catégorie *marketing*). Au mieux : un refus ou une panne n'empêche jamais le tampon ni l'utilisation ; chaque envoi réussi est journalisé (`message_sent`).
- **À faire avant la production (fondateur)** : créer et faire approuver chez Meta, en catégorie marketing, les 5 modèles × 3 langues (noms dans `config/whatsapp.php` → `loyalty_templates`, textes proposés dans `lang/*/loyalty.php` → `templates`, variables `{{1}}` établissement, `{{2}}` prénom, `{{3}}` progression, `{{4}}` récompense, `{{5}}` expiration). Ils ne sont **pas** ajoutés aux modèles soumis automatiquement : ils ne concernent que les établissements qui activent leurs messages. Sans approbation, seul l'envoi dans la fenêtre de 24 h fonctionne.

## 5. Tableau de bord, liste des clients, fiche client, rendez-vous

- **Tableau de bord** : statut et résumé de la règle ; clients inscrits, en cours, récompenses obtenues / utilisées / à utiliser / expirées ; clients proches de leur récompense (≤ 2 visites) ; activité récente ; évolution sur 90 jours (un message au lieu d'un graphique s'il y a moins de 2 points). États vides : « Votre programme de fidélité n'est pas encore configuré », « Aucun client n'a encore de progression ».
- **Clients du programme** : nom, progression, récompenses disponibles, dernière visite éligible, statut — *Non inscrit, En cours, Récompense disponible, Utilisée, Inactif* — recherche (nom, téléphone), filtre par statut, pagination. Inactif = a quitté le programme, ou aucune visite éligible depuis 180 jours sans récompense à utiliser.
- **Fiche client** (tous rôles qui voient ce client) : progression, récompenses (état, expiration, conditions), historique, actions permises.
- **Détail d'un rendez-vous** (planning) : « 6 sur 8 visites », « Ce rendez-vous a donné un tampon », « Récompense appliquée : 25,00 € (le prix et le paiement ne changent pas) », récompenses disponibles.

## 6. Utiliser une récompense (personnel)

`/clients/fidelite/utiliser` : 1. retrouver le client (nom ou téléphone) et **vérifier son identité** ; 2. voir la récompense et ses conditions, choisir si le client en a plusieurs ; 3. choisir le **rendez-vous** (confirmé ou terminé, récent ou à venir) ; confirmer ; succès avec montant à encaisser.

- **Contrôles (écran grisé + serveur)** : récompense disponible et non expirée ; rendez-vous du même client, confirmé ou terminé ; service visé ; « à la visite suivante » → pas le rendez-vous qui l'a fait gagner ; pas déjà une récompense sur ce rendez-vous (index unique) ; « autre offre » refusée si non cumulable ; rendez-vous obligatoire selon le programme (sinon utilisation au comptoir).
- **Une seule fois** : verrou de la ligne + contrôle d'état ; une seconde tentative reçoit « n'est plus disponible ».
- **Valeur** : service offert (plafonné), pourcentage (plafonné), montant fixe (jamais plus que le prix), personnalisée (aucune valeur chiffrée) ; montant à encaisser = prix − valeur, **indicatif** : le prix du rendez-vous et l'encaissé ne changent pas.
- **Annulation d'une utilisation par erreur** (propriétaire, responsable de site) : **motif obligatoire**, la récompense redevient disponible (expirée si son délai est passé entre-temps) ; journal + audit.

## 7. Rapport

`/clients/fidelite/rapport`, filtres **dates, service, site, programme** (anciens programmes archivés compris). Chiffres : inscrits, tampons gagnés / retirés / nets, récompenses obtenues / utilisées / en attente, taux d'utilisation (avec sa base), évolution (jour, semaine ou mois), retour des clients. Définitions affichées sous chaque chiffre.

- **Pas de causalité** : le « retour » compare, sur la période, la part de clients inscrits et de non inscrits ayant au moins deux rendez-vous terminés ; il est présenté comme **descriptif** (les clients inscrits sont souvent ceux qui reviennent déjà le plus). Nous ne calculons aucun chiffre d'affaires ou rétention « dû » à la fidélité.
- Avec un filtre service/site : les ajustements manuels (sans rendez-vous) et le nombre d'inscrits ne sont pas filtrés (indiqué à l'écran).
- **Décision** : le rapport vit dans l'espace Fidélité (écran, tableau accessible, définitions) ; il n'est pas encore branché sur l'export CSV/Excel ni l'impression du module Rapports (docs/23).

## 8. Droits, journal, vie privée

| Action | Propriétaire | Resp. de site | Réceptionniste | Praticien |
|---|---|---|---|---|
| Configurer le programme et les messages | ✅ | ❌ | ❌ | ❌ |
| Tableau de bord, liste, rapport | ✅ | ✅ | ✅ | ❌ |
| Utiliser une récompense | ✅ | ✅ | ✅ | ❌ |
| Ajuster des tampons, inscrire, faire quitter, annuler une utilisation (motif) | ✅ | ✅ | ❌ | ❌ |
| Voir la progression sur la fiche / le rendez-vous | ✅ | ✅ | ✅ | 🔸 ses clients, sans action |

- **Journal** `loyalty_ledger` en **ajout seul** (aucun UPDATE/DELETE, déclencheurs + droits retirés) : tampon gagné / retiré, récompense obtenue / annulée / utilisée / expirée, utilisation annulée, ajustement, inscription / départ / retour, changement de configuration (avant → après), changement de statut, message envoyé. Chaque ligne : qui (ou « automatique »), quand, motif. S'y ajoutent `audit_logs` (`loyalty.*`) pour les actions du personnel.
- **Isolation** : toutes les tables sont sous RLS par établissement ; une récompense ou un client d'un autre établissement est introuvable (404).
- **Export / suppression** : `ErasureSteps::ALL` et `gdpr_purge_org` couvrent les cinq tables ; l'effacement d'un client le fait quitter le programme.
- **Collecte minimale** : identifiant du client, visites éligibles, service ; aucune donnée supplémentaire.

## 9. Modèle de données (migration `2026_11_08_000001`)

| Table | Rôle |
|---|---|
| `loyalty_programs` | Un par établissement non archivé : statut, textes JSON par langue (nom, récompense, conditions), `required_visits`, `services_mode`, `min_amount_cents`, `require_payment`, `auto_enroll`, `reward_type/value/max_cents`, `reward_services_mode`, `redeem_timing`, `combinable`, `requires_appointment`, `expiry_days`, `expiry_reminder_days`, `messages` JSON, dates d'activation / pause / archivage. |
| `loyalty_program_services` | Services qui comptent (`earn`) ou visés par la récompense (`reward`). |
| `loyalty_members` | (programme, client) unique : statut `active / opted_out`, `stamps` (cache = somme du journal), `lifetime_stamps`, `rewards_earned`, `last_visit_at`, `joined_via`. |
| `loyalty_rewards` | Récompenses : statut `available / redeemed / expired / void`, **instantané** JSON, `earned_booking_id`, `expires_at`, `redeemed_at / by / booking`, `applied_value_cents`. Index unique : une récompense utilisée par rendez-vous. |
| `loyalty_ledger` | Journal en ajout seul. Index uniques : un `stamp_earned` et un `stamp_reversed` par (programme, rendez-vous). |

Contrôles en base : statuts, plages (visites 1–100, expiration 1–730, rappel 1–90), un seul programme non archivé par établissement.

## 10. États vides, chargement, validation, erreurs

- **Vides** : programme non configuré, aucune activité, aucun client avec progression, aucune récompense à utiliser, aucun client ne correspond, aucun résultat de recherche, pas assez de données pour une évolution.
- **Validation** : messages clairs par champ (visites, pourcentage, montant, jours, longueurs), motif obligatoire (≥ 3 caractères), solde jamais négatif ; le texte saisi est conservé en cas d'erreur.
- **Refus d'utilisation** : un message précis par cas (expirée, déjà utilisée, rendez-vous d'un autre client, service non visé, même visite, non cumulable, déjà une récompense sur ce rendez-vous, rendez-vous requis).
- **Accessibilité** : navigation clavier, étiquettes liées, tampons lus comme une image unique avec libellé, boutons d'état avec icône + libellé (jamais la couleur seule), évolution doublée d'un tableau, contrastes du design system ; responsive (tableaux défilants, cartes en une colonne sur mobile).

## 11. Hors périmètre / suite possible

Export CSV du rapport de fidélité, branchement sur l'impression des rapports, fonction de fusion de fiches clients (le transfert de fidélité est prêt), soumission automatique des modèles WhatsApp de fidélité, écran client en libre-service (aujourd'hui : WhatsApp et accueil).
