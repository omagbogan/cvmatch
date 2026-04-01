# Service Python pour CVMatch IA

Ce service Python fournit une API HTTP simple pour analyser les CV et retourner un classement par score.

## Installation

1. Installer Python 3.11+.
2. Installer les dépendances :

```bash
python -m pip install -r requirements.txt
```

## Lancer le service localement

```bash
cd python-service
python app.py
```

Le service écoute par défaut sur `http://0.0.0.0:5000`.

## Endpoints

- `POST /match`
- `POST /chat`

### Corps JSON attendu

```json
{
  "requete": "développeur PHP Abidjan",
  "filtre": "senior",
  "db_host": "localhost",
  "db_port": 3306,
  "db_user": "root",
  "db_pass": "",
  "db_name": "cvmatch_db"
}
```

Si la connexion à la base de données échoue, le service essaie au moins de scanner le dossier `uploads/cvs/` et de classer les fichiers disponibles.

## Déploiement sur serveur

1. Copier le dossier `python-service` sur le serveur.
2. Installer Python et les dépendances.
3. Ouvrir le port HTTP (5000 ou celui que vous choisissez).
4. Configurer `IA_SERVICE_URL` dans `config.php` vers l'URL de votre service, par exemple :

```php
define('IA_SERVICE_URL', 'http://mon-serveur-python:5000');
```

5. Assurer que le service Python a accès à la base de données MySQL ou au dossier partagé `uploads/cvs/`.

## Notes

- Le service retourne déjà un classement trié du plus pertinent au moins pertinent.
- Pour une vraie extraction de texte depuis des PDF/DOCX, il faudra ajouter un parseur spécifique.
