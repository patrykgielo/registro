#!/bin/bash

echo "=== Registro Docker Setup ==="
echo "Initializing Docker environment for registro.local"
echo ""

# Check if user is in docker group
if ! groups | grep -q '\bdocker\b'; then
    echo "WARNING: Your user is not in the docker group. Docker commands might fail."
    echo "Add your user to docker group: sudo usermod -aG docker $USER"
    echo "Then log out and log back in, or run this script with sudo."
    echo ""
fi

# Clean existing root-owned node_modules if it exists
if [ -d "app/node_modules" ]; then
    echo "Cleaning existing root-owned node_modules..."
    sudo rm -rf app/node_modules || {
        echo "WARNING: Could not remove root-owned node_modules."
        echo "You may need to run: sudo rm -rf app/node_modules"
        echo "Press Enter to continue or Ctrl+C to exit..."
        read -r
    }
fi

# Generate SSL certificates
echo "Generating SSL certificates..."
./docker/ssl/generate-certificates.sh

# Copy .env file for Docker environment
if [ ! -f "app/.env" ]; then
    echo "Creating .env file from .env.example..."
    cp app/.env.example app/.env
fi

# Update .env for Docker environment
echo "Configuring environment for Docker..."
sed -i 's|DB_CONNECTION=sqlite|DB_CONNECTION=mysql|g' app/.env
sed -i 's|# DB_HOST=127.0.0.1|DB_HOST=registro-mysql|g' app/.env
sed -i 's|# DB_PORT=3306|DB_PORT=3306|g' app/.env
sed -i 's|# DB_DATABASE=laravel|DB_DATABASE=registro|g' app/.env
sed -i 's|# DB_USERNAME=root|DB_USERNAME=registro|g' app/.env
sed -i 's|# DB_PASSWORD=|DB_PASSWORD=password|g' app/.env
sed -i 's|APP_URL=http://localhost|APP_URL=https://registro.local:8443|g' app/.env

# Build and start containers
echo "Building and starting Docker containers..."
docker compose up -d --build

# Wait for services to be ready
echo "Waiting for services to be ready..."
sleep 15

# Install PHP dependencies
echo "Installing Composer dependencies..."
docker compose exec app composer install --no-interaction

# Generate application key (if not already set)
echo "Setting up Laravel application..."
docker compose exec app php artisan key:generate --no-interaction

# Run migrations
echo "Running database migrations and seeders..."
docker compose exec app php artisan migrate:fresh --seed --no-interaction

# Create Livewire temporary upload directory
echo "Creating Livewire temporary upload directory..."
docker compose exec app mkdir -p storage/app/livewire-tmp
docker compose exec app chmod 775 storage/app/livewire-tmp
echo "✓ Livewire tmp directory created"

echo ""
echo "Setup completed successfully!"
echo ""
echo "IMPORTANT: You need to add the domain to your hosts file."
echo "Run the following command (you'll need to enter your password):"
echo "sudo ./add-hosts-entry.sh"
echo ""
echo "Once that's done, you can access the application at:"
echo "https://registro.local:8443"
echo ""
echo "Useful commands:"
echo "- View logs: docker compose logs -f"
echo "- Run artisan: docker compose exec app php artisan <command>"
echo "- Run npm: docker compose exec node npm <command>"
echo "- Stop containers: docker compose down"