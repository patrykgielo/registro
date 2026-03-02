#!/bin/bash

# Domain to add
DOMAIN="registro.local"
DOMAIN_ALIAS="www.registro.local"

# Check if running as root/sudo
if [ "$(id -u)" -ne 0 ]; then
   echo "This script must be run as root or with sudo" 
   exit 1
fi

# Check if entry already exists
if grep -q "$DOMAIN" /etc/hosts; then
    echo "Host entry for $DOMAIN already exists in /etc/hosts"
else
    # Add entry to hosts file
    echo "Adding $DOMAIN to /etc/hosts..."
    echo "127.0.0.1 $DOMAIN $DOMAIN_ALIAS" >> /etc/hosts
    echo "Done! Added entry: 127.0.0.1 $DOMAIN $DOMAIN_ALIAS"
fi

echo "You can now access the application at:"
echo "HTTP (redirects to HTTPS): http://$DOMAIN:8081"
echo "HTTPS: https://$DOMAIN:8444"
echo "Filament Admin Panel: https://$DOMAIN:8444/admin"
echo "Vite Dev Server: http://$DOMAIN:5173"