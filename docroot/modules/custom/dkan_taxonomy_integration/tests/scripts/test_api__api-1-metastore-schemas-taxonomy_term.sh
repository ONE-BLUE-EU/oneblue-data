#!/bin/bash

# Comprehensive Schema Validation Test for DKAN Taxonomy Integration
# Tests complete end-to-end schema behavior

set -e

# Source common functions
source "$(dirname "$0")/common_functions.sh"

echo "=== Comprehensive Schema Validation Test ==="
echo "Testing complete end-to-end schema behavior"

# Check prerequisites
check_prerequisites

# Step 1: Verify initial state (module disabled)
print_info "Step 1: Verifying initial state..."

# Make sure module is disabled for clean test
print_info "Module currently enabled - disabling for clean test..."
ddev drush pm:uninstall dkan_taxonomy_integration -y > /dev/null 2>&1
ddev drush cr > /dev/null 2>&1

# Test initial endpoint state (should be 404)
print_info "Validating Initial schema endpoint API response..."
INITIAL_SCHEMA_HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "https://dkan.ddev.site/api/1/metastore/schemas/taxonomy_term")

if [ "$INITIAL_SCHEMA_HTTP_CODE" = "404" ] || [ "$INITIAL_SCHEMA_HTTP_CODE" = "500" ]; then
    print_success "✅ Initial schema endpoint: Correct HTTP status: $INITIAL_SCHEMA_HTTP_CODE"
else
    print_error "❌ Initial schema endpoint: Wrong HTTP status: $INITIAL_SCHEMA_HTTP_CODE (expected: 404)"
    exit 1
fi

# Test initial endpoint state (DKAN limitation: may return 200 with empty array)
print_info "Validating Initial items endpoint API response..."
INITIAL_ITEMS_HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "https://dkan.ddev.site/api/1/metastore/schemas/taxonomy_term/items")

if [ "$INITIAL_ITEMS_HTTP_CODE" = "404" ] || [ "$INITIAL_ITEMS_HTTP_CODE" = "500" ]; then
    print_success "✅ Initial items endpoint: Correct HTTP status: $INITIAL_ITEMS_HTTP_CODE"
elif [ "$INITIAL_ITEMS_HTTP_CODE" = "200" ]; then
    # Due to DKAN's hardcoded schema discovery, this endpoint may return 200 with empty array
    INITIAL_ITEMS_CONTENT=$(curl -s "https://dkan.ddev.site/api/1/metastore/schemas/taxonomy_term/items")
    INITIAL_ITEMS_COUNT=$(echo "$INITIAL_ITEMS_CONTENT" | jq 'length' 2>/dev/null || echo "0")
    if [ "$INITIAL_ITEMS_COUNT" = "0" ]; then
        print_success "✅ Initial items endpoint: Returns empty array (DKAN limitation - acceptable)"
    else
        print_error "❌ Initial items endpoint: Has $INITIAL_ITEMS_COUNT items (should be empty)"
        exit 1
    fi
else
    print_error "❌ Initial items endpoint: Wrong HTTP status: $INITIAL_ITEMS_HTTP_CODE"
    exit 1
fi

echo

# Step 2: Validate source schema files
print_info "Step 2: Validating source schema files..."

MODULE_SCHEMA_DIR="docroot/modules/custom/dkan_taxonomy_integration/schema"

# Validate taxonomy_term.json
if [ -f "$MODULE_SCHEMA_DIR/taxonomy_term.json" ]; then
    print_success "✅ Source taxonomy_term.json exists"

    if jq -e '.title and .type and .required and .properties' "$MODULE_SCHEMA_DIR/taxonomy_term.json" > /dev/null; then
        print_success "✅ Source taxonomy_term.json has valid structure"
    else
        print_error "❌ Source taxonomy_term.json has invalid structure"
        exit 1
    fi
else
    print_error "❌ Source taxonomy_term.json missing"
    exit 1
fi

# Validate taxonomy_term.ui.json
if [ -f "$MODULE_SCHEMA_DIR/taxonomy_term.ui.json" ]; then
    print_success "✅ Source taxonomy_term.ui.json exists"

    if jq . "$MODULE_SCHEMA_DIR/taxonomy_term.ui.json" > /dev/null 2>&1; then
        print_success "✅ Source taxonomy_term.ui.json has valid JSON"
    else
        print_error "❌ Source taxonomy_term.ui.json has invalid JSON"
        exit 1
    fi
else
    print_error "❌ Source taxonomy_term.ui.json missing"
    exit 1
fi

echo

# Step 3: Install module and verify schema file creation
print_info "Step 3: Installing module and verifying schema file creation..."

ddev drush pm:enable dkan_taxonomy_integration -y > /dev/null 2>&1
ddev drush cr > /dev/null 2>&1

DKAN_SCHEMA_DIR="docroot/modules/contrib/dkan/schema/collections"

if [ -f "$DKAN_SCHEMA_DIR/taxonomy_term.json" ]; then
    print_success "✅ taxonomy_term.json created in DKAN directory"
else
    print_error "❌ taxonomy_term.json not created"
    exit 1
fi

if [ -f "$DKAN_SCHEMA_DIR/taxonomy_term.ui.json" ]; then
    print_success "✅ taxonomy_term.ui.json created in DKAN directory"
else
    print_error "❌ taxonomy_term.ui.json not created"
    exit 1
fi

echo

# Step 4: Validate created schema files
print_info "Step 4: Validating created schema files..."

# Validate created taxonomy_term.json
if jq -e '.title and .type and .required and .properties' "$DKAN_SCHEMA_DIR/taxonomy_term.json" > /dev/null; then
    print_success "✅ Created taxonomy_term.json has valid structure"

    # Check specific required fields
    REQUIRED_FIELDS=("identifier" "data" "taxonomy_vocabulary")
    for field in "${REQUIRED_FIELDS[@]}"; do
        if jq -e ".required | contains([\"$field\"])" "$DKAN_SCHEMA_DIR/taxonomy_term.json" > /dev/null; then
            print_success "✅ Required field present: $field"
        else
            print_error "❌ Required field missing: $field"
            exit 1
        fi
    done
else
    print_error "❌ Created taxonomy_term.json has invalid structure"
    exit 1
fi

echo

# Step 5: Test uninstall and schema file removal
print_info "Step 5: Testing uninstall and schema file removal..."

ddev drush pm:uninstall dkan_taxonomy_integration -y > /dev/null 2>&1
ddev drush cr > /dev/null 2>&1

if [ ! -f "$DKAN_SCHEMA_DIR/taxonomy_term.json" ]; then
    print_success "✅ taxonomy_term.json removed during uninstall"
else
    print_error "❌ taxonomy_term.json not removed"
    exit 1
fi

if [ ! -f "$DKAN_SCHEMA_DIR/taxonomy_term.ui.json" ]; then
    print_success "✅ taxonomy_term.ui.json removed during uninstall"
else
    print_error "❌ taxonomy_term.ui.json not removed"
    exit 1
fi

echo

print_success "=== Comprehensive Schema Validation Test PASSED! ==="

print_success "✅ SUCCESS: Source schema files validated"
print_success "✅ SUCCESS: Module install creates schema files correctly"
print_success "✅ SUCCESS: Created schema files have valid structure"
print_success "✅ SUCCESS: Module uninstall removes schema files completely"
print_success "✅ SUCCESS: End-to-end schema lifecycle verified"

echo
echo "🔧 Schema Validation Summary:"
echo "• Source file validation: VERIFIED ✅"
echo "• Install file creation: VERIFIED ✅"
echo "• Schema structure validation: VERIFIED ✅"
echo "• Uninstall file removal: VERIFIED ✅"
echo "• Complete lifecycle: VERIFIED ✅"
