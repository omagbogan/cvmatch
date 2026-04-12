# CVMatch IA

CVMatch IA est une application PHP/MySQL avec un microservice Python pour le matching de CV.

Le projet est standardise pour 3 usages simples :
- local avec XAMPP
- local avec Docker Compose
- production simple sur un serveur LAMP avec un service Python separe

## Structure utile

```text
cvmatch/
|-- index.php
|-- connexion.php
|-- inscription.php
|-- dashboard-candidat.php
|-- dashboard-recruteur.php
|-- upload-cv.php
|-- api-match.php
|-- api-contact.php
|-- config.php
|-- database.sql
|-- docker-compose.yml
|-- Dockerfile
|-- health.php
|-- includes/
|   `-- functions.php
|-- uploads/
|   `-- cvs/
`-- python-service/
    |-- app.py
    |-- requirements.txt
    `-- Dockerfile
```

## Option 1 : lancer avec XAMPP

1. Place le projet dans `htdocs/cvmatch`.
2. Demarre Apache et MySQL dans XAMPP.
3. Cree la base :

```bash
mysql -u root -p < database.sql
```

4. Copie `.env.example` vers `.env`, puis ajuste les valeurs.
   Valeurs locales typiques :

```text
DB_HOST=localhost
DB_PORT=3306
DB_NAME=cvmatch_db
DB_USER=root
DB_PASS=
APP_URL=http://localhost/cvmatch
IA_SERVICE_URL=http://localhost:5000
DEEPSEEK_API_KEY=
```

5. Lance le microservice Python :

```bash
cd python-service
python -m pip install -r requirements.txt
python app.py
```

6. Ouvre :

```text
http://localhost/cvmatch
```

## Option 2 : lancer avec Docker Compose

1. Copie `.env.example` vers `.env` et ajuste si besoin.
2. Lance les services :

```bash
docker compose up --build
```

3. Importe le schema MySQL dans le conteneur `db`.
4. Ouvre l'application sur `http://localhost`.

Services :
- `web` : Apache + PHP
- `db` : MariaDB
- `python` : service IA

## Option 3 : production simple

Pour une production simple, garde la meme architecture :
- Apache + PHP 8.2
- MySQL ou MariaDB
- Python 3.11+ pour `python-service/app.py`

Variables minimales a definir cote serveur :

```text
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=cvmatch_db
DB_USER=...
DB_PASS=...
APP_URL=https://ton-domaine.tld
IA_SERVICE_URL=http://127.0.0.1:5000
DEEPSEEK_API_KEY=
DEEPSEEK_API_URL=https://api.deepseek.com
```

## Comptes de demonstration

- `jean@candidat.ci` / `password123`
- `recruteur@cvmatch.ci` / `password123`
- `admin@cvmatch.ci` / `password123`

## Notes

- `api-match.php` appelle le service Python sur `/match`.
- Si le service Python est indisponible, un fallback lexical PHP reste actif.
- Les fichiers uploades vivent dans `uploads/cvs/`.
- Les emails simules sont journalises dans `logs/emails.log`.
