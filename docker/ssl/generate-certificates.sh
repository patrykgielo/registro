#!/bin/bash

# Set variables
DOMAIN="registro.local"
SSL_DIR="$(dirname "$0")"
CERT_FILE="$SSL_DIR/cert.pem"
KEY_FILE="$SSL_DIR/key.pem"

# Check if certificates already exist
if [ -f "$CERT_FILE" ] && [ -f "$KEY_FILE" ]; then
    echo "Certificates already exist in $SSL_DIR"
    echo "Remove them if you want to generate new ones."
    exit 0
fi

# Generate self-signed certificate
echo "Generating self-signed SSL certificate for $DOMAIN..."
openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
    -keyout "$KEY_FILE" \
    -out "$CERT_FILE" \
    -subj "/CN=$DOMAIN/O=Registro/C=US" \
    -addext "subjectAltName=DNS:$DOMAIN,DNS:www.$DOMAIN"

echo "Certificate generated:"
echo "  - Private key: $KEY_FILE"
echo "  - Certificate: $CERT_FILE"
echo ""
echo "Don't forget to add the following entry to your /etc/hosts file:"
echo "127.0.0.1 $DOMAIN www.$DOMAIN"