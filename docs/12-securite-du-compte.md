# Sécurité du compte client (Paramètres > Sécurité)

Page `/securite`, accessible à tous les rôles et quel que soit le statut du compte (un compte suspendu doit pouvoir activer sa double authentification). Toute modification sensible redemande le **mot de passe actuel** (5 essais par 15 minutes).

## Mot de passe (décision 18)
12 caractères minimum, majuscule, minuscule, chiffre, caractère spécial ; pas de mot évident (liste, même « décoré » : `P@ssw0rd…`), ni la partie locale de l'e-mail ; pas de réutilisation des 5 derniers. Le changement ferme toutes les autres sessions et prévient par e-mail. Un rappel s'affiche au bout de 12 mois (`password_max_age_months`).

## Double authentification
- **Application d'authentification (TOTP)** : secret chiffré en base, code à scanner (QR généré sur place, aucun service tiers), 8 codes de secours (empreintes seules, usage unique, affichés une seule fois, renouvelables).
- **Connexion** : après le mot de passe, une seconde étape (`/connexion/verification`) demande le code ; 5 erreurs renvoient à la connexion ; un code TOTP ne sert qu'une fois (rejeu).
- **Obligatoire, sans délai de grâce** pour le propriétaire et les responsables de site (`RequireTwoFactor`) : tant qu'elle n'est pas activée, seules la page Sécurité, la langue et la déconnexion restent accessibles. Ces rôles ne peuvent pas la désactiver. Réglage `NOVA_REQUIRE_2FA` (vrai par défaut ; faux dans les tests).
- Les praticiens et réceptionnistes peuvent l'activer (et la désactiver avec mot de passe + code).
- **Récupération** : codes de secours ; à défaut, **déblocage d'accès par le support Rendivoo** (voir ci-dessous). Non fait : clés matérielles FIDO2/WebAuthn, code par SMS.

## E-mail
Lien signé (24 h, usage unique) envoyé à la **nouvelle** adresse, avis à l'**ancienne** ; rien ne change avant la confirmation. La nouvelle adresse est alors considérée comme vérifiée et l'ancienne est prévenue.

## Téléphone
Code envoyé au **nouveau** numéro (10 min, 5 essais, 3 envois par heure) ; l'ancien numéro reste actif jusqu'à la confirmation.

## Sessions
« Fermer les autres sessions » réécrit la même empreinte pour invalider les autres appareils, sans toucher à l'historique ni à la date de changement.

## Décisions et limites
- « Administrateur de l'établissement » = propriétaire et responsables de site (validé).
- « Comptes ayant accès aux données sensibles » : non distingués (le propriétaire couvre la facturation) ; à préciser si un rôle supplémentaire doit être concerné.
- Un compte sans téléphone (praticien invité) ne peut pas ajouter de numéro avant d'avoir un numéro vérifié : le changement s'applique à tout compte, y compris sans numéro actuel.
- Le Super Admin garde sa propre 2FA (Filament), séparée.

## Déblocage d'accès par le support (Super Admin)
Action « Débloquer l'accès » sur la fiche d'une organisation, pour un membre qui a perdu son appareil ET ses codes de secours. **L'identité est vérifiée par le support hors de l'outil** (pièce d'identité ou rappel sur le numéro enregistré) ; la case « identité vérifiée » est obligatoire et le motif est conservé dans l'audit (user.access_unblocked, motif, IP, administrateur). Comme toute action sensible : mot de passe + code 2FA du Super Admin.

- Réinitialise la double authentification et lève le verrouillage ; la personne est prévenue par e-mail et devra la reconfigurer à la prochaine connexion (obligatoire pour propriétaire et responsables).
- Option « imposer un nouveau mot de passe » : invalide les sessions ouvertes (mot de passe aléatoire inutilisable) et envoie un lien de réinitialisation. À utiliser si le compte peut être compromis.
- Le Super Admin ne lit ni n'écrit la table users : deux fonctions SQL dédiées (platform_org_members, platform_unblock_user), limitées aux membres actifs de l'organisation choisie ; aucun secret n'en sort.
