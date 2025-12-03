# Plugin API Imbretex - Documentation

## 📋 Description

Plugin WordPress pour l'importation automatique des produits Imbretex dans WooCommerce avec gestion des prix, stock, variantes, images et attributs.

**Version :** 5.9  
**Auteur :** Raphael  
**Compatible avec :** WordPress 5.0+, WooCommerce 5.0+

---

## 🚀 Fonctionnalités principales

### 1. **Import de produits depuis l'API Imbretex**
- Connexion sécurisée via token API
- Import automatique des données produits
- Gestion des produits simples et variables
- Import des variantes (taille, couleur)
- Téléchargement et association automatique des images
- Récupération des prix et stocks en temps réel

### 2. **Interface d'administration intuitive**
- Page dédiée dans le menu WordPress : **API Imbretex**
- Tableau de produits avec pagination
- Filtres avancés pour la recherche
- Sélection multiple pour l'import
- Affichage du statut d'existence des produits

### 3. **Système de filtrage double**

#### **Filtres API** (pour charger depuis l'API)
- **Créé depuis le** : Filtrer par date de création (format: JJ-MM-AAAA)
- **Modifié depuis le** : Filtrer par date de modification (format: JJ-MM-AAAA)
- **Max produits** : Nombre maximum de produits à charger (1-1000)
- **Par page** : Nombre de produits à afficher par page (10, 20, 50, 100)
- Bouton **"🔍 Rechercher API"** : Lance la recherche avec loader
- Bouton **"🔄 Actualiser"** : Réinitialise tous les filtres et recharge les produits par défaut

#### **Filtres Tableau** (pour rechercher dans les produits chargés)
- **SKU** : Recherche par référence produit
- **Nom** : Recherche par nom de produit
- **Marque** : Recherche par marque
- **Catégorie** : Recherche par catégorie
- Bouton **"🔍 Filtrer tableau"** : Filtrage rapide sans rechargement API

### 4. **Système de cache intelligent**
- Cache des résultats API pendant 1 heure
- Rechargement automatique si cache vide
- Bouton "Actualiser" pour forcer le rafraîchissement

### 5. **Import intelligent avec détection automatique**

#### **Bouton d'import dynamique**
Le bouton change de label selon la sélection :
- **"✅ Importer"** : Aucune sélection
- **"➕ Ajouter (X)"** : Tous les produits sélectionnés sont nouveaux
- **"🔄 Mettre à jour (X)"** : Tous les produits sélectionnés existent déjà
- **"✅ Ajouter et mettre à jour (X)"** : Mix de nouveaux et existants

#### **Statut WC (WooCommerce)**
- **"✓ Existe"** (vert) : Le produit existe déjà dans WooCommerce
- **"➕ Nouveau"** (gris) : Le produit n'existe pas encore

### 6. **Visualisation des variantes**
- Colonne **"Info"** avec icône 📋
- Clic sur l'icône pour afficher un modal
- Affichage JSON formaté de toutes les variantes
- Permet de vérifier les données avant import

---

## 📦 Détails de l'import

### **Données importées automatiquement**

#### **Informations générales**
- Nom du produit (titre FR)
- SKU (référence unique)
- Description longue
- Description courte
- Slug (URL optimisé)

#### **Classification**
- Catégories (depuis les données API)
- Tags (mots-clés et tags API)
- Marque (brand)

#### **Images**
- Image principale (première image)
- Galerie d'images (images supplémentaires)
- Images spécifiques pour chaque variante

#### **Attributs**
- Taille (global taxonomy)
- Couleur (global taxonomy)
- Matière (attribut personnalisé)
- Genre (meta data)
- Poids net (meta data)
- Grammage (meta data)
- Pays d'origine (meta data)

#### **Prix et stock**
- Prix régulier (price)
- Prix promotionnel (price_box)
- Stock principal (stock)
- Stock fournisseur (stock_supplier)
- Gestion automatique du statut de stock (en stock / rupture)

#### **Variantes (produits variables)**
- Création automatique des variantes
- Attribution des attributs (taille, couleur)
- Prix et stock individuels par variante
- Images spécifiques par variante

### **Statut des produits importés**
- **Par défaut : BROUILLON (Draft)**
- Les produits ne sont pas publiés automatiquement
- Permet une vérification manuelle avant publication
- Publication manuelle via l'interface WooCommerce

---

## 🎯 Guide d'utilisation

### **Étape 1 : Accéder à l'interface**
1. Connectez-vous à l'administration WordPress
2. Cliquez sur **"API Imbretex"** dans le menu latéral

### **Étape 2 : Charger des produits**

**Option A : Charger tous les produits récents**
1. Cliquez sur **"🔄 Actualiser"** pour charger les 20 premiers produits

**Option B : Filtrer par date**
1. Remplissez **"Créé depuis le"** (ex: 01-01-2024)
2. Ou/et **"Modifié depuis le"** (ex: 15-03-2024)
3. Définissez **"Max produits"** (ex: 50)
4. Cliquez sur **"🔍 Rechercher API"**
5. Patientez pendant le chargement (loader visible)

### **Étape 3 : Filtrer les produits affichés**
1. Utilisez les **Filtres Tableau** pour rechercher :
   - Par SKU : "ABC123"
   - Par Nom : "T-shirt"
   - Par Marque : "Nike"
   - Par Catégorie : "Vêtements"
2. Cliquez sur **"🔍 Filtrer tableau"** (rapide, sans reload)

### **Étape 4 : Vérifier les variantes (optionnel)**
1. Cliquez sur l'icône **📋** dans la colonne "Info"
2. Consultez les détails des variantes en JSON
3. Fermez le modal

### **Étape 5 : Sélectionner les produits**
1. Cochez les produits à importer
2. Ou cliquez sur la case en haut pour **tout sélectionner**
3. Observez le changement du bouton d'import

### **Étape 6 : Lancer l'import**
1. Cliquez sur le bouton d'import (ex: **"➕ Ajouter (5)"**)
2. L'import démarre automatiquement (pas de confirmation)
3. Suivez la progression dans le modal
4. Attendez le message de fin

### **Étape 7 : Publier les produits**
1. Allez dans **WooCommerce > Produits**
2. Filtrez par statut **"Brouillon"**
3. Vérifiez chaque produit
4. Cliquez sur **"Modification rapide"** ou éditez le produit
5. Changez le statut en **"Publié"**

---

## ⚙️ Configuration technique

### **Configuration API**
```php
define('API_BASE_URL', 'https://api.imbretex.fr');
define('API_TOKEN', 'VOTRE_TOKEN_API');
```

### **Endpoints utilisés**
- `/api/products/products` : Récupération des produits
- `/api/products/price-stock` : Récupération prix/stock

### **Paramètres API**
- `perPage` : 10 (fixe)
- `page` : Pagination automatique
- `sinceCreated` : Filtrage par date de création
- `sinceUpdated` : Filtrage par date de modification

### **Cache**
- Durée : 1 heure (3600 secondes)
- Type : WordPress Transients
- Clé : `api_products_list_` + hash MD5 des paramètres

---

## 🔧 Comportements spécifiques

### **Gestion des produits simples vs variables**

#### **Produit Simple (1 variante)**
- SKU = `variantReference`
- Un seul produit WooCommerce créé
- Prix et stock directement sur le produit

#### **Produit Variable (plusieurs variantes)**
- SKU parent = `reference` du produit
- Produit variable WooCommerce créé
- Une variation par variante avec :
  - SKU = `variantReference` de chaque variante
  - Attributs : Taille, Couleur
  - Prix et stock individuels
  - Image spécifique

### **Mise à jour des produits existants**
- Détection automatique via SKU
- Si le type change (simple ↔ variable), suppression et recréation
- Sinon, mise à jour des données existantes
- Conservation de l'ID WooCommerce

### **Gestion des images**
- Vérification d'existence avant téléchargement
- Utilisation du cache d'images WordPress
- Images attachées au bon produit/variation
- Gestion automatique de la galerie

### **Gestion des catégories et tags**
- Création automatique si inexistante
- Assignation multiple possible
- Catégorie "Autres" par défaut si aucune catégorie

---

## 📊 Structure du tableau

| Colonne | Description |
|---------|-------------|
| ☑️ | Case à cocher pour sélection |
| **SKU** | Référence unique du produit |
| **Nom** | Titre du produit (FR) |
| **Marque** | Nom de la marque |
| **Catégorie** | Première catégorie du produit |
| **Variants** | Nombre de variantes (badge bleu) |
| **Créé le** | Date de création (JJ/MM/AAAA HH:MM) |
| **Mis à jour le** | Date de modification (JJ/MM/AAAA HH:MM) |
| **Statut WC** | ✓ Existe (vert) ou ➕ Nouveau (gris) |
| **Info** | 📋 Bouton pour voir les variantes JSON |

---

## 🎨 Interface utilisateur

### **Codes couleur**
- 🔵 **Bleu** : Éléments actifs, boutons principaux
- 🟢 **Vert** : Produits existants, succès
- ⚫ **Gris** : Produits nouveaux, éléments neutres
- 🔴 **Rouge** : Erreurs
- 🟡 **Jaune** : Zone d'actualisation (pour visibilité)

### **Icônes utilisées**
- 🔌 Filtres API
- 📋 Filtres Tableau / Voir variantes
- 🔍 Rechercher
- 🔄 Actualiser
- ✅ Importer
- ➕ Ajouter
- 📦 Produits
- ⚠️ Erreurs

---

## ⚠️ Points importants

### **À savoir avant l'import**
1. ✅ Les produits sont importés en **brouillon** (non publiés)
2. ✅ La vérification manuelle est **recommandée**
3. ✅ Les images sont téléchargées automatiquement
4. ✅ Les stocks sont calculés : stock + stock_supplier
5. ✅ Un produit avec 1 variante = **produit simple**
6. ✅ Un produit avec 2+ variantes = **produit variable**

### **Limitations**
- Maximum 1000 produits par recherche API
- Cache de 1 heure (actualiser pour forcer le refresh)
- Import séquentiel (un produit à la fois)
- Réseau requis pour télécharger les images

### **Performances**
- Pagination : 10/20/50/100 produits par page
- Filtres tableau : Instantanés (côté serveur)
- Filtres API : 5-30 secondes selon le nombre de produits
- Import : ~2-5 secondes par produit (selon complexité)

---

## 🐛 Dépannage

### **Aucun produit ne s'affiche après actualisation**
✅ Solution : Cliquez sur **"🔄 Actualiser"** - les produits par défaut se chargeront

### **Le statut "Existe" ne s'affiche pas pour un produit simple**
✅ Solution : Vérifiez que le SKU dans WooCommerce correspond au `variantReference`

### **Images non importées**
✅ Vérifications :
- Connexion internet active
- URLs d'images valides dans l'API
- Droits d'écriture dans `/wp-content/uploads/`

### **Erreur lors de l'import**
✅ Actions :
- Vérifier les logs : `/wp-content/debug.log`
- Vérifier le token API
- Vérifier la connexion à l'API Imbretex

### **Produits en double**
✅ Cause : SKU différent entre variantes et produit parent
✅ Solution : Supprimer les doublons dans WooCommerce, réimporter

---

## 📝 Changelog

### Version 5.9 (Actuelle)
- ✅ Statut "Brouillon" par défaut pour les produits importés
- ✅ Nécessite publication manuelle

### Version 5.8
- ✅ Correction du chargement après actualisation
- ✅ Loader sur le bouton Actualiser

### Version 5.7
- ✅ Correction détection existence produits simples
- ✅ Uniformisation logique SKU

### Version 5.6
- ✅ Bouton d'import intelligent avec label dynamique
- ✅ Modal de visualisation JSON des variantes
- ✅ Interface compacte optimisée
- ✅ Bouton Actualiser dans filtres API

### Version 5.5
- ✅ Séparation complète filtres API / Tableau
- ✅ Bouton Actualiser pour reset

### Versions antérieures
- Import de base
- Gestion variantes
- Prix et stock
- Images et attributs

---

## 👨‍💻 Support

**Développeur :** Raphael  
**Version WordPress requise :** 5.0+  
**Version WooCommerce requise :** 5.0+  
**Version PHP requise :** 7.4+

---

## 📄 Licence

Plugin propriétaire développé pour l'intégration avec l'API Imbretex.

---

**Date de dernière mise à jour :** Décembre 2024
