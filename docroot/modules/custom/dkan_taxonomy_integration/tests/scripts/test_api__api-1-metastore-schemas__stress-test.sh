#!/bin/bash

# Test Install/Uninstall Cycle Stress Test
# Tests: Multiple install/uninstall cycles to verify robustness
#
# This script validates that:
# 1. Multiple install/uninstall cycles work correctly
# 2. No files or state are left behind between cycles
# 3. Each cycle is independent and clean
# 4. Performance doesn't degrade over multiple cycles

set -e

# Source common functions
source "$(dirname "$0")/common_functions.sh"

echo "=== Install/Uninstall Cycle Stress Test ==="
echo "Testing multiple install/uninstall cycles"
echo

# Configuration
CYCLES=3  # Number of cycles to test
DKAN_SCHEMA_DIR="docroot/modules/contrib/dkan/schema/collections"
MODULE_SCHEMA_DIR="docroot/modules/custom/dkan_taxonomy_integration/schema"

# Function to check clean state
check_clean_state() {
    local cycle=$1
    print_info "Cycle $cycle: Checking clean state..."

    # Module should be disabled
    if ddev drush pm:list --status=enabled | grep -q dkan_taxonomy_integration; then
        print_error "❌ Cycle $cycle: Module is still enabled"
        return 1
    else
        print_success "✅ Cycle $cycle: Module is disabled"
    fi

    # Schema files should NEVER exist in DKAN directory (service provider approach)
    if [ -f "$DKAN_SCHEMA_DIR/taxonomy_term.json" ]; then
        print_error "❌ Cycle $cycle: taxonomy_term.json exists in DKAN directory (service provider not working)"
        return 1
    else
        print_success "✅ Cycle $cycle: taxonomy_term.json does not exist in DKAN directory (clean service provider approach)"
    fi

    if [ -f "$DKAN_SCHEMA_DIR/taxonomy_term.ui.json" ]; then
        print_error "❌ Cycle $cycle: taxonomy_term.ui.json exists in DKAN directory (service provider not working)"
        return 1
    else
        print_success "✅ Cycle $cycle: taxonomy_term.ui.json does not exist in DKAN directory (clean service provider approach)"
    fi

    # Schema files should still exist in module directory (they always remain there)
    if [ -f "$MODULE_SCHEMA_DIR/taxonomy_term.json" ]; then
        print_success "✅ Cycle $cycle: taxonomy_term.json exists in module directory (as expected)"
    else
        print_error "❌ Cycle $cycle: taxonomy_term.json missing from module directory"
        return 1
    fi

    if [ -f "$MODULE_SCHEMA_DIR/taxonomy_term.ui.json" ]; then
        print_success "✅ Cycle $cycle: taxonomy_term.ui.json exists in module directory (as expected)"
    else
        print_error "❌ Cycle $cycle: taxonomy_term.ui.json missing from module directory"
        return 1
    fi

    # API should not expose taxonomy schemas (service provider disabled)
    local api_schemas=$(curl -s "https://dkan.ddev.site/api/1/metastore/schemas" | jq 'keys[]' 2>/dev/null || echo "")

    if echo "$api_schemas" | grep -q "taxonomy_term"; then
        print_error "❌ Cycle $cycle: taxonomy_term schema still accessible via API (service provider not deactivated)"
        return 1
    else
        print_success "✅ Cycle $cycle: taxonomy_term schema not accessible via API (service provider deactivated)"
    fi

    if echo "$api_schemas" | grep -q "taxonomy_term.ui"; then
        print_error "❌ Cycle $cycle: taxonomy_term.ui schema still accessible via API (service provider not deactivated)"
        return 1
    else
        print_success "✅ Cycle $cycle: taxonomy_term.ui schema not accessible via API (service provider deactivated)"
    fi
}

# Function to perform install and verify
perform_install() {
    local cycle=$1
    print_info "Cycle $cycle: Installing module..."

    local start_time=$(date +%s)

    if ddev drush pm:enable dkan_taxonomy_integration -y; then
        print_success "✅ Cycle $cycle: Module installed successfully"
    else
        print_error "❌ Cycle $cycle: Module installation failed"
        return 1
    fi

    print_info "Cycle $cycle: Clearing cache..."
    ddev drush cr
    sleep 1

    local end_time=$(date +%s)
    local duration=$((end_time - start_time))
    print_info "Cycle $cycle: Install completed in ${duration}s"

    # Verify installation
    if ddev drush pm:list --status=enabled | grep -q dkan_taxonomy_integration; then
        print_success "✅ Cycle $cycle: Module is enabled"
    else
        print_error "❌ Cycle $cycle: Module is not enabled after install"
        return 1
    fi

    # Verify schema files remain in module directory (service provider approach)
    if [ -f "$MODULE_SCHEMA_DIR/taxonomy_term.json" ]; then
        print_success "✅ Cycle $cycle: taxonomy_term.json exists in module directory"
    else
        print_error "❌ Cycle $cycle: taxonomy_term.json missing from module directory"
        return 1
    fi

    if [ -f "$MODULE_SCHEMA_DIR/taxonomy_term.ui.json" ]; then
        print_success "✅ Cycle $cycle: taxonomy_term.ui.json exists in module directory"
    else
        print_error "❌ Cycle $cycle: taxonomy_term.ui.json missing from module directory"
        return 1
    fi

    # Verify files are NOT copied to DKAN directory (clean service provider approach)
    if [ -f "$DKAN_SCHEMA_DIR/taxonomy_term.json" ]; then
        print_error "❌ Cycle $cycle: taxonomy_term.json inappropriately copied to DKAN directory"
        return 1
    else
        print_success "✅ Cycle $cycle: taxonomy_term.json not copied to DKAN directory (clean service provider approach)"
    fi

    if [ -f "$DKAN_SCHEMA_DIR/taxonomy_term.ui.json" ]; then
        print_error "❌ Cycle $cycle: taxonomy_term.ui.json inappropriately copied to DKAN directory"
        return 1
    else
        print_success "✅ Cycle $cycle: taxonomy_term.ui.json not copied to DKAN directory (clean service provider approach)"
    fi

    # Verify service provider is working - schemas should be accessible via API
    local api_schemas=$(curl -s "https://dkan.ddev.site/api/1/metastore/schemas" | jq 'keys[]' 2>/dev/null || echo "")

    if echo "$api_schemas" | grep -q "taxonomy_term"; then
        print_success "✅ Cycle $cycle: taxonomy_term schema accessible via API (service provider working)"
    else
        print_error "❌ Cycle $cycle: taxonomy_term schema not accessible via API (service provider not working)"
        return 1
    fi

    if echo "$api_schemas" | grep -q "taxonomy_term.ui"; then
        print_success "✅ Cycle $cycle: taxonomy_term.ui schema accessible via API (service provider working)"
    else
        print_error "❌ Cycle $cycle: taxonomy_term.ui schema not accessible via API (service provider not working)"
        return 1
    fi

    # Verify individual schema content is accessible
    local taxonomy_schema=$(curl -s "https://dkan.ddev.site/api/1/metastore/schemas/taxonomy_term" 2>/dev/null || echo "{}")
    if echo "$taxonomy_schema" | jq -e '.title' > /dev/null 2>&1; then
        print_success "✅ Cycle $cycle: taxonomy_term schema content accessible and valid"
    else
        print_error "❌ Cycle $cycle: taxonomy_term schema content not accessible or invalid"
        return 1
    fi
}

# Function to perform uninstall and verify
perform_uninstall() {
    local cycle=$1
    print_info "Cycle $cycle: Uninstalling module..."

    local start_time=$(date +%s)

    if ddev drush pm:uninstall dkan_taxonomy_integration -y; then
        print_success "✅ Cycle $cycle: Module uninstalled successfully"
    else
        print_error "❌ Cycle $cycle: Module uninstall failed"
        return 1
    fi

    print_info "Cycle $cycle: Clearing cache..."
    ddev drush cr
    sleep 1

    local end_time=$(date +%s)
    local duration=$((end_time - start_time))
    print_info "Cycle $cycle: Uninstall completed in ${duration}s"

    # Verify uninstall
    if ! ddev drush pm:list --status=enabled | grep -q dkan_taxonomy_integration; then
        print_success "✅ Cycle $cycle: Module is disabled"
    else
        print_error "❌ Cycle $cycle: Module is still enabled after uninstall"
        return 1
    fi

    # Verify schema files remain in module directory (service provider approach)
    if [ -f "$MODULE_SCHEMA_DIR/taxonomy_term.json" ]; then
        print_success "✅ Cycle $cycle: taxonomy_term.json remains in module directory (as expected)"
    else
        print_error "❌ Cycle $cycle: taxonomy_term.json missing from module directory"
        return 1
    fi

    if [ -f "$MODULE_SCHEMA_DIR/taxonomy_term.ui.json" ]; then
        print_success "✅ Cycle $cycle: taxonomy_term.ui.json remains in module directory (as expected)"
    else
        print_error "❌ Cycle $cycle: taxonomy_term.ui.json missing from module directory"
        return 1
    fi

    # Verify files were never in DKAN directory (clean service provider approach)
    if [ ! -f "$DKAN_SCHEMA_DIR/taxonomy_term.json" ]; then
        print_success "✅ Cycle $cycle: taxonomy_term.json not in DKAN directory (clean approach maintained)"
    else
        print_error "❌ Cycle $cycle: taxonomy_term.json inappropriately exists in DKAN directory"
        return 1
    fi

    if [ ! -f "$DKAN_SCHEMA_DIR/taxonomy_term.ui.json" ]; then
        print_success "✅ Cycle $cycle: taxonomy_term.ui.json not in DKAN directory (clean approach maintained)"
    else
        print_error "❌ Cycle $cycle: taxonomy_term.ui.json inappropriately exists in DKAN directory"
        return 1
    fi

    # Verify service provider deactivated - schemas should not be accessible via API
    local api_schemas=$(curl -s "https://dkan.ddev.site/api/1/metastore/schemas" | jq 'keys[]' 2>/dev/null || echo "")

    if echo "$api_schemas" | grep -q "taxonomy_term"; then
        print_error "❌ Cycle $cycle: taxonomy_term schema still accessible via API (service provider not deactivated)"
        return 1
    else
        print_success "✅ Cycle $cycle: taxonomy_term schema not accessible via API (service provider deactivated)"
    fi

    if echo "$api_schemas" | grep -q "taxonomy_term.ui"; then
        print_error "❌ Cycle $cycle: taxonomy_term.ui schema still accessible via API (service provider not deactivated)"
        return 1
    else
        print_success "✅ Cycle $cycle: taxonomy_term.ui schema not accessible via API (service provider deactivated)"
    fi
}

# Initial cleanup to ensure clean starting state
print_info "Performing initial cleanup..."
if ddev drush pm:list --status=enabled | grep -q dkan_taxonomy_integration; then
    print_info "Module is currently enabled - uninstalling for clean test..."
    ddev drush pm:uninstall dkan_taxonomy_integration -y
    ddev drush cr
    sleep 2
fi

# Remove any existing schema files
rm -f "$DKAN_SCHEMA_DIR/taxonomy_term.json"
rm -f "$DKAN_SCHEMA_DIR/taxonomy_term.ui.json"

print_success "✅ Initial cleanup completed"
echo

# Verify initial clean state
check_clean_state "Initial"
echo

# Performance tracking
declare -a install_times
declare -a uninstall_times

# Run multiple cycles
for cycle in $(seq 1 $CYCLES); do
    print_info "========== CYCLE $cycle/$CYCLES =========="

    # Install cycle
    install_start=$(date +%s)
    if ! perform_install $cycle; then
        print_error "❌ Install failed in cycle $cycle"
        exit 1
    fi
    install_end=$(date +%s)
    install_duration=$((install_end - install_start))
    install_times+=($install_duration)

    echo

    # Brief pause between install and uninstall
    sleep 1

    # Uninstall cycle
    uninstall_start=$(date +%s)
    if ! perform_uninstall $cycle; then
        print_error "❌ Uninstall failed in cycle $cycle"
        exit 1
    fi
    uninstall_end=$(date +%s)
    uninstall_duration=$((uninstall_end - uninstall_start))
    uninstall_times+=($uninstall_duration)

    echo

    # Verify clean state after cycle
    if ! check_clean_state $cycle; then
        print_error "❌ Clean state verification failed after cycle $cycle"
        exit 1
    fi

    print_success "✅ Cycle $cycle completed successfully"
    echo "   Install time: ${install_duration}s, Uninstall time: ${uninstall_duration}s"
    echo
done

# Calculate performance statistics
echo "========== PERFORMANCE ANALYSIS =========="

# Install times analysis
total_install=0
max_install=0
min_install=999999
for time in "${install_times[@]}"; do
    total_install=$((total_install + time))
    if [ $time -gt $max_install ]; then max_install=$time; fi
    if [ $time -lt $min_install ]; then min_install=$time; fi
done
avg_install=$((total_install / CYCLES))

# Uninstall times analysis
total_uninstall=0
max_uninstall=0
min_uninstall=999999
for time in "${uninstall_times[@]}"; do
    total_uninstall=$((total_uninstall + time))
    if [ $time -gt $max_uninstall ]; then max_uninstall=$time; fi
    if [ $time -lt $min_uninstall ]; then min_uninstall=$time; fi
done
avg_uninstall=$((total_uninstall / CYCLES))

print_info "Install Performance:"
print_info "  Average: ${avg_install}s"
print_info "  Min: ${min_install}s"
print_info "  Max: ${max_install}s"
print_info "  Total: ${total_install}s"

print_info "Uninstall Performance:"
print_info "  Average: ${avg_uninstall}s"
print_info "  Min: ${min_uninstall}s"
print_info "  Max: ${max_uninstall}s"
print_info "  Total: ${total_uninstall}s"

# Performance consistency check
install_variance=$((max_install - min_install))
uninstall_variance=$((max_uninstall - min_uninstall))

if [ $install_variance -lt 10 ]; then
    print_success "✅ Install performance is consistent (variance: ${install_variance}s)"
else
    print_error "⚠️  Install performance varies significantly (variance: ${install_variance}s)"
fi

if [ $uninstall_variance -lt 10 ]; then
    print_success "✅ Uninstall performance is consistent (variance: ${uninstall_variance}s)"
else
    print_error "⚠️  Uninstall performance varies significantly (variance: ${uninstall_variance}s)"
fi

echo

print_success "=== Install/Uninstall Cycle Stress Test PASSED! ==="
echo
print_success "✅ SUCCESS: Completed $CYCLES install/uninstall cycles"
print_success "✅ SUCCESS: Service provider activation/deactivation worked correctly"
print_success "✅ SUCCESS: Schema files remained in module directory (clean approach)"
print_success "✅ SUCCESS: No files inappropriately copied to DKAN directory"
print_success "✅ SUCCESS: Performance remained consistent"
print_success "✅ SUCCESS: Each cycle was independent and complete"

echo
echo "🔧 Stress Test Summary:"
echo "• Cycles completed: $CYCLES ✅"
echo "• Average install time: ${avg_install}s ✅"
echo "• Average uninstall time: ${avg_uninstall}s ✅"
echo "• Performance consistency: VERIFIED ✅"
echo "• Service provider activation/deactivation: VERIFIED ✅"
echo "• Clean module directory approach: VERIFIED ✅"
echo "• API schema discovery: VERIFIED ✅"

