#!/bin/bash
################################################################################
# Deployment Verification Script
#
# Verifies all expected files exist in production container after deployment.
# Catches missing files immediately (like SMS Resources issue).
#
# Exit codes:
#   0 - All files present
#   1 - Missing files detected
################################################################################

set -euo pipefail

# Colors for output
readonly RED='\033[0;31m'
readonly GREEN='\033[0;32m'
readonly BLUE='\033[0;34m'
readonly NC='\033[0m' # No Color

# Expected Filament Resources (add new resources here)
EXPECTED_FILES=(
    # SMS Resources (added Nov 12, 2025)
    "app/Filament/Resources/SmsEventResource.php"
    "app/Filament/Resources/SmsSendResource.php"
    "app/Filament/Resources/SmsSuppressionResource.php"
    "app/Filament/Resources/SmsTemplateResource.php"

    # Email Resources
    "app/Filament/Resources/EmailEventResource.php"
    "app/Filament/Resources/EmailSendResource.php"
    "app/Filament/Resources/EmailSuppressionResource.php"
    "app/Filament/Resources/EmailTemplateResource.php"
)

echo -e "${BLUE}🔍 Verifying file deployment...${NC}"
echo ""

MISSING_COUNT=0

for file in "${EXPECTED_FILES[@]}"; do
    if docker compose -f docker-compose.prod.yml exec -T app test -f "$file" 2>/dev/null; then
        echo -e "${GREEN}✅ Found: $file${NC}"
    else
        echo -e "${RED}❌ MISSING: $file${NC}"
        ((MISSING_COUNT++))
    fi
done

echo ""

if [ $MISSING_COUNT -eq 0 ]; then
    echo -e "${GREEN}✅ All files present in production container (${#EXPECTED_FILES[@]} files)${NC}"
    exit 0
else
    echo -e "${RED}❌ Deployment verification FAILED: $MISSING_COUNT files missing${NC}"
    echo -e "${RED}🚨 Cache issue detected - container may be using stale layers${NC}"
    exit 1
fi
