#!/usr/bin/env bash
# Pilote l'application complète en arrière-plan : serveur web (8000), file d'attente et Vite (5173).
#   bash .devcontainer/dev.sh start|stop|restart|status|logs
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

pid_file=".devcontainer/.dev.pid"
log_file="/tmp/nova-dev.log"

running() {
    [ -f "$pid_file" ] && kill -0 "$(cat "$pid_file")" 2>/dev/null
}

start() {
    if running; then
        echo "L'application tourne déjà (journal : $log_file)."
        return 0
    fi

    # npm run dev:all : serveur web + file d'attente + Vite (voir package.json). setsid : un groupe de processus à part, arrêtable d'un coup.
    setsid nohup npm run dev:all >"$log_file" 2>&1 &
    echo $! >"$pid_file"

    for _ in $(seq 1 45); do
        if curl -fsS -o /dev/null "http://localhost:8000/connexion"; then
            echo "Application démarrée : ouvrez le port 8000 (journal : $log_file)."
            return 0
        fi
        sleep 2
    done

    echo "L'application n'a pas répondu à temps : voir $log_file" >&2
    return 1
}

stop() {
    if running; then
        kill -- "-$(cat "$pid_file")" 2>/dev/null || kill "$(cat "$pid_file")" 2>/dev/null || true
        rm -f "$pid_file"
        echo "Application arrêtée."
    else
        rm -f "$pid_file"
        echo "L'application ne tournait pas."
    fi
}

case "${1:-status}" in
    start) start ;;
    stop) stop ;;
    restart) stop; start ;;
    status) if running; then echo "En marche (journal : $log_file)"; else echo "Arrêtée"; fi ;;
    logs) tail -n 100 -f "$log_file" ;;
    *) echo "Usage : dev.sh start|stop|restart|status|logs" >&2; exit 2 ;;
esac
