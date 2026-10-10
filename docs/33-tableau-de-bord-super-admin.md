# 33 : Tableau de bord du Super Admin

Page d'accueil du panel (`/admin`), réservée au Super Admin. Un agent de support est renvoyé vers les tickets, un éditeur de contenu vers les FAQ (ils ne voient ni comptes ni facturation). Navigation, rôles, design et fonctions existantes inchangés. Code : `app/Modules/Admin/Dashboard/` (`DashboardPeriod`, `DashboardMetrics`, `ChartSvg`), page `AdminDashboard`, vues `resources/views/admin/dashboard/`, textes `lang/*/dashboard.php`.

Principe : **aucun chiffre inventé**. Tout vient de tables que la plateforme tient déjà ; ce qu'elle ne conserve pas est dit à l'écran (pied de page « non collectés ») au lieu d'être estimé.

## 1. Structure de la page (de haut en bas)

1. **En-tête** : « Bon retour, {nom} », date du jour, sélecteur de période, actions Actualiser et Imprimer, résumé « Période : du … au … / Comparée au … / Mis à jour … », liens Clients, Abonnements, Tickets, Rapports. Compact : une ligne de filtres.
2. **Chiffres clés** (12 cartes) : valeur, comparaison à la période précédente (flèche ▲▼■ **neutre**, écart absolu et en %, valeur précédente), définition d'une ligne, lien « Détails » vers la liste filtrée.
3. **Revenus et abonnements** : courbe des encaissements, barres abonnements démarrés / terminés, anneau des clients par état, MRR par formule, meilleurs clients, trois blocs de chiffres (revenu d'abonnement, mouvements, recettes des clients).
4. **Clients** : courbes nouveaux clients et clients actifs, taux (conversion, résiliation, activité récurrente, dormants), répartition par état, formule et pays, clients les plus actifs, clients dormants.
5. **Usage du produit** : rendez-vous, WhatsApp, configuration, courbe des rendez-vous, barres des messages, anneau des états de rendez-vous, carte de chaleur jour × heure, adoption des fonctionnalités.
6. **Support** : file à cet instant, chiffres de la période (créés, résolus, rouverts, délais), anneaux état et priorité, volume, note moyenne, charge par agent.
7. **Alertes et suivis** : 8 alertes au plus (les plus graves d'abord), le reste dans « Afficher N de plus ».
8. **Actions rapides** et **activité récente** (12 événements).

Filtre de période (tout le tableau suit) : Aujourd'hui, Cette semaine, Ce mois-ci, Le mois dernier, Ce trimestre, Cette année, Période libre (durée plafonnée à 800 jours).

## 2. Périodes et comparaison

Les périodes calendaires s'arrêtent à aujourd'hui et se comparent à la **même portion de la période précédente** (du 1er au 10 de ce mois contre du 1er au 10 du mois dernier). « Aujourd'hui » se compare à hier ; « le mois dernier » au mois d'avant ; une période libre à la période de même durée qui la précède. Graphiques : un point par jour jusqu'à 62 jours, par mois au-delà ; la période précédente est tracée en pointillé, alignée par rang.

Deux familles de chiffres, clairement marquées :
- **Sur la période** : comparés (nouveaux clients, clients actifs, encaissements, paiements refusés, mouvements d'abonnement, rendez-vous, messages, tickets, avis).
- **Situation actuelle** (« Situation actuelle », sans comparaison car l'historique n'est pas conservé) : MRR, clients par état, essais, renouvellements, impayés, tickets ouverts, limites de formule, dormants.

## 3. Cartes de chiffres clés et définitions

| Carte | Définition | Source / dépendances |
|---|---|---|
| Clients actifs | Clients avec ≥ 1 connexion d'un membre, rendez-vous créé ou message WhatsApp dans la période | `audit_logs` (`auth.login`), `bookings.created_at`, `whatsapp_messages.created_at` |
| Nouveaux clients | Comptes créés dans la période (essais commencés) | `organizations.created_at` |
| MRR | Prix mensuel de la formule des abonnements actifs ou impayés, hors TVA (situation actuelle) | `ClientDirectory` (état, formule) |
| Revenus encaissés | Paiements d'abonnement réussis, TVA incluse | `payments` (succeeded) |
| Paiements échoués | Tentatives refusées dans la période | `payments` (failed) |
| Abonnements actifs | Clients à l'état « actif » (hors impayés) | `ClientDirectory::STATE_SQL` |
| Essais en cours / bientôt terminés | État « essai » / fin d'essai sous 7 jours | `organizations.trial_ends_at` |
| Renouvellements sous 7 jours | Abonnements actifs dont la période (ou la résiliation programmée) se termine sous 7 jours | `subscriptions` |
| Clients résiliés | Abonnements terminés dans la période | `audit_logs` (`billing.subscription_ended`) |
| Tickets ouverts | Tickets ni résolus ni fermés | `support_tickets` |
| Note des clients | Moyenne (1 à 5) des avis de la période ; « aucun avis » sinon | `nova_feedback` |

Revenus et argent des clients restent **séparés** : « Revenu de Nova » (abonnements : encaissé TVA incluse et hors TVA, MRR, moyenne par client payant = MRR ÷ clients payants, impayés en cours, paiements échoués) et « Argent qui circule dans l'activité des clients » (recettes des rendez-vous tenus dans la période : `bookings.amount_collected_cents` ; pourboires : `booking_tips` ; « total encaissé par les clients » = rendez-vous + pourboires, affiché uniquement comme cette somme explicite et jamais ajouté au revenu de Nova). Taux : conversion des essais = clients créés dans la période qui ont aujourd'hui un abonnement ÷ clients créés ; résiliation = terminés ÷ (payants aujourd'hui + terminés) ; activité récurrente = clients actifs la période précédente qui le sont encore ÷ clients actifs la période précédente ; annulation = rendez-vous annulés ÷ rendez-vous décidés (hors « en attente ») ; absence = absents ÷ (terminés + absents) ; délais et réouvertures : `TicketMetrics` (docs/29).

## 4. Graphiques

| Graphique | Type | Axes / unité | Interaction |
|---|---|---|---|
| Revenus d'abonnement encaissés | courbe + aire, période précédente en pointillé | date × € TVA incluse | info-bulle par point (aussi au clavier), tableau de données |
| Abonnements démarrés / terminés | barres groupées | date × nombre | info-bulles, tableau |
| Clients par état, états de rendez-vous, tickets par état / priorité | anneau avec légende chiffrée | parts en % | info-bulle par arc |
| MRR par formule, formules, pays, meilleurs clients, charge par agent, adoption | barres horizontales | valeur | liens vers la fiche client quand pertinent |
| Nouveaux clients, clients actifs, rendez-vous, tickets, note moyenne | courbes | date × nombre | idem |
| Rendez-vous par jour et heure | carte de chaleur 7 × 24 | jour × heure | valeur par case (aria-label) |

Accessibilité : chaque graphique est un `<figure>` titré, le SVG a `role="img"` et une description, les points et barres sont focalisables avec leur valeur, une alternative « Tableau de données » (`<details>`) donne les mêmes chiffres, le sens ne repose jamais sur la couleur seule (valeurs et légendes en texte), focus visible, `prefers-reduced-motion` respecté.

## 5. Alertes (ordre : grave, à surveiller, information)

Impayés ; paiements échoués ; tickets urgents ; tickets hors délai de réponse ; essais bientôt terminés ; tickets non assignés ; notes basses (≤ 2) ; tickets rouverts ; limites de formule proches (≥ 80 % des praticiens ou lieux) ; calendriers en erreur ; messages WhatsApp échoués ; WhatsApp non connecté et aucun praticien (clients de plus de 3 jours) ; clients sans activité (≥ 30 jours) ; renouvellements sous 7 jours. Chaque alerte : titre, explication, nombre, gravité (texte), lien direct. Une alerte à zéro n'apparaît pas.

## 6. Actions rapides

Voir tous les clients, gérer les abonnements, examiner les paiements échoués, tickets, rapports clients, rapports du support, équipe de support, réglages et formules, avis, factures. **Non proposé** : « gérer les praticiens » (les praticiens appartiennent aux clients ; aucun écran Super Admin n'existe).

## 7. Clair / sombre, états, responsive

- Thème : variables CSS `--nd-*` avec valeurs claires et sombres (`.dark`), une couleur de marque (ambre) pour les points forts, neutres pour le fond et le texte, vert / orange / rouge / bleu pour les états ; pas de dégradé ni d'effet 3D.
- États : **chargement** (squelette pendant le changement de période ou l'actualisation), **vide** (« Aucune donnée sur cette période »), **erreur** (message et « Réessayer ; l'erreur est journalisée »), **accès restreint** (message, jamais de chiffres), indisponible (« Indisponible », « Aucun avis »).
- Responsive : grilles `auto-fit` (colonnes multiples sur ordinateur, cartes empilées sur tablette et téléphone), aucun défilement horizontal vérifié à 375 px ; impression : menus et filtres masqués.

## 8. Parcours (drill-down)

Cartes et alertes ouvrent la liste filtrée correspondante (clients : état, essai, paiement, activité ; tickets : vues « urgents », « non assignés », « à traiter ») ou la fiche (client, ticket) ; les classements pointent vers la fiche client ; les rapports détaillés sont `Rapports clients` et `Rapports du support`.

## 9. Ce qui n'est pas affiché, faute de donnée

Historique du MRR et des abonnements actifs (seule la valeur actuelle existe : la courbe montre les encaissements), rétention par cohorte, stockage et usage d'API, région plus fine que le pays. L'export du tableau n'est pas proposé (impression seulement) : les exports détaillés restent ceux des listes. Comparaison de la charge des agents : nombre de tickets (ouverts / total), pas de délai par agent.

## 10. Critères d'acceptation et tests

`tests/Feature/Admin/DashboardTest.php` : périodes et période précédente (jour, semaine, mois, mois dernier, trimestre, année, libre, inversée, plafonnée, tranches sans trou) ; géométrie des graphiques (maximums, arcs = 100 %, série vide) ; exactitude (nouveaux clients et comparaison, revenu d'abonnement séparé des recettes clients et pourboires, MRR sans comparaison, mouvements, états et formules, note moyenne, absence de données sans chiffre inventé) ; alertes (gravité, lien, ordre, silence) ; page (bienvenue, période, graphiques accessibles, changement de période, actualisation, erreur et nouvel essai, redirection des rôles restreints, accès réservé).
