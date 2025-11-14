#!/bin/bash
#
# Test Runner Script for WP-SlimStat Feature 004
#
# Usage:
#   chmod +x run-tests.sh
#   ./run-tests.sh
#
# Requirements:
#   - PHP 7.4+ in PATH
#   - Composer installed
#   - WordPress database configured

set -e  # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

echo -e "${BLUE}=== WP-SlimStat Test Runner ===${NC}\n"

# Step 1: Check PHP
echo -e "${YELLOW}[1/5] Checking PHP installation...${NC}"
if ! command -v php &> /dev/null; then
    echo -e "${RED}✗ PHP not found in PATH${NC}"
    echo -e "${YELLOW}Install PHP first:${NC}"
    echo "  brew install php"
    echo "  # or use Local's PHP: find ~/Library/Application\ Support/Local -name 'php'"
    exit 1
fi

PHP_VERSION=$(php -r "echo PHP_VERSION;")
echo -e "${GREEN}✓ PHP version: $PHP_VERSION${NC}\n"

# Step 2: Check Composer
echo -e "${YELLOW}[2/5] Checking Composer installation...${NC}"
if ! command -v composer &> /dev/null; then
    echo -e "${RED}✗ Composer not found in PATH${NC}"
    echo -e "${YELLOW}Install Composer first:${NC}"
    echo "  brew install composer"
    exit 1
fi

COMPOSER_VERSION=$(composer --version --no-ansi | head -1)
echo -e "${GREEN}✓ $COMPOSER_VERSION${NC}\n"

# Step 3: Install PHPUnit dependencies
echo -e "${YELLOW}[3/5] Installing PHPUnit dependencies...${NC}"
if [ ! -d "vendor" ] || [ ! -f "vendor/bin/phpunit" ]; then
    echo "Installing phpunit/phpunit:^9.5 and yoast/phpunit-polyfills..."
    composer require --dev phpunit/phpunit:^9.5 yoast/phpunit-polyfills
    echo -e "${GREEN}✓ Dependencies installed${NC}\n"
else
    echo -e "${GREEN}✓ Dependencies already installed${NC}\n"
fi

# Step 4: Verify test files exist
echo -e "${YELLOW}[4/5] Verifying test files...${NC}"
TEST_FILES=(
    "tests/bootstrap.php"
    "tests/Integration/WidgetRenderTest.php"
    "phpunit.xml"
)

for file in "${TEST_FILES[@]}"; do
    if [ ! -f "$file" ]; then
        echo -e "${RED}✗ Missing: $file${NC}"
        exit 1
    fi
    echo -e "${GREEN}✓ Found: $file${NC}"
done
echo ""

# Step 5: Run tests
echo -e "${YELLOW}[5/5] Running Integration Tests (T061-T064)...${NC}"
echo -e "${BLUE}========================================${NC}\n"

# Run PHPUnit with Integration test suite
vendor/bin/phpunit --testsuite Integration --colors=always

# Capture exit code
TEST_EXIT_CODE=$?

echo -e "\n${BLUE}========================================${NC}"

if [ $TEST_EXIT_CODE -eq 0 ]; then
    echo -e "${GREEN}✓ All tests passed!${NC}\n"
    echo -e "Test Coverage:"
    echo -e "  ${GREEN}✓${NC} T061: Widget HTML rendering with 10k visits"
    echo -e "  ${GREEN}✓${NC} T062: Performance <300ms (FR-033)"
    echo -e "  ${GREEN}✓${NC} T063: Zero-state scenario handling"
    echo -e "  ${GREEN}✓${NC} T064: AJAX refresh flow with cache"
    echo ""
    echo -e "${BLUE}Next steps:${NC}"
    echo "  - Review test output above for any warnings"
    echo "  - Optional: Run unit tests (T025-T028)"
    echo "  - Optional: Run filter tests (T072-T075)"
    echo "  - Deploy to production when ready"
else
    echo -e "${RED}✗ Some tests failed${NC}\n"
    echo -e "${YELLOW}Troubleshooting:${NC}"
    echo "  1. Check WordPress database connection in tests/bootstrap.php"
    echo "  2. Ensure wp_slim_stats and wp_slim_channels tables exist"
    echo "  3. Run: composer dump-autoload"
    echo "  4. Check test output above for specific errors"
    exit $TEST_EXIT_CODE
fi

echo -e "${BLUE}=== Test Run Complete ===${NC}\n"
