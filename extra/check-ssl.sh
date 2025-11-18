#!/bin/bash
# ==============================================
# SSL Expiry & Status Checker (Real Expiry Date)
# For acme.sh domains (ECC + RSA)
# ==============================================

ACME_DIR="/root/.acme.sh"
DAYS_WARNING=30
DAYS_CRITICAL=15

# Colors
RED="\e[31m"
GREEN="\e[32m"
YELLOW="\e[33m"
CYAN="\e[36m"
END="\e[0m"

printf "\n${CYAN}🔍 Checking real SSL expiry under: ${ACME_DIR}${END}\n\n"

find "$ACME_DIR" -type f -name "*.conf" ! -name "*.csr.conf" | sort | while read -r conf_file; do
  [[ -e "$conf_file" ]] || continue

  domain=$(basename "$conf_file" .conf)
  api_used=$(grep "^Le_API=" "$conf_file" | cut -d"'" -f2)
  cert_path=$(grep "^Le_RealFullChainPath=" "$conf_file" | cut -d"'" -f2)

  [[ -z "$cert_path" || ! -f "$cert_path" ]] && continue

  # Get expiry date using OpenSSL
  expiry_date=$(openssl x509 -enddate -noout -in "$cert_path" 2>/dev/null | cut -d'=' -f2)
  expiry_epoch=$(date -d "$expiry_date" +%s 2>/dev/null)
  now_epoch=$(date +%s)
  days_left=$(( (expiry_epoch - now_epoch) / 86400 ))

  # Determine CA name
  if [[ "$api_used" == *"zerossl"* ]]; then
    ca_name="ZeroSSL"
  elif [[ "$api_used" == *"letsencrypt"* ]]; then
    ca_name="Let's Encrypt"
  else
    ca_name="Unknown"
  fi

  # Display results
  if (( days_left < 0 )); then
    echo -e "${RED}❌ ${domain} - Expired ($(date -d "$expiry_date" +"%b %d, %Y")) | CA: ${ca_name}${END}"
  elif (( days_left <= DAYS_CRITICAL )); then
    echo -e "${RED}⚠️  ${domain} - Expiring Soon (${days_left} days left, expires on $(date -d "$expiry_date" +"%b %d, %Y")) | CA: ${ca_name}${END}"
  elif (( days_left <= DAYS_WARNING )); then
    echo -e "${YELLOW}⚠️  ${domain} - Renew Soon (${days_left} days left, expires on $(date -d "$expiry_date" +"%b %d, %Y")) | CA: ${ca_name}${END}"
  else
    echo -e "${GREEN}✅ ${domain} - Valid (${days_left} days left, expires on $(date -d "$expiry_date" +"%b %d, %Y")) | CA: ${ca_name}${END}"
  fi
done

echo -e "\n${CYAN}💡 Manual renew (if needed): ${YELLOW}acme.sh --renew-all${END}\n"
