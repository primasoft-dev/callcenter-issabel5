#!/bin/bash

# ECCP TLS certificate management for the Issabel Call Center dialer.
#
# The ECCP listener (port 20005) is TLS-only. The dialer runs as the
# unprivileged `asterisk` user and therefore needs its own readable copy of a
# certificate and key: Issabel's Apache material is 0600 root.
#
# By default clients do NOT verify this certificate (encryption only, no server
# authentication), so its subject, SANs and expiry are irrelevant. That is what
# keeps the dialer reachable as localhost, as a hostname, or as a bare LAN IP
# with no local DNS. Clients that want protection from an active
# man-in-the-middle pin the certificate by its SHA-256 fingerprint, which works
# in every mode below and needs no SAN.
#
# Usage:
#   eccp-cert.sh install [--force]   # place cert+key (skips if already present)
#   eccp-cert.sh renew               # re-copy from the source certificate and
#                                    #   restart the dialer (for Let's Encrypt
#                                    #   renewals; use as a certbot deploy hook)
#   eccp-cert.sh remove              # delete cert+key
#
# Environment:
#   ECCP_CERT_MODE=generate|generate-san|copy
#     generate      (default) dedicated self-signed ECDSA P-256, no SAN.
#                   Reachable by any name or IP; pin the fingerprint for MITM
#                   protection.
#     generate-san  same, but with SANs for localhost, this host's names and
#                   every local IP. Only useful to a client that additionally
#                   wants hostname verification; pinning does not need it.
#     copy          reuse Issabel's Apache certificate. Sensible when that is a
#                   real CA-issued certificate (Let's Encrypt), which lets a
#                   client do standard CA + hostname verification. Note the
#                   fingerprint changes on every renewal, so pinned clients must
#                   be updated - see `renew`.
#   ECCP_SRC_CERT / ECCP_SRC_KEY     source paths for copy/renew (default: the
#                                    Issabel/Apache certificate below)

CERT_DIR="/etc/issabel/dialer"
CERT_FILE="$CERT_DIR/eccp.pem"
KEY_FILE="$CERT_DIR/eccp.key"

SRC_CERT="${ECCP_SRC_CERT:-/etc/pki/tls/certs/localhost.crt}"
SRC_KEY="${ECCP_SRC_KEY:-/etc/pki/tls/private/localhost.key}"

CERT_MODE="${ECCP_CERT_MODE:-generate}"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
NC='\033[0m'

# Every name and address this host may be reached by, for the generate-san mode.
build_san() {
    local san="DNS:localhost,IP:127.0.0.1" n
    for n in $(hostname -f 2>/dev/null) $(hostname -s 2>/dev/null); do
        [ -n "$n" ] && [ "$n" != "localhost" ] && san="${san},DNS:${n}"
    done
    for n in $(hostname -I 2>/dev/null); do
        [ "$n" != "127.0.0.1" ] && san="${san},IP:${n}"
    done
    echo "$san"
}

# A dedicated certificate rather than Issabel's Apache one, for three reasons:
#
#  1. Pinning stability. Clients that pin the fingerprint would break on every
#     web certificate renewal; a dedicated 10-year certificate does not move.
#  2. Handshake cost. ECDSA P-256 signs ~29x faster than RSA-2048 (0.026 ms vs
#     0.767 ms measured), and that signing happens inside the single-threaded
#     ECCP process.
#  3. Identity separation: an ECCP key compromise does not also impersonate the
#     web interface and SIP/WSS TLS.
#
# Note this is NOT about keeping the web key away from the asterisk user:
# Issabel already ships that same key and certificate as
# /etc/asterisk/keys/asterisk.pem, owned by asterisk. Using copy mode therefore
# exposes nothing new.
generate_selfsigned() {
    if ! command -v openssl >/dev/null 2>&1; then
        echo -e "${RED}Error: openssl is required to generate an ECCP certificate${NC}"
        return 1
    fi
    local sanopt=""
    [ "$CERT_MODE" = "generate-san" ] && sanopt="-addext subjectAltName=$(build_san)"
    openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 \
        -sha256 -nodes -days 3650 \
        -subj "/C=--/O=Issabel/OU=CallCenter/CN=issabel-eccp" \
        $sanopt \
        -keyout "$KEY_FILE" -out "$CERT_FILE" >/dev/null 2>&1
}

do_install() {
    local force=false
    [ "$1" = "--force" ] && force=true

    if [ -f "$CERT_FILE" ] && [ -f "$KEY_FILE" ] && [ "$force" = false ]; then
        echo -e "${GREEN}ECCP TLS certificate already present at $CERT_FILE - keeping it${NC}"
        return 0
    fi

    mkdir -p "$CERT_DIR" || return 1

    local mode="$CERT_MODE"
    if [ "$mode" = "generate-san" ]; then mode="generate"; fi
    if [ "$mode" = "copy" ] && { [ ! -r "$SRC_CERT" ] || [ ! -r "$SRC_KEY" ]; }; then
        echo -e "${YELLOW}Issabel certificate not found at $SRC_CERT - generating a dedicated one instead${NC}"
        mode="generate"
    fi

    if [ "$mode" = "copy" ]; then
        /bin/cp -f "$SRC_CERT" "$CERT_FILE" || return 1
        /bin/cp -f "$SRC_KEY"  "$KEY_FILE"  || return 1
        echo "Copied Issabel certificate for ECCP use"
    else
        if ! generate_selfsigned; then
            echo -e "${YELLOW}Could not generate a certificate - falling back to Issabel's${NC}"
            [ -r "$SRC_CERT" ] && [ -r "$SRC_KEY" ] || return 1
            /bin/cp -f "$SRC_CERT" "$CERT_FILE" || return 1
            /bin/cp -f "$SRC_KEY"  "$KEY_FILE"  || return 1
        elif [ "$CERT_MODE" = "generate-san" ]; then
            echo "Generated a dedicated ECDSA P-256 certificate (with SANs) for ECCP use"
        else
            echo "Generated a dedicated ECDSA P-256 certificate for ECCP use"
        fi
    fi

    chown asterisk:asterisk "$CERT_DIR" "$CERT_FILE" "$KEY_FILE" || return 1
    chmod 0750 "$CERT_DIR"
    chmod 0444 "$CERT_FILE"
    chmod 0400 "$KEY_FILE"

    # The dialer runs as asterisk - prove it can actually read the key, since a
    # TLS-only listener that cannot load its key refuses to start.
    if ! su -s /bin/bash asterisk -c "test -r '$KEY_FILE'" 2>/dev/null; then
        echo -e "${RED}Error: $KEY_FILE is not readable by the asterisk user${NC}"
        return 1
    fi

    echo -e "${GREEN}ECCP TLS certificate installed at $CERT_FILE${NC}"

    # The fingerprint is what an administrator distributes to remote clients
    # that want to pin this certificate and defeat a man-in-the-middle.
    if command -v openssl >/dev/null 2>&1; then
        echo "SHA-256 fingerprint: $(openssl x509 -in "$CERT_FILE" -noout -fingerprint -sha256 2>/dev/null | cut -d= -f2)"
    fi
    return 0
}

# Refresh the certificate from its source and put it into service. Intended as
# a certbot --deploy-hook so a renewed web certificate reaches the dialer.
#
# The dialer loads the certificate once, when it creates the listener, so a
# renewal only takes effect after a restart. That is done here rather than left
# to the administrator, because a certificate that is never loaded is the same
# as no renewal at all.
do_renew() {
    if [ ! -r "$SRC_CERT" ] || [ ! -r "$SRC_KEY" ]; then
        echo -e "${RED}Error: source certificate not readable ($SRC_CERT / $SRC_KEY)${NC}"
        echo "Set ECCP_SRC_CERT and ECCP_SRC_KEY if the certificate lives elsewhere."
        return 1
    fi

    local oldfp=""
    [ -f "$CERT_FILE" ] && oldfp=$(openssl x509 -in "$CERT_FILE" -noout -fingerprint -sha256 2>/dev/null | cut -d= -f2)

    mkdir -p "$CERT_DIR" || return 1
    /bin/cp -f "$SRC_CERT" "$CERT_FILE" || return 1
    /bin/cp -f "$SRC_KEY"  "$KEY_FILE"  || return 1
    chown asterisk:asterisk "$CERT_DIR" "$CERT_FILE" "$KEY_FILE" || return 1
    chmod 0750 "$CERT_DIR"; chmod 0444 "$CERT_FILE"; chmod 0400 "$KEY_FILE"

    if ! su -s /bin/bash asterisk -c "test -r '$KEY_FILE'" 2>/dev/null; then
        echo -e "${RED}Error: $KEY_FILE is not readable by the asterisk user${NC}"
        return 1
    fi

    local newfp
    newfp=$(openssl x509 -in "$CERT_FILE" -noout -fingerprint -sha256 2>/dev/null | cut -d= -f2)
    echo -e "${GREEN}ECCP TLS certificate refreshed from $SRC_CERT${NC}"
    echo "SHA-256 fingerprint: $newfp"

    if [ -n "$oldfp" ] && [ "$oldfp" != "$newfp" ]; then
        echo -e "${YELLOW}The fingerprint changed. Any ECCP client that pins this${NC}"
        echo -e "${YELLOW}certificate must be updated with the new value above.${NC}"
    fi

    # Put the new certificate into service; the running dialer holds the old one.
    if [ "$ECCP_CERT_NO_RESTART" = "1" ]; then
        echo -e "${YELLOW}Not restarting (ECCP_CERT_NO_RESTART=1). Restart issabeldialer${NC}"
        echo -e "${YELLOW}to load the new certificate.${NC}"
    elif systemctl is-active --quiet issabeldialer 2>/dev/null; then
        echo "Restarting issabeldialer to load the new certificate..."
        systemctl restart issabeldialer && echo -e "${GREEN}Dialer restarted${NC}"
    else
        echo "Dialer is not running; the new certificate loads on its next start."
    fi
    return 0
}

do_remove() {
    rm -f "$CERT_FILE" "$KEY_FILE"
    # Only remove the directories we own, and only when empty, so an unrelated
    # /etc/issabel is never clobbered.
    rmdir "$CERT_DIR" 2>/dev/null
    rmdir /etc/issabel 2>/dev/null
    echo "Removed ECCP TLS certificate"
    return 0
}

case "$1" in
    install) do_install "$2" ;;
    renew)   do_renew ;;
    remove)  do_remove ;;
    *)       echo "Usage: $0 {install [--force]|renew|remove}" ; exit 1 ;;
esac
