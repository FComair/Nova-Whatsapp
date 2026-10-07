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
