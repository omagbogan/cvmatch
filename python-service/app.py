from flask import Flask, request, jsonify
import os
import re
import json
import hashlib
import time
import requests
from pathlib import Path
from concurrent.futures import ThreadPoolExecutor, as_completed

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
CACHE_FILE = BASE_DIR / 'cache' / 'scores.json'
CACHE_TTL  = 60 * 60 * 24  # 24 heures

DEEPSEEK_API_KEY = os.getenv('DEEPSEEK_API_KEY', '')
DEEPSEEK_API_URL = 'https://api.deepseek.com/v1/chat/completions'
DEEPSEEK_MODEL   = 'deepseek-chat'

SCORE_MIN   = 30
MAX_WORKERS = 10


# ---------------------------------------------------------------------------
# Cache
# ---------------------------------------------------------------------------

def load_cache() -> dict:
    try:
        if CACHE_FILE.exists():
            with open(CACHE_FILE, 'r', encoding='utf-8') as f:
                return json.load(f)
    except Exception as e:
        print(f'[cache] Erreur lecture : {e}')
    return {}


def save_cache(cache: dict) -> None:
    try:
        CACHE_FILE.parent.mkdir(parents=True, exist_ok=True)
        with open(CACHE_FILE, 'w', encoding='utf-8') as f:
            json.dump(cache, f, ensure_ascii=False, indent=2)
    except Exception as e:
        print(f'[cache] Erreur écriture : {e}')


def make_cache_key(requete: str, filtre: str, candidate_id: str) -> str:
    normalized = f"{requete.lower().strip()}|{filtre.lower().strip()}|{candidate_id}"
    return hashlib.md5(normalized.encode('utf-8')).hexdigest()


def get_candidate_id(candidate: dict) -> str:
    if candidate.get('id'):
        return f"db_{candidate['id']}"
    return f"file_{candidate.get('fichier_stocke', 'unknown')}"


def get_from_cache(cache: dict, key: str):
    entry = cache.get(key)
    if not entry:
        return None
    if time.time() - entry.get('timestamp', 0) > CACHE_TTL:
        return None
    return entry['score'], entry['justification']


def purge_expired_cache(cache: dict) -> dict:
    now = time.time()
    return {k: v for k, v in cache.items() if now - v.get('timestamp', 0) <= CACHE_TTL}


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
- 90-100 : Profil quasi parfait
- 70-89  : Très bon profil
- 50-69  : Profil correct
- 30-49  : Profil faible
- 0-29   : Profil hors sujet

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


def score_with_deepseek(candidate: dict, requete: str, filtre: str):
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

        score          = max(0, min(100, int(parsed.get('score', 0))))
        justif         = str(parsed.get('justification', ''))
        points_forts   = str(parsed.get('points_forts', ''))
        points_faibles = str(parsed.get('points_faibles', ''))
        competences    = str(parsed.get('competences_detectees', ''))

        return score, justif, points_forts, points_faibles, competences

    except Exception:
        return 0, 'Erreur IA.', '', '', ''


# ---------------------------------------------------------------------------
# Fallback lexical
# ---------------------------------------------------------------------------

def build_query_terms(text: str) -> list:
    normalized = normalize_text(text or '')
    terms = [t for t in normalized.split() if len(t) > 2]
    return list(dict.fromkeys(terms))


def compute_score_fallback(candidate: dict, terms: list, filtre: str):
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
# Scoring d'un candidat
# ---------------------------------------------------------------------------

def score_candidate(candidate: dict, requete: str, filtre: str,
                    use_deepseek: bool, fallback_terms: list,
                    cache: dict) -> dict:

    candidate_id = get_candidate_id(candidate)
    cache_key    = make_cache_key(requete, filtre, candidate_id)

    cached = get_from_cache(cache, cache_key)
    if cached:
        score, justification = cached
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
            'from_cache':            True,
        }

    if use_deepseek:
        score, justif, points_forts, points_faibles, competences = score_with_deepseek(candidate, requete, filtre)
    else:
        score, justif, points_forts, points_faibles, competences = compute_score_fallback(candidate, fallback_terms, filtre)

    cache[cache_key] = {
        'score':                 score,
        'justification':         justif,
        'points_forts':          points_forts,
        'points_faibles':        points_faibles,
        'competences_detectees': competences,
        'timestamp':             time.time(),
    }

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
# Endpoints
# ---------------------------------------------------------------------------

@app.route('/match', methods=['POST'])
def match():
    data    = request.get_json(silent=True) or {}
    requete = (data.get('requete') or '').strip()
    filtre  = (data.get('filtre')  or '').strip()

    # Candidats envoyés directement par le PHP
    candidates = data.get('candidates', [])

    if not requete:
        return jsonify({'error': 'Requête manquante.'}), 400

    if not candidates:
        return jsonify({'resultats': [], 'message': 'Aucun candidat reçu.'})

    cache = load_cache()
    cache = purge_expired_cache(cache)

    use_deepseek   = bool(DEEPSEEK_API_KEY)
    fallback_terms = build_query_terms(requete + ' ' + filtre) if not use_deepseek else []

    print(f'[match] 🚀 Scoring de {len(candidates)} candidats...')

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

    save_cache(cache)
    results.sort(key=lambda item: item['score'], reverse=True)

    print(f'[match] ✔ {len(results)} résultat(s).')
    return jsonify({'resultats': results})


@app.route('/chat', methods=['POST'])
def chat():
    return match()


@app.route('/health', methods=['GET'])
def health():
    return jsonify({
        'status':    'ok',
        'deepseek':  bool(DEEPSEEK_API_KEY),
        'pdf':       PDF_OK,
        'docx':      DOCX_OK,
        'score_min': SCORE_MIN,
        'workers':   MAX_WORKERS,
    })


@app.route('/cache/clear', methods=['POST'])
def clear_cache():
    try:
        if CACHE_FILE.exists():
            CACHE_FILE.unlink()
        return jsonify({'success': True, 'message': 'Cache vidé.'})
    except Exception as e:
        return jsonify({'success': False, 'error': str(e)}), 500


if __name__ == '__main__':
    print(f'🤖 DeepSeek     : {"✅ Activé" if DEEPSEEK_API_KEY else "❌ Clé manquante — fallback lexical actif"}')
    print(f'📄 PDF support  : {"✅" if PDF_OK else "❌"}')
    print(f'📝 DOCX support : {"✅" if DOCX_OK else "❌"}')
    app.run(host='0.0.0.0', port=int(os.getenv('PORT', 5000)), debug=False)