from flask import Flask, request, jsonify
import os
import re
import json
import hashlib
import time
import requests
from pathlib import Path
from concurrent.futures import ThreadPoolExecutor, as_completed
import pymysql.cursors

# Extraction de texte
try:
    import pdfplumber
    PDF_OK = True
except ImportError:
    PDF_OK = False

try:
    from docx import Document as DocxDocument
    DOCX_OK = True
except ImportError:
    DOCX_OK = False

from dotenv import load_dotenv
load_dotenv()

app = Flask(__name__)
BASE_DIR   = Path(__file__).resolve().parent
UPLOAD_DIR = BASE_DIR / 'uploads' / 'cvs'
CACHE_FILE = BASE_DIR / 'cache' / 'scores.json'   # fichier de cache
CACHE_TTL  = 60 * 60 * 24   # durée de vie du cache : 24 heures

DEEPSEEK_API_KEY = os.getenv('DEEPSEEK_API_KEY', '')
DEEPSEEK_API_URL = 'https://api.deepseek.com/v1/chat/completions'
DEEPSEEK_MODEL   = 'deepseek-chat'

SCORE_MIN   = 30
MAX_WORKERS = 10


# ---------------------------------------------------------------------------
# Système de cache
# ---------------------------------------------------------------------------

def load_cache() -> dict:
    """Charge le cache depuis le fichier JSON."""
    try:
        if CACHE_FILE.exists():
            with open(CACHE_FILE, 'r', encoding='utf-8') as f:
                return json.load(f)
    except Exception as e:
        print(f'[cache] Erreur lecture : {e}')
    return {}


def save_cache(cache: dict) -> None:
    """Sauvegarde le cache dans le fichier JSON."""
    try:
        CACHE_FILE.parent.mkdir(parents=True, exist_ok=True)
        with open(CACHE_FILE, 'w', encoding='utf-8') as f:
            json.dump(cache, f, ensure_ascii=False, indent=2)
    except Exception as e:
        print(f'[cache] Erreur écriture : {e}')


def make_cache_key(requete: str, filtre: str, candidate_id: str) -> str:
    """
    Génère une clé unique pour chaque combinaison requête + filtre + candidat.
    On normalise la requête pour que "Dev PHP" et "dev php" donnent la même clé.
    """
    normalized = f"{requete.lower().strip()}|{filtre.lower().strip()}|{candidate_id}"
    return hashlib.md5(normalized.encode('utf-8')).hexdigest()


def get_candidate_id(candidate: dict) -> str:
    """Identifiant unique d'un candidat : son id DB ou le nom de son fichier."""
    if candidate.get('id'):
        return f"db_{candidate['id']}"
    return f"file_{candidate.get('fichier_stocke', 'unknown')}"


def get_from_cache(cache: dict, key: str) -> tuple[int, str] | None:
    """Retourne (score, justification) si le cache est valide, sinon None."""
    entry = cache.get(key)
    if not entry:
        return None
    # Vérifier que l'entrée n'a pas expiré
    if time.time() - entry.get('timestamp', 0) > CACHE_TTL:
        return None
    return entry['score'], entry['justification']


def set_in_cache(cache: dict, key: str, score: int, justification: str) -> None:
    """Enregistre un score dans le cache avec un timestamp."""
    cache[key] = {
        'score':         score,
        'justification': justification,
        'timestamp':     time.time(),
    }


def purge_expired_cache(cache: dict) -> dict:
    """Supprime les entrées expirées du cache."""
    now = time.time()
    return {k: v for k, v in cache.items() if now - v.get('timestamp', 0) <= CACHE_TTL}


# ---------------------------------------------------------------------------
# Extraction de texte
# ---------------------------------------------------------------------------

def extract_text_from_file(filepath: Path) -> str:
    ext = filepath.suffix.lower()
    try:
        if ext == '.pdf' and PDF_OK:
            with pdfplumber.open(filepath) as pdf:
                pages = [page.extract_text() or '' for page in pdf.pages]
            return '\n'.join(pages).strip()

        if ext == '.docx' and DOCX_OK:
            doc = DocxDocument(filepath)
            return '\n'.join(p.text for p in doc.paragraphs).strip()

        if ext in ('.txt', '.md'):
            return filepath.read_text(encoding='utf-8', errors='ignore').strip()

    except Exception as e:
        print(f'[extract] Erreur sur {filepath.name} : {e}')
    return ''


# ---------------------------------------------------------------------------
# Normalisation
# ---------------------------------------------------------------------------

def normalize_text(value: str) -> str:
    return ' '.join(re.findall(r"[a-z0-9àâäéèêëîïôùûüç]+", value.lower(), flags=re.IGNORECASE))


# ---------------------------------------------------------------------------
# Scoring DeepSeek
# ---------------------------------------------------------------------------

DEEPSEEK_SYSTEM_PROMPT = """
Tu es un expert en recrutement et en analyse de CVs. Ton rôle est d'évaluer la pertinence d'un profil candidat par rapport à une offre ou une recherche de poste.

Tu reçois :
- La REQUÊTE du recruteur (poste recherché, compétences, contexte)
- Le FILTRE optionnel (critère prioritaire : ville, secteur, langue, etc.)
- Le PROFIL du candidat (nom, ville, compétences, expérience, extrait du CV)

Tu dois retourner UNIQUEMENT un objet JSON valide, sans aucun texte avant ou après, avec exactement cette structure :
{
  "score": <entier entre 0 et 100>,
  "justification": "<une phrase courte expliquant le score>",
  "points_forts": "<liste des points forts du profil par rapport à la recherche>",
  "points_faibles": "<liste des points faibles ou manquants>",
  "competences_detectees": "<liste des compétences clés détectées dans le CV>"
}

Règles de scoring :
- 90-100 : Profil quasi parfait, correspond à tous les critères essentiels
- 70-89  : Très bon profil, correspond à la majorité des critères
- 50-69  : Profil correct, correspondance partielle mais exploitable
- 30-49  : Profil faible, peu de correspondance avec la recherche
- 0-29   : Profil hors sujet ou données insuffisantes

Critères d'évaluation (par ordre de priorité) :
1. Compétences techniques ou métier en lien avec la requête
2. Années d'expérience pertinente
3. Localisation géographique si le filtre le précise
4. Cohérence globale du profil avec le poste recherché

Cas particuliers :
- Profil vide ou sans données → score entre 0 et 15
- Requête vague → évaluer sur les compétences générales
- Filtre géographique non correspondant → pénaliser de 10 à 20 points max
- Compétences proches mais pas exactes → score partiel, ne pas mettre 0
- Tenir compte des synonymes (JS = JavaScript, PG = PostgreSQL)
- Minimum absolu : 0

Réponds UNIQUEMENT avec le JSON demandé.
""".strip()


def build_candidate_profile(candidate: dict) -> str:
    lines = []
    if candidate.get('nom'):
        lines.append(f"Nom : {candidate['nom']}")
    if candidate.get('ville'):
        lines.append(f"Ville : {candidate['ville']}")
    if candidate.get('annees_experience'):
        lines.append(f"Années d'expérience : {candidate['annees_experience']}")
    if candidate.get('competences_extraites'):
        lines.append(f"Compétences extraites : {candidate['competences_extraites']}")
    if candidate.get('texte_extrait'):
        extrait = str(candidate['texte_extrait'])[:2000]
        lines.append(f"Contenu du CV :\n{extrait}")
    if not lines:
        lines.append('Aucune information disponible.')
    return '\n'.join(lines)


def score_with_deepseek(candidate: dict, requete: str, filtre: str) -> tuple[int, str, str, str, str]:
    """Retourne (score, justification, points_forts, points_faibles, competences_detectees)."""
    profile_text = build_candidate_profile(candidate)

    user_message = f"""REQUÊTE DU RECRUTEUR :
{requete}

FILTRE PRIORITAIRE :
{filtre if filtre else 'Aucun filtre spécifique.'}

PROFIL DU CANDIDAT :
{profile_text}

Évalue ce profil et retourne UNIQUEMENT le JSON demandé."""

    payload = {
        'model': DEEPSEEK_MODEL,
        'messages': [
            {'role': 'system', 'content': DEEPSEEK_SYSTEM_PROMPT},
            {'role': 'user',   'content': user_message},
        ],
        'temperature': 0.1,
        'max_tokens':  300,
        'response_format': {'type': 'json_object'},
    }

    headers = {
        'Authorization': f'Bearer {DEEPSEEK_API_KEY}',
        'Content-Type': 'application/json',
    }

    try:
        response = requests.post(DEEPSEEK_API_URL, headers=headers, json=payload, timeout=20)
        response.raise_for_status()
        data   = response.json()
        raw    = data['choices'][0]['message']['content'].strip()
        parsed = json.loads(raw)

        score              = max(0, min(100, int(parsed.get('score', 0))))
        justif             = str(parsed.get('justification', ''))
        points_forts       = str(parsed.get('points_forts', ''))
        points_faibles     = str(parsed.get('points_faibles', ''))
        competences        = str(parsed.get('competences_detectees', ''))

        return score, justif, points_forts, points_faibles, competences

    except requests.exceptions.Timeout:
        return 0, 'Délai dépassé.', '', '', ''
    except requests.exceptions.HTTPError as e:
        return 0, f'Erreur HTTP : {e.response.status_code}.', '', '', ''
    except (KeyError, json.JSONDecodeError, ValueError):
        return 0, 'Réponse IA invalide.', '', '', ''
    except Exception:
        return 0, 'Erreur inattendue.', '', '', ''


# ---------------------------------------------------------------------------
# Fallback lexical
# ---------------------------------------------------------------------------

def build_query_terms(text: str) -> list[str]:
    normalized = normalize_text(text or '')
    terms = [t for t in normalized.split() if len(t) > 2]
    return list(dict.fromkeys(terms))


def compute_score_fallback(candidate: dict, terms: list[str], filtre: str) -> tuple[int, str, str, str, str]:
    content = normalize_text(' '.join([
        str(candidate.get('nom') or ''),
        str(candidate.get('ville') or ''),
        str(candidate.get('competences_extraites') or ''),
        str(candidate.get('texte_extrait') or ''),
    ]))

    if not terms:
        return 0, 'Score par défaut (requête vide).', '', '', ''

    matches = sum(1 for term in terms if term in content)
    score   = max(0, min(100, int(round((matches / len(terms)) * 100))))

    if filtre and filtre.lower() in content:
        score = min(100, score + 10)
    if candidate.get('competences_extraites'):
        score = min(100, score + 5)

    return score, 'Score calculé par méthode lexicale (mode secours).', '', '', ''


# ---------------------------------------------------------------------------
# Scoring d'un candidat (avec cache)
# ---------------------------------------------------------------------------

def score_candidate(candidate: dict, requete: str, filtre: str,
                    use_deepseek: bool, fallback_terms: list,
                    cache: dict) -> dict:

    candidate_id = get_candidate_id(candidate)
    cache_key    = make_cache_key(requete, filtre, candidate_id)

    # Vérifier le cache
    cached = get_from_cache(cache, cache_key)
    if cached:
        score, justification = cached
        print(f'[cache] ⚡ HIT — {candidate.get("nom")} → {score}%')
        return {
            'id':                    candidate.get('id'),
            'nom':                   candidate.get('nom'),
            'email':                 candidate.get('email'),
            'telephone':             candidate.get('telephone'),
            'ville':                 candidate.get('ville'),
            'score':                 score,
            'annees_experience':     int(candidate.get('annees_experience') or 0),
            'competences_extraites': candidate.get('competences_extraites'),
            'cv_fichier':            candidate.get('fichier_stocke'),
            'resume_ia':             justification,
            'points_forts':          cache.get(cache_key, {}).get('points_forts', ''),
            'points_faibles':        cache.get(cache_key, {}).get('points_faibles', ''),
            'competences_detectees': cache.get(cache_key, {}).get('competences_detectees', ''),
            'from_cache':            True,
        }

    # Pas en cache → appel IA
    if use_deepseek:
        score, justif, points_forts, points_faibles, competences = score_with_deepseek(candidate, requete, filtre)
    else:
        score, justif, points_forts, points_faibles, competences = compute_score_fallback(candidate, fallback_terms, filtre)

    # Sauvegarder dans le cache (avec les détails pour la page de détail)
    cache[cache_key] = {
        'score':                score,
        'justification':        justif,
        'points_forts':         points_forts,
        'points_faibles':       points_faibles,
        'competences_detectees': competences,
        'timestamp':            time.time(),
    }

    print(f'[deepseek] 🤖 SCORED — {candidate.get("nom")} → {score}%')

    return {
        'id':                    candidate.get('id'),
        'nom':                   candidate.get('nom'),
        'email':                 candidate.get('email'),
        'telephone':             candidate.get('telephone'),
        'ville':                 candidate.get('ville'),
        'score':                 score,
        'annees_experience':     int(candidate.get('annees_experience') or 0),
        'competences_extraites': candidate.get('competences_extraites'),
        'cv_fichier':            candidate.get('fichier_stocke'),
        'resume_ia':             justif,
        'points_forts':          points_forts,
        'points_faibles':        points_faibles,
        'competences_detectees': competences,
        'from_cache':            False,
    }


# ---------------------------------------------------------------------------
# Scan du dossier local uploads/cvs
# ---------------------------------------------------------------------------

def scan_local_uploads() -> list[dict]:
    results   = []
    supported = {'.pdf', '.docx', '.txt', '.md'}

    if not UPLOAD_DIR.exists():
        print(f'[scan] Dossier introuvable : {UPLOAD_DIR}')
        return results

    for filename in sorted(os.listdir(UPLOAD_DIR)):
        if not filename or filename.startswith('.'):
            continue

        filepath = UPLOAD_DIR / filename
        ext      = Path(filename).suffix.lower()

        if ext not in supported:
            continue

        texte       = extract_text_from_file(filepath)
        nom_affiche = Path(filename).stem.replace('_', ' ').replace('-', ' ').title()

        results.append({
            'id':                    None,
            'nom':                   nom_affiche,
            'email':                 None,
            'telephone':             None,
            'ville':                 None,
            'competences_extraites': texte[:500] if texte else '',
            'annees_experience':     0,
            'fichier_stocke':        filename,
            'texte_extrait':         texte,
        })

        print(f'[scan] ✅ {filename} — {len(texte)} caractères extraits')

    return results


# ---------------------------------------------------------------------------
# Base de données
# ---------------------------------------------------------------------------

def create_db_connection(body: dict):
    host     = body.get('db_host') or os.getenv('DB_HOST', 'localhost')
    port     = body.get('db_port') or os.getenv('DB_PORT', 3306)
    user     = body.get('db_user') or os.getenv('DB_USER', 'root')
    password = body.get('db_pass') or os.getenv('DB_PASS', '')
    db       = body.get('db_name') or os.getenv('DB_NAME')
    if not db:
        return None

    return pymysql.connect(
        host=host, port=int(port), user=user, password=password,
        database=db, cursorclass=pymysql.cursors.DictCursor, charset='utf8mb4',
    )


def fetch_candidates_from_db(connection):
    with connection.cursor() as cursor:
        cursor.execute(
            "SELECT u.id, u.nom, u.email, u.telephone, u.ville, "
            "c.competences_extraites, c.annees_experience, c.fichier_stocke, c.texte_extrait "
            "FROM users u LEFT JOIN cvs c ON c.user_id = u.id WHERE u.role = 'candidat'"
        )
        return cursor.fetchall()


# ---------------------------------------------------------------------------
# Endpoints
# ---------------------------------------------------------------------------

@app.route('/match', methods=['POST'])
def match():
    data    = request.get_json(silent=True) or {}
    requete = (data.get('requete') or '').strip()
    filtre  = (data.get('filtre')  or '').strip()

    if not requete:
        return jsonify({'error': 'Requête manquante.'}), 400

    # Chargement du cache
    cache = load_cache()
    cache = purge_expired_cache(cache)

    # Récupération des candidats
    connection = None
    candidates = []
    try:
        connection = create_db_connection(data)
        if connection:
            candidates = fetch_candidates_from_db(connection)
            candidates += scan_local_uploads()
        else:
            candidates = scan_local_uploads()
    except Exception as exc:
        print(f'[match] Erreur DB : {exc}')
        candidates = scan_local_uploads()
    finally:
        if connection:
            connection.close()

    if not candidates:
        return jsonify({'resultats': [], 'message': 'Aucun CV trouvé.'})

    use_deepseek   = bool(DEEPSEEK_API_KEY)
    fallback_terms = build_query_terms(requete + ' ' + filtre) if not use_deepseek else []

    print(f'[match] 🚀 Scoring de {len(candidates)} candidats ({MAX_WORKERS} threads)...')

    results = []
    with ThreadPoolExecutor(max_workers=MAX_WORKERS) as executor:
        futures = {
            executor.submit(score_candidate, c, requete, filtre, use_deepseek, fallback_terms, cache): c
            for c in candidates
        }
        for future in as_completed(futures):
            try:
                result = future.result()
                if result['score'] >= SCORE_MIN:
                    results.append(result)
            except Exception as e:
                print(f'[match] Erreur scoring : {e}')

    # Sauvegarde du cache mis à jour
    save_cache(cache)

    results.sort(key=lambda item: item['score'], reverse=True)

    cached_count = sum(1 for r in results if r.get('from_cache'))
    print(f'[match] ✔ {len(results)} résultat(s) — {cached_count} depuis le cache.')

    return jsonify({'resultats': results})


@app.route('/chat', methods=['POST'])
def chat():
    return match()


@app.route('/cache/clear', methods=['POST'])
def clear_cache():
    """Vide le cache (utile quand on ajoute de nouveaux CVs)."""
    try:
        if CACHE_FILE.exists():
            CACHE_FILE.unlink()
        return jsonify({'success': True, 'message': 'Cache vidé avec succès.'})
    except Exception as e:
        return jsonify({'success': False, 'error': str(e)}), 500


@app.route('/cache/stats', methods=['GET'])
def cache_stats():
    """Statistiques du cache."""
    cache = load_cache()
    now   = time.time()
    valid = {k: v for k, v in cache.items() if now - v.get('timestamp', 0) <= CACHE_TTL}
    return jsonify({
        'total_entries': len(cache),
        'valid_entries': len(valid),
        'expired':       len(cache) - len(valid),
        'ttl_hours':     CACHE_TTL // 3600,
    })


@app.route('/health', methods=['GET'])
def health():
    cv_count = 0
    if UPLOAD_DIR.exists():
        cv_count = len([f for f in os.listdir(UPLOAD_DIR) if not f.startswith('.')])
    cache = load_cache()
    return jsonify({
        'status':      'ok',
        'deepseek':    bool(DEEPSEEK_API_KEY),
        'pdf':         PDF_OK,
        'docx':        DOCX_OK,
        'upload_dir':  str(UPLOAD_DIR),
        'cv_count':    cv_count,
        'score_min':   SCORE_MIN,
        'workers':     MAX_WORKERS,
        'cache_size':  len(cache),
        'cache_file':  str(CACHE_FILE),
    })


if __name__ == '__main__':
    print(f'📁 Dossier CVs  : {UPLOAD_DIR}')
    print(f'💾 Cache        : {CACHE_FILE}')
    print(f'🤖 DeepSeek     : {"✅ Activé" if DEEPSEEK_API_KEY else "❌ Clé manquante"}')
    print(f'📄 PDF support  : {"✅" if PDF_OK else "❌  →  pip install pdfplumber"}')
    print(f'📝 DOCX support : {"✅" if DOCX_OK else "❌  →  pip install python-docx"}')
    print(f'⚡ Threads      : {MAX_WORKERS}')
    print(f'🎯 Score minimum: {SCORE_MIN}%')
    app.run(host='0.0.0.0', port=int(os.getenv('PORT', 5000)), debug=True)