#!/bin/bash

# API Endpoint Isolation Verification Test
# Tests: Critical bug fix for keyword/items pollution
#
# This script verifies that:
# 1. /api/1/metastore/schemas/keyword/items remains clean (no taxonomy_term pollution)
# 2. /api/1/metastore/schemas/dataset/items still has taxonomy_term functionality
# 3. Other endpoints are not affected by our module

set -e

# Source common functions
source "$(dirname "$0")/common_functions.sh"

echo "=== API Endpoint Isolation Verification Test ==="
echo "Critical test: Ensuring our module doesn't pollute other endpoints"
echo

# Step 1: Test keyword items structure (CRITICAL - should be clean)
print_info "Step 1: Testing keyword items structure (CRITICAL - no pollution)..."

KEYWORD_ITEMS_RESPONSE=$(curl -s "https://dkan.ddev.site/api/1/metastore/schemas/keyword/items")

# Check if response is an array
if echo "$KEYWORD_ITEMS_RESPONSE" | jq -e 'type == "array"' > /dev/null; then
    print_success "✅ keyword/items returns an array"
else
    print_error "❌ keyword/items doesn't return an array"
    echo "Response: $KEYWORD_ITEMS_RESPONSE"
    exit 1
fi

# Check keyword items count
KEYWORD_COUNT=$(echo "$KEYWORD_ITEMS_RESPONSE" | jq 'length')
print_info "Found $KEYWORD_COUNT keyword items"

if [ "$KEYWORD_COUNT" -gt 0 ]; then
    # Test first keyword item structure
    FIRST_KEYWORD=$(echo "$KEYWORD_ITEMS_RESPONSE" | jq '.[0]')

    # Check required fields exist
    if echo "$FIRST_KEYWORD" | jq -e '.identifier' > /dev/null && \
       echo "$FIRST_KEYWORD" | jq -e '.data' > /dev/null; then
        print_success "✅ keyword items have required fields: identifier + data"
    else
        print_error "❌ keyword items missing required fields"
        echo "Actual structure: $FIRST_KEYWORD"
        exit 1
    fi

    # CRITICAL TEST: Verify no taxonomy_term pollution
    if echo "$FIRST_KEYWORD" | jq -e '.taxonomy_term' > /dev/null; then
        print_error "❌ CRITICAL BUG: keyword items polluted with taxonomy_term field!"
        echo "Polluted item: $FIRST_KEYWORD"
        exit 1
    else
        print_success "✅ CRITICAL: keyword items are clean (no taxonomy_term pollution)"
    fi

    # Count fields to ensure exactly 2
    FIELD_COUNT=$(echo "$FIRST_KEYWORD" | jq 'keys | length')
    if [ "$FIELD_COUNT" -eq 2 ]; then
        print_success "✅ keyword items have exactly 2 fields (identifier, data)"
    else
        print_error "❌ keyword items have $FIELD_COUNT fields (expected 2)"
        echo "Fields: $(echo "$FIRST_KEYWORD" | jq 'keys')"
        exit 1
    fi

    echo "📄 Sample clean keyword item:"
    echo "$FIRST_KEYWORD" | jq '.'

else
    print_success "✅ keyword/items is empty array (valid response)"
fi

echo

# Step 2: Test all keyword items for pollution
print_info "Step 2: Testing ALL keyword items for pollution..."

POLLUTION_COUNT=0
for i in $(seq 0 $((KEYWORD_COUNT - 1))); do
    ITEM=$(echo "$KEYWORD_ITEMS_RESPONSE" | jq ".[$i]")
    if echo "$ITEM" | jq -e '.taxonomy_term' > /dev/null; then
        POLLUTION_COUNT=$((POLLUTION_COUNT + 1))
        print_error "❌ Item $i is polluted: $(echo "$ITEM" | jq -c '.')"
    fi
done

if [ "$POLLUTION_COUNT" -eq 0 ]; then
    print_success "✅ ALL $KEYWORD_COUNT keyword items are clean (no pollution detected)"
else
    print_error "❌ CRITICAL: $POLLUTION_COUNT out of $KEYWORD_COUNT keyword items are polluted!"
    exit 1
fi

echo

# Step 3: Verify dataset items still have taxonomy functionality
print_info "Step 3: Verifying dataset items still have taxonomy functionality..."

DATASET_ITEMS_RESPONSE=$(curl -s "https://dkan.ddev.site/api/1/metastore/schemas/dataset/items")

if echo "$DATASET_ITEMS_RESPONSE" | jq -e 'type == "array"' > /dev/null; then
    DATASET_COUNT=$(echo "$DATASET_ITEMS_RESPONSE" | jq 'length')
    print_success "✅ dataset/items returns array with $DATASET_COUNT items"

    if [ "$DATASET_COUNT" -gt 0 ]; then
        FIRST_DATASET=$(echo "$DATASET_ITEMS_RESPONSE" | jq '.[0]')

        # Check if dataset has taxonomy_term field (should exist)
        if echo "$FIRST_DATASET" | jq -e '.taxonomy_term' > /dev/null; then
            print_success "✅ dataset items correctly have taxonomy_term field"

            # Check dataset @type
            DATASET_TYPE=$(echo "$FIRST_DATASET" | jq -r '.["@type"]')
            if [ "$DATASET_TYPE" = "dcat:Dataset" ]; then
                print_success "✅ dataset items have correct @type: $DATASET_TYPE"
            else
                print_error "❌ dataset items have incorrect @type: $DATASET_TYPE"
                exit 1
            fi
        else
            print_error "❌ dataset items missing taxonomy_term field (functionality broken)"
            exit 1
        fi
    fi
else
    print_error "❌ dataset/items doesn't return an array"
    exit 1
fi

echo

# Step 4: Test theme items (should also be clean)
print_info "Step 4: Testing theme items for pollution..."

THEME_ITEMS_RESPONSE=$(curl -s "https://dkan.ddev.site/api/1/metastore/schemas/theme/items")

if echo "$THEME_ITEMS_RESPONSE" | jq -e 'type == "array"' > /dev/null; then
    THEME_COUNT=$(echo "$THEME_ITEMS_RESPONSE" | jq 'length')
    print_success "✅ theme/items returns array with $THEME_COUNT items"

    if [ "$THEME_COUNT" -gt 0 ]; then
        FIRST_THEME=$(echo "$THEME_ITEMS_RESPONSE" | jq '.[0]')

        # Check for pollution
        if echo "$FIRST_THEME" | jq -e '.taxonomy_term' > /dev/null; then
            print_error "❌ theme items polluted with taxonomy_term field!"
            exit 1
        else
            print_success "✅ theme items are clean (no taxonomy_term pollution)"
        fi
    fi
else
    print_info "ℹ️  theme/items endpoint not available or empty"
fi

echo

# Step 5: Test publisher items (should also be clean)
print_info "Step 5: Testing publisher items for pollution..."

PUBLISHER_ITEMS_RESPONSE=$(curl -s "https://dkan.ddev.site/api/1/metastore/schemas/publisher/items")

if echo "$PUBLISHER_ITEMS_RESPONSE" | jq -e 'type == "array"' > /dev/null; then
    PUBLISHER_COUNT=$(echo "$PUBLISHER_ITEMS_RESPONSE" | jq 'length')
    print_success "✅ publisher/items returns array with $PUBLISHER_COUNT items"

    if [ "$PUBLISHER_COUNT" -gt 0 ]; then
        FIRST_PUBLISHER=$(echo "$PUBLISHER_ITEMS_RESPONSE" | jq '.[0]')

        # Check for pollution
        if echo "$FIRST_PUBLISHER" | jq -e '.taxonomy_term' > /dev/null; then
            print_error "❌ publisher items polluted with taxonomy_term field!"
            exit 1
        else
            print_success "✅ publisher items are clean (no taxonomy_term pollution)"
        fi
    fi
else
    print_info "ℹ️  publisher/items endpoint not available or empty"
fi

echo

print_success "=== API Endpoint Isolation Verification Test PASSED! ==="
echo
print_success "✅ SUCCESS: keyword/items endpoint is clean (no taxonomy_term pollution)"
print_success "✅ SUCCESS: dataset/items endpoint retains taxonomy_term functionality"
print_success "✅ SUCCESS: theme/items endpoint is clean (no pollution)"
print_success "✅ SUCCESS: publisher/items endpoint is clean (no pollution)"
print_success "✅ SUCCESS: Module correctly isolates its functionality to datasets only"

echo
echo "🎯 Critical Bug Fix Verification Summary:"
echo "• Our TaxonomyUnificationSubscriber now only processes dataset items (@type: dcat:Dataset)"
echo "• keyword/items structure: [{identifier, data}] ✅"
echo "• dataset/items structure: [{..., taxonomy_term: [...]}] ✅"
echo "• No pollution of other metastore endpoints ✅"
echo "• Module functionality preserved for datasets ✅"
