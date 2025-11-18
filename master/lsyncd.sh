#!/bin/bash
set -e

GREEN='\e[32m'
END='\e[0m'

log() {
  echo -e "${GREEN}[+] $1${END}"
}

### Step 1: Install Lsyncd ###
log "Installing Lsyncd..."
apt update -y
apt install -y lsyncd inotify-tools

### Step 2: Setup Logs ###
log "Setting up log directories..."
mkdir -p /var/log/lsyncd /etc/lsyncd
touch /var/log/lsyncd/lsyncd.log /var/log/lsyncd/lsyncd.status
chmod 644 /var/log/lsyncd/*
chown root:root /var/log/lsyncd/*

### Step 3: Setup SSH Keys ###
log "Writing SSH keys..."
mkdir -p ~/.ssh
cat > ~/.ssh/id_rsa <<'EOF'
-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZWQyNTUxOQAAACAPj66XmqO49DBkzKu/QD6gWmXAURfrtHGB3sp9r+3BLQAAAKB/IdaEfyHWhAAAAAtzc2gtZWQyNTUxOQAAACAPj66XmqO49DBkzKu/QD6gWmXAURfrtHGB3sp9r+3BLQAAAECZUPIxq0ki95nTHYWxWImLAzKB4tmA/TZqIsQ2geoGsQ+Prpeao7j0MGTMq79APqBaZcBRF+u0cYHeyn2v7cEtAAAAGm11a2VzaHRhbmRpQE1hY0Jvb2tQcm8ubGFuAQID
-----END OPENSSH PRIVATE KEY-----
EOF

chmod 600 ~/.ssh/id_rsa

cat > ~/.ssh/id_rsa.pub <<EOF
ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIA+Prpeao7j0MGTMq79APqBaZcBRF+u0cYHeyn2v7cEt mukeshtandi@MacBookPro.lan
EOF

chmod 644 ~/.ssh/id_rsa.pub

### Step 4: Read target IPs from config ###
TARGET_FILE="/etc/lsyncd/targets.conf"
log "Writing target IP list to $TARGET_FILE..."
cat > "$TARGET_FILE" <<EOF
192.168.1.1
192.168.1.2
EOF

mapfile -t TARGETS < "$TARGET_FILE"

### Step 5: Write Lsyncd Config ###
FOLDERS=(
    "/etc/ssl/example-certs"
    "/root/.acme.sh"
    "/usr/local/lsws/conf/"
    "/var/www"
)

log "Writing Lsyncd config..."
cat > /etc/lsyncd/lsyncd.conf.lua <<'EOF'
settings {
    logfile = "/var/log/lsyncd/lsyncd.log",
    statusFile = "/var/log/lsyncd/lsyncd.status",
    statusInterval = 10,
    nodaemon = false,
}
EOF

for FOLDER in "${FOLDERS[@]}"; do
  for TARGET in "${TARGETS[@]}"; do
    cat >> /etc/lsyncd/lsyncd.conf.lua <<EOF

sync {
    default.rsync,
    source = "$FOLDER/",
    target = "$TARGET:$FOLDER/",
    rsync = {
        archive = true,
        compress = true,
        verbose = true,
        rsh = "ssh -i /root/.ssh/id_rsa -o StrictHostKeyChecking=no"
    }
}
EOF
  done
done

### Step 6: Enable and Start Lsyncd ###
log "Enabling and starting Lsyncd..."
systemctl enable lsyncd
systemctl restart lsyncd

### Step 8: Start Watcher in Background ###
log "Starting config change watcher in background..."
nohup /root/watch_reload.sh > /var/log/watch_reload.log 2>&1 &

log "✅ Setup Complete! Lsyncd is syncing and automatic reload is active on config change."