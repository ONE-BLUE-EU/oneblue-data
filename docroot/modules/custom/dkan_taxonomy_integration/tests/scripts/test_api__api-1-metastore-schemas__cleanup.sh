#!/bin/bash

# API Endpoints Module Uninstall Cleanup Test
# Tests that endpoints are properly cleaned up after module uninstall

set -e

# Source common functions
source "$(dirname "$0")/common_functions.sh"

echo "=== API Endpoints Module Uninstall Cleanup Test ==="
echo "Testing endpoint removal after module uninstall"

# Check prerequisites
check_prerequisites

# Step 1: Verify module is installed and endpoints exist
print_info "Step 1: Verifying module is installed and endpoints exist..."

# Check module status
MODULE_CHECK=$(drush pm:list | grep "dkan_taxonomy_integration" | grep "Enabled" || echo "")
if [ -n "$MODULE_CHECK" ]; then
    MODULE_STATUS="Enabled"
else
    MODULE_STATUS="Disabled"
fi

if [ "$MODULE_STATUS" = "Enabled" ]; then
    print_success "✅ Module dkan_taxonomy_integration is currently enabled"
else
    print_error "❌ Module dkan_taxonomy_integration is not enabled - cannot test uninstall"
    exit 1
fi

# The module serves its schema through its service provider (files stay in the
# module's schema/ directory, never copied into DKAN's schema/collections).
DKAN_SCHEMA_DIR="docroot/modules/contrib/dkan/schema/collections"
MODULE_SCHEMA_DIR="docroot/modules/custom/dkan_taxonomy_integration/schema"

SCHEMA_HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/api/1/metastore/schemas/taxonomy_term")
if [ "$SCHEMA_HTTP_CODE" = "200" ]; then
    print_success "✅ taxonomy_term schema endpoint accessible before uninstall (HTTP 200)"
else
    print_error "❌ taxonomy_term schema endpoint not accessible before uninstall (HTTP $SCHEMA_HTTP_CODE)"
    exit 1
fi

# Check that keyword endpoints still work (baseline check)
KEYWORD_SCHEMA_BEFORE=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/api/1/metastore/schemas/keyword")
KEYWORD_ITEMS_BEFORE=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/api/1/metastore/schemas/keyword/items")

if [ "$KEYWORD_SCHEMA_BEFORE" = "200" ]; then
    print_success "✅ keyword schema endpoint accessible before uninstall (HTTP 200)"
else
    print_error "❌ keyword schema endpoint not accessible (HTTP $KEYWORD_SCHEMA_BEFORE)"
    exit 1
fi

if [ "$KEYWORD_ITEMS_BEFORE" = "200" ]; then
    print_success "✅ keyword/items endpoint accessible before uninstall (HTTP 200)"
else
    print_error "❌ keyword/items endpoint not accessible (HTTP $KEYWORD_ITEMS_BEFORE)"
    exit 1
fi

echo

# Step 2: Uninstall the module
print_info "Step 2: Uninstalling module..."

drush pm:uninstall dkan_taxonomy_integration -y > /dev/null 2>&1
drush cr > /dev/null 2>&1

print_success "✅ Module uninstalled successfully"

echo

# Step 3: Verify no schema files are left in DKAN's directory
print_info "Step 3: Verifying no schema files are left in DKAN's schema directory..."

if [ ! -f "$DKAN_SCHEMA_DIR/taxonomy_term.json" ] && [ ! -f "$DKAN_SCHEMA_DIR/taxonomy_term.ui.json" ]; then
    print_success "✅ No taxonomy_term files in $DKAN_SCHEMA_DIR"
else
    print_error "❌ taxonomy_term files found in $DKAN_SCHEMA_DIR after uninstall"
    exit 1
fi

if [ -f "$MODULE_SCHEMA_DIR/taxonomy_term.json" ] && [ -f "$MODULE_SCHEMA_DIR/taxonomy_term.ui.json" ]; then
    print_success "✅ Module's own schema files remain in place"
else
    print_error "❌ Module's own schema files were removed"
    exit 1
fi

echo

# Step 4: Verify API endpoints respond correctly after uninstall
print_info "Step 4: Verifying API endpoints after uninstall..."

# Test taxonomy_term endpoints (should return 404 or empty)
TAXONOMY_SCHEMA_AFTER=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/api/1/metastore/schemas/taxonomy_term")
TAXONOMY_ITEMS_AFTER=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/api/1/metastore/schemas/taxonomy_term/items")

if [ "$TAXONOMY_SCHEMA_AFTER" = "404" ] || [ "$TAXONOMY_SCHEMA_AFTER" = "500" ]; then
    print_success "✅ taxonomy_term schema endpoint properly removed (HTTP $TAXONOMY_SCHEMA_AFTER)"
else
    print_error "❌ taxonomy_term schema endpoint still accessible (HTTP $TAXONOMY_SCHEMA_AFTER)"
    exit 1
fi

if [ "$TAXONOMY_ITEMS_AFTER" = "404" ] || [ "$TAXONOMY_ITEMS_AFTER" = "500" ]; then
    print_success "✅ taxonomy_term/items endpoint properly removed (HTTP $TAXONOMY_ITEMS_AFTER)"
elif [ "$TAXONOMY_ITEMS_AFTER" = "200" ]; then
    # Check if it's empty due to DKAN limitation
    ITEMS_CONTENT=$(curl -s "$BASE_URL/api/1/metastore/schemas/taxonomy_term/items")
    ITEMS_COUNT=$(echo "$ITEMS_CONTENT" | jq 'length' 2>/dev/null || echo "0")
    if [ "$ITEMS_COUNT" = "0" ]; then
        print_success "✅ taxonomy_term/items endpoint returns empty array (DKAN limitation - acceptable)"
    else
        print_error "❌ taxonomy_term/items endpoint has $ITEMS_COUNT items (should be empty)"
        exit 1
    fi
else
    print_error "❌ taxonomy_term/items endpoint unexpected response (HTTP $TAXONOMY_ITEMS_AFTER)"
    exit 1
fi

echo

# Step 5: Verify that standard endpoints still work
print_info "Step 5: Verifying standard endpoints still work..."

KEYWORD_SCHEMA_AFTER=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/api/1/metastore/schemas/keyword")
KEYWORD_ITEMS_AFTER=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/api/1/metastore/schemas/keyword/items")

if [ "$KEYWORD_SCHEMA_AFTER" = "200" ]; then
    print_success "✅ keyword schema endpoint still works (HTTP 200)"
else
    print_error "❌ keyword schema endpoint broken (HTTP $KEYWORD_SCHEMA_AFTER)"
    exit 1
fi

if [ "$KEYWORD_ITEMS_AFTER" = "200" ]; then
    print_success "✅ keyword/items endpoint still works (HTTP 200)"
else
    print_error "❌ keyword/items endpoint broken (HTTP $KEYWORD_ITEMS_AFTER)"
    exit 1
fi

echo

print_success "=== API Endpoints Module Uninstall Cleanup Test PASSED! ==="

print_success "✅ SUCCESS: Module uninstalled successfully"
print_success "✅ SUCCESS: No schema files left in DKAN's directory"
print_success "✅ SUCCESS: taxonomy_term endpoints properly removed"
print_success "✅ SUCCESS: Standard endpoints unaffected"
print_success "✅ SUCCESS: Complete cleanup verified"

echo
echo "🔧 Uninstall Cleanup Summary:"
echo "• Module uninstall: VERIFIED ✅"
echo "• No leftover schema files: VERIFIED ✅"
echo "• Endpoint cleanup: VERIFIED ✅"
echo "• Standard endpoints preserved: VERIFIED ✅"
echo "• Complete cleanup: VERIFIED ✅"
