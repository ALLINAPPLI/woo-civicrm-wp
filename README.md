# WooCommerce → CiviCRM (woo-civicrm-wp)

Fork du plugin [woo-civicrm-wp](https://github.com/lmoncany/woo-civicrm-wp) (Loic Moncany). Version locale : **1.0.4**.

## TL;DR

- Quand une commande WooCommerce est **payée** ou passe en **completed**, le plugin crée une **contribution CiviCRM** (API4).
- Il rattache la contribution à un **contact existant** (email, sinon prénom + nom) ou **crée** le contact.
- Réglages : menu WP **WC CiviCRM** → URL CiviCRM, jeton API, type financier, mapping des moyens de paiement.
- Une commande n’est synchronisée **qu’une fois** (`_civicrm_synced`).

## Prérequis

- WordPress 5.0+
- WooCommerce 3.0+ (actif)
- PHP 7.2+
- CiviCRM joignable en **API4** (`/civicrm/ajax/api4/…`)
- Un **jeton d’authentification** CiviCRM (`X-Civi-Auth: Bearer …`)

CiviCRM peut être sur le même WordPress ou sur un autre site : seule l’URL de base compte.

## Installation

1. Copier le dossier `woo-civicrm-wp` dans `wp-content/plugins/`.
2. Activer le plugin (**Extensions**). WooCommerce doit déjà être actif, sinon l’activation est refusée.
3. Aller dans **WC CiviCRM** (menu admin).

À l’activation, des mappings de champs par défaut sont enregistrés s’ils n’existent pas encore. Ils servent surtout à l’UI admin : **la synchro réelle n’utilise pas ces mappings** (voir [Limites](#limites)).

## Configuration

Menu **WC CiviCRM** → **Settings**. Enregistrer via **Save Settings**.

### Connection

| Réglage | Option WP | Rôle |
|---|---|---|
| CiviCRM API URL | `wc_civicrm_url` | URL du site CiviCRM, sans slash final (ex. `https://exemple.org`) |
| API Authentication Token | `wc_civicrm_auth_token` | Jeton API4 Bearer |

Tester la connexion avant d’enregistrer. Le statut est mis en cache (`wc_civicrm_connection_status`, 15 min).

### Mapping Payment

Associe l’id machine WooCommerce (`stripe`, `bacs`, …) à un `payment_instrument_id` CiviCRM.

- Source CiviCRM : `OptionValue` du groupe `payment_instrument` (utiliser **value**, pas l’id de ligne).
- Bouton **Refresh Instruments** pour recharger la liste.
- Option WP : `wc_civicrm_payment_method_map` (`['stripe' => 2, …]`).
- **Fallback** si aucun mapping : instrument **2** (Carte bancaire, historique ASPAS).

Les ids machines WooCommerce sont listés dans l’onglet **Debug**.

### Contribution

| Réglage | Option WP | Défaut |
|---|---|---|
| Financial Type | `wc_civicrm_contribution_type_id` | `1` |

**Refresh Types** recharge les types financiers actifs (`FinancialType.get`). Cache : `wc_civicrm_financial_types`.

### Field Mappings

Interface WooCommerce ↔ CiviCRM (Contact / Contribution). **Non appliquée** à la création réelle du contact ni de la contribution. Les champs synchronisés sont **codés en dur**.

### Testing

Trois tests AJAX (capacité `manage_options`) :

1. Connexion API
2. Création d’un contact test (`Test Contact_<timestamp>`) + email
3. Création d’une contribution test

### Debug

Case **Debug Mode** (`wc_civicrm_debug_mode`) : prévue pour un log plus verbeux. En pratique, le logger écrit déjà les événements de synchro et d’API.

## Fonctionnement de la synchro

Flux unidirectionnel : **WooCommerce → CiviCRM**. Pas de retour CiviCRM → WooCommerce.

### Déclencheurs

La synchro part de `handle_order_completed()` sur :

- `woocommerce_payment_complete`
- `woocommerce_order_status_completed`
- `woocommerce_order_status_changed` si le nouveau statut est `completed`

Elle est **ignorée** si :

- URL ou jeton CiviCRM manquant
- commande introuvable
- meta `_civicrm_synced` déjà vraie
- moyen de paiement **ND** (vide) ou **Autre** (`other`) — voir [ND et Autre](#nd-et-autre-admin)

### Étapes

1. Extraire les données de facturation / commande.
2. Trouver ou créer le contact CiviCRM.
3. Créer une **nouvelle** contribution.
4. Marquer la commande :

| Meta commande | Contenu |
|---|---|
| `_civicrm_synced` | `1` |
| `_civicrm_contact_id` | ID contact CiviCRM |
| `_civicrm_contribution_id` | ID contribution CiviCRM |

### Matching du contact

Méthode `get_or_create_contact()`. Champs WooCommerce **obligatoires** : `billing_email`, `billing_first_name`, `billing_last_name`.

Ordre des recherches (égalité stricte, `limit: 1`) :

1. **Email** — API4 `Email.get` où `email = billing_email`. Si trouvé, le `contact_id` est utilisé **sans** comparer le nom et **sans** mettre à jour le contact.
2. **Prénom + nom** — API4 `Contact.get` où `first_name` **et** `last_name` correspondent. Aucun filtre email, aucun dédoublonnage CiviCRM.
3. **Création** — `Organization` si `billing_company` est rempli, sinon `Individual`. Puis email (et téléphone éventuel) en requêtes séparées. Adresse de facturation jointe à la création du contact.

Risque : deux homonymes → la contribution peut être rattachée au **premier** contact trouvé par nom.

### Contribution créée

Toujours un `Contribution.create` (jamais une recherche de contribution existante).

| WooCommerce | CiviCRM |
|---|---|
| Contact trouvé / créé | `contact_id` |
| Type financier (réglage) | `financial_type_id` |
| Total TTC | `total_amount` |
| Devise | `currency` |
| `WooCommerce Order #<id>` | `source` |
| Date de création commande | `receive_date` |
| Gateway mappée | `payment_instrument_id` |
| — | `contribution_status_id` = 1 (Completed) |
| — | `is_test` = 0 |

Si CiviCRM renvoie une *constraint violation*, un second essai part avec `financial_type_id = 1` et `source = WooCommerce Test`.

### Champs commande extraits (mais pas tous envoyés)

`extract_order_data()` lit aussi adresse de livraison, notes, n° de commande, etc. Seuls les champs listés ci-dessus partent dans la contribution. L’adresse sert à la **création** de contact, pas à une mise à jour d’un contact existant.

## Champs de facturation obligatoires

Fichier `required-billing-fields.php`. Force prénom, nom et email de facturation pour que la synchro ait toujours une identité.

| Contexte | Comportement |
|---|---|
| Checkout classique | Champs `required` + validation serveur |
| Checkout Blocks / Store API | Erreur 400 si un champ manque ou email invalide |
| Admin commande | Astérisque sur les labels ; notice si enregistrement incomplet (la sauvegarde n’est pas forcément bloquée) |
| REST API | Refus à la **création**, ou si le bloc `billing` est envoyé |

La validation dans `get_or_create_contact()` reste un filet : trop tard pour empêcher la commande, elle fait échouer la synchro.

## ND et Autre (admin)

WooCommerce ajoute toujours deux options dans le select `_payment_method` du formulaire d’ajout / édition de commande : **ND** (`value=""` = `N/A`) et **Autre** (`value="other"`). Ce ne sont pas des gateways.

Fichier `required-payment-method.php`. Si le staff passe la commande en **completed** avec ND ou Autre :

- le statut **n’est pas** passé en Terminée (il reste pending ou l’ancien statut)
- une erreur s’affiche dans l’admin
- la synchro CiviCRM **ne part pas** (`_civicrm_synced` n’est pas posé)

Le select admin **n’est pas modifié** : ND et Autre restent disponibles à l’ajout et à l’édition. Les brouillons / commandes `pending` avec ND restent possibles.

| Contexte | Comportement |
|---|---|
| Admin (ajout / édition) | `$_POST['order_status']` réécrit avant la sauvegarde WooCommerce |
| Lots / « Marquer terminée » | Le passage en completed est annulé |
| REST API | Erreur 400 si `status=completed` avec ND / Autre |
| Synchro | Filet dans `handle_order_completed()` : log + note de commande |

Une fois un vrai moyen choisi (Stripe, virement, chèque, COD), passer en Terminée relance la synchro.

## Logs

Page **WC CiviCRM → Logs**.

- Fichier : `wp-content/uploads/wc-civicrm-logs/wc-civicrm-integration.log`
- Format : une ligne JSON par événement
- Rotation : 1 Mo, 5 fichiers (`.1` … `.5`)
- Protection : `.htaccess` + `index.php` dans le dossier
- Filtres : type (`success` / `error`), événement, logs rotatés
- Pagination : 50 lignes
- **Clear Logs** vide tous les fichiers (nonce)

Événements utiles : `contact_found_by_email`, `contact_found_by_name`, `contact_creation`, `plugin_debug`, `plugin_error`, `api_request`, `status_change`.

## Structure

```
woo-civicrm-wp/
├── woocommerce-civicrm-plugin.php   # Bootstrap, synchro commande → contribution
├── required-billing-fields.php      # Champs facturation obligatoires
├── required-payment-method.php      # Blocage completed si ND / Autre
├── send-civicrm-request.php         # Trait API4 (POST, Bearer token)
├── settings-page.php                # Admin réglages + tests AJAX
├── logging.php                      # Logger fichier
├── logs-page.php                    # UI des logs
├── uninstall.php                    # Nettoyage partiel à la désinstallation
└── assets/                          # CSS / JS admin
```

Classes principales :

- `WooCommerceCiviCRMIntegration` — hooks commande + synchro
- `WC_CiviCRM_Required_Billing_Fields` — validation identité
- `WC_CiviCRM_Required_Payment_Method` — blocage ND / Autre en completed
- `WC_CiviCRM_Settings` — page de réglages
- `WC_CiviCRM_Logger` / `WC_CiviCRM_Logs_Page`
- Trait `WC_CiviCRM_API_Request` — `file_get_contents` vers `/civicrm/ajax/api4/{Entity}/{action}`

## Options WordPress

| Option | Usage |
|---|---|
| `wc_civicrm_url` | URL CiviCRM |
| `wc_civicrm_auth_token` | Jeton API |
| `wc_civicrm_contribution_type_id` | Type financier |
| `wc_civicrm_payment_method_map` | Gateway WC → instrument CiviCRM |
| `wc_civicrm_field_mappings` | Mappings UI (non utilisés à la synchro) |
| `wc_civicrm_debug_mode` | Case debug |
| `wc_civicrm_connection_status` | Dernier test de connexion |
| `wc_civicrm_financial_types` | Cache types financiers |
| `wc_civicrm_payment_instruments` | Cache instruments |

`wc_civicrm_site_key` est lu dans les settings mais **n’est pas** envoyé par le trait API principal.

## Désinstallation

`uninstall.php` supprime `wc_civicrm_url`, `wc_civicrm_auth_token`, `wc_civicrm_field_mappings` et un ancien fichier `wc-civicrm.log` dans le dossier plugin.

**Non nettoyé** : mapping paiement, type financier, caches, logs dans `uploads/wc-civicrm-logs/`, métas des commandes.

## Dépannage

- **Aucune contribution** : vérifier URL + jeton ; onglet Testing ; logs `plugin_error`. La commande doit être payée ou `completed`.
- **Commande déjà synchro** : meta `_civicrm_synced` = 1. La retirer pour forcer un nouvel envoi (créera une **deuxième** contribution).
- **Mauvais contact** : matching email d’abord, puis homonyme prénom+nom. Vérifier l’email de facturation dans WooCommerce et CiviCRM.
- **Mauvais instrument de paiement** : onglet Mapping Payment ; sinon fallback id `2` (sauf ND / Autre, refusés).
- **Commande admin restée pending** : moyen de paiement ND ou Autre. Choisir une gateway puis passer en Terminée.
- **Constraint violation** : second essai en type financier `1`. Vérifier le type choisi et les champs obligatoires CiviCRM.
- **Checkout refusé** : prénom, nom ou email de facturation vide / email invalide (`required-billing-fields.php`).
- **Connexion KO** : URL de base du site (pas `/civicrm` seul), jeton API4 valide, CiviCRM joignable depuis le serveur WP.

## Limites

- Pas de synchro inverse (CiviCRM → WooCommerce).
- Pas de mise à jour d’un contact déjà trouvé (nom, adresse, email inchangés).
- Pas de dédoublonnage CiviCRM (règles unsupervised / supervised).
- Les Field Mappings admin ne pilotent pas la synchro.
- Une contribution par commande completed ; pas de lignes d’items, pas de ligne financière détaillée.
- Statut contribution toujours Completed, même si le mapping métier voudrait Pending.
- `checkPermissions` est à `false` sur les appels API.

## Licence

GPL-2.0 or later. Voir l’en-tête de `woocommerce-civicrm-plugin.php`.
