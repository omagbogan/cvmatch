@echo off
echo Activation de l'environnement virtuel...
call venv\Scripts\activate

echo D?marrage des microservices CVMatch...
start "Match API - 5000"    cmd /k "python app.py"
start "CV Extractor - 5001" cmd /k "python cv_extractor.py"
start "Agent Chat - 5002"   cmd /k "python agent_chat.py"

echo Tous les services sont lanc?s.
pause
