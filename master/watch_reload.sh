#!/bin/bash
# Parallel reload watcher for LiteSpeed
# Sends reload to all child servers simultaneously, then shows reload times after completion.

set -euo pipefail

TARGET_FILE="/etc/lsyncd/targets.conf"
WATCH_FILE="/usr/local/lsws/conf/httpd_config.conf"
RELOAD_CMD="/usr/local/lsws/bin/lswsctrl reload"
SSH_KEY="/root/.ssh/id_rsa"

WAIT_BEFORE_RELOAD=30     # Wait 30 seconds after file change before reloading
VERIFY_DELAY=5            # Wait 5 seconds after reload before showing results
TMP_DIR="/tmp/reload_flags"

mkdir -p "$TMP_DIR"

# Load targets
if [[ ! -f "$TARGET_FILE" ]]; then
  echo "❌ Missing $TARGET_FILE"
  exit 1
fi
mapfile -t TARGETS < <(sed -n '/\S/p' "$TARGET_FILE")

if [[ ${#TARGETS[@]} -eq 0 ]]; then
  echo "❌ No targets found in $TARGET_FILE"
  exit 1
fi

# Function to send reload (no verification yet)
send_reload_only() {
  local TARGET="$1"
  ssh -i "$SSH_KEY" -o StrictHostKeyChecking=no "$TARGET" "$RELOAD_CMD" >/dev/null 2>&1
  if [[ $? -eq 0 ]]; then
    echo "ok" > "$TMP_DIR/$(echo "$TARGET" | tr '@.' '___')"
  else
    echo "fail" > "$TMP_DIR/$(echo "$TARGET" | tr '@.' '___')"
  fi
}

# Function to check reload time
check_reload_time() {
  local TARGET="$1"
  LAST_RELOAD=$(ssh -i "$SSH_KEY" -o StrictHostKeyChecking=no "$TARGET" \
    "ps -eo lstart,cmd | grep '[l]itespeed' | head -n 1 | awk '{print \$1, \$2, \$3, \$4, \$5}'" 2>/dev/null || true)
  if [[ -n "$LAST_RELOAD" ]]; then
    echo "  ✔ $TARGET — Last reload time: $LAST_RELOAD"
  else
    echo "  ⚠ $TARGET — Could not read reload time"
  fi
}

# Function to broadcast reload in parallel
broadcast_reload() {
  echo "[🚀] Sending reload to all targets simultaneously..."
  rm -f "$TMP_DIR"/* 2>/dev/null || true
  for TARGET in "${TARGETS[@]}"; do
    (send_reload_only "$TARGET") &
  done
  wait
  echo "[i] All reload requests sent at $(date '+%H:%M:%S')."
}

# Function to show results after reload
show_results() {
  echo
  echo "[🕓] Waiting $VERIFY_DELAY seconds before checking reload times..."
  sleep "$VERIFY_DELAY"
  echo
  echo "[📋] Reload verification results:"
  for TARGET in "${TARGETS[@]}"; do
    STATUS_FILE="$TMP_DIR/$(echo "$TARGET" | tr '@.' '___')"
    if [[ -f "$STATUS_FILE" ]]; then
      STATUS=$(cat "$STATUS_FILE")
      if [[ "$STATUS" == "ok" ]]; then
        check_reload_time "$TARGET"
      else
        echo "  ❌ $TARGET — Reload command failed"
      fi
    else
      echo "  ⚠ $TARGET — No reload status file found"
    fi
  done
  echo "-----------------------------------------------------------"
}

# Main watcher loop
echo "[+] Watching $WATCH_FILE for changes..."
while true; do
  inotifywait -e modify "$WATCH_FILE" >/dev/null 2>&1
  echo
  echo "[⚙] Change detected at $(date '+%Y-%m-%d %H:%M:%S')"
  echo "[i] Waiting $WAIT_BEFORE_RELOAD seconds before sending reload..."
  sleep "$WAIT_BEFORE_RELOAD"

  broadcast_reload
  show_results
done
