#!/usr/bin/env bash

################################################################################
# backup-database.sh - MySQL database backup script with rotation
#
# This script creates compressed MySQL backups with automatic rotation
# and optional S3/cloud storage upload.
#
# Usage: ./scripts/backup-database.sh [OPTIONS]
#
# Options:
#   --retention-days NUM    Number of days to keep backups (default: 30)
#   --backup-dir PATH       Backup directory (default: /var/backups/registro)
#   --compress              Compress backups with gzip (default: true)
#   --upload-s3             Upload to S3 bucket (requires AWS CLI)
#   --s3-bucket NAME        S3 bucket name (required if --upload-s3)
#
# Prerequisites:
#   - Docker containers running
#   - Sufficient disk space for backups
#   - (Optional) AWS CLI configured for S3 uploads
#
# Cron example (daily at 2 AM):
#   0 2 * * * /var/www/registro/scripts/backup-database.sh
#
# Author: Registro Development Team
# Version: 1.0.0
################################################################################

set -e  # Exit on error
set -u  # Exit on undefined variable
set -o pipefail  # Exit on pipe failure

# Color codes for output
readonly RED='\033[0;31m'
readonly GREEN='\033[0;32m'
readonly YELLOW='\033[1;33m'
readonly BLUE='\033[0;34m'
readonly NC='\033[0m' # No Color

# Script configuration
readonly SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
readonly DOCKER_COMPOSE_FILE="${PROJECT_ROOT}/docker-compose.prod.yml"
readonly ENV_FILE="${PROJECT_ROOT}/.env"

# Default configuration
BACKUP_DIR="/var/backups/registro"
RETENTION_DAYS=30
COMPRESS=true
UPLOAD_S3=false
S3_BUCKET=""

# Database configuration (will be loaded from .env)
DB_HOST="mysql"
DB_DATABASE=""
DB_USERNAME=""
DB_PASSWORD=""

################################################################################
# Helper Functions
################################################################################

log() {
    echo -e "${GREEN}[INFO]${NC} $(date '+%Y-%m-%d %H:%M:%S') - $*"
}

warn() {
    echo -e "${YELLOW}[WARN]${NC} $(date '+%Y-%m-%d %H:%M:%S') - $*"
}

error() {
    echo -e "${RED}[ERROR]${NC} $(date '+%Y-%m-%d %H:%M:%S') - $*" >&2
}

success() {
    echo -e "${GREEN}[SUCCESS]${NC} $(date '+%Y-%m-%d %H:%M:%S') - $*"
}

################################################################################
# Parse Command-Line Arguments
################################################################################

parse_arguments() {
    while [[ $# -gt 0 ]]; do
        case $1 in
            --retention-days)
                RETENTION_DAYS="$2"
                shift 2
                ;;
            --backup-dir)
                BACKUP_DIR="$2"
                shift 2
                ;;
            --compress)
                COMPRESS=true
                shift
                ;;
            --no-compress)
                COMPRESS=false
                shift
                ;;
            --upload-s3)
                UPLOAD_S3=true
                shift
                ;;
            --s3-bucket)
                S3_BUCKET="$2"
                shift 2
                ;;
            -h|--help)
                show_usage
                exit 0
                ;;
            *)
                error "Unknown option: $1"
                show_usage
                exit 1
                ;;
        esac
    done
}

show_usage() {
    cat << EOF
Usage: $0 [OPTIONS]

MySQL database backup script with automatic rotation.

OPTIONS:
    --retention-days NUM    Number of days to keep backups (default: 30)
    --backup-dir PATH       Backup directory (default: /var/backups/registro)
    --compress              Compress backups with gzip (default: enabled)
    --no-compress           Disable compression
    --upload-s3             Upload to S3 bucket
    --s3-bucket NAME        S3 bucket name (required if --upload-s3)
    -h, --help              Show this help message

EXAMPLES:
    $0                                      # Create backup with defaults
    $0 --retention-days 60                  # Keep backups for 60 days
    $0 --upload-s3 --s3-bucket my-bucket    # Upload to S3

CRON SETUP:
    # Daily backup at 2 AM
    0 2 * * * /var/www/registro/scripts/backup-database.sh >> /var/log/db-backup.log 2>&1

EOF
}

################################################################################
# Validation Functions
################################################################################

check_prerequisites() {
    log "Checking prerequisites..."

    # Check if Docker Compose file exists
    if [[ ! -f "$DOCKER_COMPOSE_FILE" ]]; then
        error "Production docker-compose file not found: $DOCKER_COMPOSE_FILE"
        exit 1
    fi

    # Check if MySQL container is running.
    # Not `ps mysql | grep -q "Up"`: under `set -o pipefail` grep -q exits on the
    # first match, docker compose takes SIGPIPE, and the pipeline reports failure
    # exactly when the container IS up -- refusing to back up a healthy database.
    if [[ "$(docker compose -f "$DOCKER_COMPOSE_FILE" ps mysql)" != *Up* ]]; then
        error "MySQL container is not running"
        exit 1
    fi

    # Check if .env file exists
    if [[ ! -f "$ENV_FILE" ]]; then
        error ".env file not found: $ENV_FILE"
        exit 1
    fi

    # Check S3 prerequisites
    if [[ "$UPLOAD_S3" == true ]]; then
        if [[ -z "$S3_BUCKET" ]]; then
            error "S3 bucket name required (--s3-bucket)"
            exit 1
        fi

        if ! command -v aws &> /dev/null; then
            error "AWS CLI not installed. Install with: apt-get install awscli"
            exit 1
        fi

        # Test S3 access
        if ! aws s3 ls "s3://$S3_BUCKET" &> /dev/null; then
            error "Cannot access S3 bucket: $S3_BUCKET"
            error "Check AWS credentials and bucket permissions"
            exit 1
        fi
    fi

    success "Prerequisites met"
}

load_database_config() {
    log "Loading database configuration..."

    # Source .env file and extract database credentials
    DB_DATABASE=$(grep "^DB_DATABASE=" "$ENV_FILE" | cut -d'=' -f2 | tr -d '"')
    DB_USERNAME=$(grep "^DB_USERNAME=" "$ENV_FILE" | cut -d'=' -f2 | tr -d '"')
    DB_PASSWORD=$(grep "^DB_PASSWORD=" "$ENV_FILE" | cut -d'=' -f2 | tr -d '"')

    if [[ -z "$DB_DATABASE" || -z "$DB_USERNAME" || -z "$DB_PASSWORD" ]]; then
        error "Database credentials not found in .env file"
        exit 1
    fi

    log "Database: $DB_DATABASE (user: $DB_USERNAME)"
}

################################################################################
# Backup Functions
################################################################################

create_backup_directory() {
    if [[ ! -d "$BACKUP_DIR" ]]; then
        log "Creating backup directory: $BACKUP_DIR"
        mkdir -p "$BACKUP_DIR"
        chmod 700 "$BACKUP_DIR"  # Secure directory
    fi
}

create_backup() {
    log "Creating database backup..."

    # Generate backup filename with timestamp
    local timestamp
    timestamp=$(date +%Y%m%d_%H%M%S)
    local backup_file="${BACKUP_DIR}/registro_${DB_DATABASE}_${timestamp}.sql"

    # Create backup using mysqldump via Docker
    log "Dumping database: $DB_DATABASE"
    if docker compose -f "$DOCKER_COMPOSE_FILE" exec -T mysql \
        mysqldump \
        -u"$DB_USERNAME" \
        -p"$DB_PASSWORD" \
        --single-transaction \
        --routines \
        --triggers \
        --events \
        --add-drop-database \
        --databases "$DB_DATABASE" \
        > "$backup_file"; then
        success "Database dumped to: $backup_file"
    else
        error "Database dump failed"
        rm -f "$backup_file"  # Clean up partial backup
        exit 1
    fi

    # Compress backup if enabled
    if [[ "$COMPRESS" == true ]]; then
        log "Compressing backup..."
        if gzip "$backup_file"; then
            backup_file="${backup_file}.gz"
            success "Backup compressed: $backup_file"
        else
            error "Compression failed, keeping uncompressed backup"
        fi
    fi

    # Display backup size
    local backup_size
    backup_size=$(du -h "$backup_file" | cut -f1)
    log "Backup size: $backup_size"

    # Upload to S3 if enabled
    if [[ "$UPLOAD_S3" == true ]]; then
        upload_to_s3 "$backup_file"
    fi

    echo "$backup_file"
}

upload_to_s3() {
    local backup_file="$1"
    local backup_filename
    backup_filename=$(basename "$backup_file")

    log "Uploading to S3: s3://$S3_BUCKET/backups/$backup_filename"

    if aws s3 cp "$backup_file" "s3://$S3_BUCKET/backups/$backup_filename" --storage-class STANDARD_IA; then
        success "Uploaded to S3 successfully"
    else
        error "S3 upload failed (local backup preserved)"
        return 1
    fi
}

################################################################################
# Cleanup Functions
################################################################################

rotate_backups() {
    log "Rotating old backups (keeping last $RETENTION_DAYS days)..."

    local deleted_count=0

    # Find and delete backups older than retention period
    while IFS= read -r -d '' backup_file; do
        log "Deleting old backup: $backup_file"
        rm -f "$backup_file"
        # Not ((deleted_count++)): post-increment evaluates to the OLD value, so
        # the first deletion returns 1 and `set -e` kills the script silently --
        # mid-rotation, with no summary and no error. Rotation only ever runs
        # once files are old enough, so this stays invisible until it matters.
        deleted_count=$((deleted_count + 1))
    done < <(find "$BACKUP_DIR" -name "registro_*.sql*" -type f -mtime "+$RETENTION_DAYS" -print0)

    if [[ $deleted_count -gt 0 ]]; then
        success "Deleted $deleted_count old backup(s)"
    else
        log "No old backups to delete"
    fi
}

rotate_s3_backups() {
    if [[ "$UPLOAD_S3" != true ]]; then
        return 0
    fi

    log "Rotating S3 backups (keeping last $RETENTION_DAYS days)..."

    # Calculate cutoff date
    local cutoff_date
    cutoff_date=$(date -d "$RETENTION_DAYS days ago" +%s)

    # List and delete old S3 backups
    aws s3 ls "s3://$S3_BUCKET/backups/" | while read -r line; do
        local file_date
        local file_name
        file_date=$(echo "$line" | awk '{print $1}')
        file_name=$(echo "$line" | awk '{print $4}')

        if [[ -n "$file_name" ]]; then
            local file_timestamp
            file_timestamp=$(date -d "$file_date" +%s)

            if [[ $file_timestamp -lt $cutoff_date ]]; then
                log "Deleting old S3 backup: $file_name"
                aws s3 rm "s3://$S3_BUCKET/backups/$file_name"
            fi
        fi
    done

    success "S3 backup rotation completed"
}

################################################################################
# Reporting Functions
################################################################################

show_backup_summary() {
    log "==================================================================="
    log "                    Backup Summary"
    log "==================================================================="

    # Count total backups
    local total_backups
    total_backups=$(find "$BACKUP_DIR" -name "registro_*.sql*" -type f | wc -l)

    # Calculate total size
    local total_size
    total_size=$(du -sh "$BACKUP_DIR" 2>/dev/null | cut -f1)

    # Oldest backup
    local oldest_backup
    oldest_backup=$(find "$BACKUP_DIR" -name "registro_*.sql*" -type f -printf '%T+ %p\n' | sort | head -1 | cut -d' ' -f2-)

    # Latest backup
    local latest_backup
    latest_backup=$(find "$BACKUP_DIR" -name "registro_*.sql*" -type f -printf '%T+ %p\n' | sort | tail -1 | cut -d' ' -f2-)

    log "Total backups: $total_backups"
    log "Total size: $total_size"
    log "Oldest backup: $(basename "$oldest_backup" 2>/dev/null || echo "N/A")"
    log "Latest backup: $(basename "$latest_backup" 2>/dev/null || echo "N/A")"
    log "Retention policy: $RETENTION_DAYS days"

    if [[ "$UPLOAD_S3" == true ]]; then
        log "S3 bucket: s3://$S3_BUCKET/backups/"
    fi

    log "==================================================================="
}

################################################################################
# Main Function
################################################################################

main() {
    log "==================================================================="
    log "          Registro Database Backup"
    log "==================================================================="
    echo ""

    # Parse arguments
    parse_arguments "$@"

    # Step 1: Check prerequisites
    check_prerequisites

    # Step 2: Load database config
    load_database_config

    # Step 3: Create backup directory
    create_backup_directory

    # Step 4: Create backup
    local backup_file
    backup_file=$(create_backup)

    # Step 5: Rotate old backups
    rotate_backups
    rotate_s3_backups

    # Step 6: Show summary
    show_backup_summary

    echo ""
    success "Backup completed successfully: $(basename "$backup_file")"
    echo ""
}

# Run main function
main "$@"
