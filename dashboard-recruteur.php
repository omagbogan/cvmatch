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

        /* ====== CHAT FLOTTANT IA ====== */
        #chatFloatBtn {
            position: fixed; bottom: 2rem; right: 2rem;
            width: 60px; height: 60px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 50%; border: none; cursor: pointer;
            box-shadow: 0 8px 25px rgba(59,130,246,.4);
            display: flex; align-items: center; justify-content: center;
            color: white; font-size: 1.4rem; z-index: 9999;
            transition: transform .2s, box-shadow .2s;
        }
        #chatFloatBtn:hover { transform: scale(1.1); box-shadow: 0 12px 30px rgba(59,130,246,.5); }
        #chatFloatBtn .badge {
            position: absolute; top: -4px; right: -4px;
            background: #ef4444; color: white; border-radius: 50%;
            width: 20px; height: 20px; font-size: .65rem;
            display: none; align-items: center; justify-content: center; font-weight: 700;
        }
        #chatFloatWindow {
            position: fixed; bottom: 6rem; right: 2rem;
            width: 380px; max-height: 520px;
            background: white; border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,.15);
            display: none; flex-direction: column; z-index: 9998;
            overflow: hidden; border: 1px solid var(--gray-200);
        }
        #chatFloatWindow.open { display: flex; }
        .chat-float-header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            padding: 1rem 1.25rem; color: white;
            display: flex; align-items: center; justify-content: space-between;
        }
        .agent-info { display: flex; align-items: center; gap: .75rem; }
        .agent-avatar {
            width: 36px; height: 36px; background: rgba(255,255,255,.2);
            border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1rem;
        }
        .agent-name { font-weight: 700; font-size: .95rem; }
        .agent-status { font-size: .72rem; opacity: .85; }
        .chat-float-close { background: none; border: none; color: white; cursor: pointer; font-size: 1.1rem; opacity: .8; }
        .chat-float-close:hover { opacity: 1; }
        .chat-float-messages {
            flex: 1; overflow-y: auto; padding: 1rem;
            display: flex; flex-direction: column; gap: .75rem; background: var(--gray-50);
        }
        .float-msg { display: flex; gap: .5rem; align-items: flex-end; }
        .float-msg.user { flex-direction: row-reverse; }
        .float-msg-avatar {
            width: 28px; height: 28px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: .7rem; font-weight: 700; flex-shrink: 0;
        }
        .float-msg.ia .float-msg-avatar { background: linear-gradient(135deg, var(--primary), var(--secondary)); color: white; }
        .float-msg.user .float-msg-avatar { background: var(--gray-200); color: var(--gray-700); }
        .float-msg-bubble {
            max-width: 75%; padding: .65rem .9rem;
            border-radius: 14px; font-size: .82rem; line-height: 1.5;
        }
        .float-msg.ia .float-msg-bubble { background: white; border: 1px solid var(--gray-200); color: var(--gray-800); border-bottom-left-radius: 4px; }
        .float-msg.user .float-msg-bubble { background: var(--primary); color: white; border-bottom-right-radius: 4px; }
        .typing-indicator { display: flex; gap: 4px; padding: .65rem .9rem; }
        .typing-indicator span { width: 7px; height: 7px; background: var(--gray-400); border-radius: 50%; animation: bounce 1.2s infinite; }
        .typing-indicator span:nth-child(2) { animation-delay: .2s; }
        .typing-indicator span:nth-child(3) { animation-delay: .4s; }
        @keyframes bounce { 0%,60%,100% { transform: translateY(0); } 30% { transform: translateY(-6px); } }
        .chat-float-suggestions {
            padding: .5rem 1rem; display: flex; gap: .4rem; flex-wrap: wrap;
            border-top: 1px solid var(--gray-100); background: white;
        }
        .chat-suggestion-chip {
            padding: .3rem .7rem; background: var(--gray-100);
            border-radius: 20px; font-size: .7rem; cursor: pointer;
            border: none; font-family: inherit; color: var(--gray-700); transition: all .15s;
        }
        .chat-suggestion-chip:hover { background: var(--primary); color: white; }
        .chat-float-footer {
            padding: .75rem 1rem; border-top: 1px solid var(--gray-200);
            display: flex; gap: .5rem; background: white;
        }
        .chat-float-input {
            flex: 1; padding: .65rem .9rem;
            border: 1px solid var(--gray-200); border-radius: 20px;
            font-family: inherit; font-size: .82rem; transition: border-color .2s;
        }
        .chat-float-input:focus { outline: none; border-color: var(--primary); }
        .chat-float-send {
            width: 36px; height: 36px; background: var(--primary);
            border: none; border-radius: 50%; color: white; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            font-size: .85rem; transition: background .2s; flex-shrink: 0;
        }
        .chat-float-send:hover { background: var(--primary-dark); }
        .chat-float-send:disabled { opacity: .5; cursor: not-allowed; }

        @media (max-width: 768px) {
            .candidate-card { flex-direction: column; }
            .container { padding: 1rem; }
            #chatFloatWindow { width: calc(100vw - 2rem); right: 1rem; }
        }
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
            <div id="analysisEstimate" style="margin-top:.75rem;font-size:.82rem;color:var(--gray-500);">
                Estimation du temps d'analyse : environ 50 seconde(s).
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

<!-- ====== BOUTON CHAT FLOTTANT ====== -->
<button id="chatFloatBtn" onclick="toggleChatFloat()" title="Agent IA - Affinez votre recherche">
    <i class="fas fa-robot"></i>
    <span class="badge" id="chatBadge">1</span>
</button>

<!-- ====== FENÊTRE CHAT FLOTTANT ====== -->
<div id="chatFloatWindow">
    <div class="chat-float-header">
        <div class="agent-info">
            <div class="agent-avatar">🤖</div>
            <div>
                <div class="agent-name">Agent IA CVMatch</div>
                <div class="agent-status">● En ligne · Propulsé par DeepSeek</div>
            </div>
        </div>
        <button class="chat-float-close" onclick="toggleChatFloat()">✕</button>
    </div>

    <div class="chat-float-messages" id="floatMessages">
        <div class="float-msg ia">
            <div class="float-msg-avatar">🤖</div>
            <div class="float-msg-bubble">
                Bonjour ! Je suis votre assistant de recrutement IA.<br><br>
                Faites d'abord une recherche, puis demandez-moi d'affiner. Par exemple :<br>
                <em>"Seulement ceux avec +3 ans d'expérience"</em><br>
                <em>"Score supérieur à 80%"</em><br>
                <em>"Basé à Abidjan"</em>
            </div>
        </div>
    </div>

    <div class="chat-float-suggestions">
        <button class="chat-suggestion-chip" onclick="useChip(this)">Score > 80%</button>
        <button class="chat-suggestion-chip" onclick="useChip(this)">+5 ans d'exp.</button>
        <button class="chat-suggestion-chip" onclick="useChip(this)">Basé à Abidjan</button>
        <button class="chat-suggestion-chip" onclick="useChip(this)">Trier par score</button>
    </div>

    <div class="chat-float-footer">
        <input type="text" id="floatInput" class="chat-float-input"
            placeholder="Affinez votre recherche..."
            onkeypress="if(event.key==='Enter')envoyerFloatChat()">
        <button class="chat-float-send" id="floatSendBtn" onclick="envoyerFloatChat()">
            <i class="fas fa-paper-plane"></i>
        </button>
    </div>
</div>

<script>
// ============================================
// Variables globales
// ============================================
let allResults    = [];
let currentFilter = '';
let floatHistorique = [];
const totalCvCount = <?= (int) $totalCvs ?>;

// ============================================
// Recherche IA via API PHP
// ============================================
async function rechercher() {
    const query = document.getElementById('searchQuery').value.trim();
    if (!query) { alert('Veuillez entrer une requête de recherche.'); return; }

    const btn = document.getElementById('searchBtn');
    const estimateEl = document.getElementById('analysisEstimate');
    const estimatedSeconds = estimerTempsAnalyse(totalCvCount);
    const clientStartedAt = performance.now();

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Analyse en cours...';
    estimateEl.textContent = `Analyse en cours... temps estimé : environ ${estimatedSeconds} seconde(s).`;
    document.getElementById('resultsContainer').innerHTML = '<div class="empty-state"><span class="spinner" style="border-color:rgba(59,130,246,.3);border-top-color:var(--primary);"></span><p style="margin-top:1rem;">L\'IA analyse les CV...</p><p style="margin-top:.5rem;font-size:.82rem;color:var(--gray-500);">Temps estimé : environ ' + estimatedSeconds + ' seconde(s).</p></div>';

    try {
        const response = await fetch('api-match.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ requete: query })
        });
        const data = await response.json();

        if (data.error) {
            afficherErreur(data.error);
            estimateEl.textContent = 'Analyse interrompue avant la fin.';
        } else {
            allResults = data.resultats || [];
            afficherResultats(allResults);
            document.getElementById('sortSelect').disabled = false;

            const elapsedMs = Math.round(performance.now() - clientStartedAt);
            const serverMs  = data.meta?.duration_ms || elapsedMs;
            const estimated = data.meta?.estimated_seconds || estimatedSeconds;
            const candidateCount = data.meta?.candidate_count;
            const formatted = formaterDuree(serverMs);

            estimateEl.textContent = candidateCount
                ? `Analyse terminée en ${formatted} pour ${candidateCount} CV(s). Estimation initiale : ${estimated} seconde(s).`
                : `Analyse terminée en ${formatted}. Estimation initiale : ${estimated} seconde(s).`;

            // Ouvrir le chat flottant automatiquement si des résultats
            if (allResults.length > 0) {
                setTimeout(() => {
                    document.getElementById('chatBadge').style.display = 'flex';
                }, 500);
            }
        }
    } catch (err) {
        afficherErreur('Erreur de communication avec le service IA. Vérifiez que le microservice Python est actif.');
        estimateEl.textContent = 'Impossible d\'afficher le temps réel : le service IA n\'a pas répondu.';
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
    const count     = document.getElementById('resultsCount');

    if (!resultats || resultats.length === 0) {
        container.innerHTML = '<div class="empty-state"><i class="fas fa-user-slash"></i><p>Aucun candidat trouvé pour cette recherche.<br>Essayez des termes plus généraux.</p></div>';
        count.textContent = '0 candidat trouvé';
        return;
    }

    count.textContent = resultats.length + ' candidat' + (resultats.length > 1 ? 's' : '') + ' trouvé' + (resultats.length > 1 ? 's' : '');

    container.innerHTML = resultats.map(c => `
        <div class="candidate-card">
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
    const sort   = document.getElementById('sortSelect').value;
    const sorted = [...allResults].sort((a, b) => {
        if (sort === 'score')      return b.score - a.score;
        if (sort === 'name')       return a.nom.localeCompare(b.nom);
        if (sort === 'experience') return (b.annees_experience||0) - (a.annees_experience||0);
        return 0;
    });
    afficherResultats(sorted);
}

// ============================================
// CHAT FLOTTANT — Agent IA DeepSeek (port 5001)
// ============================================
function toggleChatFloat() {
    const win = document.getElementById('chatFloatWindow');
    win.classList.toggle('open');
    document.getElementById('chatBadge').style.display = 'none';
}

function useChip(el) {
    document.getElementById('floatInput').value = el.textContent;
    envoyerFloatChat();
}

function addFloatMsg(texte, type) {
    const container = document.getElementById('floatMessages');
    const div = document.createElement('div');
    div.className = `float-msg ${type}`;
    div.innerHTML = `
        <div class="float-msg-avatar">${type === 'ia' ? '🤖' : '👤'}</div>
        <div class="float-msg-bubble">${texte}</div>
    `;
    container.appendChild(div);
    container.scrollTop = container.scrollHeight;
}

function showTyping() {
    const container = document.getElementById('floatMessages');
    const div = document.createElement('div');
    div.className = 'float-msg ia';
    div.id = 'typingEl';
    div.innerHTML = `
        <div class="float-msg-avatar">🤖</div>
        <div class="float-msg-bubble typing-indicator"><span></span><span></span><span></span></div>
    `;
    container.appendChild(div);
    container.scrollTop = container.scrollHeight;
}

function removeTyping() {
    const el = document.getElementById('typingEl');
    if (el) el.remove();
}

async function envoyerFloatChat() {
    const input = document.getElementById('floatInput');
    const msg   = input.value.trim();
    if (!msg) return;

    if (allResults.length === 0) {
        addFloatMsg("Veuillez d'abord lancer une recherche IA, puis je pourrai affiner les résultats pour vous.", 'ia');
        input.value = '';
        return;
    }

    input.value = '';
    addFloatMsg(msg, 'user');
    floatHistorique.push({ role: 'user', content: msg });

    const sendBtn = document.getElementById('floatSendBtn');
    sendBtn.disabled = true;
    showTyping();

    try {
        const response = await fetch('api-agent.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                message:           msg,
                requete_initiale:  document.getElementById('searchQuery').value.trim(),
                candidats:         allResults,
                historique:        floatHistorique
            })
        });

        const data = await response.json();
        removeTyping();

        if (data.resultats !== undefined) {
            allResults = data.resultats;
            afficherResultats(allResults);
        }

        const reponse = data.message || 'Résultats mis à jour.';
        addFloatMsg(reponse, 'ia');
        floatHistorique.push({ role: 'assistant', content: reponse });

    } catch (e) {
        removeTyping();
        addFloatMsg('Erreur de communication avec le service agent IA.', 'ia');
    }

    sendBtn.disabled = false;
}

// ============================================
// Modal Contact
// ============================================
function openModal(candidatId, nom, email) {
    document.getElementById('contactCandidatId').value  = candidatId;
    document.getElementById('contactDestinataire').value = `${nom} <${email}>`;
    document.getElementById('contactObjet').value        = 'Opportunité d\'emploi - CVMatch IA';
    document.getElementById('contactMessage').value      = `Bonjour ${nom},\n\nVotre profil a retenu notre attention lors d'une recherche sur CVMatch IA. Nous serions ravis d'échanger avec vous.\n\nCordialement,\n${<?= json_encode($user['nom']) ?>}`;
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
function estimerTempsAnalyse(totalCvs) { return 50; }
function formaterDuree(durationMs) {
    const seconds = Math.max(1, Math.round(durationMs / 1000));
    return seconds + ' seconde' + (seconds > 1 ? 's' : '');
}

// Fermer modal en cliquant dehors
document.getElementById('contactModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

// Recherche au Enter
document.getElementById('searchQuery').addEventListener('keypress', e => {
    if (e.key === 'Enter') rechercher();
});
</script>
</body>
</html>