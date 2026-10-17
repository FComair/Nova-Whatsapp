# BROUILLON : documents juridiques avec le nom Rendivoo (NON PUBLIÉ)

Statut : **brouillon pour validation juridique**. Ces fichiers ne sont lus par aucune partie de l'application : ils ne changent rien tant qu'ils ne sont pas publiés.

Ce que c'est : une copie des trois documents de `resources/legal` (conditions d'utilisation, politique de confidentialité, accord de sous-traitance DPA ; français et anglais, il n'y a pas de version néerlandaise) où seul le **nom du produit** est remplacé : « Nova WhatsApp » et « Nova » deviennent « Rendivoo ». Aucune autre phrase n'est modifiée : 32 ligne(s) au total.

Les originaux de `resources/legal` restent inchangés : ils sont la première version (2026-10-01), figée en base, déjà acceptée par les utilisateurs.

## Points à faire valider
1. **Terme défini** : « Nova WhatsApp (« Nova ») » devient « Rendivoo (« Rendivoo ») » (article 1 des conditions). C'est littéral mais redondant ; une formule comme « Rendivoo (le « Service ») » serait plus nette, mais c'est une modification de fond.
2. **Partie contractante** : les textes renvoient à `{{company}}` (raison sociale du vendeur, `BILLING_SELLER_NAME`). Le nom commercial change ; la **société** qui contracte doit être celle qui est inscrite et facture. À confirmer : un changement de dénomination sociale ou d'entité ne se règle pas par ce remplacement.
3. **Nature du changement** : simple changement de nom commercial (pas de modification des droits ou obligations). Dire si une ré-acceptation est exigée ou seulement une information.
4. **Date d'effet et résumé des changements** (champ « changes » de la version) : à rédiger, par exemple « Le service Nova WhatsApp devient Rendivoo ; aucune autre modification ».
5. **Autres documents** qui citent l'ancien nom : modèles WhatsApp déjà approuvés chez Meta, contenus déjà envoyés, factures déjà émises (les factures émises gardent le vendeur et le nom de l'époque).

## Comment publier (une fois validé)
Super Admin › Documents juridiques › choisir le document › nouvelle version (copier le texte de chaque langue depuis ce dossier), renseigner la date d'effet, le résumé des changements et le fichier PDF, puis publier. La version publiée est figée ; les utilisateurs verront la nouvelle version dans Réglages › Juridique, et devront la ré-accepter si la version l'exige. Voir `docs/28-documents-juridiques.md` et `docs/35-documents-juridiques-pdf.md`.

## Détail des modifications (avant → après)

### Conditions d’utilisation / Terms of Use : 8 ligne(s) modifiée(s)

- **FR · ligne 4**
  - avant : Nova WhatsApp (« Nova ») est un service en ligne qui permet à un établissement (le « Client ») de gérer ses rendez-vous, ses clients et ses échanges WhatsApp. Ces conditions régissent l’utilisation du service par le Client et par les personnes qu’il invite. En créant un compte, vous déclarez agir pour un établissement professionnel et accepter ces conditions. Le service est fourni par {{company}}.
  - après : Rendivoo (« Rendivoo ») est un service en ligne qui permet à un établissement (le « Client ») de gérer ses rendez-vous, ses clients et ses échanges WhatsApp. Ces conditions régissent l’utilisation du service par le Client et par les personnes qu’il invite. En créant un compte, vous déclarez agir pour un établissement professionnel et accepter ces conditions. Le service est fourni par {{company}}.
- **FR · ligne 10**
  - avant : Nova fournit un planning, une base de clients, des messages WhatsApp automatiques et des rapports. L’envoi de messages passe par WhatsApp (Meta) et reste soumis à leurs règles, y compris l’accord préalable de vos clients pour les messages promotionnels. Nous pouvons faire évoluer le service ; nous vous préviendrons de tout changement important.
  - après : Rendivoo fournit un planning, une base de clients, des messages WhatsApp automatiques et des rapports. L’envoi de messages passe par WhatsApp (Meta) et reste soumis à leurs règles, y compris l’accord préalable de vos clients pour les messages promotionnels. Nous pouvons faire évoluer le service ; nous vous préviendrons de tout changement important.
- **FR · ligne 19**
  - avant : Vous restez responsable des données de vos propres clients que vous saisissez dans Nova. Nova les traite pour votre compte selon l’accord de sous-traitance des données (DPA). Vous garantissez avoir les droits et consentements nécessaires pour les utiliser.
  - après : Vous restez responsable des données de vos propres clients que vous saisissez dans Rendivoo. Rendivoo les traite pour votre compte selon l’accord de sous-traitance des données (DPA). Vous garantissez avoir les droits et consentements nécessaires pour les utiliser.
- **FR · ligne 25**
  - avant : Dans les limites permises par la loi, Nova n’est pas responsable des dommages indirects ni de la perte de chiffre d’affaires, et sa responsabilité totale est limitée aux sommes payées pour le service au cours des douze derniers mois. Rien dans ces conditions n’exclut une responsabilité qui ne peut l’être légalement.
  - après : Dans les limites permises par la loi, Rendivoo n’est pas responsable des dommages indirects ni de la perte de chiffre d’affaires, et sa responsabilité totale est limitée aux sommes payées pour le service au cours des douze derniers mois. Rien dans ces conditions n’exclut une responsabilité qui ne peut l’être légalement.
- **EN · ligne 4**
  - avant : Nova WhatsApp (“Nova”) is an online service that lets a business (the “Customer”) manage its appointments, its clients and its WhatsApp conversations. These terms govern the use of the service by the Customer and the people it invites. By creating an account you state that you act for a professional business and accept these terms. The service is provided by {{company}}.
  - après : Rendivoo (“Rendivoo”) is an online service that lets a business (the “Customer”) manage its appointments, its clients and its WhatsApp conversations. These terms govern the use of the service by the Customer and the people it invites. By creating an account you state that you act for a professional business and accept these terms. The service is provided by {{company}}.
- **EN · ligne 10**
  - avant : Nova provides a calendar, a client database, automatic WhatsApp messages and reports. Messages are sent through WhatsApp (Meta) and remain subject to its rules, including your clients’ prior consent for promotional messages. We may change the service; we will tell you about any important change.
  - après : Rendivoo provides a calendar, a client database, automatic WhatsApp messages and reports. Messages are sent through WhatsApp (Meta) and remain subject to its rules, including your clients’ prior consent for promotional messages. We may change the service; we will tell you about any important change.
- **EN · ligne 19**
  - avant : You remain responsible for the data of your own clients that you enter in Nova. Nova processes it on your behalf under the Data Processing Agreement (DPA). You guarantee that you have the rights and consents needed to use it.
  - après : You remain responsible for the data of your own clients that you enter in Rendivoo. Rendivoo processes it on your behalf under the Data Processing Agreement (DPA). You guarantee that you have the rights and consents needed to use it.
- **EN · ligne 25**
  - avant : Within the limits allowed by law, Nova is not liable for indirect damage or loss of revenue, and its total liability is limited to the amounts paid for the service over the previous twelve months. Nothing in these terms excludes liability that cannot lawfully be excluded.
  - après : Within the limits allowed by law, Rendivoo is not liable for indirect damage or loss of revenue, and its total liability is limited to the amounts paid for the service over the previous twelve months. Nothing in these terms excludes liability that cannot lawfully be excluded.

### Politique de confidentialité / Privacy Policy : 6 ligne(s) modifiée(s)

- **FR · ligne 4**
  - avant : {{company}} est responsable du traitement des données de votre compte Nova WhatsApp (utilisateurs de l’espace client). Pour les données de vos propres clients que vous saisissez dans Nova, c’est vous qui êtes responsable du traitement et Nova agit comme sous-traitant, selon l’accord de sous-traitance (DPA).
  - après : {{company}} est responsable du traitement des données de votre compte Rendivoo (utilisateurs de l’espace client). Pour les données de vos propres clients que vous saisissez dans Rendivoo, c’est vous qui êtes responsable du traitement et Rendivoo agit comme sous-traitant, selon l’accord de sous-traitance (DPA).
- **FR · ligne 9**
  - avant : - **Facturation** : identité de facturation, factures et paiements ; aucune donnée de carte n’est conservée par Nova.
  - après : - **Facturation** : identité de facturation, factures et paiements ; aucune donnée de carte n’est conservée par Rendivoo.
- **FR · ligne 37**
  - avant : Pour toute question ou pour exercer vos droits, utilisez l’espace Aide de Nova ou écrivez à {{company}}.
  - après : Pour toute question ou pour exercer vos droits, utilisez l’espace Aide de Rendivoo ou écrivez à {{company}}.
- **EN · ligne 4**
  - avant : {{company}} is the controller of the data of your Nova WhatsApp account (users of the customer area). For the data of your own clients that you enter in Nova, you are the controller and Nova acts as processor under the Data Processing Agreement (DPA).
  - après : {{company}} is the controller of the data of your Rendivoo account (users of the customer area). For the data of your own clients that you enter in Rendivoo, you are the controller and Rendivoo acts as processor under the Data Processing Agreement (DPA).
- **EN · ligne 9**
  - avant : - **Billing**: billing identity, invoices and payments; no card data is kept by Nova.
  - après : - **Billing**: billing identity, invoices and payments; no card data is kept by Rendivoo.
- **EN · ligne 37**
  - avant : For any question or to exercise your rights, use Nova’s Help area or write to {{company}}.
  - après : For any question or to exercise your rights, use Rendivoo’s Help area or write to {{company}}.

### Accord de sous-traitance (DPA) / Data Processing Agreement : 18 ligne(s) modifiée(s)

- **FR · ligne 4**
  - avant : Cet accord complète les conditions d’utilisation. Pour les données personnelles de ses propres clients saisies dans Nova (« Données du Client »), l’établissement est responsable du traitement et {{company}} (« Nova ») est sous-traitant au sens de l’article 28 du RGPD.
  - après : Cet accord complète les conditions d’utilisation. Pour les données personnelles de ses propres clients saisies dans Rendivoo (« Données du Client »), l’établissement est responsable du traitement et {{company}} (« Rendivoo ») est sous-traitant au sens de l’article 28 du RGPD.
- **FR · ligne 7**
  - avant : Nova traite les Données du Client uniquement pour fournir le service (planning, base de clients, messages WhatsApp, rapports) et selon les instructions documentées du Client, pendant la durée du contrat puis jusqu’à leur effacement.
  - après : Rendivoo traite les Données du Client uniquement pour fournir le service (planning, base de clients, messages WhatsApp, rapports) et selon les instructions documentées du Client, pendant la durée du contrat puis jusqu’à leur effacement.
- **FR · ligne 12**
  - avant : ## 4. Obligations de Nova
  - après : ## 4. Obligations de Rendivoo
- **FR · ligne 13**
  - avant : Nova traite les données sur instruction du Client, garantit la confidentialité de son personnel, applique les mesures de sécurité de l’article 5, aide le Client à répondre aux demandes des personnes concernées et à respecter ses obligations de sécurité, de notification et d’analyse d’impact, et l’informe si une instruction lui paraît contraire à la loi.
  - après : Rendivoo traite les données sur instruction du Client, garantit la confidentialité de son personnel, applique les mesures de sécurité de l’article 5, aide le Client à répondre aux demandes des personnes concernées et à respecter ses obligations de sécurité, de notification et d’analyse d’impact, et l’informe si une instruction lui paraît contraire à la loi.
- **FR · ligne 16**
  - avant : Le Client autorise Nova à recourir à des sous-traitants ultérieurs nécessaires au service (hébergement, envoi d’e-mails, WhatsApp/Meta). Nova leur impose des obligations équivalentes, reste responsable de leur exécution et informe le Client de tout changement de sous-traitant, qui peut s’y opposer pour un motif raisonnable.
  - après : Le Client autorise Rendivoo à recourir à des sous-traitants ultérieurs nécessaires au service (hébergement, envoi d’e-mails, WhatsApp/Meta). Rendivoo leur impose des obligations équivalentes, reste responsable de leur exécution et informe le Client de tout changement de sous-traitant, qui peut s’y opposer pour un motif raisonnable.
- **FR · ligne 25**
  - avant : Nova avertit le Client sans retard injustifié après avoir pris connaissance d’une violation de données touchant les Données du Client, avec les informations utiles pour qu’il puisse la notifier.
  - après : Rendivoo avertit le Client sans retard injustifié après avoir pris connaissance d’une violation de données touchant les Données du Client, avec les informations utiles pour qu’il puisse la notifier.
- **FR · ligne 28**
  - avant : Nova transmet au Client toute demande d’une personne concernée reçue directement et fournit les outils d’export et d’effacement du service pour l’aider à y répondre.
  - après : Rendivoo transmet au Client toute demande d’une personne concernée reçue directement et fournit les outils d’export et d’effacement du service pour l’aider à y répondre.
- **FR · ligne 31**
  - avant : Nova met à la disposition du Client les informations nécessaires pour démontrer le respect de cet accord et permet des audits raisonnables, annoncés à l’avance et sans perturber les autres clients.
  - après : Rendivoo met à la disposition du Client les informations nécessaires pour démontrer le respect de cet accord et permet des audits raisonnables, annoncés à l’avance et sans perturber les autres clients.
- **FR · ligne 34**
  - avant : À la fin du contrat ou sur demande, Nova supprime les Données du Client selon le calendrier de suppression du service, après que le Client a pu les exporter, sauf obligation légale de conservation.
  - après : À la fin du contrat ou sur demande, Rendivoo supprime les Données du Client selon le calendrier de suppression du service, après que le Client a pu les exporter, sauf obligation légale de conservation.
- **EN · ligne 4**
  - avant : This agreement supplements the Terms of Use. For the personal data of its own clients entered in Nova (“Customer Data”), the business is the controller and {{company}} (“Nova”) is the processor within the meaning of Article 28 GDPR.
  - après : This agreement supplements the Terms of Use. For the personal data of its own clients entered in Rendivoo (“Customer Data”), the business is the controller and {{company}} (“Rendivoo”) is the processor within the meaning of Article 28 GDPR.
- **EN · ligne 7**
  - avant : Nova processes Customer Data only to provide the service (calendar, client database, WhatsApp messages, reports) and on the Customer’s documented instructions, for the duration of the contract and then until erasure.
  - après : Rendivoo processes Customer Data only to provide the service (calendar, client database, WhatsApp messages, reports) and on the Customer’s documented instructions, for the duration of the contract and then until erasure.
- **EN · ligne 12**
  - avant : ## 4. Nova’s obligations
  - après : ## 4. Rendivoo’s obligations
- **EN · ligne 13**
  - avant : Nova processes data on the Customer’s instructions, ensures the confidentiality of its staff, applies the security measures in section 7, helps the Customer respond to data subject requests and meet its security, notification and impact-assessment obligations, and tells the Customer if an instruction seems to breach the law.
  - après : Rendivoo processes data on the Customer’s instructions, ensures the confidentiality of its staff, applies the security measures in section 7, helps the Customer respond to data subject requests and meet its security, notification and impact-assessment obligations, and tells the Customer if an instruction seems to breach the law.
- **EN · ligne 16**
  - avant : The Customer authorises Nova to use sub-processors needed for the service (hosting, email delivery, WhatsApp/Meta). Nova imposes equivalent obligations on them, remains liable for their performance and informs the Customer of any change of sub-processor, to which the Customer may object on reasonable grounds.
  - après : The Customer authorises Rendivoo to use sub-processors needed for the service (hosting, email delivery, WhatsApp/Meta). Rendivoo imposes equivalent obligations on them, remains liable for their performance and informs the Customer of any change of sub-processor, to which the Customer may object on reasonable grounds.
- **EN · ligne 25**
  - avant : Nova notifies the Customer without undue delay after becoming aware of a breach affecting Customer Data, with the information needed for the Customer to notify it.
  - après : Rendivoo notifies the Customer without undue delay after becoming aware of a breach affecting Customer Data, with the information needed for the Customer to notify it.
- **EN · ligne 28**
  - avant : Nova forwards to the Customer any request received directly from a data subject and provides the service’s export and erasure tools to help the Customer respond.
  - après : Rendivoo forwards to the Customer any request received directly from a data subject and provides the service’s export and erasure tools to help the Customer respond.
- **EN · ligne 31**
  - avant : Nova makes available the information needed to demonstrate compliance with this agreement and allows reasonable audits, announced in advance and without disrupting other customers.
  - après : Rendivoo makes available the information needed to demonstrate compliance with this agreement and allows reasonable audits, announced in advance and without disrupting other customers.
- **EN · ligne 34**
  - avant : At the end of the contract or on request, Nova deletes Customer Data according to the service’s deletion schedule, after the Customer has been able to export it, unless the law requires retention.
  - après : At the end of the contract or on request, Rendivoo deletes Customer Data according to the service’s deletion schedule, after the Customer has been able to export it, unless the law requires retention.
