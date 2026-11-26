#!/bin/bash

# Comprehensive Schema Validation Test for DKAN Taxonomy Integration
# Tests complete end-to-end schema behavior
#
# The module serves its schema through its service provider: the files stay in
# the module's schema/ directory and are never copied into DKAN's
# schema/collections. So this test checks what the API serves on install and
# that it disappears on uninstall, plus that DKAN's directory stays untouched.

set -e

# Source common functions
source "$(dirname "$0")/common_functions.sh"

echo "=== Comprehensive Schema Validation Test ==="
echo "Testing complete end-to-end schema behavior"

# Check prerequisites
check_prerequisites

# Step 1: Verify initial state (module disabled)
print_info "Step 1: Verifying initial state..."

# Make sure module is disabled for clean test (it may already be uninstalled)
print_info "Uninstalling the module (if installed) for a clean test..."
drush pm:uninstall dkan_taxonomy_integration -y > /dev/null 2>&1 || true
drush cr > /dev/null 2>&1

# Test initial endpoint state (should be 404)
print_info "Validating Initial schema endpoint API response..."
INITIAL_SCHEMA_HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/api/1/metastore/schemas/taxonomy_term")

if [ "$INITIAL_SCHEMA_HTTP_CODE" = "404" ] || [ "$INITIAL_SCHEMA_HTTP_CODE" = "500" ]; then
    print_success "✅ Initial schema endpoint: Correct HTTP status: $INITIAL_SCHEMA_HTTP_CODE"
else
    print_error "❌ Initial schema endpoint: Wrong HTTP status: $INITIAL_SCHEMA_HTTP_CODE (expected: 404)"
    exit 1
fi

# Test initial endpoint state (DKAN limitation: may return 200 with empty array)
print_info "Validating Initial items endpoint API response..."
INITIAL_ITEMS_HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/api/1/metastore/schemas/taxonomy_term/items")

if [ "$INITIAL_ITEMS_HTTP_CODE" = "404" ] || [ "$INITIAL_ITEMS_HTTP_CODE" = "500" ]; then
    print_success "✅ Initial items endpoint: Correct HTTP status: $INITIAL_ITEMS_HTTP_CODE"
elif [ "$INITIAL_ITEMS_HTTP_CODE" = "200" ]; then
    # Due to DKAN's hardcoded schema discovery, this endpoint may return 200 with empty array
    INITIAL_ITEMS_CONTENT=$(curl -s "$BASE_URL/api/1/metastore/schemas/taxonomy_term/items")
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

# Step 3: Install module and verify the API serves the schema
print_info "Step 3: Installing module and verifying the API serves the schema..."

drush pm:enable dkan_taxonomy_integration -y > /dev/null 2>&1
drush cr > /dev/null 2>&1

DKAN_SCHEMA_DIR="docroot/modules/contrib/dkan/schema/collections"
SCHEMA_URL="$BASE_URL/api/1/metastore/schemas/taxonomy_term"

SCHEMA_HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "$SCHEMA_URL")
if [ "$SCHEMA_HTTP_CODE" = "200" ]; then
    print_success "✅ Schema endpoint returns 200 after install"
else
    print_error "❌ Schema endpoint returns $SCHEMA_HTTP_CODE after install (expected: 200)"
    exit 1
fi

if curl -s "$BASE_URL/api/1/metastore/schemas" | jq -e 'has("taxonomy_term")' > /dev/null; then
    print_success "✅ taxonomy_term listed in /api/1/metastore/schemas"
else
    print_error "❌ taxonomy_term not listed in /api/1/metastore/schemas"
    exit 1
fi

if [ ! -f "$DKAN_SCHEMA_DIR/taxonomy_term.json" ] && [ ! -f "$DKAN_SCHEMA_DIR/taxonomy_term.ui.json" ]; then
    print_success "✅ No schema files copied into DKAN's schema directory (served by the service provider)"
else
    print_error "❌ Schema files were copied into $DKAN_SCHEMA_DIR"
    exit 1
fi

echo

# Step 4: Validate the served schema
print_info "Step 4: Validating the served schema..."

SERVED_SCHEMA=$(curl -s "$SCHEMA_URL")
if echo "$SERVED_SCHEMA" | jq -e '.title and .type and .required and .properties' > /dev/null; then
    print_success "✅ Served schema has valid structure"

    # Check specific required fields
    REQUIRED_FIELDS=("identifier" "data" "taxonomy_vocabulary")
    for field in "${REQUIRED_FIELDS[@]}"; do
        if echo "$SERVED_SCHEMA" | jq -e ".required | contains([\"$field\"])" > /dev/null; then
            print_success "✅ Required field present: $field"
        else
            print_error "❌ Required field missing: $field"
            exit 1
        fi
    done
else
    print_error "❌ Served schema has invalid structure"
    exit 1
fi

if [ "$(echo "$SERVED_SCHEMA" | jq -S .)" = "$(jq -S . "$MODULE_SCHEMA_DIR/taxonomy_term.json")" ]; then
    print_success "✅ Served schema matches the module's taxonomy_term.json"
else
    print_error "❌ Served schema differs from the module's taxonomy_term.json"
    exit 1
fi

echo

# Step 5: Test uninstall removes the schema from the API
print_info "Step 5: Testing uninstall removes the schema from the API..."

drush pm:uninstall dkan_taxonomy_integration -y > /dev/null 2>&1
drush cr > /dev/null 2>&1

SCHEMA_HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "$SCHEMA_URL")
if [ "$SCHEMA_HTTP_CODE" = "404" ] || [ "$SCHEMA_HTTP_CODE" = "500" ]; then
    print_success "✅ Schema endpoint returns $SCHEMA_HTTP_CODE after uninstall"
else
    print_error "❌ Schema endpoint returns $SCHEMA_HTTP_CODE after uninstall (expected: 404)"
    exit 1
fi

if curl -s "$BASE_URL/api/1/metastore/schemas" | jq -e 'has("taxonomy_term")' > /dev/null; then
    print_error "❌ taxonomy_term still listed in /api/1/metastore/schemas after uninstall"
    exit 1
else
    print_success "✅ taxonomy_term no longer listed in /api/1/metastore/schemas"
fi

if [ -f "$MODULE_SCHEMA_DIR/taxonomy_term.json" ] && [ -f "$MODULE_SCHEMA_DIR/taxonomy_term.ui.json" ]; then
    print_success "✅ Module's own schema files remain in place"
else
    print_error "❌ Module's own schema files were removed"
    exit 1
fi

echo

print_success "=== Comprehensive Schema Validation Test PASSED! ==="

print_success "✅ SUCCESS: Source schema files validated"
print_success "✅ SUCCESS: Module install serves the schema through the API"
print_success "✅ SUCCESS: Served schema has valid structure and matches the source"
print_success "✅ SUCCESS: Module uninstall removes the schema from the API"
print_success "✅ SUCCESS: End-to-end schema lifecycle verified"

echo
echo "🔧 Schema Validation Summary:"
echo "• Source file validation: VERIFIED ✅"
echo "• Schema served on install: VERIFIED ✅"
echo "• Schema structure validation: VERIFIED ✅"
echo "• Schema gone on uninstall: VERIFIED ✅"
echo "• Complete lifecycle: VERIFIED ✅"
