#!/bin/bash

# Common functions for DKAN Taxonomy Integration test scripts
# This script should be sourced by other test scripts

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Common configuration
API_BASE="https://dkan.ddev.site/api/1/metastore/schemas/dataset/items"

# Function to print colored output
print_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

print_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Function to retrieve the first valid dataset with proper metadata
get_valid_dataset() {
    print_info "Retrieving first valid dataset with proper metadata..."

    # Get node ID
    local node_id=$(ddev drush php:eval "
    \$nodes = Drupal::entityTypeManager()->getStorage('node')->loadByProperties(['type' => 'data']);
    foreach (\$nodes as \$node) {
        if (\$node->hasField('field_json_metadata')) {
            \$metadata = \$node->get('field_json_metadata')->value;
            \$decoded = json_decode(\$metadata, true);
            if (\$decoded && isset(\$decoded['title']) && strlen(\$metadata) > 100) {
                echo \$node->id();
                break;
            }
        }
    }
    ")

    if [ -z "$node_id" ]; then
        print_error "No valid datasets with proper metadata found"
        return 1
    fi

    # Get dataset UUID
    local dataset_id=$(ddev drush php:eval "
    \$node = Drupal::entityTypeManager()->getStorage('node')->load($node_id);
    if (\$node) {
        echo \$node->uuid();
    } else {
        echo 'Node not found';
        exit(1);
    }
    ")

    if [ "$dataset_id" = "Node not found" ]; then
        print_error "Node not found"
        return 1
    fi

    # Set global variables
    NODE_ID="$node_id"
    DATASET_ID="$dataset_id"

    print_success "Found valid dataset - Node ID: $NODE_ID, Dataset ID: $DATASET_ID"
    return 0
}

# Function to check prerequisites
check_prerequisites() {
    print_info "Checking prerequisites..."

    # Check if ddev is available
    if ! command -v ddev &> /dev/null; then
        print_error "ddev is not installed or not in PATH"
        return 1
    fi

    # Check if jq is available
    if ! command -v jq &> /dev/null; then
        print_error "jq is not installed or not in PATH"
        return 1
    fi

    # Check if DDEV is running
    if ! ddev status &> /dev/null; then
        print_error "DDEV is not running"
        return 1
    fi

    print_success "All prerequisites are met"
    return 0
}

# Function to clear cache and wait
clear_cache_and_wait() {
    print_info "Clearing cache and waiting for processing..."
    ddev drush cr
    sleep 2
}

# Function to get term IDs for a vocabulary
get_term_ids() {
    local vocabulary_id="$1"

    local term_ids=$(ddev drush php:eval "
    \$terms = Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties(['vid' => '$vocabulary_id']);
    \$ids = [];
    foreach (\$terms as \$term) {
        \$ids[] = \$term->id();
    }
    echo implode(',', \$ids);
    ")

    echo "$term_ids"
}

# Function to assign terms to dataset
assign_terms_to_dataset() {
    local vocabulary_id="$1"
    local term_ids="$2"

    print_info "Assigning terms to dataset..."

    ddev drush php:eval "
    \$node = Drupal::entityTypeManager()->getStorage('node')->load($NODE_ID);
    \$term_ids = explode(',', '$term_ids');
    \$node->set('field_$vocabulary_id', \$term_ids);
    \$node->save();
    echo 'Terms assigned to dataset';
    "
}

# Function to check API response for a specific field
check_api_field() {
    local field_name="$1"
    local expected_count="${2:-0}"

    print_info "Checking API response for field: $field_name"

    local response=$(curl -s -X 'GET' "$API_BASE/$DATASET_ID?show-reference-ids=true" -H 'accept: application/json' | jq ".$field_name")

    if [ "$response" = "null" ]; then
        if [ "$expected_count" -eq 0 ]; then
            print_success "Field $field_name is not present (as expected)"
            return 0
        else
            print_error "Field $field_name is not present (expected $expected_count items)"
            return 1
        fi
    else
        local actual_count=$(echo "$response" | jq 'length')
        if [ "$actual_count" -eq "$expected_count" ]; then
            print_success "Field $field_name has $actual_count items (as expected)"
            echo "$response"
            return 0
        else
            print_error "Field $field_name has $actual_count items (expected $expected_count)"
            echo "$response"
            return 1
        fi
    fi
}

# Function to show full API response
show_full_api_response() {
    print_info "Full API response:"
    curl -s -X 'GET' "$API_BASE/$DATASET_ID?show-reference-ids=true" -H 'accept: application/json' | jq '.'
}

# Function to check logs for specific messages
check_logs() {
    local search_term="$1"
    local count="${2:-5}"

    print_info "Checking logs for: $search_term"
    if ddev drush watchdog:show --type=dkan_taxonomy_integration --count="$count" | grep "$search_term" > /dev/null; then
        print_success "Found log entries for: $search_term"
        ddev drush watchdog:show --type=dkan_taxonomy_integration --count="$count" | grep "$search_term"
    else
        print_info "Note: No recent log entries found for '$search_term' (this may be expected if no datasets use the updated terms)"
    fi
}

# Function to cleanup vocabulary and terms
cleanup_vocabulary() {
    local vocabulary_id="$1"
    local delete_vocabulary="${2:-false}"

    print_info "Cleaning up vocabulary: $vocabulary_id"

    if [ "$delete_vocabulary" = "true" ]; then
        ddev drush dkan:remove-taxonomy-from-dataset "$vocabulary_id" --delete-vocabulary
        print_success "Vocabulary $vocabulary_id completely removed"
    else
        ddev drush dkan:remove-taxonomy-from-dataset "$vocabulary_id"
        print_success "Vocabulary $vocabulary_id field removed (vocabulary kept)"
    fi
}

# Function to create vocabulary and terms
create_vocabulary_and_terms() {
    local vocabulary_id="$1"
    local terms="$2"
    local description="${3:-Test vocabulary}"

    print_info "Creating vocabulary: $vocabulary_id"
    ddev drush dkan:add-taxonomy-to-dataset "$vocabulary_id" --description="$description"

    if [ -n "$terms" ]; then
        print_info "Adding terms: $terms"
        ddev drush dkan:add-taxonomy-terms "$vocabulary_id" "$terms"
    fi
}

# Function to test field detection
test_field_detection() {
    print_info "Testing field detection..."
    ddev drush dkan:test-taxonomy-fields
}

# Function to show initial state
show_initial_state() {
    print_info "Checking initial state..."
    ddev drush dkan:list-taxonomy-vocabularies
}

# Function to show final state
show_final_state() {
    print_info "Final vocabulary list:"
    ddev drush dkan:list-taxonomy-vocabularies
}

# Export functions so they can be used by sourcing scripts
export -f print_info print_success print_warning print_error
export -f get_valid_dataset check_prerequisites clear_cache_and_wait
export -f get_term_ids assign_terms_to_dataset check_api_field show_full_api_response
export -f check_logs cleanup_vocabulary create_vocabulary_and_terms
export -f test_field_detection show_initial_state show_final_state
export API_BASE
