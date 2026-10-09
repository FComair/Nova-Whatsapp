# Facturation (étape 8)

Le prestataire de paiement n'est **pas encore choisi** : tout passe par l'interface `PaymentGateway`, avec un prestataire **simulé** (`BILLING_GATEWAY=fake`) qui ne débite jamais rien. Nova pilote elle-même la facturation ; le prestataire ne sert qu'à recueillir un moyen de paiement (page hébergée) et à le débiter. **Aucune donnée de carte ne transite par Nova** : seulement un jeton (chiffré) et quatre chiffres pour reconnaître la carte.

## Cycle de vie

| Situation | Effet |
|---|---|
| Inscription | Essai de `trial_days` jours (14 par défaut, `platform_settings`) |
| `trial_reminder_days` avant la fin (3) | E-mail au propriétaire, une seule fois |
| Fin d'essai sans abonnement | Compte **suspendu** (raison système `system.trial_ended`), données conservées |
| Souscription | Le premier mois est débité **avant** toute écriture (refus : rien n'est enregistré) ; facture payée ; compte actif |
| Renouvellement mensuel | Facture de la période suivante puis débit (commande horaire `billing:run`) |
| Paiement refusé | Statut « impayé », relances espacées de `dunning_retry_days` (3), `dunning_max_attempts` (3) tentatives au total, e-mail à chaque refus |
| Dernier refus | Compte **suspendu** (`system.payment_failed`) ; le propriétaire régularise en changeant de moyen de paiement (la facture ouverte est aussitôt représentée) |
| Montée de formule | Immédiate, complément au **prorata** du temps restant facturé et débité tout de suite |
| Descente de formule | Appliquée à l'échéance ; refusée si l'usage dépasse les limites de la nouvelle formule (E8) |
| Annulation | Effective à la fin de la période payée ; puis compte suspendu (`system.subscription_ended`) ; annulable avant |

Une suspension **décidée par la plateforme** (fraude, etc.) n'est jamais levée par un paiement, et le propriétaire ne peut alors pas souscrire.

## Factures

Numérotation séquentielle **sans trou** (`NOVA-AAAA-000001`, compteur par année, fonction SQL à verrou, le numéro n'est consommé que si la facture est validée). Vendeur et identité du client **figés** à l'émission. Total = sous-total + TVA (contrainte en base). Page imprimable côté client, liste en lecture seule côté Super Admin. Texte des lignes dans la langue de l'établissement.

## TVA (provisoire, à faire valider par un comptable)

Belgique : 21 %. Professionnel d'un autre pays de l'UE avec un numéro de TVA : 0 % (autoliquidation). Tout autre cas : 21 %. Le numéro de TVA n'est **pas** vérifié (VIES à ajouter).

## RGPD

Factures, paiements, lignes et abonnements sont **conservés** (obligation comptable de 7 ans) ; à la suppression du compte, l'identité figée des factures est **effacée** (pseudonymisation), les moyens de paiement et les relances sont supprimés (docs/09).

## Pas encore fait

Choix du prestataire réel et son adaptateur (webhooks : remboursements, contestations), remboursements et avoirs depuis le Super Admin, facturation annuelle (remise non arrêtée), coupons, PDF des factures (l'impression navigateur suffit pour l'instant), vérification VIES, identité légale du vendeur (`BILLING_SELLER_*`), quotas de conversations et de rendez-vous par formule (les limites existent, leur application arrive avec le bot), pause d'abonnement.

## Choix de la formule à l'inscription

L'inscription se fait en deux étapes : **1. coordonnées**, **2. formule** (essai gratuit, puis chaque formule payante active), récapitulatif de facturation et acceptations. Rien n'est créé avant la validation finale ; revenir à l'étape 1 conserve les champs saisis.

- **Tout vient de la configuration** (`SignupPlans`) : formules actives de la table `plans` (nom, prix mensuel hors TVA, devise, limites praticiens / sites / conversations / rendez-vous ; une limite absente = illimitée), durée de l'essai (`platform_settings.trial_days`), TVA (`nova.billing.vat`, taux belge affiché ; l'autoliquidation intracommunautaire est mentionnée). Aucune liste « d'avantages » inventée : les caractéristiques comparées sont les limites configurées. Changer un prix, une limite ou la durée de l'essai, désactiver une formule, ne demande aucune modification de l'écran.
- **Formule recommandée** : `plans.is_recommended` (aucune par défaut, une seule possible, garanti par un index unique). Le badge « Recommandée » n'apparaît que si la plateforme l'a désignée (`UPDATE plans SET is_recommended = true WHERE code = '…'`).
- **Effet** : le choix est enregistré dans `organizations.signup_plan` ; l'établissement démarre son essai (`trial_days`) avec les **limites de la formule choisie** (`PlanLimits`) ; l'essai gratuit sans formule retenue donne les limites de la formule de repli (Business, hypothèse existante). **L'inscription ne demande aucun moyen de paiement et ne crée jamais d'abonnement, de facture ni de paiement** : l'abonnement payant naît seulement quand le propriétaire le confirme depuis la page Facturation (carte demandée à ce moment). Abandonner l'inscription ne crée donc rien.
- **Récapitulatif avant validation** : formule, prix et fréquence, durée de l'essai et fin d'essai (compte suspendu, données conservées, sans abonnement confirmé), moyen de paiement (aucun à l'inscription), suite (renouvellement mensuel, annulation effective à la fin de la période payée), TVA (taux et prix TTC). Jamais « gratuit » sans dire jusqu'à quand.
- **Formule indisponible** : une formule retirée entre l'affichage et l'envoi est refusée avec un message clair, sans rien créer ; sans formule payante active, seul l'essai est proposé. Un lien `?plan=…` ne présélectionne que les formules existantes.
- **Pas de doublon** : un second envoi (rafraîchissement, double clic) se heurte à l'unicité de l'e-mail et ne crée ni compte ni abonnement ; le bouton est désactivé pendant l'envoi.
- **Comptes existants** : la page d'inscription est réservée aux visiteurs ; la page Facturation reste le seul endroit où un compte existant choisit ou change de formule.
