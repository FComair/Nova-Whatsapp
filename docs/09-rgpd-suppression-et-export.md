# RGPD : suppression de compte, export et effacement d'un client (étape 7)

## 1. Suppression d'un compte (décision Q7)

Standard : **J+30** après la demande (délai dans `platform_settings`, modifiable). Immédiate : **fraude ou abus uniquement**, décidée par le fondateur.

### Qui peut la demander

- Le **propriétaire**, depuis « Mes données » : mot de passe actuel + nom exact de l'établissement + case de confirmation. Un e-mail de confirmation lui est envoyé. Il peut **annuler tant que l'effacement n'a pas commencé** (le compte retrouve son statut d'avant, y compris « suspendu » : annuler une suppression ne lève jamais une suspension).
- Le **Super Admin** (docs/07) : suppression programmée, ou suppression immédiate (nom saisi + case « irréversible » + mot de passe + code 2FA + motif).

### Ce qui se passe à l'échéance

La commande `gdpr:process-deletions` (toutes les 5 minutes, verrou pour éviter deux exécutions) :

1. ouvre le suivi (`deletion_requests`), mémorise l'adresse du propriétaire **pour le seul message de confirmation** et pose `deletion_started_at` (l'annulation n'est plus possible) ;
2. exécute **une tâche par table** (`deletion_jobs`), dans l'ordre de `ErasureSteps` (enfants avant parents) ;
3. **3 tentatives** par tâche, puis alerte du fondateur par e-mail (erreur E12, sans donnée personnelle) et attente d'une **relance** depuis le Super Admin (rubrique Suppressions). Les tâches déjà faites ne sont jamais refaites ;
4. à la fin : l'organisation est pseudonymisée (nom, slug, facturation effacés ; identifiant, secteur, pays et dates conservés), statut « supprimé », confirmation envoyée au propriétaire, puis son adresse est **oubliée**.

### Ce qui est effacé

Toutes les tables portant `org_id` (clients, rendez-vous et leur journal, conversations, équipe, services, calendriers, clés d'API, numéros WhatsApp…), les **comptes utilisateurs qui n'appartiennent qu'à cette organisation** (les comptes partagés avec une autre organisation sont conservés) et le journal d'audit du client (adresses IP, agents). **Un test échoue si une nouvelle table avec `org_id` n'a pas d'étape d'effacement.**

### Ce qui est conservé

Les actions du Super Admin sur le compte et le suivi de la suppression (preuve, sans donnée de client) ; la ligne pseudonymisée de l'organisation. Les données de facturation (7 ans) seront à traiter avec la facturation.

### Garde-fous techniques

Tout passe par la fonction SQL `gdpr_purge_org` (liste blanche d'étapes, exécutable par le seul rôle plateforme). Les tables en ajout seul (`audit_logs`, `booking_events`) n'acceptent la suppression que par cette fonction (réglage local à la transaction + rôle propriétaire de la fonction) : ni le rôle client, ni le rôle plateforme, ni le propriétaire de la base ne peuvent les vider autrement (testé). Les messages d'erreur SQL ne sont jamais conservés.

## 2. Export des données (portabilité, art. 20)

« Mes données » → fichier JSON (établissement, équipe, clients, rendez-vous et journal, conversations déchiffrées, services, praticiens, plannings, politiques). Exclus : mots de passe, clés et secrets d'API, adresses et identifiants de calendriers, adresses de flux ICS. Mot de passe exigé, 3 exports par heure, action tracée sans contenu. Disponible quand le compte est suspendu, en pause ou en suppression.

## 3. Effacement d'un client final (art. 17, erreur E14)

Sur la fiche client (propriétaire et responsable de site) : nom, e-mail, langue, étiquettes, conversations et notes de rendez-vous supprimés ; numéro remplacé par un numéro fictif (`+999…`) ; historique des rendez-vous conservé sans lien avec une personne. **Refusé tant qu'un rendez-vous à venir existe.** Le client bloqué est aussi « débloqué » par l'effacement (voir docs/04).

## 4. Pas encore fait

Purge automatique par durée de conservation (durées à décider), rappel au propriétaire à J-7 avant l'effacement, effacement côté prestataires externes (Meta : la suppression du numéro reste à faire dans la console Meta), sauvegardes (délai d'expiration à fixer avec l'hébergeur), données de facturation (avec l'étape facturation), export en plusieurs fichiers pour les très gros comptes.
