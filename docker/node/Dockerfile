FROM node:20-alpine

# Install dumb-init for proper signal handling
RUN apk add --no-cache dumb-init

# Set working directory
WORKDIR /var/www

# Change ownership of working directory to user with UID 1000 (existing user in Alpine)
RUN chown -R 1000:1000 /var/www

# Set npm cache directory to writable location
ENV npm_config_cache=/tmp/.npm

# Create npm cache directory with proper permissions
RUN mkdir -p /tmp/.npm && chown -R 1000:1000 /tmp/.npm

# Switch to user with UID 1000 (existing node user in Alpine)
USER 1000

ENTRYPOINT ["dumb-init", "--"]