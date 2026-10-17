# 26. Avis des clients et avis publics

## Deux choses distinctes
1. **Avis privé à Rendivoo** (`nova_feedback`) : 1 à 5 étoiles + commentaire facultatif (2000 caractères max), envoyé à l'équipe, jamais publié.
2. **Avis public** (Google, Trustpilot) : écrit et publié par le client lui-même sur ces plateformes. Rendivoo ne publie rien et ne le laisse jamais entendre. Aucune note publique n'est stockée ni affichée par Rendivoo.

Les deux sont stockés et affichés séparément : le Super Admin ne voit que les avis privés (et quelles plateformes publiques ont été ouvertes), jamais présentés comme des notes Google ou Trustpilot.

## Parcours
- **À tout moment** : Aide → onglet « Donner votre avis » (`GET /aide/avis`, route `help.reviews`). Tous les rôles. Plusieurs avis généraux possibles dans le temps.
- **Après un échange avec le support** : sur la page d'un ticket, une carte discrète (`FeedbackPrompt`) apparaît à l'auteur du ticket
  - après la première réponse de l'équipe (`source = intervention`, clé `intervention:{ticket}`),
  - quand le ticket est résolu ou fermé (`source = ticket`, clé `ticket:{ticket}`).
  « Donner mon avis » ouvre le formulaire rattaché au ticket (référence `NWS-n` affichée) ; « Pas maintenant » (`POST /aide/avis/plus-tard`) ne repropose pas ce moment (`feedback_prompt_dismissals`). L'avis reste possible plus tard depuis l'Aide. Rien n'est jamais exigé pour fermer un ticket ou continuer à utiliser la plateforme.
- **Un avis par personne, par ticket et par moment** (index unique partiel) ; doublon → message clair, aucune perte.
- Toutes les notes sont acceptées (1 à 5). Échec : le texte et la note restent dans le formulaire.

## Liens d'avis publics (neutres)
- URL **uniquement configurées** : `NOVA_REVIEW_URL_GOOGLE`, `NOVA_REVIEW_URL_TRUSTPILOT` (`config/rendivoo.php` → `reviews`). Aucune valeur par défaut, aucun lien inventé. Une adresse non https ou hors des domaines de la plateforme (`ReviewLinks`) est ignorée ; sans adresse valable l'option est masquée.
- **Mêmes options, même ordre (Google puis Trustpilot), pour tout le monde**, quelle que soit la note. Aucun filtrage selon la satisfaction, aucun routage selon la note, aucune contrepartie, aucune sollicitation sélective.
- Ouverture dans un nouvel onglet (`target=_blank`, `rel=noopener noreferrer`) avec la mention qu'on quitte Rendivoo.
- Suivi : le lien passe par `GET /aide/avis/lien/{platform}`, qui note l'ouverture (plateforme seulement, `review_link_clicks`) puis redirige vers l'adresse configurée telle quelle, sans y ajouter de paramètre. Ce suivi est annoncé dans l'interface. Nous ne savons jamais si un avis a été écrit ni ce qu'il contient.

## Super Admin
Ressource Filament en lecture seule « Avis clients » (groupe Exploitation) : date, note, commentaire, origine (général / après une réponse du support / ticket résolu ou fermé), référence du ticket, compte client, plateformes publiques ouvertes. Filtres par note et origine, recherche dans les commentaires, moyenne des avis privés. Rappel permanent : ce ne sont ni des avis Google ni des avis Trustpilot.

## Données
- `nova_feedback`, `review_link_clicks`, `feedback_prompt_dismissals` : RLS par organisation, journaux en ajout seul, effacés avec le compte (`gdpr_purge_org`, `ErasureSteps`).
- Textes : `ui.feedback.*` (fr/nl/en), `admin.feedback.*`.
