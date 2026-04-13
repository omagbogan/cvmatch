"""
CVMatch IA — Agent conversationnel DeepSeek
Microservice Flask sur le port 5001
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
        return None  # Fallback local si pas de clé

    payload = {
        "model": "deepseek-chat",
        "messages": [{"role": "system", "content": system_prompt}] + messages if system_prompt else messages,
        "temperature": 0.4,
        "max_tokens": 600,
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
            timeout=20,
        )
        resp.raise_for_status()
        data = resp.json()
        return data["choices"][0]["message"]["content"].strip()
    except Exception as e:
        print(f"[DEEPSEEK ERROR] {e}")
        return None


# ============================================================
# Filtrage intelligent local (fallback)
# ============================================================
def filtrer_candidats_local(candidats: list, instruction: str) -> tuple[list, str]:
    """Filtre les candidats localement si DeepSeek est indisponible."""
    m = instruction.lower()
    filtered = candidats[:]
    message = ""

    # Filtre par score
    score_match = re.search(r'(\d+)\s*%', m)
    if score_match:
        seuil = int(score_match.group(1))
        filtered = [c for c in filtered if (c.get("score") or 0) >= seuil]
        message = f"{len(filtered)} candidat(s) avec un score ≥ {seuil}%."

    # Filtre par ville
    elif "abidjan" in m:
        filtered = [c for c in filtered if "abidjan" in (c.get("ville") or "").lower()]
        message = f"{len(filtered)} candidat(s) basé(s) à Abidjan."
    elif "bouaké" in m or "bouake" in m:
        filtered = [c for c in filtered if "bouak" in (c.get("ville") or "").lower()]
        message = f"{len(filtered)} candidat(s) basé(s) à Bouaké."

    # Filtre par expérience
    elif "exp" in m or "ans" in m or "année" in m:
        ans_match = re.search(r'(\d+)\s*an', m)
        ans = int(ans_match.group(1)) if ans_match else 3
        filtered = [c for c in filtered if (c.get("annees_experience") or 0) >= ans]
        message = f"{len(filtered)} candidat(s) avec ≥ {ans} an(s) d'expérience."

    # Tri par score
    elif "trier" in m or "meilleur" in m or "top" in m:
        filtered = sorted(filtered, key=lambda c: c.get("score") or 0, reverse=True)
        message = f"Candidats triés par score décroissant ({len(filtered)} profil(s))."

    # Filtre compétence
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
# Route principale : /chat
# ============================================================
@app.route("/chat", methods=["POST"])
def chat():
    try:
        body = request.get_json(force=True)
    except Exception:
        return jsonify({"error": "JSON invalide"}), 400

    instruction     = body.get("message", "").strip()
    requete_initiale = body.get("requete_initiale", "")
    candidats_actuels = body.get("candidats", [])  # résultats déjà affichés côté frontend
    historique      = body.get("historique", [])   # [{role, content}, ...]

    if not instruction:
        return jsonify({"error": "Message vide"}), 400

    # Si le frontend envoie les candidats actuels, on les utilise
    # Sinon on recharge depuis la BDD
    if not candidats_actuels:
        candidats_actuels = get_candidats_from_db()

    # ---- Tentative DeepSeek ----
    if DEEPSEEK_API_KEY:
        # Résumé des candidats pour le contexte IA
        resume_candidats = "\n".join([
            f"- {c.get('nom','?')} | score: {c.get('score') or 0}% | ville: {c.get('ville') or '?'} | "
            f"exp: {c.get('annees_experience') or 0} ans | compétences: {c.get('competences_extraites') or '?'}"
            for c in candidats_actuels[:30]  # max 30 pour le contexte
        ])

        system_prompt = f"""Tu es un assistant de recrutement intelligent pour la plateforme CVMatch IA.
Tu aides les recruteurs à affiner leurs recherches de candidats.

Recherche initiale du recruteur : "{requete_initiale}"

Candidats actuellement affichés ({len(candidats_actuels)} profils) :
{resume_candidats}

Ton rôle :
1. Comprendre la demande d'affinement du recruteur (filtrer par score, ville, expérience, compétence, genre, etc.)
2. Retourner une réponse JSON UNIQUEMENT avec ce format :
{{
  "message": "Ta réponse en français (1-2 phrases max)",
  "filtres": {{
    "score_min": null,
    "ville": null,
    "exp_min": null,
    "competence": null,
    "trier_par": null
  }}
}}

Exemples de filtres :
- "Score > 80%" → filtres.score_min = 80
- "Basé à Abidjan" → filtres.ville = "abidjan"
- "+3 ans d'expérience" → filtres.exp_min = 3
- "Trier par score" → filtres.trier_par = "score"
- "Compétences PHP" → filtres.competence = "php"

Réponds UNIQUEMENT en JSON valide, sans markdown ni backticks."""

        messages_ds = historique + [{"role": "user", "content": instruction}]
        reponse_brute = call_deepseek(messages_ds, system_prompt)

        if reponse_brute:
            try:
                # Nettoyer la réponse si elle contient des backticks
                reponse_brute = re.sub(r'```json|```', '', reponse_brute).strip()
                parsed = json.loads(reponse_brute)
                filtres = parsed.get("filtres", {})
                message_ia = parsed.get("message", "Voici les résultats affinés.")

                # Appliquer les filtres
                candidats_filtres = candidats_actuels[:]

                if filtres.get("score_min") is not None:
                    seuil = int(filtres["score_min"])
                    candidats_filtres = [c for c in candidats_filtres if (c.get("score") or 0) >= seuil]

                if filtres.get("ville"):
                    v = filtres["ville"].lower()
                    candidats_filtres = [c for c in candidats_filtres if v in (c.get("ville") or "").lower()]

                if filtres.get("exp_min") is not None:
                    ans = int(filtres["exp_min"])
                    candidats_filtres = [c for c in candidats_filtres if (c.get("annees_experience") or 0) >= ans]

                if filtres.get("competence"):
                    comp = filtres["competence"].lower()
                    candidats_filtres = [c for c in candidats_filtres if comp in (c.get("competences_extraites") or "").lower()]

                if filtres.get("trier_par") == "score":
                    candidats_filtres = sorted(candidats_filtres, key=lambda c: c.get("score") or 0, reverse=True)
                elif filtres.get("trier_par") == "experience":
                    candidats_filtres = sorted(candidats_filtres, key=lambda c: c.get("annees_experience") or 0, reverse=True)

                return jsonify({
                    "message": message_ia,
                    "resultats": candidats_filtres,
                    "count": len(candidats_filtres),
                    "source": "deepseek"
                })

            except (json.JSONDecodeError, KeyError, ValueError) as e:
                print(f"[PARSE ERROR] {e} — réponse brute: {reponse_brute}")
                # Continuer vers le fallback local

    # ---- Fallback local ----
    candidats_filtres, message = filtrer_candidats_local(candidats_actuels, instruction)
    return jsonify({
        "message": message,
        "resultats": candidats_filtres,
        "count": len(candidats_filtres),
        "source": "local"
    })


# ============================================================
# Route santé
# ============================================================
@app.route("/health", methods=["GET"])
def health():
    return jsonify({
        "status": "ok",
        "service": "cvmatch-agent",
        "deepseek": "configured" if DEEPSEEK_API_KEY else "missing"
    })


# ============================================================
# Lancement
# ============================================================
if __name__ == "__main__":
    port = int(os.getenv("AGENT_PORT", 5001))
    print(f"[CVMatch Agent] Démarrage sur le port {port}")
    print(f"[CVMatch Agent] DeepSeek: {'✓ configuré' if DEEPSEEK_API_KEY else '✗ clé manquante (fallback local actif)'}")
    app.run(host="0.0.0.0", port=port, debug=False)