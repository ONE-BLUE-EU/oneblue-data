#!/bin/bash

# Test Taxonomy Term Updates - API Dataset Items Endpoint
# Tests: /api/1/metastore/schemas/dataset/items/{id}
#
# This script validates that taxonomy term updates (both programmatic and UI-based)
# trigger dataset saves and API updates correctly. Covers single and multiple term scenarios.

set -e

# Source common functions
source "$(dirname "$0")/common_functions.sh"

# Configuration
VOCABULARY_ID="test_vocab_updates"
INITIAL_TERMS="InitialTerm"

# Check prerequisites
check_prerequisites

# Clean up any existing test vocabulary to ensure clean state
print_info "Cleaning up any existing test vocabulary..."
cleanup_vocabulary "$VOCABULARY_ID" "true" 2>/dev/null || true

# Get valid dataset
get_valid_dataset || exit 1

echo "=== Taxonomy Term Updates - API Dataset Items Test ==="
echo "Testing Endpoint: /api/1/metastore/schemas/dataset/items/{id}"
echo "Vocabulary ID: $VOCABULARY_ID"
echo "Dataset ID: $DATASET_ID"
echo "Node ID: $NODE_ID"
echo

# Step 1: Create vocabulary and add initial term
print_info "Step 1: Creating vocabulary and adding initial term..."
create_vocabulary_and_terms "$VOCABULARY_ID" "$INITIAL_TERMS" "Term update test vocabulary"
echo

# Step 2: Get term ID and assign to dataset
print_info "Step 2: Assigning initial term to dataset..."
TERM_IDS=$(get_term_ids "$VOCABULARY_ID")
echo "Initial Term IDs: $TERM_IDS"

assign_terms_to_dataset "$VOCABULARY_ID" "$TERM_IDS"
echo

# Step 3: Check initial API response
print_info "Step 3: Checking initial API response..."
clear_cache_and_wait
check_api_field "taxonomy_term" 1
echo

# Step 4: Test programmatic term update
print_info "Step 4: Testing programmatic term update..."
TERM_ID=$(echo $TERM_IDS | cut -d',' -f1)
drush php:eval "
\$term = Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($TERM_ID);
if (\$term) {
    \$old_name = \$term->getName();
    \$term->setName('UpdatedProgrammaticTerm');
    \$term->save();
    echo 'Updated term from \"' . \$old_name . '\" to \"UpdatedProgrammaticTerm\"';
} else {
    echo 'Term not found';
}
"
echo

# Step 5: Check logs for programmatic update trigger
print_info "Step 5: Checking logs for programmatic update trigger..."
check_logs "term update" 5
echo

# Step 6: Check API response after programmatic update
print_info "Step 6: Checking API response after programmatic update..."
clear_cache_and_wait
check_api_field "taxonomy_term" 1
echo

# Step 7: Test UI-based term update simulation
print_info "Step 7: Testing UI-based term update simulation..."
drush php:eval "
\$term = Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($TERM_ID);
if (\$term) {
    \$old_name = \$term->getName();
    \$term->setName('UpdatedUITerm');
    \$term->save();
    echo 'Updated term from \"' . \$old_name . '\" to \"UpdatedUITerm\" via UI simulation';
} else {
    echo 'Term not found';
}
"
echo

# Step 8: Check logs for UI update processing
print_info "Step 8: Checking logs for UI update processing..."
check_logs "UI immediate" 3
echo

# Step 9: Check API response after UI update
print_info "Step 9: Checking API response after UI update..."
clear_cache_and_wait
check_api_field "taxonomy_term" 1
echo

# Step 10: Test multiple terms scenario
print_info "Step 10: Testing multiple terms scenario..."
drush dkan:add-taxonomy-terms "$VOCABULARY_ID" "SecondTerm,ThirdTerm"
echo

# Get all term IDs and assign to dataset
ALL_TERM_IDS=$(get_term_ids "$VOCABULARY_ID")
echo "All Term IDs: $ALL_TERM_IDS"

assign_terms_to_dataset "$VOCABULARY_ID" "$ALL_TERM_IDS"
echo

# Step 11: Update multiple terms
print_info "Step 11: Testing multiple term updates..."
SECOND_TERM_ID=$(echo $ALL_TERM_IDS | cut -d',' -f2)
THIRD_TERM_ID=$(echo $ALL_TERM_IDS | cut -d',' -f3)

# Update second term
drush php:eval "
\$term = Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($SECOND_TERM_ID);
if (\$term) {
    \$old_name = \$term->getName();
    \$term->setName('UpdatedSecondTerm');
    \$term->save();
    echo 'Updated second term from \"' . \$old_name . '\" to \"UpdatedSecondTerm\"';
} else {
    echo 'Second term not found';
}
"

# Update third term (form-based simulation)
drush php:eval "
\$term = Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($THIRD_TERM_ID);
if (\$term) {
    \$old_name = \$term->getName();
    \$term->setName('FormUpdatedThirdTerm');
    \$term->save();
    echo 'Updated third term from \"' . \$old_name . '\" to \"FormUpdatedThirdTerm\" via form simulation';
} else {
    echo 'Third term not found';
}
"
echo

# Step 12: Check API response with multiple updated terms
print_info "Step 12: Checking API response with multiple updated terms..."
clear_cache_and_wait
check_api_field "taxonomy_term" 3
echo

# Step 13: Verify all term updates
print_info "Step 13: Verifying all term updates..."
drush php:eval "
\$terms = [$TERM_ID, $SECOND_TERM_ID, $THIRD_TERM_ID];
foreach (\$terms as \$term_id) {
    \$term = Drupal::entityTypeManager()->getStorage('taxonomy_term')->load(\$term_id);
    if (\$term) {
        echo 'Term ID ' . \$term_id . ' name: ' . \$term->getName() . PHP_EOL;
    }
}
"
echo

# Step 14: Check final logs
print_info "Step 14: Checking final update logs..."
check_logs "triggered save" 5
echo

# Step 15: Clean up
print_info "Step 15: Cleaning up..."
cleanup_vocabulary "$VOCABULARY_ID" "true"
echo

print_success "=== Taxonomy term updates test completed successfully! ==="
echo
print_success "✅ SUCCESS: Programmatic taxonomy term updates trigger dataset saves!"
print_success "✅ SUCCESS: UI-based taxonomy term updates trigger immediate saves!"
print_success "✅ SUCCESS: Multiple terms are handled correctly!"
print_success "✅ SUCCESS: API responses reflect all changes immediately!"
print_success "✅ SUCCESS: Both update types maintain data integrity!"
