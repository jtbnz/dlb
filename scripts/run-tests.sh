#!/bin/bash

# DLB Test Runner Script
# Comprehensive test suite for Fire Brigade Attendance System

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

cd "$PROJECT_DIR"

echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}  DLB Test Suite Runner${NC}"
echo -e "${BLUE}========================================${NC}"
echo ""

# Parse command line arguments
TEST_TYPE="${1:-all}"
ENVIRONMENT="${2:-local}"

show_help() {
    echo "Usage: ./scripts/run-tests.sh [test_type] [environment]"
    echo ""
    echo "Test Types:"
    echo "  all         Run all tests (unit + e2e)"
    echo "  unit        Run PHPUnit tests only"
    echo "  e2e         Run Playwright E2E tests only"
    echo "  auth        Run authentication tests only"
    echo "  crud        Run CRUD operation tests only"
    echo "  api         Run API v1 tests only"
    echo ""
    echo "Environments:"
    echo "  local       Run against local development server (default)"
    echo "  production  Run against production demo brigade"
    echo ""
    echo "Examples:"
    echo "  ./scripts/run-tests.sh              # Run all tests locally"
    echo "  ./scripts/run-tests.sh e2e local    # Run E2E tests locally"
    echo "  ./scripts/run-tests.sh e2e production  # Run E2E tests against production"
    echo "  ./scripts/run-tests.sh auth         # Run only auth tests"
}

if [[ "$1" == "--help" || "$1" == "-h" ]]; then
    show_help
    exit 0
fi

# Check dependencies
check_dependencies() {
    echo -e "${YELLOW}Checking dependencies...${NC}"

    # Check Node.js
    if ! command -v node &> /dev/null; then
        echo -e "${RED}Error: Node.js is not installed${NC}"
        exit 1
    fi

    # Check PHP
    if ! command -v php &> /dev/null; then
        echo -e "${RED}Error: PHP is not installed${NC}"
        exit 1
    fi

    echo -e "${GREEN}Dependencies OK${NC}"
    echo ""
}

# Install npm dependencies if needed
install_npm_deps() {
    if [ ! -d "node_modules" ]; then
        echo -e "${YELLOW}Installing npm dependencies...${NC}"
        npm install
        npx playwright install chromium
        echo -e "${GREEN}Dependencies installed${NC}"
        echo ""
    fi
}

# Install composer dependencies if needed
install_composer_deps() {
    if [ ! -d "vendor" ] && [ -f "composer.json" ]; then
        echo -e "${YELLOW}Installing composer dependencies...${NC}"
        composer install --dev
        echo -e "${GREEN}Dependencies installed${NC}"
        echo ""
    fi
}

# Run PHPUnit tests
run_unit_tests() {
    echo -e "${BLUE}Running PHPUnit Tests...${NC}"
    echo ""

    if [ -f "vendor/bin/phpunit" ]; then
        php vendor/bin/phpunit --configuration phpunit.xml --colors=always
    else
        echo -e "${YELLOW}PHPUnit not installed. Run: composer install${NC}"
        return 1
    fi

    echo ""
}

# Run E2E tests with Playwright
run_e2e_tests() {
    local filter="${1:-}"

    echo -e "${BLUE}Running Playwright E2E Tests...${NC}"
    echo -e "Environment: ${ENVIRONMENT}"
    echo ""

    local env_args=""
    local project_args=""

    if [ "$ENVIRONMENT" == "production" ]; then
        env_args="BASE_URL=https://kiaora.tech/dlb BRIGADE_SLUG=demo"
        project_args="--project=production"
    fi

    local test_args=""
    if [ -n "$filter" ]; then
        test_args="--grep \"$filter\""
    fi

    eval "$env_args npx playwright test $project_args $test_args"

    echo ""
}

# Run specific test suites
run_auth_tests() {
    echo -e "${BLUE}Running Authentication Tests...${NC}"
    run_e2e_tests "Authentication"
}

run_crud_tests() {
    echo -e "${BLUE}Running CRUD Tests...${NC}"
    run_e2e_tests "Member|Truck|Position|Callout"
}

run_api_tests() {
    echo -e "${BLUE}Running API v1 Tests...${NC}"
    run_e2e_tests "API"
}

# Main execution
main() {
    check_dependencies
    install_npm_deps

    case "$TEST_TYPE" in
        all)
            install_composer_deps
            run_unit_tests
            run_e2e_tests
            ;;
        unit)
            install_composer_deps
            run_unit_tests
            ;;
        e2e)
            run_e2e_tests
            ;;
        auth)
            run_auth_tests
            ;;
        crud)
            run_crud_tests
            ;;
        api)
            run_api_tests
            ;;
        *)
            echo -e "${RED}Unknown test type: $TEST_TYPE${NC}"
            show_help
            exit 1
            ;;
    esac

    echo -e "${GREEN}========================================${NC}"
    echo -e "${GREEN}  Tests Complete!${NC}"
    echo -e "${GREEN}========================================${NC}"
}

main
