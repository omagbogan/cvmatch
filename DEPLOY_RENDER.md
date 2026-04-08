# Déploiement CVMatch sur Render

## Avantages de Render

- Pas besoin d'installer Docker ou SSH
- Déploiement automatique depuis GitHub
- Base de données managée incluse
- HTTPS gratuit et automatique
- Scaling facile

---

## Étapes de déploiement

### 1) Connecter ton dépôt GitHub

1. Crée un compte sur [Render.com](https://render.com)
2. Clique sur **"New +"** → **"Blueprint"**
3. Connecte ton repo GitHub (où tu as push CVMatch)
4. Sélectionne la branche à déployer (ex: `main`)

---

### 2) Configurer les variables d'environnement

Render lira automatiquement le `render.yaml`. Ajoute manuellement via le dashboard :

- **DEEPSEEK_API_KEY** : ta clé DeepSeek API (si tu l'utilises)
- Les autres variables sont auto-générées depuis la BDD

> Secret : clique sur **"Secret"** pour les valeurs sensibles

---

### 3) Lancer le déploiement

1. Render va lire `render.yaml`
2. Il va créer :
   - 1 service Web (PHP) → accessible publiquement
   - 1 service Privé (Python) → uniquement accessible depuis le Web
   - 1 base PostgreSQL ou MySQL managée
3. Clique **"Deploy"** → attends ~10-15 minutes

---

### 4) Initialiser la base de données

Une fois déployé, tu dois importer le schéma SQL :

#### Option A : Via l'interface Render

1. Va dans le dashboard du service Web
2. Clique **"Shell"** 
3. Exécute :

```bash
mysql -h $DB_HOST -u $DB_USER -p$DB_PASS -P $DB_PORT $DB_NAME < database.sql
```

#### Option B : Depuis ton poste local

Récupère d'abord les infos de connexion du dashboard Render :

```bash
mysql -h <db_host> -u <db_user> -p <password> -P 3306 cvmatch_db < database.sql
```

---

### 5) Accéder à l'application

Après déploiement, tu auras une URL comme :
- `https://cvmatch-web-xxxxx.onrender.com`

Elle est automatiquement en HTTPS. C'est bon !

---

## Problèmes courants

### PHP retourne 502 Bad Gateway

- Vérifie que la base de données est bien créée
- Regarde les logs : clique sur "Logs" dans le dashboard

### Le service Python ne démarre pas

- Vérifie que `DEEPSEEK_API_KEY` est défini (ou vide si pas utilisé)
- Regarde les logs Python dans Dashboard → cvmatch-python

### Les uploads ne persistent pas

Render efface les fichiers locaux à chaque redémarrage. Solution :
- Utilise [Render Disks](https://render.com/docs/disks) (payant)
- Ou stocke les CVs dans une base de données (BLOB)
- Ou utilise un service comme AWS S3 / DigitalOcean Spaces

---

## Limites de Render (plan gratuit)

- Les services se "dorment" après 15 min d'inactivité
- Pas de support des volumes persistants
- Crédits limités par mois

**Solution** : upgrade au plan **Standard** (~$25/mois) pour un usage en production. Le `render.yaml` est configuré pour utiliser les plans Standard.

---

## Prochaines étapes optionnelles

1. Connecte un domaine personnalisé
2. Configure le backup automatique de la BDD
3. Ajoute des Cronjobs pour nettoyer les CVs périmés
4. Active les alertes de downtime

---

**Besoin d'aide ?** Dis-moi quelle étape bloque !
