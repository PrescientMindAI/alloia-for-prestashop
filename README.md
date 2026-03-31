# AlloIA for PrestaShop

Plugin PrestaShop pour synchroniser le catalogue avec le graphe AlloIA (API key, sync produits, meta AI, caractéristiques).

## Installation

1. Générer le zip : à la racine du repo `alloia-plugins`, exécuter :
   ```bash
   node scripts/build-prestashop-zip.js
   ```
2. Dans PrestaShop : **Modules → Module Manager → Upload a module**, choisir `alloiaprestashop.zip`.
3. Configurer la clé API (alloia.ai) et lancer **Synchronize all products**.

## Publier une nouvelle version (PrestaShop uniquement, n’impacte pas WooCommerce)

1. **Bumper la version** dans `alloiaprestashop.php` : `$this->version = '1.0.1';` (ou la version à publier).
2. **Ajouter un script d’upgrade** si besoin (config, DB, etc.) : créer `upgrade/upgrade-1.0.1.php` avec :
   ```php
   <?php
   function upgrade_module_1_0_1($module) { return true; }
   ```
3. **Générer le zip** à la racine de `alloia-plugins` : `npm run build:prestashop`.
4. **Pousser sur GitHub** : un push sous `alloia-for-prestashop/**` déclenche la release (tag `prestashop-vX.Y.Z`, asset `alloiaprestashop.zip`). Ou lancer manuellement l’action **Release PrestaShop Plugin** et attacher le zip.

Lien de téléchargement stable : `https://github.com/PrescientMindAI/alloia-plugins/releases/latest/download/alloiaprestashop.zip` (tant que la dernière release du dépôt est PrestaShop).

## Auto-update (structure officielle PrestaShop)

Pour que PrestaShop détecte les nouvelles versions et propose le bouton **Mise à jour** :

- **Version** : `$this->version` dans `alloiaprestashop.php`.
- **Dossier `/upgrade/`** : scripts `upgrade-X.Y.Z.php` avec `upgrade_module_X_Y_Z($module)` retournant `true`. PrestaShop les exécute séquentiellement lors du clic sur « Mise à jour ».

## Mise à jour depuis le dépôt Git

1. `git pull` puis `npm run build:prestashop` (ou `node scripts/build-prestashop-zip.js`).
2. Créer une release GitHub et attacher `alloiaprestashop.zip`.
3. Côté boutique : télécharger le zip de la release, **Modules → Upload module** ; PrestaShop affiche **Upgrade** et exécute les scripts dans `/upgrade/`.

## Structure

- `alloiaprestashop.php` : point d’entrée, hooks, config.
- `src/` : AlloiaApiClient, ProductExporter (sync + caractéristiques), AlloiaCore (meta AI).
- `views/` : templates back-office.

## Exigences

- PrestaShop 8.x / 9.x
- PHP 7.4+
- Clé API AlloIA (préfixe `ak_`)
