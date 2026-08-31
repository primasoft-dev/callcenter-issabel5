#!/bin/bash

# Unified installation script for Issabel CallCenter.
# Run with --help for usage; the usage() function below is the authoritative
# description of what this installer does.

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
NC='\033[0m' # No Color
GITHUB_ACCOUNT='ISSABELPBX'

# Resolve this script's directory up front: it is reported to the user when a
# previous installation blocks the install, reused by the --local branch, and
# used to locate the repository's VERSION file below.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# The repository's VERSION file is the single source of truth for the release
# number. Read it rather than carrying a second copy here, which is what let the
# installer and the changelog drift apart in the past. A missing file is not
# fatal: it only affects what is printed, never what is installed.
read_repo_version() {
    local f="$1/VERSION"
    [ -f "$f" ] || return 1
    local v
    v=$(head -1 "$f" 2>/dev/null | tr -d '\r' | tr -d '[:space:]')
    [ -n "$v" ] || return 1
    printf '%s' "$v"
}

REPO_VERSION="$(read_repo_version "$SCRIPT_DIR/../..")" || REPO_VERSION='unknown'

usage() {
    cat <<EOF
Issabel Call Center ${REPO_VERSION} installer

Usage: $(basename "$0") [options]

Options:
  -l, --local   Install from the checkout this script lives in (locally)
                instead of cloning the repository from GitHub.
  -h, --help    Show this help and exit

Requires Asterisk 18 - the installer aborts on any other version.

Must be run as root, and only performs a CLEAN install: it aborts when a previous
Call Center installation is detected. Remove that one first with
  bash ${SCRIPT_DIR}/remove-issabel-callcenter.sh
answering 'n' to its database question to keep your existing data.

The installer sets the asterisk user's shell to /bin/bash, then enables and starts
issabeldialer and runs asterisk -rx 'core reload'.

The ECCP port (20005) that agent consoles connect to is TLS-only, and the dialer
creates /etc/issabel/dialer/(eccp.pem,eccp.key) pair.
Manage the certificate later (renew, remove, force a new one) with
/opt/issabel/dialer/eccp-cert.sh.

  ECCP_CERT_MODE=generate       dedicated self-signed certificate (default)
  ECCP_CERT_MODE=generate-san   same, plus SANs for this host's names and IPs
  ECCP_CERT_MODE=copy           reuse Issabel's Apache certificate instead
  ECCP_SRC_CERT=, ECCP_SRC_KEY= source paths for copy mode (also used as the
                                fallback if certificate generation fails)

  Note: the variables above only apply when no certificate exists yet. An
  existing eccp.pem/eccp.key pair is kept as-is, so on a reinstall they have
  no effect.

Examples:
  bash $(basename "$0")                    # install from GitHub
  bash $(basename "$0") --local            # install from this checkout
  ECCP_CERT_MODE=copy bash $(basename "$0") --local
  ECCP_CERT_MODE=copy ECCP_SRC_CERT=/path/to/cert.pem ECCP_SRC_KEY=/path/to/key.pem \\
    bash $(basename "$0") --local
EOF
}

# Parse arguments. This runs before the root check below so that --help works
# for any user, and rejects anything unrecognised rather than falling through
# into a full installation.
LOCAL_INSTALL=false
ORIG_ARGS="$*"   # kept for the "sudo bash ..." hint below, which runs after the shifts
while [ $# -gt 0 ]; do
    case "$1" in
        -l|--local) LOCAL_INSTALL=true ;;
        -h|--help)  usage; exit 0 ;;
        *)
            echo -e "${RED}Error: unknown option '$1'${NC}" >&2
            echo "Run 'bash $0 --help' for usage." >&2
            exit 2
            ;;
    esac
    shift
done

# Must run as root: the installer writes to /opt, /etc and /var/www, manages
# systemd units and the database, and installs the ECCP TLS certificate.
if [ "$(id -u)" -ne 0 ]; then
    echo -e "${RED}Error: this installer must be run as root.${NC}"
    echo -e "${YELLOW}Try:  sudo bash $0 $ORIG_ARGS${NC}"
    exit 1
fi

# Refuse to install over an existing installation. Installing on top of a
# previous version leaves stale files behind and cannot reliably migrate
# configuration, so a clean install is required.
FOUND_MARKERS=""
[ -d /opt/issabel/dialer ] && FOUND_MARKERS="${FOUND_MARKERS}  - /opt/issabel/dialer\n"
[ -f /etc/systemd/system/issabeldialer.service ] && FOUND_MARKERS="${FOUND_MARKERS}  - /etc/systemd/system/issabeldialer.service\n"
[ -f /etc/rc.d/init.d/issabeldialer ] && FOUND_MARKERS="${FOUND_MARKERS}  - /etc/rc.d/init.d/issabeldialer\n"
if rpm -q issabel-callcenter &> /dev/null; then
    FOUND_MARKERS="${FOUND_MARKERS}  - RPM package: $(rpm -q issabel-callcenter)\n"
fi

if [ -n "$FOUND_MARKERS" ]; then
    # Prefer the deployed VERSION file. Fall back to the first line of a deployed
    # CHANGELOG: installations made before VERSION was introduced shipped that
    # file under that name, so the legacy path is what is on disk there.
    INSTALLED_VERSION="$(read_repo_version /usr/share/issabel/module_installer/callcenter)" \
        || INSTALLED_VERSION=""
    if [ -z "$INSTALLED_VERSION" ] && \
       [ -f /usr/share/issabel/module_installer/callcenter/CHANGELOG ]; then
        INSTALLED_VERSION=$(head -1 /usr/share/issabel/module_installer/callcenter/CHANGELOG 2>/dev/null | tr -d '\r')
    fi

    echo -e "${RED}Error: an Issabel CallCenter dialer is already installed on this system.${NC}"
    if [ -n "$INSTALLED_VERSION" ]; then
        echo -e "${YELLOW}Installed version: ${INSTALLED_VERSION}${NC}"
        echo -e "${YELLOW}This installer:    ${REPO_VERSION}${NC}"
    fi
    echo
    echo "Detected:"
    echo -e "$FOUND_MARKERS"
    echo -e "${YELLOW}This installer only performs a clean installation.${NC}"
    echo "Remove the existing installation first, then run this script again:"
    echo
    echo "    bash ${SCRIPT_DIR}/remove-issabel-callcenter.sh"
    echo
    echo "The removal script asks whether to delete the call_center database."
    echo "Answer 'n' to KEEP your existing data (agents, campaigns, calls, forms,"
    echo "break definitions and reports); the new installation will reuse it."
    echo "Answer 'y' only if you want to start from an empty database."
    exit 1
fi

# Check Asterisk version. This release targets Asterisk 18 and nothing else, so
# any other version is refused up front rather than half-installed: the check
# runs before the first file is written, so an aborted run changes nothing.
ASTERISK_VERSION=$(asterisk -rx "core show version" 2>/dev/null | awk '{print $2}' | cut -d. -f 1)

if [ -z "$ASTERISK_VERSION" ]; then
    echo -e "${RED}Error: Cannot detect Asterisk version. Is Asterisk running?${NC}"
    exit 1
fi

if [ "$ASTERISK_VERSION" != "18" ]; then
    echo -e "${RED}Error: Issabel CallCenter ${REPO_VERSION} requires Asterisk 18.${NC}"
    echo -e "${RED}Detected Asterisk version: ${ASTERISK_VERSION}${NC}"
    echo -e "${RED}Installation aborted - nothing was installed or modified.${NC}"
    exit 1
fi

echo -e "${GREEN}Info: Detected Asterisk $ASTERISK_VERSION. Using app_agent_pool mode.${NC}"
echo -e "${GREEN}  - Agent authentication: via ECCP/database${NC}"
echo -e "${GREEN}  - Agent interface: Local/XXXX@agents${NC}"
echo -e "${GREEN}  - Agent logout: Hangup login channel${NC}"
echo

# Determine source directory
if [ "$LOCAL_INSTALL" = true ]; then
    # Find the repository root (two levels up from this script)
    REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

    if [ ! -f "$REPO_ROOT/menu.xml" ]; then
        echo -e "${RED}Error: Cannot find repository root. Expected menu.xml at $REPO_ROOT${NC}"
        exit 1
    fi

    echo -e "${GREEN}Installing Issabel CallCenter from local directory: $REPO_ROOT${NC}"
    WORK_DIR="$REPO_ROOT"
else
    echo -e "${GREEN}Installing Issabel CallCenter from GitHub: ${GITHUB_ACCOUNT}/callcenter-issabel5${NC}"

    # Install git if not present
    if ! command -v git &> /dev/null; then
        echo "Installing git..."
        dnf -y install git || yum -y install git
    fi

    # Clone repository
    cd /usr/src
    rm -rf callcenter
    echo "Cloning repository..."
    if ! git clone "https://github.com/${GITHUB_ACCOUNT}/callcenter-issabel5.git" callcenter; then
        echo -e "${RED}Error: Failed to clone repository${NC}"
        exit 1
    fi
    WORK_DIR="/usr/src/callcenter"
fi

cd "$WORK_DIR"

# The clone may be newer than the checkout this script was launched from, so
# report and deploy the version actually being installed.
REPO_VERSION="$(read_repo_version "$WORK_DIR")" || true

echo "Installing modules..."
# Install modules (force overwrite)
chown asterisk.asterisk modules/* -R
/bin/cp -prf modules/* /var/www/html/modules/

echo "Patching dashboard ProcessesStatus applet..."
DASHBOARD_DIR="/var/www/html/modules/dashboard/applets/ProcessesStatus"
DASHBOARD_INDEX="$DASHBOARD_DIR/index.php"

if [ -f "$DASHBOARD_INDEX" ]; then
    # Copy the dialer icon
    /bin/cp -f "$WORK_DIR/setup/icon_headphones.png" "$DASHBOARD_DIR/images/"

    # 1. Add Dialer icon mapping (after 'Apache' => 'icon_www.png')
    if ! grep -q "'Dialer'" "$DASHBOARD_INDEX"; then
        sed -i "/'Apache'.*=>.*'icon_www.png'/a\\            'Dialer'    =>  'icon_headphones.png'," "$DASHBOARD_INDEX"
    fi

    # 2. Add Dialer service mapping in _controlServicio (after 'Apache' => 'httpd')
    if ! grep -q "'Dialer'.*=>.*'issabeldialer'" "$DASHBOARD_INDEX"; then
        sed -i "/'Apache'.*=>.*'httpd'/a\\            'Dialer'    =>  'issabeldialer'," "$DASHBOARD_INDEX"
    fi

    # 3. Add Dialer status detection (after Apache status line in getStatusServices)
    if ! grep -q 'dialerd.pid' "$DASHBOARD_INDEX"; then
        sed -i '/\$arrSERVICES\["Apache"\]\["name_service"\].*=.*"Web Server"/a\
\
        $arrSERVICES["Dialer"]["status_service"]   = $this->_existPID_ByFile("/opt/issabel/dialer/dialerd.pid","issabeldialer");\
        $arrSERVICES["Dialer"]["activate"]     = $this->_isActivate("issabeldialer");\
        $arrSERVICES["Dialer"]["name_service"]     = "Issabel Call Center Service";' "$DASHBOARD_INDEX"
    fi

    # 4. Fix _existService() to check /etc/systemd/system/ (for systemd services installed in /etc)
    # Check if the fix is already applied by looking for the specific pattern
    if ! grep -q 'file_exists("/etc/systemd/system/{$ns}.service")' "$DASHBOARD_INDEX"; then
        sed -i 's|if (file_exists("/usr/lib/systemd/system/{$ns}.service"))|if (file_exists("/etc/systemd/system/{$ns}.service"))\n                return TRUE;\n            if (file_exists("/usr/lib/systemd/system/{$ns}.service"))|' "$DASHBOARD_INDEX"
        echo "  - Added _existService() fix for /etc/systemd/system/ detection"
    else
        echo "  - _existService() fix already present, skipping"
    fi

    echo -e "${GREEN}Dashboard patched successfully${NC}"
else
    echo -e "${YELLOW}Warning: Dashboard applet not found at $DASHBOARD_INDEX - skipping patch${NC}"
fi

echo "Installing dialer..."
# Install dialer
mkdir -p /opt/issabel/dialer/
chmod 755 /opt/issabel/dialer/
/bin/cp -rf setup/dialer_process/dialer/ /opt/issabel/
chmod +x /opt/issabel/dialer/dialerd

# Install systemd service file
/bin/cp -f setup/dialer_process/issabeldialer.service /etc/systemd/system/
systemctl daemon-reload

# Install logrotate config
mkdir -p /etc/logrotate.d/
/bin/cp -f setup/issabeldialer.logrotate /etc/logrotate.d/issabeldialer

# Create callcenter module log directory
mkdir -p /var/log/callcenter-module/
chown asterisk:asterisk /var/log/callcenter-module/
chmod 750 /var/log/callcenter-module/

# Install web modules logrotate config
/bin/cp -f setup/callcenter-modules.logrotate /etc/logrotate.d/callcenter-modules

# Install DNC script
/bin/cp -f setup/usr/bin/issabel-callcenter-local-dnc /usr/bin/

# Set ownership
chown asterisk.asterisk /opt/issabel -R

echo "Installing ECCP TLS certificate..."
# The ECCP port (20005) is TLS-only, and the dialer runs as the unprivileged
# asterisk user, so it needs its own readable copy of a certificate and key.
# Existing certificates are kept, so upgrades never churn working TLS material.
if ! command -v openssl &> /dev/null; then
    dnf -y install openssl || yum -y install openssl
fi
if ! bash /opt/issabel/dialer/eccp-cert.sh install; then
    echo -e "${RED}Error: could not install the ECCP TLS certificate.${NC}"
    echo -e "${RED}The dialer will refuse to start its ECCP listener without it.${NC}"
    exit 1
fi

echo "Installing module installer files..."
# Install module installer files
rm -rf /usr/share/issabel/module_installer/callcenter/
mkdir -p /usr/share/issabel/module_installer/callcenter/
/bin/cp -rf setup/ /usr/share/issabel/module_installer/callcenter/
/bin/cp -f menu.xml /usr/share/issabel/module_installer/callcenter/
/bin/cp -f CHANGELOG_OLD.md /usr/share/issabel/module_installer/callcenter/
/bin/cp -f VERSION          /usr/share/issabel/module_installer/callcenter/

# Merge menu
echo "Merging menu..."
issabel-menumerge /usr/share/issabel/module_installer/callcenter/menu.xml

# Install SSE Apache config only on Rocky/PHP-FPM systems
if [ -f /etc/rocky-release ]; then
    /bin/cp -f /usr/share/issabel/module_installer/callcenter/setup/issabel-sse.conf /etc/httpd/conf.d/
    systemctl reload httpd 2>/dev/null || true
fi

# Run database installer
echo "Running database installer..."
mkdir -p /tmp/new_module/callcenter
/bin/cp -rf /usr/share/issabel/module_installer/callcenter/* /tmp/new_module/callcenter/
chown -R asterisk.asterisk /tmp/new_module/callcenter

php /tmp/new_module/callcenter/setup/installer.php
rm -rf /tmp/new_module

# Set shell for user asterisk (required for dialer to work)
echo "Configuring asterisk user shell..."
if ! rpm -q util-linux-user &>/dev/null; then
    dnf install -y util-linux-user 2>/dev/null || yum install -y util-linux-user 2>/dev/null || true
fi

# Use usermod instead of chsh to avoid interactive prompts
if id asterisk &>/dev/null; then
    usermod -s /bin/bash asterisk 2>/dev/null || chsh -s /bin/bash asterisk </dev/null 2>/dev/null || true
fi

# Enable and start the systemd service
echo "Enabling issabeldialer service..."
systemctl enable issabeldialer

# Restart dialer if already running, otherwise start it
if systemctl is-active --quiet issabeldialer; then
    echo -e "${GREEN}Restarting issabeldialer service...${NC}"
    systemctl restart issabeldialer
else
    echo -e "${GREEN}Starting issabeldialer service...${NC}"
    systemctl start issabeldialer
fi

# Reload Asterisk
asterisk -rx'core reload' 2>/dev/null || true

# Clean up cloned repository if installed from GitHub
if [ "$LOCAL_INSTALL" = false ]; then
    rm -rf /usr/src/callcenter
fi

echo
echo -e "${GREEN}============================================${NC}"
echo -e "${GREEN}Issabel CallCenter ${REPO_VERSION} installation complete!${NC}"
echo -e "${GREEN}============================================${NC}"
echo

# Post-install reminders for settings the installer deliberately does not touch.
# Everything here is read-only and failure-tolerant: a probe that cannot answer
# prints "unknown" and the recommendation is shown anyway. Never changes $?.
print_post_install_notice() {
    local maxconn parkingtime parkpos parkstart parkend parkslots parkfiles parkfilehint f v
    local rootpw

    # --- MariaDB max_connections -------------------------------------------
    maxconn='unknown'
    rootpw=$(awk -F= '/^mysqlrootpwd/{print $2}' /etc/issabel.conf 2>/dev/null)
    if [ -n "$rootpw" ] && command -v mysql &> /dev/null; then
        # MYSQL_PWD (not -p<pw>) so the root password never appears in ps output
        v=$(MYSQL_PWD="$rootpw" mysql -uroot -N -B \
                -e "SHOW VARIABLES LIKE 'max_connections'" 2>/dev/null \
            | awk '{print $2}')
        [ -n "$v" ] && maxconn="$v"
    fi
    unset rootpw

    # --- Parking lot: timeout and slot range --------------------------------
    # Agent hold parks into the call center's own lot, written by the installer
    # into res_parking_custom_general.conf. The PBX "default" lot is irrelevant
    # to hold, so it is deliberately not probed here.
    parkfilehint="res_parking_custom_general.conf"
    parkfiles="/etc/asterisk/$parkfilehint"
    parkingtime='unknown'
    parkpos='unknown'
    for f in $parkfiles; do
        [ -r "$f" ] || continue
        v=$(grep -E '^[[:space:]]*parkingtime[[:space:]]*=' "$f" 2>/dev/null \
            | tail -n1 | cut -d= -f2 | tr -d '[:space:]')
        [ -n "$v" ] && parkingtime="$v"
        v=$(grep -E '^[[:space:]]*parkpos[[:space:]]*=' "$f" 2>/dev/null \
            | tail -n1 | cut -d= -f2 | tr -d '[:space:]')
        [ -n "$v" ] && parkpos="$v"
    done

    parkslots='unknown'
    parkstart=${parkpos%%-*}
    parkend=${parkpos##*-}
    if [ "$parkpos" != "unknown" ] && [ "$parkstart" != "$parkend" ] &&
       [ -n "$parkstart" ] && [ -n "$parkend" ] &&
       [ "$parkstart" -ge 0 ] 2>/dev/null && [ "$parkend" -ge "$parkstart" ] 2>/dev/null; then
        parkslots=$(( parkend - parkstart + 1 ))
    fi

    echo
    echo -e "${YELLOW}============================================${NC}"
    echo -e "${YELLOW} POST-INSTALL: settings to review${NC}"
    echo -e "${YELLOW}============================================${NC}"
    echo
    echo -e "${YELLOW}1) Agent Hold uses its own parking lot 'callcenter_hold'${NC}"
    echo "   parkpos     = ${parkpos} (${parkslots} slots - the cap on concurrent holds)"
    echo "   ext: 70000, It has 100 slots 70001-70100 , consider not using these numbers"
    echo
    return 0
}

print_post_install_notice
