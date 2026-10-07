# Tableau de bord : résumé du jour (flowchart 2B)

Page `/tableau-de-bord`, `DashboardController` + `DaySummary` (module Booking).

## Résumé du jour
- **Jour** : celui du fuseau de l'établissement (`organizations.timezone`).
- **Compteurs** : rendez-vous aujourd'hui (en attente, confirmés, terminés, absents), à venir aujourd'hui, annulés aujourd'hui, demain, 7 prochains jours, nouveaux clients des 7 derniers jours.
- **Prochain rendez-vous** et **liste du jour** dans l'ordre (heure, client, service, praticien, statut ; site si plusieurs). 40 lignes au plus ; le compteur reste exact et un message renvoie vers le planning.
- **Droits** (docs/02 §1) : propriétaire = tout ; responsable de site et réceptionniste = leurs sites ; praticien = ses propres rendez-vous, sans compteur global de clients. Les notes de rendez-vous n'apparaissent pas.

## Autres éléments
- **Premiers pas** (propriétaire) : activer la double authentification, créer un premier rendez-vous, connecter un calendrier externe, choisir une formule ; la carte disparaît quand tout est fait.
- **Essai** : jours restants et lien vers la facturation.
- Reprise de l'assistant d'onboarding et profil du praticien invité : inchangés.

## Pas encore fait (écarts avec le flowchart)
Autorisation des notifications du navigateur, indicateurs d'activité détaillés et rapports (docs/11), connexion WhatsApp dans les « Premiers pas » (l'écran de connexion n'existe pas encore), rafraîchissement automatique de la page.
