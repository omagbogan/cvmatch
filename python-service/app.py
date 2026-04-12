from flask import Flask, request, jsonify
import os
import re
import json
import hashlib
import time
import base64
import tempfile
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

try:
    import pytesseract
    from PIL import Image
    OCR_OK = True
except ImportError:
    OCR_OK = False

from dotenv import load_dotenv
load_dotenv()

app = Flask(__name__)
BASE_DIR    = Path(__file__).resolve().parent
PROJECT_DIR = BASE_DIR.parent
UPLOAD_DIR  = Path(os.getenv('CV_UPLOAD_DIR', str(PROJECT_DIR / 'uploads' / 'cvs'))).resolve()
CACHE_FILE  = BASE_DIR / 'cache' / 'scores.json'
CACHE_TTL   = 60 * 60 * 24  # 24 heures

DEEPSEEK_API_KEY = os.getenv('DEEPSEEK_API_KEY', '')
DEEPSEEK_API_URL = 'https://api.deepseek.com/v1/chat/completions'
DEEPSEEK_MODEL   = 'deepseek-chat'

SCORE_MIN   = 0
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
# Extraction de texte
# ---------------------------------------------------------------------------

def extract_text_from_file(filepath: Path) -> str:
    ext = filepath.suffix.lower()
    try:
        if ext == '.pdf' and PDF_OK:
            with pdfplumber.open(filepath) as pdf:
                pages = [page.extract_text() or '' for page in pdf.pages]
            text = '\n'.join(pages).strip()
            # Si le PDF est scanné (pas de texte), essayer l'OCR
            if not text and OCR_OK:
                with pdfplumber.open(filepath) as pdf:
                    images = []
                    for page in pdf.pages:
                        img = page.to_image(resolution=200).original
                        images.append(img)
                ocr_texts = [pytesseract.image_to_string(img, lang='fra+eng') for img in images]
                text = '\n'.join(ocr_texts).strip()
            return text

        if ext == '.docx' and DOCX_OK:
            doc = DocxDocument(filepath)
            return '\n'.join(p.text for p in doc.paragraphs).strip()

        if ext in ('.jpg', '.jpeg', '.png', '.webp', '.bmp', '.tiff') and OCR_OK:
            img = Image.open(filepath)
            text = pytesseract.image_to_string(img, lang='fra+eng')
            return text.strip()

        if ext in ('.txt', '.md'):
            return filepath.read_text(encoding='utf-8', errors='ignore').strip()

    except Exception as e:
        print(f'[extract] Erreur sur {filepath.name} : {e}')
    return ''


def scan_local_uploads() -> list[dict]:
    supported_exts = {'.pdf', '.docx', '.txt', '.md', '.jpg', '.jpeg', '.png', '.webp', '.bmp', '.tiff'}
    candidates = []

    if not UPLOAD_DIR.exists():
        print(f'[uploads] Dossier introuvable : {UPLOAD_DIR}')
        return candidates

    for filepath in sorted(UPLOAD_DIR.iterdir()):
        if not filepath.is_file() or filepath.suffix.lower() not in supported_exts:
            continue

        texte = extract_text_from_file(filepath)
        candidates.append({
            'id': None,
            'nom': filepath.stem.replace('_', ' ').replace('-', ' ').strip() or filepath.name,
            'email': None,
            'telephone': None,
            'ville': None,
            'competences_extraites': None,
            'annees_experience': 0,
            'fichier_stocke': filepath.name,
            'texte_extrait': texte,
        })

    print(f'[uploads] {len(candidates)} CV(s) local(aux) chargé(s) depuis {UPLOAD_DIR}')
    return candidates


def merge_candidates_with_local_uploads(candidates: list[dict], local_candidates: list[dict]) -> list[dict]:
    local_by_file = {
        str(candidate.get('fichier_stocke')): candidate
        for candidate in local_candidates
        if candidate.get('fichier_stocke')
    }

    merged_candidates = []
    for candidate in candidates:
        merged = dict(candidate)
        local_candidate = local_by_file.get(str(candidate.get('fichier_stocke')))
        if local_candidate:
            if not merged.get('texte_extrait') and local_candidate.get('texte_extrait'):
                merged['texte_extrait'] = local_candidate['texte_extrait']
            if not merged.get('competences_extraites') and local_candidate.get('competences_extraites'):
                merged['competences_extraites'] = local_candidate['competences_extraites']
            if not merged.get('nom') and local_candidate.get('nom'):
                merged['nom'] = local_candidate['nom']
        merged_candidates.append(merged)

    existing_files = {
        str(candidate.get('fichier_stocke'))
        for candidate in merged_candidates
        if candidate.get('fichier_stocke')
    }
    for local_candidate in local_candidates:
        if str(local_candidate.get('fichier_stocke')) not in existing_files:
            merged_candidates.append(local_candidate)

    return merged_candidates


# ---------------------------------------------------------------------------
# Normalisation
# ---------------------------------------------------------------------------

def normalize_text(value: str) -> str:
    return ' '.join(re.findall(r"[a-z0-9àâäéèêëîïôùûüç]+", value.lower(), flags=re.IGNORECASE))


# ---------------------------------------------------------------------------
# Prompt DeepSeek amélioré
# ---------------------------------------------------------------------------

DEEPSEEK_SYSTEM_PROMPT = """
Tu es un expert senior en recrutement avec 15 ans d'expérience en analyse de CVs et en matching candidat/poste.

Ta mission : évaluer avec précision la pertinence d'un profil candidat par rapport à une recherche de poste.

Tu reçois :
- La REQUÊTE du recruteur (poste, compétences recherchées, contexte)
- Le FILTRE optionnel (ville, secteur, langue, niveau d'expérience)
- Le PROFIL complet du candidat

INSTRUCTIONS STRICTES :
1. Lis attentivement le contenu du CV avant de scorer
2. Identifie les compétences explicites ET implicites
3. Tiens compte des synonymes et technologies équivalentes :
   - JS = JavaScript = ES6, PG = PostgreSQL, ML = Machine Learning
   - Dev = Développeur = Ingénieur logiciel
4. Un profil junior peut scorer haut si la requête cherche un junior
5. Ne pénalise pas un profil uniquement parce qu'il a trop d'expérience
6. Si le CV est en image ou mal formaté, extrais les informations visibles

Retourne UNIQUEMENT ce JSON valide, sans texte avant ou après :
{
  "score": <entier 0-100>,
  "justification": "<2-3 phrases expliquant précisément le score>",
  "points_forts": "<liste des atouts du profil pour ce poste>",
  "points_faibles": "<lacunes ou éléments manquants>",
  "competences_detectees": "<toutes les compétences identifiées dans le CV>",
  "niveau_experience": "<junior|confirmé|senior|expert>",
  "recommandation": "<fort recommandé|recommandé|à considérer|non recommandé>"
}

BARÈME DE SCORING :
- 90-100 : Profil idéal, correspond à tous les critères essentiels
- 75-89  : Très bon profil, correspondance forte
- 55-74  : Bon profil, correspondance partielle mais solide
- 35-54  : Profil moyen, quelques correspondances
- 15-34  : Profil faible, peu de correspondance
- 0-14   : Profil hors sujet ou données insuffisantes

PÉNALITÉS :
- Filtre géographique non respecté : -10 à -20 points
- CV vide ou illisible : score max 15
- Aucune compétence en lien : score max 25

BONUS :
- Compétences rares très recherchées : +5 à +10 points
- Expérience dans le même secteur d'activité : +5 points
- Multilinguisme pertinent : +3 points
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
        extrait = str(candidate['texte_extrait'])[:3000]
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
        'max_tokens':  400,
        'response_format': {'type': 'json_object'},
    }

    headers = {
        'Authorization': f'Bearer {DEEPSEEK_API_KEY}',
        'Content-Type': 'application/json',
    }

    try:
        response = requests.post(DEEPSEEK_API_URL, headers=headers, json=payload, timeout=30)
        response.raise_for_status()
        data   = response.json()
        raw    = data['choices'][0]['message']['content'].strip()
        parsed = json.loads(raw)

        score          = max(0, min(100, int(parsed.get('score', 0))))
        justif         = str(parsed.get('justification', ''))
        points_forts   = str(parsed.get('points_forts', ''))
        points_faibles = str(parsed.get('points_faibles', ''))
        competences    = str(parsed.get('competences_detectees', ''))
        niveau_exp     = str(parsed.get('niveau_experience', ''))
        recommandation = str(parsed.get('recommandation', ''))

        return score, justif, points_forts, points_faibles, competences, niveau_exp, recommandation

    except Exception as e:
        print(f'[deepseek] Erreur : {e}')
        return 0, 'Erreur IA.', '', '', '', '', ''


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
        return 0, 'Score par défaut (requête vide).', '', '', '', '', ''

    matches = sum(1 for term in terms if term in content)
    score   = max(0, min(100, int(round((matches / len(terms)) * 100))))

    if filtre and filtre.lower() in content:
        score = min(100, score + 10)
    if candidate.get('competences_extraites'):
        score = min(100, score + 5)

    return score, 'Score calculé par méthode lexicale (mode secours).', '', '', '', '', ''


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
        score, justif, points_forts, points_faibles, competences, niveau_exp, recommandation = score_with_deepseek(candidate, requete, filtre)
    else:
        score, justif, points_forts, points_faibles, competences, niveau_exp, recommandation = compute_score_fallback(candidate, fallback_terms, filtre)

    cache[cache_key] = {
        'score':                 score,
        'justification':         justif,
        'points_forts':          points_forts,
        'points_faibles':        points_faibles,
        'competences_detectees': competences,
        'niveau_experience':     niveau_exp,
        'recommandation':        recommandation,
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
        'niveau_experience':     niveau_exp,
        'recommandation':        recommandation,
        'from_cache':            False,
    }


# ---------------------------------------------------------------------------
# Endpoints
# ---------------------------------------------------------------------------

@app.route('/match', methods=['POST'])
def match():
    data             = request.get_json(silent=True) or {}
    requete          = (data.get('requete') or '').strip()
    filtre           = (data.get('filtre')  or '').strip()
    local_candidates = scan_local_uploads()
    candidates       = data.get('candidates', []) or []
    candidates       = merge_candidates_with_local_uploads(candidates, local_candidates)

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


@app.route('/extract', methods=['POST'])
def extract():
    data     = request.get_json(silent=True) or {}
    filename = data.get('filename', '')
    mimetype = data.get('mimetype', '')
    filedata = data.get('filedata', '')

    if not filedata:
        return jsonify({'error': 'Aucun fichier reçu.'}), 400

    try:
        file_bytes = base64.b64decode(filedata)
    except Exception as e:
        return jsonify({'error': f'Décodage base64 échoué : {e}'}), 400

    ext = Path(filename).suffix.lower() if filename else ''
    if not ext:
        if 'pdf' in mimetype:
            ext = '.pdf'
        elif 'word' in mimetype or 'docx' in mimetype:
            ext = '.docx'
        elif 'image' in mimetype or 'jpeg' in mimetype or 'png' in mimetype:
            ext = '.jpg'
        else:
            ext = '.txt'

    texte = ''
    try:
        with tempfile.NamedTemporaryFile(suffix=ext, delete=False) as tmp:
            tmp.write(file_bytes)
            tmp_path = Path(tmp.name)

        texte = extract_text_from_file(tmp_path)
        tmp_path.unlink(missing_ok=True)
    except Exception as e:
        print(f'[extract] Erreur extraction : {e}')

    tech_keywords = [
        'python', 'php', 'javascript', 'java', 'c++', 'c#', 'ruby', 'swift',
        'react', 'vue', 'angular', 'node', 'django', 'flask', 'laravel',
        'mysql', 'postgresql', 'mongodb', 'redis', 'docker', 'kubernetes',
        'git', 'linux', 'aws', 'azure', 'gcp', 'html', 'css', 'sql',
        'tensorflow', 'pytorch', 'machine learning', 'deep learning',
        'excel', 'word', 'powerpoint', 'photoshop', 'illustrator',
        'comptabilité', 'marketing', 'gestion', 'management', 'finance',
        'communication', 'anglais', 'français', 'espagnol', 'typescript',
        'kotlin', 'flutter', 'dart', 'graphql', 'rest', 'api', 'devops',
        'agile', 'scrum', 'figma', 'sketch', 'seo', 'google ads',
    ]

    texte_lower = texte.lower()
    competences_trouvees = [kw for kw in tech_keywords if kw in texte_lower]
    competences_str = ', '.join(competences_trouvees) if competences_trouvees else None

    annees = 0
    exp_matches = re.findall(r'(\d+)\s*(?:an|ans|année|années|year|years)', texte_lower)
    if exp_matches:
        annees = min(int(max(exp_matches, key=int)), 40)

    print(f'[extract] ✅ {filename} — {len(texte)} caractères, compétences: {competences_str}')

    return jsonify({
        'texte':             texte[:5000],
        'competences':       competences_str,
        'annees_experience': annees,
    })


@app.route('/health', methods=['GET'])
def health():
    return jsonify({
        'status':            'ok',
        'deepseek':          bool(DEEPSEEK_API_KEY),
        'pdf':               PDF_OK,
        'docx':              DOCX_OK,
        'ocr':               OCR_OK,
        'upload_dir':        str(UPLOAD_DIR),
        'upload_dir_exists': UPLOAD_DIR.exists(),
        'score_min':         SCORE_MIN,
        'workers':           MAX_WORKERS,
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
    print(f'📁 Upload dir   : {UPLOAD_DIR}')
    print(f'🤖 DeepSeek     : {"✅ Activé" if DEEPSEEK_API_KEY else "❌ Clé manquante — fallback lexical actif"}')
    print(f'📄 PDF support  : {"✅" if PDF_OK else "❌"}')
    print(f'📝 DOCX support : {"✅" if DOCX_OK else "❌"}')
    print(f'🖼️  OCR support  : {"✅" if OCR_OK else "❌"}')
    app.run(host='0.0.0.0', port=int(os.getenv('PORT', 5000)), debug=False)