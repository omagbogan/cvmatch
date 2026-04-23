"""
CVMatch IA — Agent conversationnel DeepSeek
Microservice Flask sur le port 5002

Rôles de l'agent :
  1. Reformulation de requête brute → critères structurés
  2. Explication de score (pourquoi 68% et pas 90%)
  3. Comparaison fine entre deux profils proches
  4. Suggestion d'assouplissement si zéro résultats
  5. Synthèse narrative de shortlist
"""

from flask import Flask, request, jsonify
from flask_cors import CORS
import os
import json
import requests
import mysql.connector
from mysql.connector import Error
import re
from dotenv import load_dotenv

load_dotenv()

app = Flask(__name__)
CORS(app)

# ============================================================
# Configuration
# ============================================================
DEEPSEEK_API_KEY = os.getenv("DEEPSEEK_API_KEY", "")
DEEPSEEK_API_URL = os.getenv("DEEPSEEK_API_URL", "https://api.deepseek.com")

DB_CONFIG = {
    "host":     os.getenv("DB_HOST", "localhost"),
    "user":     os.getenv("DB_USER", "root"),
    "password": os.getenv("DB_PASS", ""),
    "database": os.getenv("DB_NAME", "cvmatch"),
    "charset":  "utf8mb4",
}

# ============================================================
# Connexion base de données
# ============================================================
def get_db():
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        return conn
    except Error as e:
        print(f"[DB ERROR] {e}")
        return None


def get_candidats_from_db():
    """Récupère tous les candidats avec leurs CVs depuis la BDD."""
    conn = get_db()
    if not conn:
        return []
    try:
        cursor = conn.cursor(dictionary=True)
        cursor.execute("""
            SELECT
                u.id,
                u.nom,
                u.email,
                u.telephone,
                u.ville,
                c.fichier        AS cv_fichier,
                c.competences    AS competences_extraites,
                c.annees_experience,
                c.resume_ia,
                c.score_dernier  AS score
            FROM users u
            LEFT JOIN cvs c ON c.user_id = u.id
            WHERE u.role = 'candidat'
            ORDER BY u.id
        """)
        rows = cursor.fetchall()
        return rows
    except Error as e:
        print(f"[DB QUERY ERROR] {e}")
        return []
    finally:
        cursor.close()
        conn.close()


# ============================================================
# Appel DeepSeek
# ============================================================
def call_deepseek(messages: list, system_prompt: str = "") -> str:
    """Appelle l'API DeepSeek et retourne le texte de réponse."""
    if not DEEPSEEK_API_KEY:
        return None

    payload = {
        "model": "deepseek-chat",
        "messages": [{"role": "system", "content": system_prompt}] + messages if system_prompt else messages,
        "temperature": 0.4,
        "max_tokens": 800,
    }
    headers = {
        "Authorization": f"Bearer {DEEPSEEK_API_KEY}",
        "Content-Type": "application/json",
    }
    try:
        resp = requests.post(
            f"{DEEPSEEK_API_URL}/v1/chat/completions",
            headers=headers,
            json=payload,
            timeout=25,
        )
        resp.raise_for_status()
        data = resp.json()
        return data["choices"][0]["message"]["content"].strip()
    except Exception as e:
        print(f"[DEEPSEEK ERROR] {e}")
        return None


# ============================================================
# Détection de l'intention de l'agent
# ============================================================
def detecter_intention(message: str) -> str:
    """
    Détecte l'intention dominante du message recruteur.
    Retourne : 'explication' | 'comparaison' | 'synthese' | 'reformulation' | 'filtrage'
    """
    m = message.lower()

    if any(k in m for k in ["pourquoi", "comment se fait", "explique", "justifie", "quel score", "c'est quoi"]):
        return "explication"

    if any(k in m for k in ["compare", "comparaison", "différence entre", "lequel", "meilleur entre", "vs", "versus"]):
        return "comparaison"

    if any(k in m for k in ["résumé", "synthèse", "synthese", "récapitule", "recap", "top candidat", "shortlist", "présente"]):
        return "synthese"

    if any(k in m for k in ["cherche", "trouve", "recherche", "besoin de", "je veux"]):
        return "reformulation"

    return "filtrage"


# ============================================================
# Fallback local (si DeepSeek indisponible)
# ============================================================
def filtrer_candidats_local(candidats: list, instruction: str) -> tuple[list, str]:
    """Filtre les candidats localement si DeepSeek est indisponible."""
    m = instruction.lower()
    filtered = candidats[:]
    message = ""

    score_match = re.search(r'(\d+)\s*%', m)
    if score_match:
        seuil = int(score_match.group(1))
        filtered = [c for c in filtered if (c.get("score") or 0) >= seuil]
        message = f"{len(filtered)} candidat(s) avec un score ≥ {seuil}%."

    elif "abidjan" in m:
        filtered = [c for c in filtered if "abidjan" in (c.get("ville") or "").lower()]
        message = f"{len(filtered)} candidat(s) basé(s) à Abidjan."

    elif "bouaké" in m or "bouake" in m:
        filtered = [c for c in filtered if "bouak" in (c.get("ville") or "").lower()]
        message = f"{len(filtered)} candidat(s) basé(s) à Bouaké."

    elif "exp" in m or "ans" in m or "année" in m:
        ans_match = re.search(r'(\d+)\s*an', m)
        ans = int(ans_match.group(1)) if ans_match else 3
        filtered = [c for c in filtered if (c.get("annees_experience") or 0) >= ans]
        message = f"{len(filtered)} candidat(s) avec ≥ {ans} an(s) d'expérience."

    elif "trier" in m or "meilleur" in m or "top" in m:
        filtered = sorted(filtered, key=lambda c: c.get("score") or 0, reverse=True)
        message = f"Candidats triés par score décroissant ({len(filtered)} profil(s))."

    else:
        mots = [w for w in m.split() if len(w) > 3]
        if mots:
            filtered = [
                c for c in filtered
                if any(mot in (c.get("competences_extraites") or "").lower() for mot in mots)
            ]
            message = f"{len(filtered)} candidat(s) correspondent à votre critère."
        else:
            message = "Je n'ai pas compris ce filtre. Essayez : \"Score > 80%\", \"+3 ans d'expérience\", \"Basé à Abidjan\"."

    return filtered, message


# ============================================================
# Construction du system prompt selon l'intention
# ============================================================
def build_system_prompt(intention: str, requete_initiale: str, candidats: list) -> str:
    """Construit le system prompt adapté à l'intention détectée."""

    nb = len(candidats)
    top5 = candidats[:5]

    resume_top5 = "\n".join([
        f"  - {c.get('nom','?')} | score: {c.get('score') or 0}% | ville: {c.get('ville') or '?'} | "
        f"exp: {c.get('annees_experience') or 0} ans | compétences: {(c.get('competences_extraites') or '')[:120]}"
        for c in top5
    ])

    resume_tous = "\n".join([
        f"  - {c.get('nom','?')} | score: {c.get('score') or 0}% | ville: {c.get('ville') or '?'} | "
        f"exp: {c.get('annees_experience') or 0} ans | compétences: {(c.get('competences_extraites') or '')[:80]}"
        for c in candidats[:30]
    ])

    base = f"""Tu es un consultant senior en recrutement qui assiste un recruteur sur la plateforme CVMatch IA.
La recherche initiale du recruteur était : "{requete_initiale}"
Il y a actuellement {nb} candidat(s) dans les résultats.
"""

    if intention == "explication":
        return base + f"""
TON RÔLE : Expliquer précisément pourquoi un ou plusieurs candidats ont leur score.
Identifie les pénalités appliquées (localisation, expérience manquante, compétences absentes)
et les bonus obtenus (compétences rares, ville exacte, expérience exacte).
Sois factuel, précis, et utilise les données disponibles.

Top 5 candidats affichés :
{resume_top5}

Réponds en français, en 3-5 phrases claires. Pas de JSON. Langage naturel de consultant.
"""

    elif intention == "comparaison":
        return base + f"""
TON RÔLE : Comparer deux profils ou aider le recruteur à arbitrer entre des candidats proches.
Analyse les différences concrètes : score, localisation, expérience, compétences clés.
Formule une recommandation claire avec justification.

Top 5 candidats affichés :
{resume_top5}

Réponds en français, de manière structurée (profil A vs profil B), sans JSON. 4-6 phrases max.
"""

    elif intention == "synthese":
        return base + f"""
TON RÔLE : Produire une synthèse narrative de la shortlist pour le recruteur.
Présente les meilleurs profils de manière concise et actionnable.
Indique pour chacun : point fort principal, point faible principal, et ton avis de consultant.

Top 5 candidats affichés :
{resume_top5}

Réponds en français. Format narratif, pas de JSON. Style rapport de présélection. Max 150 mots.
"""

    elif intention == "reformulation":
        return base + f"""
TON RÔLE : Reformuler la demande du recruteur en critères structurés exploitables par le moteur de matching,
puis retourner un JSON avec les critères extraits ET un message d'accompagnement.

Retourne UNIQUEMENT ce JSON valide :
{{
  "message": "Confirmation en 1 phrase de ce que tu as compris",
  "criteres_reformules": {{
    "poste": null,
    "competences_obligatoires": [],
    "competences_souhaitees": [],
    "annees_experience_min": null,
    "ville": null,
    "genre": null,
    "niveau": null
  }},
  "filtres": {{
    "score_min": null,
    "ville": null,
    "exp_min": null,
    "competence": null,
    "trier_par": "score"
  }}
}}
"""

    else:  # filtrage standard
        return base + f"""
TON RÔLE : Comprendre la demande d'affinement du recruteur et appliquer les filtres appropriés.
Si les résultats sont vides ou très peu nombreux, suggère des assouplissements concrets.

Candidats disponibles ({nb} profils) :
{resume_tous}

Retourne UNIQUEMENT ce JSON valide :
{{
  "message": "Réponse en 1-2 phrases (inclure le nombre de résultats et un conseil si pertinent)",
  "suggestion_assouplissement": null,
  "filtres": {{
    "score_min": null,
    "ville": null,
    "exp_min": null,
    "competence": null,
    "trier_par": null
  }}
}}

Si après filtrage il resterait 0 résultats, mets dans "suggestion_assouplissement" une phrase
conseillant quoi assouplir (ex: "Aucun profil à Abidjan avec 5 ans. Essayez 3 ans ou incluez Yamoussoukro.").
Réponds UNIQUEMENT en JSON valide, sans markdown ni backticks.
"""


# ============================================================
# Application des filtres sur les candidats
# ============================================================
def appliquer_filtres(candidats: list, filtres: dict) -> list:
    """Applique les filtres extraits par DeepSeek sur la liste des candidats."""
    result = candidats[:]

    if filtres.get("score_min") is not None:
        seuil = int(filtres["score_min"])
        result = [c for c in result if (c.get("score") or 0) >= seuil]

    if filtres.get("ville"):
        v = filtres["ville"].lower()
        result = [c for c in result if v in (c.get("ville") or "").lower()]

    if filtres.get("exp_min") is not None:
        ans = int(filtres["exp_min"])
        result = [c for c in result if (c.get("annees_experience") or 0) >= ans]

    if filtres.get("competence"):
        comp = filtres["competence"].lower()
        result = [c for c in result if comp in (c.get("competences_extraites") or "").lower()]

    if filtres.get("trier_par") == "score":
        result = sorted(result, key=lambda c: c.get("score") or 0, reverse=True)
    elif filtres.get("trier_par") == "experience":
        result = sorted(result, key=lambda c: c.get("annees_experience") or 0, reverse=True)

    return result


# ============================================================
# Route principale : /chat
# ============================================================
@app.route("/chat", methods=["POST"])
def chat():
    try:
        body = request.get_json(force=True)
    except Exception:
        return jsonify({"error": "JSON invalide"}), 400

    instruction       = body.get("message", "").strip()
    requete_initiale  = body.get("requete_initiale", "")
    candidats_actuels = body.get("candidats", [])
    historique        = body.get("historique", [])

    if not instruction:
        return jsonify({"error": "Message vide"}), 400

    if not candidats_actuels:
        candidats_actuels = get_candidats_from_db()

    # Détecter l'intention
    intention = detecter_intention(instruction)

    # ---- Tentative DeepSeek ----
    if DEEPSEEK_API_KEY:
        system_prompt = build_system_prompt(intention, requete_initiale, candidats_actuels)
        messages_ds   = historique + [{"role": "user", "content": instruction}]
        reponse_brute = call_deepseek(messages_ds, system_prompt)

        if reponse_brute:

            # --- Intentions narratives : réponse texte directe ---
            if intention in ("explication", "comparaison", "synthese"):
                return jsonify({
                    "message":   reponse_brute,
                    "resultats": candidats_actuels,
                    "count":     len(candidats_actuels),
                    "intention": intention,
                    "source":    "deepseek"
                })

            # --- Intentions avec JSON attendu ---
            try:
                reponse_clean = re.sub(r'```json|```', '', reponse_brute).strip()
                parsed = json.loads(reponse_clean)

                filtres    = parsed.get("filtres", {})
                message_ia = parsed.get("message", "Voici les résultats affinés.")
                suggestion = parsed.get("suggestion_assouplissement")
                criteres   = parsed.get("criteres_reformules")

                candidats_filtres = appliquer_filtres(candidats_actuels, filtres)

                # Si 0 résultats et pas de suggestion → en générer une basique
                if len(candidats_filtres) == 0 and not suggestion:
                    suggestion = (
                        "Aucun candidat ne correspond à ces critères stricts. "
                        "Essayez d'assouplir la localisation, de réduire l'expérience minimum, "
                        "ou de rendre une compétence souhaitée plutôt qu'obligatoire."
                    )

                response_data = {
                    "message":   message_ia,
                    "resultats": candidats_filtres,
                    "count":     len(candidats_filtres),
                    "intention": intention,
                    "source":    "deepseek",
                }
                if suggestion:
                    response_data["suggestion_assouplissement"] = suggestion
                if criteres:
                    response_data["criteres_reformules"] = criteres

                return jsonify(response_data)

            except (json.JSONDecodeError, KeyError, ValueError) as e:
                print(f"[PARSE ERROR] {e} — réponse brute: {reponse_brute}")
                # Réponse narrative de secours
                return jsonify({
                    "message":   reponse_brute,
                    "resultats": candidats_actuels,
                    "count":     len(candidats_actuels),
                    "intention": intention,
                    "source":    "deepseek_narratif"
                })

    # ---- Fallback local ----
    candidats_filtres, message = filtrer_candidats_local(candidats_actuels, instruction)
    return jsonify({
        "message":   message,
        "resultats": candidats_filtres,
        "count":     len(candidats_filtres),
        "intention": "filtrage",
        "source":    "local"
    })


# ============================================================
# Route santé
# ============================================================
@app.route("/health", methods=["GET"])
def health():
    return jsonify({
        "status":    "ok",
        "service":   "cvmatch-agent",
        "deepseek":  "configured" if DEEPSEEK_API_KEY else "missing",
        "modes":     ["explication", "comparaison", "synthese", "reformulation", "filtrage"]
    })


# ============================================================
# Lancement
# ============================================================
if __name__ == "__main__":
    port = int(os.getenv("AGENT_PORT", 5002))
    print(f"[CVMatch Agent] Démarrage sur le port {port}")
    print(f"[CVMatch Agent] DeepSeek: {'✓ configuré' if DEEPSEEK_API_KEY else '✗ clé manquante (fallback local actif)'}")
    app.run(host="0.0.0.0", port=port, debug=False)