"""
match_api.py
Microservice Flask de matching CV/recruteur.
Les données (texte, compétences, expérience) sont déjà extraites en base
par cv_extractor.py — ce service les lit et les utilise directement pour scorer.
"""

from flask import Flask, request, jsonify
import os
import re
import json
import hashlib
import time
import requests
from pathlib import Path
from concurrent.futures import ThreadPoolExecutor, as_completed

from dotenv import load_dotenv
load_dotenv()

app = Flask(__name__)

BASE_DIR  = Path(__file__).resolve().parent
CACHE_FILE = BASE_DIR / 'cache' / 'scores.json'
CACHE_TTL  = 60 * 60 * 24  # 24 heures

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
# Normalisation
# ---------------------------------------------------------------------------

def normalize_text(value: str) -> str:
    return ' '.join(re.findall(r"[a-z0-9àâäéèêëîïôùûüç]+", value.lower(), flags=re.IGNORECASE))


# ---------------------------------------------------------------------------
# Prompt DeepSeek
# (Les données sont déjà extraites → on les envoie directement au scoring)
# ---------------------------------------------------------------------------

DEEPSEEK_SYSTEM_PROMPT = """
Tu es un expert senior en recrutement avec 15 ans d'expérience en analyse de CVs et en matching candidat/poste.

Ta mission : évaluer avec RIGUEUR et PRÉCISION la pertinence d'un profil candidat par rapport à une recherche de poste.

Tu reçois :
- La REQUÊTE du recruteur (poste, compétences recherchées, contexte)
- Le FILTRE optionnel (ville, secteur, langue, niveau d'expérience)
- Le PROFIL complet du candidat (compétences et expérience déjà extraits par IA)

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

========================================
ÉTAPE 1 — ANALYSE DE LA REQUÊTE DU RECRUTEUR
========================================
Avant de scorer, tu DOIS extraire et noter en mémoire interne TOUS les critères
explicitement mentionnés dans la requête ET dans le filtre :

A) GENRE
   - Si la requête mentionne explicitement un genre (homme, femme, masculin, féminin,
     monsieur, madame, etc.), ce critère est OBLIGATOIRE et non négociable.
   - Si le genre du candidat ne correspond pas → score plafonné à 20,
     recommandation "non recommandé".
   - Si le genre n'est pas mentionné dans la requête → ignorer ce critère,
     ne pas pénaliser.

B) ÂGE
   - Si la requête mentionne un âge précis, une fourchette d'âge, ou un groupe
     (jeune, senior, moins de X ans, entre X et Y ans, etc.), ce critère est OBLIGATOIRE.
   - Comparer l'âge du candidat (si disponible dans le profil) avec le critère demandé.
   - Âge hors fourchette → pénalité selon l'écart :
     * Écart de 1-3 ans  : -15 points
     * Écart de 4-7 ans  : -25 points
     * Écart de 8 ans+   : -40 points
   - Âge non disponible dans le profil → signaler dans "points_faibles" (-5 points max).
   - Si l'âge n'est pas mentionné dans la requête → ignorer ce critère.

C) VILLE / LOCALISATION
   - Si la requête ou le filtre mentionne une ville, une région ou un pays précis
     (ex: "à Abidjan", "basé à Dakar", "région de Paris", etc.), ce critère est OBLIGATOIRE.
   - Comparer la ville du candidat avec la localisation demandée.
   - Appliquer les pénalités suivantes :
     * Même ville que demandée                        → bonus +10 points
     * Ville différente dans le même pays             → -20 points
     * Pays différent mais continent identique        → -30 points
     * Continent différent                            → -40 points
   - Ville non renseignée dans le profil candidat → signaler dans "points_faibles"
     (-10 points max), ne pas appliquer la pénalité maximale.
   - Si aucune ville n'est mentionnée dans la requête ni dans le filtre → ignorer
     ce critère, ne pas pénaliser.

D) COMPÉTENCES 
   - Lister TOUTES les compétences demandées dans la requête (techniques, outils,
     langues, soft skills).
   - Pour CHAQUE compétence demandée, vérifier si elle est présente dans le profil.
   - Compétence OBLIGATOIRE manquante (indiquée par "impératif", "obligatoire",
     "indispensable") → pénalité -15 points par compétence.
   - Compétence SOUHAITÉE manquante → pénalité -5 points par compétence.
   - Tenir compte des synonymes et équivalences
     (JS = JavaScript, PG = PostgreSQL, ML = Machine Learning, etc.).
   - Si aucune compétence demandée n'est présente → score plafonné à 25.

E) ANNÉES D'EXPÉRIENCE
   - Si la requête mentionne une durée d'expérience (ex: "5 ans d'expérience", ce critère est OBLIGATOIRE.
   - Appliquer les règles suivantes :
     * Expérience candidat < minimum demandé :
       - Écart de 1-2 ans : score plaphonner a 50%
       - Écart de 3-4 ans : disqualifier
       - Écart de 5 ans+  : disqualifier
   - Si aucune durée n'est mentionnée dans la requête → ignorer ce critère.

========================================
ÉTAPE 2 — SCORING DE BASE
========================================
Calculer le score de correspondance globale sur les critères métier :

BARÈME :
- 90-100 : Profil idéal, correspond à tous les critères essentiels
- 75-89  : Très bon profil, correspondance forte
- 55-74  : Bon profil, correspondance partielle mais solide
- 35-54  : Profil moyen, quelques correspondances
- 15-34  : Profil faible, peu de correspondance
- 0-14   : Profil hors sujet ou données insuffisantes

RÈGLES SUPPLÉMENTAIRES :
- Les compétences et années d'expérience ont déjà été extraites automatiquement
  — les utiliser en priorité.
- Utiliser le texte brut du CV comme complément si disponible.
- Un profil junior peut scorer haut si la requête cherche un junior.
- Ne pas pénaliser un profil uniquement parce qu'il a trop d'expérience
  (sauf surqualification explicitement refusée).

========================================
ÉTAPE 3 — APPLICATION DES PÉNALITÉS ET BONUS
========================================
Appliquer dans l'ordre suivant :

PÉNALITÉS :
1.  Genre non correspondant (si demandé)              → score plafonné à 20
2.  Âge hors fourchette (écart 1-3 ans)               → -15 points
3.  Âge hors fourchette (écart 4-7 ans)               → -25 points
4.  Âge hors fourchette (écart 8 ans+)                → -40 points
5.  Ville différente, même pays (si demandée)         → -20 points
6.  Pays différent, même continent (si demandée)      → -30 points
7.  Continent différent (si demandée)                 → -40 points
8.  Compétence obligatoire manquante                  → -15 points par compétence
9.  Compétence souhaitée manquante                    → -5 points par compétence
10. Expérience insuffisante (écart 1-2 ans)           → score plafonné = 50%
11. Expérience insuffisante (écart 3-4 ans)           → disqualifier score = 50%
12. Expérience insuffisante (écart 5 ans+)            → disqualifier score = 50%
13. Aucune compétence en lien                         → score plafonné à 25%

BONUS :
1. Même ville que demandée                            → +10 points
2. Compétences rares très recherchées                 → +5 à +10 points
3. Expérience dans le même secteur d'activité         → +5 points
4. Toutes les compétences obligatoires présentes      → +10 points

Score final = Score de base + Bonus - Pénalités
Score final doit rester entre 0 et 100.

========================================
ÉTAPE 4 — DÉTERMINATION DE LA RECOMMANDATION
========================================
- Score ≥ 75 et aucun critère bloquant (genre)  → "fort recommandé"
- Score 55-74                                    → "recommandé"
- Score 35-54                                    → "à considérer"
- Score < 35 ou genre non correspondant          → "non recommandé"

========================================
RÈGLES GÉNÉRALES
========================================
- Ne JAMAIS inventer des informations absentes du profil candidat.
- Si une information est manquante dans le profil, le signaler dans "points_faibles".
- Être factuel et précis dans la justification.
- Aucun commentaire avant ou après le JSON.
""".strip()


def build_candidate_profile(candidate: dict) -> str:
    """
    Construit le profil textuel du candidat à partir des données déjà extraites en base.
    NB : Plus besoin de parser le fichier CV ici.
    """
    lines = []
    if candidate.get('nom'):
        lines.append(f"Nom : {candidate['nom']}")
    if candidate.get('genre'):
        lines.append(f"Genre : {candidate['genre']}")
    if candidate.get('age') is not None:
        lines.append(f"Âge : {candidate['age']} ans")
    if candidate.get('ville'):
        lines.append(f"Ville : {candidate['ville']}")
    if candidate.get('annees_experience'):
        lines.append(f"Années d'expérience (extrait IA) : {candidate['annees_experience']}")
    if candidate.get('competences_extraites'):
        lines.append(f"Compétences extraites par IA : {candidate['competences_extraites']}")
    if candidate.get('formation'):
        lines.append(f"Formation : {candidate['formation']}")
    # Le texte extrait est utilisé en complément, tronqué pour ne pas surcharger le prompt
    if candidate.get('texte_extrait'):
        extrait = str(candidate['texte_extrait'])[:2000]
        lines.append(f"Extrait du CV :\n{extrait}")
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
    """
    Scoring lexical basé sur les données déjà en base.
    Avantage : utilise competences_extraites qui est plus précis qu'un scan du fichier.
    """
    content = normalize_text(' '.join([
        str(candidate.get('nom') or ''),
        str(candidate.get('ville') or ''),
        str(candidate.get('competences_extraites') or ''),
        str(candidate.get('texte_extrait') or ''),
        str(candidate.get('formation') or ''),
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
        score, justif, points_forts, points_faibles, competences, niveau_exp, recommandation = \
            score_with_deepseek(candidate, requete, filtre)
    else:
        score, justif, points_forts, points_faibles, competences, niveau_exp, recommandation = \
            compute_score_fallback(candidate, fallback_terms, filtre)

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
    """
    Reçoit les candidats depuis api-match.php (avec données déjà extraites de la BDD)
    et retourne les scores de matching.
    NB : scan_local_uploads() supprimé — on ne lit plus les fichiers directement.
    """
    data       = request.get_json(silent=True) or {}
    requete    = (data.get('requete') or '').strip()
    filtre     = (data.get('filtre')  or '').strip()
    candidates = data.get('candidates', []) or []

    if not requete:
        return jsonify({'error': 'Requête manquante.'}), 400

    if not candidates:
        return jsonify({'resultats': [], 'message': 'Aucun candidat reçu.'})

    cache = load_cache()
    cache = purge_expired_cache(cache)

    use_deepseek   = bool(DEEPSEEK_API_KEY)
    fallback_terms = build_query_terms(requete + ' ' + filtre) if not use_deepseek else []

    print(f'[match] 🚀 Scoring de {len(candidates)} candidat(s) depuis la base...')

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
        'service':   'match_api',
        'deepseek':  bool(DEEPSEEK_API_KEY),
        'score_min': SCORE_MIN,
        'workers':   MAX_WORKERS,
        'note':      'Extraction déléguée à cv_extractor.py (port 5001)',
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
    port = int(os.getenv('PORT', 5000))
    print(f'🚀 Match API démarré sur le port {port}')
    print(f'🤖 DeepSeek : {"✅ Activé" if DEEPSEEK_API_KEY else "❌ Clé manquante — fallback lexical actif"}')
    print(f'📝 Extraction CV : déléguée à cv_extractor.py (port 5001)')
    app.run(host='0.0.0.0', port=port, debug=False)