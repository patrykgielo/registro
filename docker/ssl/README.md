# Trusting Self-Signed SSL Certificates

When using self-signed certificates, browsers will display a security warning. Here's how to make your browser trust the certificate:

## Chrome/Edge/Brave (Windows/Linux/macOS)

1. Open https://registro.local:8443
2. Click on "Not secure" in the address bar
3. Click on "Certificate (Invalid)"
4. Details tab > Export (Save the certificate somewhere)
5. Then follow OS-specific instructions:

### Windows
1. Double-click the exported certificate file
2. Click "Install Certificate"
3. Select "Current User" and click "Next"
4. Select "Place all certificates in the following store"
5. Click "Browse" and select "Trusted Root Certification Authorities"
6. Click "Next" and then "Finish"

### macOS
1. Double-click the exported certificate
2. It will be added to your Keychain
3. Open Keychain Access
4. Find the certificate (search for "registro")
5. Double-click it, expand "Trust", and set "When using this certificate" to "Always Trust"

### Linux (Ubuntu/Debian)
```bash
# Copy the certificate to the CA certificates directory
sudo cp docker/ssl/cert.pem /usr/local/share/ca-certificates/registro.local.crt

# Update the CA certificates
sudo update-ca-certificates
```

## Firefox (All Platforms)
1. Open Firefox and go to https://registro.local:8443
2. Click "Advanced"
3. Click "Accept the Risk and Continue"
4. To permanently trust:
   - Open Preferences/Options
   - Search for "certificates"
   - Click "View Certificates"
   - Go to "Servers" tab
   - Click "Add Exception"
   - Enter "https://registro.local:8443" and click "Get Certificate"
   - Make sure "Permanently store this exception" is checked
   - Click "Confirm Security Exception"