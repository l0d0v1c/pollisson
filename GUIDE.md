# Système de Sondage PHP

Application de sondage complète avec interface d'administration et système de vote en temps réel.

## Fonctionnalités

- **Interface Administrateur** : Création et gestion de sondages
- **Système de Vote** : Interface utilisateur intuitive avec animations
- **Résultats en Temps Réel** : Visualisation des statistiques avec graphiques animés
- **Export Markdown** : Export des résultats au format Markdown
- **Protection Anti-Spam** : Un vote par IP par sondage
- **Sécurisé** : Base de données SQLite protégée, headers de sécurité

## Installation

1. **Téléchargez les fichiers** dans votre répertoire web :
   - `index.php` : Application principale
   - `style.css` : Styles et animations
   - `.htaccess` : Configuration Apache

2. **Configuration serveur** :
   - PHP 7.4+ avec PDO SQLite
   - Apache avec mod_rewrite activé
   - Permissions d'écriture sur le dossier pour la base de données

3. **Accès** : `http://votre-site.com/`

## Utilisation

### Administration

1. **Connexion** : Code administrateur par défaut : `admin123`
2. **Créer un sondage** :
   - Code unique : Identifiant du sondage
   - HTML d'en-tête : Message personnalisé
   - Options : Une option par ligne
   - Choix multiple : Permettre plusieurs sélections
   - Date de fin : Optionnelle
   - Message de remerciement : Personnalisable

3. **Gestion** :
   - Voir les sondages existants
   - Consulter les résultats
   - Exporter en Markdown

### Utilisateurs

- **Accès direct** : `http://votre-site.com/CODE_SONDAGE`
- **Vote** : Sélectionner une ou plusieurs options
- **Option "Autre"** : Commentaire personnalisé
- **Résultats** : Affichage immédiat après vote

## Structure des Fichiers

```
pollisson/
├── index.php      # Application principale PHP
├── style.css      # Styles CSS3 avec animations
├── .htaccess      # Configuration Apache
├── polls.db       # Base de données SQLite (créée automatiquement)
└── GUIDE.md       # Documentation
```

## Sécurité

- Base de données SQLite protégée par .htaccess
- Headers de sécurité configurés
- Protection contre les votes multiples par IP
- Validation des données d'entrée
- Échappement HTML pour éviter les XSS

## Personnalisation

### Changer le Code Admin

Dans `index.php`, ligne 54 :
```php
return isset($_POST['admin_code']) && $_POST['admin_code'] === 'admin123';
```

### Modifier les Couleurs

Dans `style.css`, variables CSS :
```css
:root {
    --primary-color: #3498db;
    --primary-hover: #2980b9;
    /* ... */
}
```

## Compatibilité

- **Navigateurs** : Chrome, Firefox, Safari, Edge (dernières versions)
- **Mobile** : Interface responsive
- **PHP** : 7.4+ avec PDO SQLite
- **Serveur** : Apache avec mod_rewrite