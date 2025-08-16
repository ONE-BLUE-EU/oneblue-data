#!/bin/bash

# Complete DKAN Taxonomy Integration Test Script
# This script demonstrates the full workflow including term assignment

set -e

# Source common functions
source "$(dirname "$0")/common_functions.sh"

# Configuration
VOCABULARY_ID="test_vocab_complete"
TERMS="Lion,Tiger,Bear"

# Check prerequisites
check_prerequisites

# Clean up any leftover test data from previous runs
cleanup_vocabulary "$VOCABULARY_ID" "true" 2>/dev/null || true

# Get valid dataset
get_valid_dataset || exit 1

echo "Using Node ID: $NODE_ID"
echo "Using Dataset ID: $DATASET_ID"

echo "=== DKAN Taxonomy Integration Complete Test ==="
echo "Vocabulary ID: $VOCABULARY_ID"
echo "Terms: $TERMS"
echo "Dataset ID: $DATASET_ID"
echo "Node ID: $NODE_ID"
echo

# Step 1: Check initial state
show_initial_state
echo

# Step 2: Create vocabulary and terms
print_info "Step 2: Creating vocabulary and terms..."
create_vocabulary_and_terms "$VOCABULARY_ID" "$TERMS" "Complete test vocabulary"
echo

# Step 3: Check field detection
print_info "Step 3: Checking field detection..."
test_field_detection
echo

# Step 4: Assign terms to dataset
print_info "Step 4: Assigning terms to dataset..."
TERM_IDS=$(get_term_ids "$VOCABULARY_ID")
echo "Term IDs: $TERM_IDS"

assign_terms_to_dataset "$VOCABULARY_ID" "$TERM_IDS"
echo

# Step 5: Check API (after adding and assigning)
print_info "Step 5: Checking API response (after adding taxonomy and assigning terms)..."
check_api_field "taxonomy_term" 3
echo

# Step 6: Show full API response
print_info "Step 6: Full API response..."
show_full_api_response
echo

# Step 7: Remove taxonomy
print_info "Step 7: Removing taxonomy association..."
cleanup_vocabulary "$VOCABULARY_ID"
echo

# Step 8: Trigger save again
print_info "Step 8: Triggering dataset save (cleanup)..."
ddev drush php:eval "Drupal::entityTypeManager()->getStorage('node')->load($NODE_ID)->save();"
echo

# Step 9: Check API (after removing)
print_info "Step 9: Checking API response (after removing taxonomy)..."
check_api_field "taxonomy_term" 0
echo

# Step 10: Clean up
print_info "Step 10: Final cleanup..."
print_info "Removing taxonomy vocabulary completely..."

cleanup_vocabulary "$VOCABULARY_ID" "true"
echo

# Final verification
show_final_state
echo

print_success "=== Complete test finished! ==="
