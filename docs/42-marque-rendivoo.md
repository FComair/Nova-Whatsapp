# Marque Rendivoo (anciennement Nova)

Le produit s'appelle **Rendivoo** (anciennement « Nova » et « Nova WhatsApp »). Ce document dit ce qui a été renommé et, surtout, ce qui garde volontairement son ancien nom, pour ne rien casser.

## Écriture
« Rendivoo » : une majuscule, jamais « RendiVoo », « Rendivoo-whatsapp » ni « Rendivoo Chatbot ». En technique : `rendivoo` en minuscules.

## Renommé
- Tous les textes visibles : traductions (fr, nl, en), e-mails, vues, centre d'aide, documentation, README, commentaires.
- Panel Super Admin : « Rendivoo Admin ». Émetteur affiché par les applications d'authentification (TOTP) : « Rendivoo ».
- Configuration PHP : `config/nova.php` → `config/rendivoo.php`, clés `config('rendivoo.…')` (migrations historiques comprises : seuls les appels de config y ont changé).
- Commandes Artisan : `rendivoo:preflight`, `rendivoo:create-admin`, `rendivoo:dev-admin-link`, `rendivoo:admin-allowlist`. Les anciens noms `nova:…` restent **alias** : un script, un cron ou un superviseur qui les appelle encore continue de fonctionner (à migrer hors dépôt, puis retirer les alias).
- Design : jetons `rendivoo-900` / `rendivoo-700`, `--ease-rendivoo`, classes `.rendivoo-*`, fonctions JS internes.
- Domaines de test et d'exemple : `rendivoo.test`, `rendivoo.example`. Classe `RendivooFeedback` (la table garde son nom).
- Mot de passe : « rendivoo » s'ajoute aux mots refusés (« nova » reste refusé).

## Conservé volontairement (identifiants techniques)
| Identifiant | Pourquoi on ne le renomme pas | Pour le renommer un jour |
|---|---|---|
| Base `nova`, base de test `nova_test`, rôles `nova_app`, `nova_owner`, `nova_platform` (et leurs mots de passe de dev), réglages internes `nova.gdpr_purge`, `nova.ticket_purge` | Existent en production, dans les droits, les migrations déjà jouées et les fonctions SQL | Migration d'infrastructure coordonnée (renommer base et rôles, mettre à jour `.env`, droits par défaut et fonctions) |
| Table `nova_feedback` et ses contraintes | Table en production ; seule la classe PHP a changé de nom | Migration `RENAME TABLE` |
| Variables `NOVA_*` (`NOVA_APP_DOMAIN`, `NOVA_ADMIN_DOMAIN`, `NOVA_FOUNDER_EMAIL`, `NOVA_REQUIRE_2FA`, `NOVA_CSP`…) | Le `.env` de chaque serveur les utilise | Lire les deux noms le temps de la transition, puis mettre à jour chaque environnement |
| En-têtes API `X-Nova-Key`, `X-Nova-Signature`, `X-Nova-Timestamp` | Contrat avec n8n et les clients de l'API interne | Accepter les deux noms, migrer les clients, retirer l'ancien |
| Préfixe `nova_` des noms de modèles WhatsApp (`nova_hairdressing_reminder`) | Nom enregistré et approuvé chez Meta | Nouveaux modèles sous un nouveau préfixe, anciens retirés une fois remplacés |
| Préfixe `NOVA-` des numéros de facture (`NOVA-2026-000001`, fonction SQL de la migration de facturation) | Numérotation continue exigée pour la comptabilité : **décision comptable**, à valider avant tout changement | Nouvelle migration de la fonction, à une date de rupture choisie |
| UID `@feed.nova` du flux ICS | Les agendas déjà abonnés reconnaissent leurs rendez-vous par cet identifiant : le changer créerait des doublons | Aucun, sauf nouvel abonnement des utilisateurs |
| Clés de navigateur `nova-theme`, `nova.customers.columns`, `nova.planning.filters`, `nova.settingsSearch.recent` | Préférences des utilisateurs : les renommer les réinitialiserait | Lire l'ancienne clé en repli |
| Type de consentement `nova_news`, clé de traduction `nova_team` | Valeurs enregistrées en base | Migration de données |
| Projet Docker `nova`, image `nova-php`, `.devcontainer` (`nova-codespaces`), `zz-nova-dev.ini`, `nova-dev.log` | Les renommer recrée les volumes de développement | À faire au besoin, avec une sauvegarde locale |
| Scripts de déploiement (`deploy/server-setup.sh`) | Noms de base et de rôles ci-dessus | Avec la migration d'infrastructure |

## À faire hors code (décisions et exploitation)
1. **Documents juridiques** : les CGU, la politique de confidentialité et le DPA (`resources/legal`) nomment toujours « Nova » : ces versions sont **publiées et figées en base**. Le changement de nom de l'éditeur se fait par une **nouvelle version publiée depuis le Super Admin** (avec ré-acceptation), après validation juridique. Les fichiers du dépôt n'ont volontairement pas été modifiés pour rester identiques à ce qui est publié.
2. **Vendeur sur les factures** : `BILLING_SELLER_NAME` et l'identité légale sont déjà configurables (le texte par défaut est « Rendivoo (raison sociale à renseigner) »).
3. **Modèles WhatsApp** déjà approuvés chez Meta : leur texte contient l'ancien nom tant qu'il n'est pas resoumis.
4. **Cron, supervisor, scripts externes** qui appellent `php artisan nova:…` : à migrer vers `rendivoo:…`.
5. **Services tiers** (Meta, Stripe/prestataire de paiement, comptes de messagerie, Google/Trustpilot) : noms d'application et d'expéditeur à mettre à jour dans leurs consoles.
