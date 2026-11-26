#!/bin/bash

# Test Install/Uninstall Service Provider Operations
# Tests: Complete install/uninstall cycle with service provider verification
#
# This script validates the service provider approach:
# 1. Module install enables service provider
# 2. Schema files remain in module directory
# 3. API correctly exposes taxonomy schemas
# 4. Module uninstall disables service provider
# 5. API no longer exposes taxonomy schemas

set -e

# Source common functions
source "$(dirname "$0")/common_functions.sh"

echo "=== Install/Uninstall Service Provider Operations Test ==="
echo "Testing service provider functionality"
echo

# Define paths
MODULE_SCHEMA_DIR="docroot/modules/custom/dkan_taxonomy_integration/schema"
API_URL="$BASE_URL/api/1/metastore/schemas"

# Step 1: Verify starting state (module should be disabled, files should not exist)
print_info "Step 1: Verifying clean starting state..."

# Check module status
if drush pm:list --status=enabled | grep -q dkan_taxonomy_integration; then
    print_info "Module is currently enabled - disabling for clean test..."
    drush pm:uninstall dkan_taxonomy_integration -y
    drush cr
    sleep 2
fi

# Verify schema files don't exist
if [ -f "$TAXONOMY_TERM_JSON" ]; then
    print_error "❌ taxonomy_term.json exists before install - test environment not clean"
    exit 1
else
    print_success "✅ taxonomy_term.json does not exist (clean state)"
fi

if [ -f "$TAXONOMY_TERM_UI_JSON" ]; then
    print_error "❌ taxonomy_term.ui.json exists before install - test environment not clean"
    exit 1
else
    print_success "✅ taxonomy_term.ui.json does not exist (clean state)"
fi

echo

# Step 2: Verify source schema files exist in module
print_info "Step 2: Verifying source schema files exist in module..."

if [ -f "$MODULE_SCHEMA_DIR/taxonomy_term.json" ]; then
    print_success "✅ Source taxonomy_term.json exists in module"
else
    print_error "❌ Source taxonomy_term.json missing from module"
    exit 1
fi

if [ -f "$MODULE_SCHEMA_DIR/taxonomy_term.ui.json" ]; then
    print_success "✅ Source taxonomy_term.ui.json exists in module"
else
    print_error "❌ Source taxonomy_term.ui.json missing from module"
    exit 1
fi

# Verify source file content
print_info "Validating source schema file structure..."

# Check taxonomy_term.json structure
if jq -e '.title and .type and .required and .properties' "$MODULE_SCHEMA_DIR/taxonomy_term.json" > /dev/null; then
    print_success "✅ taxonomy_term.json has valid structure"
else
    print_error "❌ taxonomy_term.json has invalid structure"
    exit 1
fi

# Check required fields
REQUIRED_FIELDS=$(jq -r '.required[]' "$MODULE_SCHEMA_DIR/taxonomy_term.json")
if echo "$REQUIRED_FIELDS" | grep -q "identifier" && \
   echo "$REQUIRED_FIELDS" | grep -q "data" && \
   echo "$REQUIRED_FIELDS" | grep -q "taxonomy_vocabulary"; then
    print_success "✅ taxonomy_term.json has required fields: identifier, data, taxonomy_vocabulary"
else
    print_error "❌ taxonomy_term.json missing required fields"
    exit 1
fi

# Check taxonomy_term.ui.json structure
if jq -e '.identifier and .data and .taxonomy_vocabulary' "$MODULE_SCHEMA_DIR/taxonomy_term.ui.json" > /dev/null; then
    print_success "✅ taxonomy_term.ui.json has valid structure"
else
    print_error "❌ taxonomy_term.ui.json has invalid structure"
    exit 1
fi

echo

# Step 3: Install module and verify service provider activation
print_info "Step 3: Installing module and verifying service provider activation..."

print_info "Installing dkan_taxonomy_integration module..."
if drush pm:enable dkan_taxonomy_integration -y; then
    print_success "✅ Module installed successfully"
else
    print_error "❌ Module installation failed"
    exit 1
fi

print_info "Clearing cache after install..."
drush cr
sleep 2

# Verify schema files still exist in module directory
if [ -f "$MODULE_SCHEMA_DIR/taxonomy_term.json" ] && [ -f "$MODULE_SCHEMA_DIR/taxonomy_term.ui.json" ]; then
    print_success "✅ Schema files remain in module directory"
else
    print_error "❌ Schema files missing from module directory"
    exit 1
fi

# Test that service provider is working by checking API
print_info "Testing service provider functionality via API..."
API_RESPONSE=$(curl -s "$API_URL" 2>/dev/null || echo "{}")
if echo "$API_RESPONSE" | jq -e 'has("taxonomy_term") and has("taxonomy_term.ui")' > /dev/null 2>&1; then
    print_success "✅ Service provider working - taxonomy schemas accessible via API"
else
    print_error "❌ Service provider not working - taxonomy schemas not accessible via API"
    print_error "    Available schemas: $(echo "$API_RESPONSE" | jq -r 'keys[]' 2>/dev/null | tr '\n' ' ')"
    exit 1
fi

# Verify individual schema accessibility
TAXONOMY_SCHEMA=$(curl -s "$API_URL/taxonomy_term" 2>/dev/null || echo "{}")
if echo "$TAXONOMY_SCHEMA" | jq -e '.type' > /dev/null 2>&1; then
    print_success "✅ taxonomy_term schema accessible and valid"
else
    print_error "❌ taxonomy_term schema not accessible or invalid"
    exit 1
fi

echo

# Step 4: Verify schema content via API matches source files
print_info "Step 4: Verifying schema content via API matches source files..."

# Compare API response with source file content
SOURCE_SCHEMA=$(cat "$MODULE_SCHEMA_DIR/taxonomy_term.json")
API_SCHEMA=$(curl -s "$API_URL/taxonomy_term" 2>/dev/null)

# Extract the core schema properties for comparison
SOURCE_TITLE=$(echo "$SOURCE_SCHEMA" | jq -r '.title')
API_TITLE=$(echo "$API_SCHEMA" | jq -r '.title')

if [ "$SOURCE_TITLE" = "$API_TITLE" ]; then
    print_success "✅ API schema matches source file content"
else
    print_error "❌ API schema differs from source file"
    print_error "    Source title: $SOURCE_TITLE"
    print_error "    API title: $API_TITLE"
    exit 1
fi

# Verify required fields match
SOURCE_REQUIRED=$(echo "$SOURCE_SCHEMA" | jq -r '.required | sort | join(",")')
API_REQUIRED=$(echo "$API_SCHEMA" | jq -r '.required | sort | join(",")')

if [ "$SOURCE_REQUIRED" = "$API_REQUIRED" ]; then
    print_success "✅ API schema required fields match source file"
else
    print_error "❌ API schema required fields differ from source file"
    exit 1
fi

# Test our module's direct functionality (metastore service usage)
print_info "Testing module functionality directly..."
METASTORE_TEST=$(drush eval "try { \$service = \Drupal::service('dkan.metastore.service'); echo 'Metastore service available'; } catch (\Exception \$e) { echo 'Error: ' . \$e->getMessage(); }")

if echo "$METASTORE_TEST" | grep -q "Metastore service available"; then
    print_success "✅ Module can access DKAN metastore service"
else
    print_error "❌ Module cannot access DKAN metastore service: $METASTORE_TEST"
    exit 1
fi

echo

# Step 5: Uninstall module and verify service provider deactivation
print_info "Step 5: Uninstalling module and verifying service provider deactivation..."

print_info "Uninstalling dkan_taxonomy_integration module..."
if drush pm:uninstall dkan_taxonomy_integration -y; then
    print_success "✅ Module uninstalled successfully"
else
    print_error "❌ Module uninstall failed"
    exit 1
fi

print_info "Clearing cache after uninstall..."
drush cr
sleep 2

# Verify schema files still exist in module directory (they should remain)
if [ -f "$MODULE_SCHEMA_DIR/taxonomy_term.json" ] && [ -f "$MODULE_SCHEMA_DIR/taxonomy_term.ui.json" ]; then
    print_success "✅ Schema files remain in module directory (as expected)"
else
    print_error "❌ Schema files missing from module directory"
    exit 1
fi

# Check that taxonomy schemas are NO LONGER accessible via API (service provider deactivated)
print_info "Verifying service provider deactivation..."
API_RESPONSE=$(curl -s "$API_URL" 2>/dev/null || echo "{}")
if echo "$API_RESPONSE" | jq -e 'has("taxonomy_term")' > /dev/null 2>&1; then
    print_error "❌ taxonomy_term schema still accessible after uninstall"
    print_error "    This indicates service provider was not properly deactivated"
    exit 1
else
    print_success "✅ Service provider deactivated - taxonomy schemas no longer accessible via API"
fi

echo

# Step 6: Verify API still works for standard DKAN schemas
print_info "Step 6: Verifying standard DKAN schemas still work after uninstall..."

# Standard DKAN schemas should still be accessible
STANDARD_SCHEMAS=("dataset" "keyword" "theme" "publisher")
for schema in "${STANDARD_SCHEMAS[@]}"; do
    if echo "$API_RESPONSE" | jq -e "has(\"$schema\")" > /dev/null 2>&1; then
        print_success "✅ Standard schema '$schema' still accessible"
    else
        print_error "❌ Standard schema '$schema' not accessible"
        exit 1
    fi
done

print_success "✅ Standard DKAN functionality unaffected by module uninstall"

echo

# Final verification: Ensure service provider approach is working correctly
print_info "Final verification: Service provider approach working correctly..."

# Verify that we never created files in DKAN directory
TAXONOMY_FILES_IN_DKAN=$(find docroot/modules/contrib/dkan/schema/collections/ -name "*taxonomy*" -type f 2>/dev/null | wc -l)
if [ "$TAXONOMY_FILES_IN_DKAN" -eq 0 ]; then
    print_success "✅ No taxonomy files created in DKAN directory (clean service provider approach)"
else
    print_error "❌ Found taxonomy files in DKAN directory - service provider approach not working"
    exit 1
fi

# Verify our module files are still intact
if [ -f "$MODULE_SCHEMA_DIR/taxonomy_term.json" ] && [ -f "$MODULE_SCHEMA_DIR/taxonomy_term.ui.json" ]; then
    print_success "✅ Module schema files remain intact in module directory"
else
    print_error "❌ Module schema files missing"
    exit 1
fi

# Final API verification - standard schemas should still work
FINAL_API_CHECK=$(curl -s "$API_URL" 2>/dev/null || echo "{}")
DKAN_SCHEMAS_COUNT=$(echo "$FINAL_API_CHECK" | jq 'keys | length' 2>/dev/null || echo "0")
if [ "$DKAN_SCHEMAS_COUNT" -gt 5 ]; then
    print_success "✅ DKAN API functionality preserved ($DKAN_SCHEMAS_COUNT schemas available)"
else
    print_error "❌ DKAN API functionality appears compromised"
    exit 1
fi

echo
print_success "=== Install/Uninstall Service Provider Operations Test PASSED! ==="
echo
print_success "✅ SUCCESS: Module install activates service provider correctly"
print_success "✅ SUCCESS: Schema files remain in module directory (clean approach)"
print_success "✅ SUCCESS: Schemas are accessible via API through service provider"
print_success "✅ SUCCESS: Module uninstall deactivates service provider correctly"
print_success "✅ SUCCESS: Schemas no longer accessible after uninstall"
print_success "✅ SUCCESS: No files created in DKAN directory (clean approach)"
print_success "✅ SUCCESS: Standard DKAN functionality preserved"

echo
echo "🔧 Service Provider Test Summary:"
echo "• Service provider activation: VERIFIED ✅"
echo "• API schema discovery: VERIFIED ✅"
echo "• Schema content integrity: VERIFIED ✅"
echo "• Service provider deactivation: VERIFIED ✅"
echo "• Clean file system approach: VERIFIED ✅"
echo "• DKAN core preservation: VERIFIED ✅"
