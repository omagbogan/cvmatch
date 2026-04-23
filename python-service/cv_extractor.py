"""
cv_extractor.py
Microservice Flask dédié à l'extraction d'informations d'un CV via DeepSeek.
Appelé par upload-cv.php après chaque upload de CV.

Endpoint : POST /extract-cv
  - Reçoit : { filename, mimetype, filedata (base64) }
  - Retourne : { texte, competences, annees_experience, formation, success }
"""

from flask import Flask, request, jsonify
import os
import re
import json
import base64
import tempfile
import requests
from pathlib import Path

# Extraction de texte locale
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

import pytesseract
pytesseract.pytesseract.tesseract_cmd = r'C:\Program Files\Tesseract-OCR\tesseract.exe'

app = Flask(__name__)

DEEPSEEK_API_KEY = os.getenv('DEEPSEEK_API_KEY', '')
DEEPSEEK_API_URL = 'https://api.deepseek.com/v1/chat/completions'
DEEPSEEK_MODEL   = 'deepseek-chat'

# ---------------------------------------------------------------------------
# Extraction de texte brut depuis le fichier
# ---------------------------------------------------------------------------

def extract_text_from_file(filepath: Path) -> str:
    """Extrait le texte brut d'un fichier CV (PDF, DOCX, image)."""
    ext = filepath.suffix.lower()
    try:
        if ext == '.pdf' and PDF_OK:
            with pdfplumber.open(filepath) as pdf:
                pages = [page.extract_text() or '' for page in pdf.pages]
            text = '\n'.join(pages).strip()
            # PDF scanné → OCR
            if not text and OCR_OK:
                with pdfplumber.open(filepath) as pdf:
                    images = [page.to_image(resolution=200).original for page in pdf.pages]
                text = '\n'.join(pytesseract.image_to_string(img, lang='fra+eng') for img in images).strip()
            return text

        if ext == '.docx' and DOCX_OK:
            doc = DocxDocument(filepath)
            return '\n'.join(p.text for p in doc.paragraphs).strip()

        if ext in ('.jpg', '.jpeg', '.png', '.webp', '.bmp', '.tiff') and OCR_OK:
            img = Image.open(filepath)
            return pytesseract.image_to_string(img, lang='fra+eng').strip()

        if ext in ('.txt', '.md'):
            return filepath.read_text(encoding='utf-8', errors='ignore').strip()

    except Exception as e:
        print(f'[cv_extractor] Erreur extraction texte : {e}')
    return ''


# ---------------------------------------------------------------------------
# Extraction IA via DeepSeek
# ---------------------------------------------------------------------------

EXTRACTION_SYSTEM_PROMPT = """
Tu es un expert en analyse de CV avec un regard critique de Responsable RH senior. Tu reçois le texte brut d'un CV et tu dois en extraire les informations structurées de manière FACTUELLE et RAISONNÉE.

Retourne UNIQUEMENT ce JSON valide, sans texte avant ou après :
{
  "competences": "<chaîne de caractères listant les compétences explicites, séparées par des virgules>",
  "annees_experience": <nombre entier représentant les années d'expérience professionnelle effective>,
  "formation": "<chaîne de caractères résumant le parcours académique le plus élevé>"
}

========================================
SECTION 1 : RÈGLES POUR "competences"
========================================
1. EXTRAIRE UNIQUEMENT ce qui est écrit textuellement ou très faiblement paraphrasé.
2. Ne jamais inventer une technologie ou un outil non mentionné.
   - Exemple : si le CV dit "Maîtrise des logiciels bureautiques", écrire "Logiciels bureautiques" et NON "Word, Excel, PowerPoint".
   - Exemple : si le CV dit "Connaissance des langages de programmation" sans les lister, écrire "Langages de programmation (non spécifiés)".
3. Inclure les langues étrangères mentionnées.
4. Inclure les soft skills (qualités personnelles) si elles sont listées dans une section dédiée.
5. Format de sortie : chaîne unique avec séparateur virgule. Exemple : "Gestion de projet, Anglais, Rigoureux, Pack Office"

========================================
SECTION 2 : RÈGLES POUR "annees_experience"
========================================
OBJECTIF : Additionner la durée de CHAQUE poste listé. Aucun poste ne doit être omis.

ÉTAPES OBLIGATOIRES — Tu DOIS les suivre dans l'ordre :

ÉTAPE 1 : Lister TOUS les postes présents dans la section expérience.
          Ne jamais en ignorer un, même s'il semble ancien ou secondaire.

ÉTAPE 2 : Pour chaque poste, calculer sa durée individuellement.
  - Date de fin "Présent" / "En cours" / "Actuel" / vide → utiliser 2026 comme année de fin.
  - Mois + année précisés → calculer au mois près (ex: Février 2017 - Mars 2026 = 9 ans 1 mois = 9.1 ans).
  - Année seule sans mois → compter 1 an max pour une plage "AAAA-AAAA", 0.5 an pour une année isolée.

ÉTAPE 3 : Additionner TOUTES les durées calculées à l'étape 2 pour obtenir le total brut.

ÉTAPE 4 : Détecter les TROUS CHRONOLOGIQUES.
  - Si un écart entre deux postes dépasse 2 ans sans justification (reprise d'études, congé mentionné),
    NE PAS compter cet écart comme expérience.
  - Additionner uniquement les périodes réellement travaillées.

ÉTAPE 5 : Appliquer les pondérations de PERTINENCE par rapport au métier cible du CV.
  - Poste en adéquation totale avec le métier cible → facteur 1.0 (100%)
  - Poste générique ou faiblement lié au domaine → facteur 0.5 (50%)
  - Poste clairement hors domaine → facteur 0.0 (0%)

ÉTAPE 6 : Arrondir le résultat final à l'entier INFÉRIEUR (ex: 12.5 → 12, 1.8 → 1).

EXEMPLE OBLIGATOIRE À REPRODUIRE :
CV avec 2 postes :
  - Développeur Full Stack | Février 2017 - Présent (2026) → 9 ans 1 mois → 9.1 ans × 1.0 = 9.1
  - Administrateur Système  | Août 2013 - Janvier 2017    → 3 ans 5 mois → 3.4 ans × 1.0 = 3.4
  Total brut : 9.1 + 3.4 = 12.5 → arrondi inférieur → annees_experience: 12

INTERDICTIONS ABSOLUES :
- Ne JAMAIS ignorer un poste listé dans la section expérience du CV.
- Ne JAMAIS retourner uniquement la durée du poste le plus récent.
- Ne JAMAIS inventer des postes absents du CV.
- Ne JAMAIS retourner une valeur négative.

========================================
SECTION 3 : RÈGLES POUR "formation"
========================================
1. Extraire le DIPLÔME LE PLUS ÉLEVÉ uniquement.
2. Hiérarchie académique standard : Doctorat > Master > Licence/Bachelor > BTS/DUT > Baccalauréat.
3. Format de sortie idéal : "Niveau - Spécialité - Établissement (Année d'obtention)"
4. Si l'année d'obtention est absente, ne pas en inventer.
5. Si plusieurs formations de même niveau, prendre la plus récente.
6. Si aucune formation n'est mentionnée, retourner null.

========================================
SECTION 4 : RÈGLES GÉNÉRALES
========================================
- Si une information est ABSENTE ou INDÉTERMINABLE, retourner null pour les chaînes et 0 pour les entiers.
- Ne JAMAIS inventer, extrapoler ou compléter des données manquantes.
- Si un champ contient plusieurs éléments, les concaténer dans une chaîne unique avec virgules.
- Aucun commentaire avant ou après le JSON.

========================================
EXEMPLES DE BONNE EXTRACTION (Pour calibration)
========================================
Exemple 1 - CV Technique avec 2 postes :
Input : "Développeur Full Stack | Fév 2017 - Présent. Administrateur Système | Août 2013 - Jan 2017. Master MIAGE 2012. Compétences : Langages de programmation, Bases de données, Anglais courant."
Calcul : (9.1 × 1.0) + (3.4 × 1.0) = 12.5 → 12
Output : {"competences": "Langages de programmation, Bases de données, Anglais courant", "annees_experience": 12, "formation": "Master - MIAGE - (2012)"}

Exemple 2 - CV Flou et Vague :
Input : "Informaticien. 2020 : Stage. 2024 : Employé polyvalent. BTS Info."
Calcul : 0.5 (stage) + 0.5 (polyvalent × 0.5) = 0.75 → 0
Output : {"competences": "Informatique générale", "annees_experience": 0, "formation": "BTS - Informatique"}

Exemple 3 - Aucune donnée exploitable :
Input : "Je cherche du travail. J'aime l'informatique."
Output : {"competences": null, "annees_experience": 0, "formation": null}
""".strip()


def extract_with_deepseek(texte: str) -> dict:
    """Envoie le texte du CV à DeepSeek et retourne les données extraites."""
    if not DEEPSEEK_API_KEY:
        print('[cv_extractor] Clé DeepSeek manquante, fallback lexical.')
        return extract_with_fallback(texte)

    payload = {
        'model': DEEPSEEK_MODEL,
        'messages': [
            {'role': 'system', 'content': EXTRACTION_SYSTEM_PROMPT},
            {'role': 'user', 'content': f"Voici le texte du CV à analyser :\n\n{texte[:4000]}"},
        ],
        'temperature': 0.1,
        'max_tokens': 500,
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

        return {
            'competences':       parsed.get('competences') or None,
            'annees_experience': max(0, min(50, int(parsed.get('annees_experience') or 0))),
            'formation':         parsed.get('formation') or None,
        }

    except Exception as e:
        print(f'[cv_extractor] Erreur DeepSeek : {e}')
        return extract_with_fallback(texte)


# ---------------------------------------------------------------------------
# Fallback lexical (si DeepSeek indisponible)
# ---------------------------------------------------------------------------

TECH_KEYWORDS = [
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


def extract_with_fallback(texte: str) -> dict:
    """Extraction lexicale si DeepSeek est indisponible."""
    texte_lower = texte.lower()

    competences_trouvees = [kw for kw in TECH_KEYWORDS if kw in texte_lower]
    competences_str = ', '.join(competences_trouvees) if competences_trouvees else None

    annees = 0
    exp_matches = re.findall(r'(\d+)\s*(?:an|ans|année|années|year|years)', texte_lower)
    if exp_matches:
        annees = min(int(max(exp_matches, key=int)), 50)

    return {
        'competences':       competences_str,
        'annees_experience': annees,
        'formation':         None,
    }


# ---------------------------------------------------------------------------
# Endpoint principal
# ---------------------------------------------------------------------------

@app.route('/extract-cv', methods=['POST'])
def extract_cv():
    """
    Reçoit un CV en base64, extrait le texte et les données IA.
    Payload attendu :
      { "filename": "cv.pdf", "mimetype": "application/pdf", "filedata": "<base64>" }
    """
    data     = request.get_json(silent=True) or {}
    filename = data.get('filename', '')
    mimetype = data.get('mimetype', '')
    filedata = data.get('filedata', '')

    if not filedata:
        return jsonify({'success': False, 'error': 'Aucun fichier reçu.'}), 400

    # Décodage base64
    try:
        file_bytes = base64.b64decode(filedata)
    except Exception as e:
        return jsonify({'success': False, 'error': f'Décodage base64 échoué : {e}'}), 400

    # Détermination de l'extension
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

    # Extraction du texte
    texte = ''
    try:
        with tempfile.NamedTemporaryFile(suffix=ext, delete=False) as tmp:
            tmp.write(file_bytes)
            tmp_path = Path(tmp.name)

        texte = extract_text_from_file(tmp_path)
        tmp_path.unlink(missing_ok=True)
    except Exception as e:
        print(f'[cv_extractor] Erreur lecture fichier temp : {e}')

    if not texte:
        return jsonify({
            'success':           True,
            'texte':             '',
            'competences':       None,
            'annees_experience': 0,
            'formation':         None,
            'warning':           'Texte non extractible (fichier vide ou illisible).',
        })

    # Extraction IA des données structurées
    extracted = extract_with_deepseek(texte)

    print(f'[cv_extractor] ✅ {filename} — {len(texte)} chars | '
          f'compétences: {extracted["competences"]} | '
          f'expérience: {extracted["annees_experience"]} an(s)')

    return jsonify({
        'success':           True,
        'texte':             texte[:8000],  # Limité pour la BDD
        'competences':       extracted['competences'],
        'annees_experience': extracted['annees_experience'],
        'formation':         extracted['formation'],
    })


# ---------------------------------------------------------------------------
# Health check
# ---------------------------------------------------------------------------

@app.route('/health', methods=['GET'])
def health():
    return jsonify({
        'status':    'ok',
        'service':   'cv_extractor',
        'deepseek':  bool(DEEPSEEK_API_KEY),
        'pdf':       PDF_OK,
        'docx':      DOCX_OK,
        'ocr':       OCR_OK,
    })


if __name__ == '__main__':
    port = int(os.getenv('EXTRACTOR_PORT', 5001))
    print(f'🚀 CV Extractor démarré sur le port {port}')
    print(f'🤖 DeepSeek : {"✅ Activé" if DEEPSEEK_API_KEY else "❌ Clé manquante — fallback lexical actif"}')
    print(f'📄 PDF : {"✅" if PDF_OK else "❌"} | DOCX : {"✅" if DOCX_OK else "❌"} | OCR : {"✅" if OCR_OK else "❌"}')
    app.run(host='0.0.0.0', port=port, debug=False)