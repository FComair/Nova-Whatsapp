# Messages WhatsApp par secteur (Super Admin)

Bibliothèque centralisée de modèles de messages WhatsApp, **un jeu indépendant par secteur d'activité**, géré dans le Super Admin (Configuration › Messages WhatsApp).

## 1. État avant ce chantier (audit)

| Question | Constat |
|---|---|
| Où sont les textes ? | `lang/{fr,nl,en}/whatsapp.php` (4 modèles, **globaux**). Noms Meta : `config/whatsapp.php` (4 de réservation, 5 de fidélité). |
| Où est l'approbation ? | Uniquement chez Meta. `TemplateManager` soumet les modèles manquants (permission `whatsapp.templates`) et `whatsapp:sync-templates` met à jour les statuts toutes les heures. Aucune validation interne par Nova. |
| Statuts | `pending`, `approved`, `rejected`, `paused` (table `whatsapp_templates`, **par établissement**). Pas de brouillon ni d'archivé. |
| Qui crée / édite / approuve ? | Personne ne crée ni n'édite (textes dans le code). Le propriétaire soumet ; Meta approuve ou refuse. |
| Déclencheurs | Confirmation, déplacement, annulation (`BookingNotifier`, y compris pause d'établissement), rappel (`ReminderService`), 5 messages de fidélité (`LoyaltyMessenger`, inchangés). |
| Portée | Textes globaux, dupliqués chez Meta par établissement. Rien par secteur. |

## 2. Architecture

Tables **globales** (sans `org_id`) : lecture seule pour `nova_app` (envoi), écriture pour `nova_platform`.

- `message_templates` : `sector_code` (FK `sectors.code`, suppression en cascade), `type`, `slug`, `name`, `meta_name` (unique, préfixé par le secteur : `nova_{secteur}_{slug}`), `category` (utility / marketing), `status`, `is_enabled`, `rejection_reason`, auteur et décideur.
- `message_template_translations` : un texte par langue (fr, nl, en), variables **nommées** `{{customer_name}}`.
- `message_template_events` : journal **en ajout seul** (ni UPDATE ni DELETE, pour personne ; fermé à `nova_app`) ; sans clé étrangère, l'historique survit à la suppression du modèle.

Code : `Whatsapp/Support/{MessageTemplateType,MessageTemplateStatus,MessageVariables,MessageTemplateLibrary,ResolvedTemplate}`, `Whatsapp/Services/SectorTemplateResolver`, `Admin/Services/{MessageTemplateManager,MessageTemplateImporter}`, `Admin/Filament/Resources/MessageTemplateResource`, `Booking/Services/{BookingMessageSender,BookingMessageValues}`.

### Types
Bienvenue, confirmation, rappel, modification, annulation, rappel de paiement, confirmation de paiement, suivi après prestation, demande d'avis, relance, promotion, information, message personnalisé. Confirmation, rappel, modification et annulation sont **envoyés automatiquement** ; les autres sont prêts à l'emploi (pas encore de déclencheur dans l'application).

### Variables
`business_name`, `customer_name`, `service_name`, `practitioner_name`, `date`, `time`, `address`, `booking_link`, `amount`, `payment_link`, `review_link`. Règles de Meta contrôlées dès la saisie : chaque variable une seule fois, jamais au début ni à la fin du message, dix au plus, 1024 caractères. Pour Meta, les variables sont numérotées (`{{1}}`…) dans l'ordre d'apparition.

### Circuit d'approbation
`brouillon → en attente d'approbation → approuvé | refusé`, `archivé` (et « restaurer » → brouillon). Modifier le texte ou le type d'un modèle en attente ou approuvé le **remet en brouillon**. Suppression : brouillon, refusé ou archivé seulement. Chaque transition est écrite dans le journal du modèle **et** dans le journal d'audit de la plateforme (permission `settings.edit`).

### Envoi
`SectorTemplateResolver` retient un modèle seulement s'il est : du secteur de l'établissement, du bon type, **approuvé** et **activé** par le Super Admin, disponible dans la langue du client (sinon la langue de repli), et **approuvé par Meta** pour cet établissement (`whatsapp_templates`). Chaque établissement soumet à Meta les modèles approuvés de son secteur depuis sa page WhatsApp (`TemplateManager`, déjà en place) ; le modèle du secteur remplace alors le modèle global.

**Transition** : tant qu'aucun modèle du secteur n'est approuvé pour un déclencheur, les 4 modèles globaux restent utilisés (`WHATSAPP_LEGACY_FALLBACK=true`, défaut). Passer à `false` impose « approuvé uniquement » : sans modèle approuvé, le message n'est pas envoyé (`SendRefused::NO_TEMPLATE`).

## 3. Contenu importé

`MessageTemplateImporter` (migration `2026_11_20_000002` ou action « Importer le jeu standard ») crée, **en brouillon**, 12 types × 3 langues pour chacun des 11 secteurs de départ, avec le vocabulaire du métier (rendez-vous, consultation, visite, séance, soin, intervention, leçon) et des phrases propres (suivi, avis, relance, promotion, information). Idempotent : un modèle existant n'est jamais écrasé. Un secteur créé plus tard reçoit le jeu « Autre » à l'import. Les textes sont des **propositions à relire** avant approbation (comme docs/15).

## 4. Décisions à valider

- Tout est importé en brouillon : rien n'est approuvé tant que le Super Admin ne l'a pas fait. Les 4 modèles globaux assurent la continuité.
- Séparation des tâches : le même administrateur peut créer et approuver (le journal garde l'auteur et le décideur).
- Les messages sans déclencheur (bienvenue, paiement, suivi, avis, relance, promotion, information) sont gérés et approuvables, mais Nova ne les envoie pas encore.
- Le prestataire ne modifie jamais un modèle approuvé : il n'a pas d'écran d'édition ; sa permission `whatsapp.templates` ne fait que soumettre à Meta les modèles du secteur.
- Les modèles de fidélité (docs/27) restent hors de cette bibliothèque pour l'instant.
