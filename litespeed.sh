#!/bin/bash
set -e

GREEN="\e[32m"
RED="\e[31m"
ENDCOLOR="\e[0m"

echo "Select mode:"
echo -e "${GREEN}[+] 1) Master server${ENDCOLOR}"
echo -e "${GREEN}[+] 2) Child server${ENDCOLOR}"
read -rp "Enter choice (1 or 2): " CHOICE

# Common function that runs the main script content
run_main_code() {
#!/bin/bash
set -e

PUBKEY="ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIA+Prpeao7j0MGTMq79APqBaZcBRF+u0cYHeyn2v7cEt mukeshtandi@MacBookPro.lan"

echo -e "${GREEN}⚰ Starting LiteSpeed Setup Without SSL or Compression...${ENDCOLOR}"

### 1. System Update and Tools
apt update -y && apt upgrade -y
apt install -y curl wget nano ufw software-properties-common zip unzip htop gzip logrotate

### 2. LiteSpeed Install
wget -O - https://repo.litespeed.sh | bash
apt update -y
apt install -y openlitespeed certbot
# 🔧 Stop OLS immediately to prevent loading default Example config
systemctl stop lsws || /usr/local/lsws/bin/lswsctrl stop

# OpenLiteSpeed config file path
# Paths
OLS_CONF="/usr/local/lsws/conf/httpd_config.conf"
EXAMPLE_VH_DIR="/usr/local/lsws/conf/vhosts/Example"

# 🔥 Fully remove any reference to Example virtual host (space-insensitive, safe)
sed -i '/virtualHost[[:space:]]\+Example[[:space:]]*{/,/^[[:space:]]*}/d' "$OLS_CONF"
sed -i '/include[[:space:]]\+conf\/vhosts\/Example\/vhconf\.conf/d' "$OLS_CONF"
sed -i '/vhmap[[:space:]]\+Example/d' "$OLS_CONF"
sed -i '/map[[:space:]]\+Example[[:space:]]\+\*/d' "$OLS_CONF"

# 🧹 Remove the Example vhost directory if it exists
rm -rf "$EXAMPLE_VH_DIR"

# ✅ Verify cleanup (optional, shows if any "example" lines are still left)
if grep -qi example "$OLS_CONF"; then
    echo -e "${RED}⚠️ Warning: Some 'Example' config lines may still exist! Please review manually.${ENDCOLOR}"
else
    echo -e "${GREEN}✅ Example virtual host fully removed from config.${ENDCOLOR}"
fi

### 3. Enable SSL Session Cache
CACHE_LINE="sslSessionCache shm:SSLCache"

# Check if line already exists (excluding commented lines)
if grep -v '^\s*#' "$OLS_CONF" | grep -qF "$CACHE_LINE"; then
    echo -e "${GREEN}✔ SSL session cache already set.${ENDCOLOR}"
else
    echo -e "${RED}🔧 Adding SSL session cache...${ENDCOLOR}"

    cp "$OLS_CONF" "${OLS_CONF}.bak"

    if grep -q "^serverName" "$OLS_CONF"; then
        sed -i "/^serverName.*/a\\
$CACHE_LINE
" "$OLS_CONF"
        echo -e "${GREEN}✅ Line inserted after 'serverName'.${ENDCOLOR}"
    else
        echo -e "\n$CACHE_LINE" >> "$OLS_CONF"
        echo -e "${GREEN}✅ Line appended at the end.${ENDCOLOR}"
    fi
fi

### 4. PHP 8.3 + Extensions
PHP_PACKAGES=(
  lsphp83 lsphp83-common lsphp83-curl lsphp83-mysql
  lsphp83-opcache lsphp83-intl
)

for pkg in "${PHP_PACKAGES[@]}"; do
  if apt install -y "$pkg"; then
    echo -e "${GREEN}✔ Installed $pkg${ENDCOLOR}"
  else
    echo -e "${RED}⚠️ Failed to install $pkg${ENDCOLOR}"
  fi
done

ln -sf /usr/local/lsws/lsphp83/bin/lsphp /usr/local/lsws/fcgi-bin/lsphp5
apt install -y composer

### 6. SSH Key Security
echo -e "${GREEN}🔐 Setting up SSH Key login...${ENDCOLOR}"
mkdir -p /root/.ssh
echo "$PUBKEY" > /root/.ssh/authorized_keys
chmod 600 /root/.ssh/authorized_keys
chmod 700 /root/.ssh
sed -i 's/^#\?PasswordAuthentication .*/PasswordAuthentication no/' /etc/ssh/sshd_config
sed -i 's/^#\?PermitRootLogin .*/PermitRootLogin prohibit-password/' /etc/ssh/sshd_config
sudo systemctl restart ssh   # <-- changed from sshd to ssh
echo -e "${GREEN}✅ SSH Key-only login configured.${ENDCOLOR}"

### 7. UFW Rules
echo -e "${GREEN}🔒 Configuring UFW firewall...${ENDCOLOR}"
ufw allow 22
ufw allow 80
ufw allow 7080
ufw allow 443/tcp
ufw allow 443/udp
ufw --force enable
echo -e "${GREEN}✅ UFW is active with necessary ports allowed.${ENDCOLOR}"

### 8. PHP Tuning
echo -e "${GREEN}⚙️ Tuning PHP settings...${ENDCOLOR}"
PHPINI="/usr/local/lsws/lsphp83/etc/php/8.3/litespeed/php.ini"
sed -i 's/^memory_limit = .*/memory_limit = 256M/' "$PHPINI"
sed -i 's/^upload_max_filesize = .*/upload_max_filesize = 50M/' "$PHPINI"
sed -i 's/^post_max_size = .*/post_max_size = 50M/' "$PHPINI"
sed -i 's/^max_execution_time = .*/max_execution_time = 60/' "$PHPINI"
sed -i 's/^max_input_vars = .*/max_input_vars = 3000/' "$PHPINI"
echo -e "${GREEN}✅ PHP tuning applied.${ENDCOLOR}"

### 10. Logrotate for Litespeed logs
cat <<EOF > /etc/logrotate.d/openlitespeed
/usr/local/lsws/logs/*.log {
    daily
    missingok
    rotate 7
    compress
    delaycompress
    notifempty
    create 640 nobody nogroup
    sharedscripts
    postrotate
        /usr/local/lsws/bin/lswsctrl reopen > /dev/null 2>&1 || true
    endscript
}
EOF

### 11. Cloudflare IPs + Real IP Forwarding
echo -e "${GREEN}🌐 Inserting Cloudflare IPs and real-IP config...${ENDCOLOR}"

CF_BLOCK=$(curl -s https://www.cloudflare.com/ips-v4; curl -s https://www.cloudflare.com/ips-v6)
ALLOW_BLOCK=$(cat <<EOF
accessControl  {
  allow                   <<<END_allow
$(echo "$CF_BLOCK" | sed 's/^/  /')
  END_allow
}
EOF
)

# useIpInProxyHeader insertion
if ! grep -q "useIpInProxyHeader" "$OLS_CONF"; then
  sed -i '/^adminEmails.*/a useIpInProxyHeader        2' "$OLS_CONF"
  echo -e "${GREEN}✅ useIpInProxyHeader set to 2.${ENDCOLOR}"
fi

# Add accessControl block
if ! grep -q "accessControl" "$OLS_CONF"; then
  echo -e "\n$ALLOW_BLOCK" >> "$OLS_CONF"
  echo -e "${GREEN}✅ Cloudflare IP allow-list added to config.${ENDCOLOR}"
fi

echo -e "${GREEN}[+] Setup Wordpress installer ${ENDCOLOR}"
apt install -y mariadb-server mariadb-client
apt install -y php-mysql

# Auto MySQL Secure Installation Script
# Author: Mukesh’s helper 😉
# Works for MariaDB / MySQL

MYSQL_PASS="%Temp%10"

echo -e "${GREEN}[+] 🔒 Running automatic MariaDB secure installation...${ENDCOLOR}"

sudo mariadb -u root -p"$MYSQL_PASS" <<EOF
-- Switch to unix_socket authentication
ALTER USER 'root'@'localhost' IDENTIFIED VIA unix_socket;
FLUSH PRIVILEGES;

-- Remove anonymous users
DELETE FROM mysql.user WHERE User='';

-- Disallow remote root login
DELETE FROM mysql.user WHERE User='root' AND Host!='localhost';

-- Remove test database
DROP DATABASE IF EXISTS test;
DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';

-- Reload privilege tables
FLUSH PRIVILEGES;
EOF

# -----------------------------------------
#  CREATE DB + USER ONLY IF NOT EXISTS
# -----------------------------------------
sudo mariadb -e "
-- Create DB (skip if exists)
CREATE DATABASE IF NOT EXISTS sql_create_wish_;

-- Create users (skip if exists)
CREATE USER IF NOT EXISTS 'suru'@'localhost' IDENTIFIED BY 'Mukesh@123';
CREATE USER IF NOT EXISTS 'suru'@'%' IDENTIFIED BY 'Mukesh@123';

-- Grant privileges
GRANT ALL PRIVILEGES ON sql_create_wish_.* TO 'suru'@'localhost';
GRANT ALL PRIVILEGES ON sql_create_wish_.* TO 'suru'@'%';
FLUSH PRIVILEGES;
"

# Import SQL only if database is empty
RECORDS=$(sudo mariadb -N -s -u suru -p'Mukesh@123' -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='sql_create_wish_';")

if [ "$RECORDS" -eq 0 ]; then
    echo "[+] Importing SQL file..."
    sudo mariadb -u suru -p'MMukesh@123' sql_create_wish_ < /root/wish-script/files/sql_create_wish_.sql
else
    echo "[+] SQL Import Skipped (Database already has tables)"
fi

echo -e "${GREEN}[+] ✅ MySQL/MariaDB has been secured successfully!${ENDCOLOR}"

echo -e "${GREEN}🎉 Setup Complete. Start OpenLiteSpeed with: systemctl start lsws${ENDCOLOR}"

### 15. Required directory creation
echo -e "${GREEN}[+] Checking and creating required directories...${ENDCOLOR}"
REQUIRED_DIRS=(
    "/root/.acme.sh"
    "/var/www"
)

for DIR in "${REQUIRED_DIRS[@]}"; do
    if [ -d "$DIR" ]; then
        echo -e "${YELLOW}[✔] Directory exists: $DIR${ENDCOLOR}"
    else
        echo -e "${BLUE}[+] Creating directory: $DIR${ENDCOLOR}"
        mkdir -p "$DIR"
    fi
done
chown -R nobody:nogroup /var/www/

}

# ==================================================================================== #
# ==================================================================================== #
if [ "$CHOICE" = "1" ]; then
    echo "Running in MASTER mode..."
    # Step 1: Run main code
    run_main_code

### 5. Generate DH Param (2048 bits)
DHPARAM="/usr/local/lsws/conf/dhparam.pem"
if [ ! -f "$DHPARAM" ]; then
  echo -e "${GREEN}🔐 Generating 2048-bit DH param... (may take a while)${ENDCOLOR}"
  openssl dhparam -out "$DHPARAM" 2048
  echo -e "${GREEN}✅ DH param generated at $DHPARAM${ENDCOLOR}"
else
  echo -e "${GREEN}✔ DH param already exists at $DHPARAM${ENDCOLOR}"
fi
### 9. Dummy SSL for Listener 443
echo -e "${GREEN}🔐 Generating dummy SSL cert for 443...${ENDCOLOR}"
mkdir -p /usr/local/lsws/conf/cert
openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
-keyout /usr/local/lsws/conf/cert/self.key \
-out /usr/local/lsws/conf/cert/self.crt \
-subj "/C=IN/ST=Rajasthan/L=Jaipur/O=TestOrg/CN=localhost"
echo -e "${GREEN}✅ Dummy self-signed cert generated.${ENDCOLOR}"

### 12. Install acme.sh (SSL automation)
if ! command -v acme.sh &> /dev/null; then
    curl https://get.acme.sh | sh
    source ~/.bashrc
else
    echo -e "${GREEN}✔ acme.sh already installed.${ENDCOLOR}"
fi

### Ensure executable permission
if [ -f ~/.acme.sh/acme.sh ]; then
    chmod +x ~/.acme.sh/acme.sh
fi

### Force Let's Encrypt as default CA
if [ -x ~/.acme.sh/acme.sh ]; then
    ~/.acme.sh/acme.sh --set-default-ca --server letsencrypt
    ~/.acme.sh/acme.sh --register-account -m mukeshtandi@gmail.com
    ~/.acme.sh/acme.sh --update-account --server letsencrypt
    echo -e "${GREEN}✔ Default CA set to Let's Encrypt.${ENDCOLOR}"
else
    echo -e "${RED}❌ acme.sh not executable. Please check permissions.${ENDCOLOR}"
fi
echo '1 0 * * * "/root/.acme.sh"/acme.sh --cron --home "/root/.acme.sh" > /dev/null' | crontab -

    # Step 1: Copy childrefresh first
    if [ -f /root/wish-script/master/childrefresh ]; then
        echo "Copying /root/wish-script/master/childrefresh to /usr/local/bin/childrefresh"
        cp -f /root/wish-script/master/childrefresh /usr/local/bin/childrefresh
        chmod +x /usr/local/bin/childrefresh
    else
        echo "Warning: /root/wish-script/master/childrefresh not found."
    fi

    # Step 1.1: Copy watch_reload.sh to /root/
    if [ -f /root/wish-script/master/watch_reload.sh ]; then
        echo "Copying /root/wish-script/master/watch_reload.sh to /root/"
        cp -f /root/wish-script/master/watch_reload.sh /root/
        chmod +x /root/watch_reload.sh
    else
        echo "Warning: /root/wish-script/master/watch_reload.sh not found."
    fi

echo "Auto backup on Cloudflare R2..."
echo "🌀 Starting Cloudflare R2 setup..."
echo "📦 Installing awscli and zip..."

if ! apt install -y awscli zip; then
    echo "⚠️ apt installation failed — trying official AWS installer..."
    cd /tmp || exit
    curl -s "https://awscli.amazonaws.com/awscli-exe-linux-x86_64.zip" -o "awscliv2.zip"
    unzip -q awscliv2.zip
    sudo ./aws/install
    echo "✅ AWS CLI installed via official installer."
fi

# Verify AWS CLI
if command -v aws >/dev/null 2>&1; then
    echo "✅ AWS CLI and ZIP installed successfully."
else
    echo "❌ AWS CLI installation failed — aborting setup."
    exit 1
fi

# Cloudflare R2 Credentials
ACCESS_KEY="24157976ea9850defb983336906b7104"
SECRET_KEY="ffd63f9cc3183971443b91710aa9baeb82c764712b7430b42453199a94f8ab9c"
R2_ENDPOINT="https://0be5d4516fdefd9e863d3e84eab80c10.r2.cloudflarestorage.com"

echo "🧾 Creating /root/.aws directory..."
mkdir -p /root/.aws

echo "🔑 Writing AWS credentials..."
cat > /root/.aws/credentials <<EOF
[default]
aws_access_key_id = ${ACCESS_KEY}
aws_secret_access_key = ${SECRET_KEY}
EOF

echo "⚙️ Writing AWS config..."
cat > /root/.aws/config <<EOF
[default]
region = auto
output = json
s3 =
    endpoint_url = ${R2_ENDPOINT}
EOF

echo ""
echo "🎉 Cloudflare R2 configuration complete!"
echo "📂 Credentials saved: /root/.aws/credentials"
echo "⚙️ Config file: /root/.aws/config"
echo "🌐 Endpoint: ${R2_ENDPOINT}"
echo ""

echo "✅ Testing Cloudflare R2 connection..."
aws s3 ls --endpoint-url "${R2_ENDPOINT}" || echo "⚠️ Warning: R2 connection test failed. Please check keys or endpoint."
echo ""

# Add daily backup cron at 10:00 AM
(crontab -l 2>/dev/null; echo "0 10 * * * /usr/local/bin/backup >> /var/log/server_backup.log 2>&1") | crontab -
echo "🕙 Daily R2 backup cron job added (10:00 AM)."

    echo "🔄 Setting up lsyncd (real-time sync service)..."
    # Step 3: Run lsyncd.sh in foreground
    if [ -f /root/wish-script/master/lsyncd.sh ]; then
        echo "Running /root/wish-script/master/lsyncd.sh (foreground)..."
        bash /root/wish-script/master/lsyncd.sh
    else
        echo "Warning: /root/wish-script/master/lsyncd.sh not found."
    fi
### 13. Ensure check-ssl.sh is auto-run on shell login
cp /root/wish-script/extra/check-ssl.sh /root/check-ssl.sh
if ! grep -qxF '/root/check-ssl.sh' /root/.bashrc; then
    echo '/root/check-ssl.sh' >> /root/.bashrc
    echo -e "${GREEN}✔ /root/check-ssl.sh added to .bashrc${ENDCOLOR}"
else
    echo -e "${GREEN}✔ /root/check-ssl.sh already present in .bashrc${ENDCOLOR}"
fi
### copy important files
cp /root/wish-script/extra/vhostsetup /usr/local/bin/vhostsetup
cp /root/wish-script/extra/backup /usr/local/bin/backup
cp /root/wish-script/extra/renew /usr/local/bin/renew
cp /root/wish-script/extra/checkfile /usr/local/bin/checkfile
cp /root/wish-script/files/child-cpu.php /var/www/
cp /root/wish-script/files/cpu.php /var/www/
cp /root/wish-script/files/status.php /var/www/
cp /root/wish-script/files/health.php /var/www/
cp /root/wish-script/files/web.php /var/www/
cp /root/wish-script/files/data.php /var/www/
mkdir -p /usr/local/lsws/conf/vhosts/Example
cp -r /root/wish-script/conf/Example/* /usr/local/lsws/conf/vhosts/Example/

### 14. Set execute permissions on helper scripts
chmod +x /usr/local/bin/vhostsetup
chmod +x /usr/local/bin/backup
chmod +x /usr/local/bin/renew
chmod +x /usr/local/bin/checkfile
chmod +x /root/check-ssl.sh

# Installing rmate to open files in a sublime/remote editor
echo "Installing rmate to enable opening files in a sublime/remote editor"
wget -O /usr/local/bin/rmate https://raw.githubusercontent.com/aurora/rmate/master/rmate
chmod +x /usr/local/bin/rmate
# ==================================================================================== #
# ==================================================================================== #

elif [ "$CHOICE" = "2" ]; then
    echo "Running in CHILD mode..."
    run_main_code
else
    echo "Invalid choice."
    exit 1
fi
# ==================================================================================== #
# ==================================================================================== #

echo "All tasks completed."

cp /root/wish-script/conf/httpd_config.conf /usr/local/lsws/conf/

# 🔁 Final restart before summary
/usr/local/lsws/bin/lswsctrl restart

# 🧹 Delete script folder
rm -rf /root/wish-script/

# ✅ Show summary after everything is done
echo -e "${GREEN}
┌────────────────────────────────────────────────────────────────────────┐
│   OpenLiteSpeed Server Setup Completed Successfully!                   │
├────────────────────────────────────────────────────────────────────────┤
│   System Preparation                                                   │
│     1. System update and essential tools installed                     │
│     2. OpenLiteSpeed installed via official repo                       │
│     3. PHP 8.3 + extensions installed                                  │
│     4. Dummy SSL certificate generated (self-signed)                   │
│     5. Strong DH param (2048-bit) generated                            │
│                                                                        │
│   Security Configuration                                               │
│     6. SSH hardened with key-only access                               │
│     7. UFW firewall configured (22, 80, 443, 7080)                     │
│     8. Basic PHP tuning (memory, upload, timeout)                      │
│     9. SSL session cache enabled in LiteSpeed                          │
│    10. Root login now shows SSL expiry warning                         │
│                                                                        │
│   Automation & Scripts                                                 │
│    11. vhostsetup CLI (add/remove domains, with/without SSL)           │
│    12. SSL auto-renew command (acme.sh + renew helper)                 │
│    13. acme.sh installed for Let's Encrypt SSL automation              │
│    14. Executable permissions set for helper scripts                   │
│    15. Logrotate setup for LiteSpeed logs                              │
│    16. Required directories created                                    │
│    17. Final cleanup and OpenLiteSpeed restarted                       │
├────────────────────────────────────────────────────────────────────────┤
│     Use 'vhostsetup' to manage virtual hosts:                          │
│     • Add domains with or without SSL                                  │
│     • Remove existing domains                                          │
│     • Automatically configure LiteSpeed for your sites                 │
│                                                                        │
│   To check for setup errors:                                           │
│     /usr/local/lsws/bin/openlitespeed -t                               │
│                                                                        │
│   Check lsyncd status after Master Server setup:                       │
│     systemctl status lsyncd                                            │
└────────────────────────────────────────────────────────────────────────┘
${ENDCOLOR}"
