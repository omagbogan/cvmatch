<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

requireRole('recruteur');
$user  = getCurrentUser();
$db    = getDB();
$flash = getFlash();

// Historique des recherches récentes de ce recruteur
$stmtH = $db->prepare("SELECT * FROM recherches WHERE recruteur_id = ? ORDER BY created_at DESC LIMIT 5");
$stmtH->execute([$user['id']]);
$historique = $stmtH->fetchAll();

// Statistiques globales (pour les admins et recruteurs)
$stmtStats = $db->query("SELECT COUNT(*) as total FROM users WHERE role = 'candidat'");
$totalCandidats = $stmtStats->fetch()['total'];

$stmtStatsCv = $db->query("SELECT COUNT(*) as total FROM cvs");
$totalCvs = $stmtStatsCv->fetch()['total'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CVMatch IA - Dashboard Recruteur</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f5f7fb; color: #1e293b; }
        :root { --primary: #3b82f6; --primary-dark: #2563eb; --secondary: #8b5cf6; --success: #10b981; --warning: #f59e0b; --danger: #ef4444; --gray-50: #f8fafc; --gray-100: #f1f5f9; --gray-200: #e2e8f0; --gray-300: #cbd5e1; --gray-400: #94a3b8; --gray-500: #64748b; --gray-600: #475569; --gray-700: #334155; --gray-800: #1e293b; --radius: 16px; --shadow-sm: 0 1px 2px 0 rgb(0 0 0/0.05); --shadow-md: 0 4px 6px -1px rgb(0 0 0/0.1); }
        .navbar { background: white; box-shadow: var(--shadow-sm); padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center; }
        .logo { font-size: 1.5rem; font-weight: 800; background: linear-gradient(135deg, var(--primary), var(--secondary)); -webkit-background-clip: text; background-clip: text; color: transparent; text-decoration: none; }
        .logo span { background: none; color: var(--gray-800); }
        .user-info { display: flex; align-items: center; gap: 1rem; }
        .user-avatar { width: 40px; height: 40px; background: linear-gradient(135deg, var(--primary), var(--secondary)); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; font-size: .85rem; }
        .container { max-width: 1200px; margin: 0 auto; padding: 2rem; }
        .card { background: white; border-radius: var(--radius); box-shadow: var(--shadow-sm); margin-bottom: 1.5rem; }
        .card-header { padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--gray-200); display: flex; justify-content: space-between; align-items: center; }
        .card-header h2 { font-size: 1.1rem; font-weight: 700; }
        .card-body { padding: 1.5rem; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .stat-card { background: white; border-radius: 12px; padding: 1.25rem; box-shadow: var(--shadow-sm); text-align: center; }
        .stat-number { font-size: 2rem; font-weight: 800; }
        .stat-label  { font-size: .8rem; color: var(--gray-500); margin-top: .25rem; }
        .search-input { width: 100%; padding: .875rem 1.25rem; border: 1px solid var(--gray-200); border-radius: 12px; font-size: .875rem; font-family: inherit; transition: border-color .2s; }
        .search-input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(59,130,246,.1); }
        .search-btn { padding: .875rem 1.75rem; background: var(--primary); color: white; border: none; border-radius: 12px; font-weight: 600; cursor: pointer; font-family: inherit; font-size: .875rem; white-space: nowrap; transition: background .2s; }
        .search-btn:hover { background: var(--primary-dark); }
        .search-btn:disabled { opacity: .6; cursor: not-allowed; }
        .filters-bar { display: flex; gap: .75rem; flex-wrap: wrap; margin-top: 1rem; }
        .filter-tag { padding: .5rem 1rem; background: var(--gray-100); border-radius: 30px; font-size: .75rem; cursor: pointer; border: none; font-family: inherit; font-weight: 500; transition: all .2s; }
        .filter-tag.active { background: var(--primary); color: white; }
        .candidate-card { background: white; border-radius: var(--radius); padding: 1.25rem; margin-bottom: 1rem; display: flex; gap: 1.25rem; border: 1px solid var(--gray-100); transition: all .2s; }
        .candidate-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
        .candidate-avatar { width: 70px; height: 70px; background: linear-gradient(135deg, var(--primary), var(--secondary)); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; font-size: 1.25rem; flex-shrink: 0; }
        .candidate-info { flex: 1; }
        .candidate-name { font-size: 1.1rem; font-weight: 700; }
        .match-score { display: inline-block; padding: .25rem .75rem; border-radius: 30px; font-size: .75rem; font-weight: 700; margin-left: .5rem; }
        .score-high   { background: #d1fae5; color: #065f46; }
        .score-medium { background: #fed7aa; color: #9a3412; }
        .score-low    { background: #fee2e2; color: #991b1b; }
        .skills-list { display: flex; flex-wrap: wrap; gap: .5rem; margin: .75rem 0; }
        .skill-tag { background: var(--gray-100); padding: .25rem .75rem; border-radius: 20px; font-size: .7rem; font-weight: 500; color: var(--gray-700); }
        .ai-summary { background: var(--gray-50); padding: .75rem 1rem; border-radius: 10px; font-size: .8rem; border-left: 3px solid var(--primary); margin: .75rem 0; color: var(--gray-700); line-height: 1.5; }
        .btn { padding: .5rem 1rem; border-radius: 8px; font-weight: 600; font-size: .75rem; cursor: pointer; border: none; font-family: inherit; transition: all .2s; text-decoration: none; display: inline-block; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-dark); }
        .btn-outline { background: transparent; border: 1px solid var(--gray-300); color: var(--gray-700); }
        .btn-outline:hover { border-color: var(--primary); color: var(--primary); }
        .btn-success { background: var(--success); color: white; }
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,.5); z-index: 1000; justify-content: center; align-items: center; }
        .modal-overlay.open { display: flex; }
        .modal-content { background: white; border-radius: var(--radius); max-width: 520px; width: 90%; box-shadow: 0 25px 50px rgba(0,0,0,.15); }
        .modal-header { padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--gray-200); display: flex; justify-content: space-between; align-items: center; }
        .modal-header h3 { font-size: 1rem; font-weight: 700; }
        .modal-body { padding: 1.5rem; }
        .modal-footer { padding: 1.25rem 1.5rem; border-top: 1px solid var(--gray-200); display: flex; justify-content: flex-end; gap: .75rem; }
        .modal-body label { display: block; margin-bottom: .5rem; font-weight: 500; font-size: .875rem; }
        .modal-body input, .modal-body textarea { width: 100%; padding: .75rem; border: 1px solid var(--gray-200); border-radius: 10px; font-family: inherit; font-size: .875rem; margin-bottom: 1rem; }
        .modal-body input:focus, .modal-body textarea:focus { outline: none; border-color: var(--primary); }
        .spinner { display: inline-block; width: 18px; height: 18px; border: 2px solid rgba(255,255,255,.3); border-top-color: white; border-radius: 50%; animation: spin 0.8s linear infinite; vertical-align: middle; margin-right: .5rem; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .empty-state { text-align: center; padding: 3rem; color: var(--gray-500); }
        .empty-state i { font-size: 3rem; color: var(--gray-300); margin-bottom: 1rem; display: block; }
        .ia-chat { background: var(--gray-50); border-radius: 12px; padding: 1rem; margin-top: 1rem; display: none; }
        .ia-chat.visible { display: block; }
        .chat-messages { max-height: 200px; overflow-y: auto; margin-bottom: 1rem; }
        .chat-msg { padding: .75rem; border-radius: 10px; margin-bottom: .5rem; font-size: .875rem; }
        .chat-msg.user { background: var(--primary); color: white; text-align: right; }
        .chat-msg.ia   { background: white; border: 1px solid var(--gray-200); }
        .chat-input-row { display: flex; gap: .5rem; }
        .chat-input { flex: 1; padding: .75rem; border: 1px solid var(--gray-200); border-radius: 10px; font-family: inherit; font-size: .875rem; }
        @media (max-width: 768px) { .candidate-card { flex-direction: column; } .container { padding: 1rem; } }
    </style>
</head>
<body>

<nav class="navbar">
    <a href="index.php" class="logo">CV<span>Match</span> IA</a>
    <div class="user-info">
        <div class="user-avatar"><?= getInitiales($user['nom']) ?></div>
        <span style="font-weight:500;font-size:.875rem;"><?= clean($user['nom']) ?></span>
        <a href="logout.php" class="btn btn-outline">Déconnexion</a>
    </div>
</nav>

<div class="container">

    <?php if ($flash): ?>
        <div class="alert" style="padding:.875rem 1rem;border-radius:10px;margin-bottom:1rem;font-size:.875rem;background:<?= $flash['type']==='success'?'#d1fae5':'#fee2e2' ?>;color:<?= $flash['type']==='success'?'#065f46':'#dc2626' ?>;">
            <?= clean($flash['message']) ?>
        </div>
    <?php endif; ?>

    <!-- Stats rapides -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-number" style="color:var(--primary);"><?= $totalCandidats ?></div>
            <div class="stat-label">Candidats inscrits</div>
        </div>
        <div class="stat-card">
            <div class="stat-number" style="color:var(--success);"><?= $totalCvs ?></div>
            <div class="stat-label">CV disponibles</div>
        </div>
        <div class="stat-card">
            <div class="stat-number" style="color:var(--secondary);"><?= count($historique) ?></div>
            <div class="stat-label">Recherches récentes</div>
        </div>
    </div>

    <!-- Barre de recherche IA -->
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-robot" style="color:var(--primary);margin-right:.5rem;"></i> Recherche IA</h2>
        </div>
        <div class="card-body">
            <div style="display:flex; gap:1rem; flex-wrap:wrap;">
                <input type="text" id="searchQuery" class="search-input"
                       placeholder="Ex : Développeur PHP avec 2 ans d'expérience à Abidjan, bonne connaissance MySQL"
                       style="flex:1; min-width:280px;">
                <button class="search-btn" id="searchBtn" onclick="rechercher()">
                    <i class="fas fa-robot"></i> Analyser avec l'IA
                </button>
            </div>

            <!-- Filtres rapides -->
            <div class="filters-bar">
                <button class="filter-tag active" onclick="setFilter(this, '')">Tous</button>
                <button class="filter-tag" onclick="setFilter(this, 'high')">Score > 75%</button>
                <button class="filter-tag" onclick="setFilter(this, 'abidjan')">Abidjan</button>
                <button class="filter-tag" onclick="setFilter(this, 'exp5')">Exp. > 5 ans</button>
            </div>

            <!-- Suggestions rapides -->
            <div style="margin-top:.75rem; font-size:.8rem; color:var(--gray-500);">
                <strong>Suggestions :</strong>
                <span class="suggestion" onclick="useSuggestion(this)" style="cursor:pointer;color:var(--primary);margin-left:.5rem;">Développeur web PHP MySQL</span> ·
                <span class="suggestion" onclick="useSuggestion(this)" style="cursor:pointer;color:var(--primary);">Designer UI/UX junior</span> ·
                <span class="suggestion" onclick="useSuggestion(this)" style="cursor:pointer;color:var(--primary);">Data Analyst PowerBI</span>
            </div>
        </div>
    </div>

    <!-- Résultats -->
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
        <span id="resultsCount" style="font-size:.875rem;color:var(--gray-500);font-weight:500;">
            Lancez une recherche pour voir les candidats
        </span>
        <select id="sortSelect" onchange="trierResultats()" style="padding:.5rem .75rem;border:1px solid var(--gray-200);border-radius:8px;font-family:inherit;font-size:.8rem;" disabled>
            <option value="score">Trier par score</option>
            <option value="name">Trier par nom</option>
            <option value="experience">Par expérience</option>
        </select>
    </div>

    <div id="resultsContainer">
        <div class="empty-state">
            <i class="fas fa-search"></i>
            <p>Entrez une description du profil recherché et cliquez sur "Analyser avec l'IA"</p>
        </div>
    </div>

    <!-- Agent IA conversationnel -->
    <div class="ia-chat" id="iaChat">
        <div style="font-weight:600;font-size:.875rem;margin-bottom:.75rem;">
            <i class="fas fa-robot" style="color:var(--primary);"></i> Agent IA — Affinez votre recherche
        </div>
        <div class="chat-messages" id="chatMessages">
            <div class="chat-msg ia">Bonjour ! Vous pouvez me demander d'affiner les résultats. Par exemple : "Montre-moi seulement les femmes", "Ceux avec plus de 3 ans d'expérience", "Uniquement les profils d'Abidjan"...</div>
        </div>
        <div class="chat-input-row">
            <input type="text" id="chatInput" class="chat-input" placeholder="Affinez votre recherche..." onkeypress="if(event.key==='Enter')envoyerChat()">
            <button class="btn btn-primary" onclick="envoyerChat()"><i class="fas fa-paper-plane"></i></button>
        </div>
    </div>
</div>

<!-- Modal Contact -->
<div id="contactModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-envelope" style="color:var(--primary);margin-right:.5rem;"></i> Contacter le candidat</h3>
            <button onclick="closeModal()" style="background:none;border:none;font-size:1.25rem;cursor:pointer;color:var(--gray-500);">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="contactCandidatId">
            <label>Destinataire</label>
            <input type="text" id="contactDestinataire" readonly style="background:var(--gray-50);">
            <label>Objet</label>
            <input type="text" id="contactObjet" placeholder="Opportunité d'emploi - CVMatch IA">
            <label>Message</label>
            <textarea id="contactMessage" rows="5" placeholder="Bonjour, votre profil correspond à nos recherches..."></textarea>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal()">Annuler</button>
            <button class="btn btn-primary" onclick="envoyerContact()"><i class="fas fa-paper-plane"></i> Envoyer</button>
        </div>
    </div>
</div>

<script>
// ============================================
// Variables globales
// ============================================
let allResults = [];
let currentFilter = '';

// ============================================
// Recherche IA via API PHP
// ============================================
async function rechercher() {
    const query = document.getElementById('searchQuery').value.trim();
    if (!query) {
        alert('Veuillez entrer une requête de recherche.');
        return;
    }

    const btn = document.getElementById('searchBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Analyse en cours...';
    document.getElementById('resultsContainer').innerHTML = '<div class="empty-state"><span class="spinner" style="border-color:rgba(59,130,246,.3);border-top-color:var(--primary);"></span><p style="margin-top:1rem;">L\'IA analyse les CV...</p></div>';

    try {
        const response = await fetch('api-match.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ requete: query })
        });

        const data = await response.json();

        if (data.error) {
            afficherErreur(data.error);
        } else {
            allResults = data.resultats || [];
            afficherResultats(allResults);
            document.getElementById('sortSelect').disabled = false;

            // Afficher l'agent IA si des résultats
            if (allResults.length > 0) {
                document.getElementById('iaChat').classList.add('visible');
            }
        }
    } catch (err) {
        afficherErreur('Erreur de communication avec le service IA. Vérifiez que le microservice Python est actif.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-robot"></i> Analyser avec l\'IA';
    }
}

// ============================================
// Affichage des résultats
// ============================================
function afficherResultats(resultats) {
    const container = document.getElementById('resultsContainer');
    const count = document.getElementById('resultsCount');

    if (!resultats || resultats.length === 0) {
        container.innerHTML = '<div class="empty-state"><i class="fas fa-user-slash"></i><p>Aucun candidat trouvé pour cette recherche.<br>Essayez des termes plus généraux.</p></div>';
        count.textContent = '0 candidat trouvé';
        return;
    }

    count.textContent = resultats.length + ' candidat' + (resultats.length > 1 ? 's' : '') + ' trouvé' + (resultats.length > 1 ? 's' : '');

    container.innerHTML = resultats.map(c => `
        <div class="candidate-card" data-score="${c.score}" data-ville="${(c.ville||'').toLowerCase()}" data-experience="${c.annees_experience||0}">
            <div class="candidate-avatar">${getInitiales(c.nom)}</div>
            <div class="candidate-info">
                <div style="display:flex;align-items:center;flex-wrap:wrap;gap:.5rem;">
                    <span class="candidate-name">${escapeHtml(c.nom)}</span>
                    <span class="match-score ${scoreClass(c.score)}">${c.score}% match</span>
                    ${c.ville ? `<span style="font-size:.75rem;color:var(--gray-500);"><i class="fas fa-map-marker-alt"></i> ${escapeHtml(c.ville)}</span>` : ''}
                </div>
                <div style="font-size:.8rem;color:var(--gray-500);margin:.25rem 0;">
                    ${c.email} · ${c.telephone || 'Tel non renseigné'}
                    ${c.annees_experience ? ` · ${c.annees_experience} an(s) d'expérience` : ''}
                </div>

                ${c.competences_extraites ? `
                <div class="skills-list">
                    ${c.competences_extraites.split(',').slice(0,6).map(s => `<span class="skill-tag">${escapeHtml(s.trim())}</span>`).join('')}
                </div>` : ''}

                ${c.resume_ia ? `<div class="ai-summary"><i class="fas fa-robot" style="color:var(--primary);margin-right:.4rem;"></i>${escapeHtml(c.resume_ia)}</div>` : ''}

                <div style="display:flex;gap:.5rem;margin-top:.75rem;flex-wrap:wrap;">
                    ${c.cv_fichier ? `<a href="uploads/cvs/${escapeHtml(c.cv_fichier)}" target="_blank" class="btn btn-outline"><i class="fas fa-file-alt"></i> Voir CV</a>` : '<span class="btn btn-outline" style="opacity:.5;cursor:default;">Pas de CV</span>'}
                    <button class="btn btn-primary" onclick="openModal(${c.id}, '${escapeHtml(c.nom)}', '${escapeHtml(c.email)}')">
                        <i class="fas fa-envelope"></i> Contacter
                    </button>
                </div>
            </div>
        </div>
    `).join('');
}

function afficherErreur(msg) {
    document.getElementById('resultsContainer').innerHTML = `
        <div class="empty-state">
            <i class="fas fa-exclamation-triangle" style="color:#ef4444;"></i>
            <p style="color:#ef4444;">${escapeHtml(msg)}</p>
        </div>`;
    document.getElementById('resultsCount').textContent = 'Erreur';
}

// ============================================
// Filtres
// ============================================
function setFilter(el, filter) {
    currentFilter = filter;
    document.querySelectorAll('.filter-tag').forEach(t => t.classList.remove('active'));
    el.classList.add('active');
    appliquerFiltre();
}

function appliquerFiltre() {
    let filtered = [...allResults];
    if (currentFilter === 'high')    filtered = filtered.filter(c => c.score >= 75);
    if (currentFilter === 'abidjan') filtered = filtered.filter(c => (c.ville||'').toLowerCase().includes('abidjan'));
    if (currentFilter === 'exp5')    filtered = filtered.filter(c => (c.annees_experience||0) >= 5);
    afficherResultats(filtered);
}

function trierResultats() {
    const sort = document.getElementById('sortSelect').value;
    const sorted = [...allResults].sort((a, b) => {
        if (sort === 'score')      return b.score - a.score;
        if (sort === 'name')       return a.nom.localeCompare(b.nom);
        if (sort === 'experience') return (b.annees_experience||0) - (a.annees_experience||0);
        return 0;
    });
    afficherResultats(sorted);
}

// ============================================
// Agent IA conversationnel
// ============================================
async function envoyerChat() {
    const input = document.getElementById('chatInput');
    const msg = input.value.trim();
    if (!msg) return;

    ajouterMessage(msg, 'user');
    input.value = '';

    const query = document.getElementById('searchQuery').value.trim();

    try {
        const response = await fetch('api-match.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ requete: query, filtre: msg, mode: 'chat' })
        });
        const data = await response.json();

        if (data.resultats) {
            allResults = data.resultats;
            afficherResultats(allResults);
            ajouterMessage(`J'ai trouvé ${data.resultats.length} profil(s) correspondant à votre affinage.`, 'ia');
        }
        if (data.message) {
            ajouterMessage(data.message, 'ia');
        }
    } catch (e) {
        ajouterMessage('Erreur de communication avec le service IA.', 'ia');
    }
}

function ajouterMessage(texte, type) {
    const container = document.getElementById('chatMessages');
    const div = document.createElement('div');
    div.className = `chat-msg ${type}`;
    div.textContent = texte;
    container.appendChild(div);
    container.scrollTop = container.scrollHeight;
}

// ============================================
// Modal Contact
// ============================================
function openModal(candidatId, nom, email) {
    document.getElementById('contactCandidatId').value = candidatId;
    document.getElementById('contactDestinataire').value = `${nom} <${email}>`;
    document.getElementById('contactObjet').value = 'Opportunité d\'emploi - CVMatch IA';
    document.getElementById('contactMessage').value = `Bonjour ${nom},\n\nVotre profil a retenu notre attention lors d'une recherche sur CVMatch IA. Nous serions ravis d'échanger avec vous.\n\nCordialement,\n${<?= json_encode($user['nom']) ?>}`;
    document.getElementById('contactModal').classList.add('open');
}

function closeModal() {
    document.getElementById('contactModal').classList.remove('open');
}

async function envoyerContact() {
    const candidatId = document.getElementById('contactCandidatId').value;
    const objet      = document.getElementById('contactObjet').value.trim();
    const message    = document.getElementById('contactMessage').value.trim();

    if (!objet || !message) { alert('Veuillez remplir l\'objet et le message.'); return; }

    try {
        const response = await fetch('api-contact.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ candidat_id: candidatId, objet, message })
        });
        const data = await response.json();

        if (data.success) {
            closeModal();
            alert('Message envoyé avec succès ! (Simulé — voir logs/emails.log)');
        } else {
            alert('Erreur : ' + (data.error || 'Envoi échoué.'));
        }
    } catch (e) {
        alert('Erreur de communication.');
    }
}

// ============================================
// Utilitaires
// ============================================
function getInitiales(nom) {
    return nom.split(' ').slice(0,2).map(p => p[0]?.toUpperCase() || '').join('');
}

function scoreClass(score) {
    if (score >= 75) return 'score-high';
    if (score >= 50) return 'score-medium';
    return 'score-low';
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = String(str || '');
    return div.innerHTML;
}

function useSuggestion(el) {
    document.getElementById('searchQuery').value = el.textContent;
}

// Fermer modal en cliquant dehors
document.getElementById('contactModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

// Recherche au clic sur Entrée
document.getElementById('searchQuery').addEventListener('keypress', e => {
    if (e.key === 'Enter') rechercher();
});
</script>
</body>
</html>
