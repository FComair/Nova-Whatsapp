# 35 · Documents juridiques déposés en PDF

Complète `docs/28-documents-juridiques.md`. Le Super Admin dépose ou remplace, pour chacun des trois documents (Conditions d'utilisation, Politique de confidentialité, DPA), un PDF qui devient la version publique.

## Principe

- Déposer un PDF **crée une nouvelle version publiée** (`legal_document_versions`), en vigueur le jour même. Une version publiée est figée par la base (docs/28) : « archiver » = être dépassée par une version plus récente. Rien n'est modifié ni supprimé.
- Numéro de version : `AAAA-MM-JJ`, puis `.2`, `.3`… pour plusieurs dépôts le même jour.
- États affichés : **en vigueur**, **archivée**, **planifiée** (date d'effet future). Un dépôt est refusé si une version planifiée existe déjà (elle passerait devant le nouveau PDF).
- Colonnes ajoutées : `file_path`, `file_name`, `file_size`, `file_sha256`, `uploaded_by`, `uploaded_at` (migration `2026_11_16_000001`). Une version est soit un texte, soit un PDF (contrainte CHECK).

## Contrôle du fichier

Côté navigateur (FilePond) : type PDF et 20 Mo. Côté serveur (`LegalDocumentFiles::validate`) : fichier reçu entier, non vide, ≤ 20 Mo, extension `.pdf`, type MIME `application/pdf`, signature `%PDF-` dans le premier Ko, **aucun contenu actif** (`/Launch`, `/JavaScript`, `/JS`, `/EmbeddedFile`, `/RichMedia`). Messages d'erreur clairs en fr/nl/en (`admin.legal_files.errors.*`). Limites relevées pour 20 Mo : règle Livewire (`config/livewire.php`) et PHP (`docker/php/uploads.ini` ; en production, `upload_max_filesize`/`post_max_size` ≥ 20M côté PHP et `client_max_body_size` côté serveur web).

## Stockage et accès

- Disque privé `local` : `storage/app/private/legal/{document}/{uuid}.pdf`. Empreinte SHA-256 enregistrée.
- **Public** : seule la version en vigueur, `/legal/{slug}` (lecteur intégré), `/legal/{slug}/file` (en ligne) et `/legal/{slug}/download`. `X-Frame-Options: SAMEORIGIN` uniquement pour `legal/*/file`, `nosniff`, `no-cache`.
- **Versions archivées en PDF** : introuvables côté public (404) ; téléchargeables par le Super Admin via `/admin/legal-documents/{document}/versions/{version}/file`. Les versions archivées au format texte restent publiques (docs/28).
- Accès réservé au Super Admin hors rôles restreints (agents de support, éditeurs).

## Écrans (Super Admin › Configuration › Documents juridiques)

Liste → fiche d'un document → dépôt / historique. Chaque écran a un bouton « Retour » en haut à gauche (« Retour au tableau de bord », « Retour aux documents juridiques », « Retour à [document] »), pleine largeur sur mobile. Le dépôt demande une confirmation (« … La version précédente sera archivée. »), affiche une barre de progression, puis un message de succès. Quitter la page avec un fichier choisi mais non déposé demande confirmation (liens Retour/Annuler, fermeture d'onglet, bouton Précédent).

## Audit (`audit_logs`)

`legal.file_uploaded`, `legal.file_replaced` (version et fichier précédents), `legal.file_rejected` (raison), `legal.file_downloaded` (drapeau `archived`). Aucune suppression n'existe : aucune action de suppression à tracer.

## Limites connues

- Les téléchargements publics de la version en vigueur ne sont pas journalisés.
- Le contrôle du contenu actif est une recherche de marqueurs dans le fichier : il ne remplace pas un antivirus (PDF compressés en flux d'objets non analysés).
- Un PDF n'a pas de texte extrait : pas de recherche ni de sommaire sur la page publique.

Tests : `tests/Feature/Admin/LegalFilesTest.php`, `resources/js/Pages/Legal/Legal.test.ts`.
