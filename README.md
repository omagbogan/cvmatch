# CVMatch IA — Guide d'installation

## Structure du projet

```
cvmatch/
├── index.php                  # Page d'accueil
├── connexion.php              # Formulaire de connexion
├── inscription.php            # Formulaire d'inscription
├── logout.php                 # Déconnexion
├── dashboard-candidat.php     # Espace candidat
├── dashboard-recruteur.php    # Dashboard recruteur/admin
├── upload-cv.php              # Traitement upload CV
├── api-match.php              # API matching IA (↔ Python)
├── api-contact.php            # API envoi email simulé
├── config.php                 # Configuration globale
├── .htaccess                  # Sécurité Apache
├── includes/
│   └── functions.php          # Fonctions utilitaires
├── uploads/
│   └── cvs/                   # Fichiers CV uploadés
├── logs/
│   └── emails.log             # Emails simulés
└── database.sql               # Schéma MySQL
```

## Installation

### 1. Base de données MySQL

```bash
mysql -u root -p < database.sql
```

### 2. Configuration

Éditez `config.php` et modifiez :
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'votre_user');
define('DB_PASS', 'votre_mot_de_passe');
define('APP_URL', 'http://localhost/cvmatch');
define('IA_SERVICE_URL', 'http://localhost:5000');
```

Si vous utilisez DeepSeek directement, définissez également :
```php
define('DEEPSEEK_API_URL', 'https://api.deepseek.ai');
define('DEEPSEEK_API_KEY', 'votre_cle_deepseek');
```

> Le code utilise désormais `DEEPSEEK_API_KEY` en priorité pour les recherches de matching.

### 3. Permissions dossiers

```bash
chmod 755 uploads/ uploads/cvs/ logs/
```

### 4. Régénérer les hash de mots de passe

Les comptes de démo ont le mot de passe `password123`.
Pour régénérer le hash en production :
```php
echo password_hash('votreMotDePasse', PASSWORD_BCRYPT);
```

## Comptes de démonstration

| Rôle | Email | Mot de passe |
|------|-------|--------------|
| Candidat | jean@candidat.ci | password123 |
| Recruteur | recruteur@cvmatch.ci | password123 |
| Admin | admin@cvmatch.ci | password123 |

## Fonctionnement du service IA Python

Le fichier `api-match.php` appelle automatiquement le microservice Python
sur `http://localhost:5000/match` (POST JSON).

**Si Python est indisponible**, un fallback basique par mots-clés
s'active automatiquement — le site reste fonctionnel.

## Déploiement du service Python distant

Le service Python peut être hébergé sur un serveur séparé. Dans ce cas :

- Installez Python et les dépendances dans `python-service/`
- Démarrez `python app.py`
- Configurez `IA_SERVICE_URL` dans `config.php` vers l'URL distante, par exemple :

```php
define('IA_SERVICE_URL', 'http://mon-serveur-python:5000');
```

- Le service doit pouvoir accéder à la base de données MySQL ou au dossier partagé `uploads/cvs/`.

- Le service expose les mêmes endpoints que la version locale : `POST /match` et `POST /chat`.

### Déploiement sur Railway

Railway peut héberger :

- le service Web PHP (`Dockerfile`)
- le service Python (`python-service/Dockerfile`)
- une base de données PostgreSQL gérée

#### Étapes Railway

1. Crée un projet Railway.
2. Ajoute le plugin PostgreSQL ou MySQL.
3. Crée un service Web en utilisant le `Dockerfile` à la racine.
4. Crée un service Python en utilisant `python-service/Dockerfile`.
5. Configure ces variables Railway :
   - `RAILWAY_DATABASE_URL` ou `DATABASE_URL` (fourni par le plugin de base de données Railway)
   - `APP_URL` = l'URL publique du service Web
   - `IA_SERVICE_URL` = `http://<nom-du-service-python>:5000` si les deux services sont dans le même projet
   - `DEEPSEEK_API_KEY` (optionnel)
   - `DEEPSEEK_API_URL` = `https://api.deepseek.ai`

#### Pourquoi ça fonctionne déjà

`config.php` supporte déjà `RAILWAY_DATABASE_URL` et `DATABASE_URL`, donc Railway fournira directement les informations de connexion.

#### Attention

- Railway ne garantit pas la persistance locale des fichiers uploadés.
- Pour les CVs, prévois un stockage externe ou un plugin de stockage.

### Format attendu de la réponse Python :
```json
{
  "resultats": [
    {
      "id": 3,
      "nom": "Jean Dupont",
      "email": "jean@candidat.ci",
      "telephone": "+225 05 44 55 66",
      "ville": "Abidjan",
      "score": 87,
      "annees_experience": 3,
      "competences_extraites": "PHP, MySQL, JavaScript",
      "cv_fichier": "cv_3_1720000000_abc123.pdf",
      "resume_ia": "Développeur PHP expérimenté avec solide maîtrise de MySQL."
    }
  ]
}
```

## Sécurité implémentée

- Mots de passe hashés avec `password_hash()` (bcrypt)
- Protection CSRF sur tous les formulaires
- Sessions sécurisées (HttpOnly, SameSite)
- Validation MIME réelle des fichiers uploadés
- Noms de fichiers aléatoires (non devinables)
- Requêtes SQL avec PDO et paramètres liés
- Protection contre le brute-force (sleep après échec)
- Headers de sécurité via .htaccess

## Logs d'emails simulés

Les emails sont enregistrés dans `logs/emails.log` :
```
=== EMAIL SIMULÉ - 2025-01-15 14:32:00 ===
De      : Marie Recruteur <recruteur@cvmatch.ci>
À       : Jean Dupont <jean@candidat.ci>
Objet   : Opportunité d'emploi
Message : Bonjour Jean, votre profil...
```
