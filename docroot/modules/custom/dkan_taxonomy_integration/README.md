# DKAN Taxonomy Integration Module

This module provides seamless integration between custom taxonomy vocabularies and DKAN datasets, making custom taxonomy fields available through the DKAN API just like the native `theme` and `keyword` fields.

## 🎯 Overview

The DKAN Taxonomy Integration module allows you to:
- Create custom taxonomy vocabularies for datasets
- Associate taxonomy vocabularies with dataset content types
- Automatically integrate taxonomy data into DKAN's JSON metadata
- Expose custom taxonomy fields through the DKAN API
- Remove taxonomy associations and clean up metadata

## ✨ Key Features

- **Drush-Only Setup**: All taxonomy vocabularies and fields are created through Drush commands
- **API Integration**: Custom taxonomy fields appear in DKAN API responses like native fields
- **Automatic Metadata Management**: Taxonomy data is automatically integrated into JSON metadata
- **Real-time Term Updates**: Taxonomy term changes automatically trigger dataset saves and API updates
- **Automatic Vocabulary Cleanup**: When vocabularies are deleted, metadata is automatically cleaned up from all datasets
- **Clean Removal**: Properly removes fields and metadata when vocabularies are unassociated
- **Generic Solution**: Works with any DKAN installation without breaking existing APIs

## 🚀 Installation

1. **Enable the module:**
   ```bash
   drush en dkan_taxonomy_integration -y
   ```

2. **Clear caches:**
   ```bash
   drush cr
   ```

**Note**: The module only provides Drush commands. No fields are created automatically during installation.

## 📋 Complete Workflow Example

This example demonstrates the complete lifecycle of a taxonomy vocabulary called `marine_species` for categorizing marine datasets.

### Step 1: Create Taxonomy Vocabulary and Field

```bash
# Create the marine_species vocabulary and associate it with datasets
drush dkan:add-taxonomy-to-dataset marine_species --description="Marine species for categorizing ocean datasets" --required
```

**What happens:**
- Creates vocabulary `marine_species` with name "Marine Species"
- Creates field `field_marine_species` on the dataset node type
- Configures the field to use `entity_reference_autocomplete_tags` widget
- Sets placeholder text to "Start typing to search..."

### Step 2: Add Terms to the Vocabulary

```bash
# Add marine species terms
drush dkan:add-taxonomy-terms marine_species "Dolphin,Whale,Shark,Sea Turtle,Coral Reef,Marine Fish"
```

**Result:**
```
Created term "Dolphin" in vocabulary marine_species.
Created term "Whale" in vocabulary marine_species.
Created term "Shark" in vocabulary marine_species.
Created term "Sea Turtle" in vocabulary marine_species.
Created term "Coral Reef" in vocabulary marine_species.
Created term "Marine Fish" in vocabulary marine_species.
Successfully created 6 new terms.
```

### Step 3: List Available Vocabularies

```bash
# Check all available vocabularies
drush dkan:list-taxonomy-vocabularies
```

**Result:**
```
Available taxonomy vocabularies:

ID: marine_species
Name: Marine Species
Description: Marine species for categorizing ocean datasets
Terms Count: 6

ID: country
Name: Country
Description: -
Terms Count: 2
```

### Step 4: Associate Terms with Datasets

When you edit a dataset through the DKAN interface, you'll now see a "Marine Species" field with autocomplete functionality. You can:

1. Start typing to search for existing terms
2. Select multiple terms (they appear as pill-shaped elements)
3. Save the dataset

### Step 5: Verify API Integration

After saving a dataset with marine species terms, the data is automatically integrated into the JSON metadata:

**Before integration:**
```json
{
  "@type": "dcat:Dataset",
  "title": "Ocean Biodiversity Study 2024",
  "identifier": "12345-abcde",
  "description": "Study of marine biodiversity in the Atlantic Ocean",
  "keyword": ["biodiversity", "ocean"]
}
```

**After integration:**
```json
{
  "@type": "dcat:Dataset",
  "title": "Ocean Biodiversity Study 2024",
  "identifier": "12345-abcde",
  "description": "Study of marine biodiversity in the Atlantic Ocean",
  "keyword": ["biodiversity", "ocean"],
  "marine_species": [
    {
      "identifier": "dolphin-uuid",
      "data": "Dolphin"
    },
    {
      "identifier": "whale-uuid",
      "data": "Whale"
    },
    {
      "identifier": "coral-reef-uuid",
      "data": "Coral Reef"
    }
  ]
}
```

### Step 6: Access via DKAN API

The custom taxonomy field is now available through the DKAN API:

```bash
# Get dataset with custom taxonomy field
curl -X 'GET' \
  'http://localhost:8080/api/1/metastore/schemas/dataset/items/12345-abcde?show-reference-ids=true' \
  -H 'accept: application/json'
```

**API Response:**
```json
{
  "@type": "dcat:Dataset",
  "title": "Ocean Biodiversity Study 2024",
  "identifier": "12345-abcde",
  "description": "Study of marine biodiversity in the Atlantic Ocean",
  "keyword": ["biodiversity", "ocean"],
  "marine_species": [
    {
      "identifier": "dolphin-uuid",
      "data": "Dolphin"
    },
    {
      "identifier": "whale-uuid",
      "data": "Whale"
    },
    {
      "identifier": "coral-reef-uuid",
      "data": "Coral Reef"
    }
  ]
}
```

### Step 7: Remove Taxonomy Association

When you no longer need the taxonomy vocabulary:

```bash
# Remove the field from datasets (keeps vocabulary and terms)
drush dkan:remove-taxonomy-from-dataset marine_species

# Or remove field and delete vocabulary completely
drush dkan:remove-taxonomy-from-dataset marine_species --delete-vocabulary
```

**What happens:**
- Removes `field_marine_species` from dataset node type
- Cleans up form and view display configurations
- Automatically removes `marine_species` from JSON metadata on next save
- Optionally deletes the vocabulary and all its terms

### Step 8: Verify Clean Removal

After removing the association and saving a dataset:

**Before removal:**
```json
{
  "@type": "dcat:Dataset",
  "title": "Ocean Biodiversity Study 2024",
  "marine_species": [
    {
      "identifier": "dolphin-uuid",
      "data": "Dolphin"
    },
    {
      "identifier": "whale-uuid",
      "data": "Whale"
    },
    {
      "identifier": "coral-reef-uuid",
      "data": "Coral Reef"
    }
  ]
}
```

**After removal:**
```json
{
  "@type": "dcat:Dataset",
  "title": "Ocean Biodiversity Study 2024"
}
```

The `marine_species` field is completely removed from the JSON metadata.

## 🗑️ Automatic Vocabulary Cleanup

When a taxonomy vocabulary is deleted (either through the Drush command with `--delete-vocabulary` or through the Drupal UI), the module automatically:

1. **Triggers the cleanup hook**: `hook_taxonomy_vocabulary_delete()` is called
2. **Finds affected datasets**: Searches all datasets for metadata containing the vocabulary's field
3. **Removes metadata**: Deletes the vocabulary's field from all dataset metadata
4. **Saves datasets**: Triggers dataset saves to update the API
5. **Logs the process**: Provides detailed logging of what was cleaned up

**Example cleanup logs:**
```
Taxonomy vocabulary "Final Test" (ID: final_test) deleted, cleaning up metadata from datasets
Removed final_test from metadata for dataset "North Adriatic - Mediterranean Sea - 2024" (ID: 7) due to vocabulary deletion
Cleaned up metadata from 1 datasets for vocabulary "final_test": North Adriatic - Mediterranean Sea - 2024
```

This ensures that when vocabularies are deleted, no orphaned metadata remains in the API responses.

## 🔧 Drush Commands Reference

### Add Taxonomy Vocabulary to Datasets

```bash
drush dkan:add-taxonomy-to-dataset vocabulary_id [options]
```

**Arguments:**
- `vocabulary_id`: Machine name for the vocabulary (lowercase, underscores only)

**Options:**
- `--description="Description"`: Add a description to the vocabulary
- `--required`: Make the field required

**Examples:**
```bash
# Basic usage
drush dkan:add-taxonomy-to-dataset geographic_regions

# With description and required flag
drush dkan:add-taxonomy-to-dataset data_sources --description="Data sources for datasets" --required
```

### List Taxonomy Vocabularies

```bash
drush dkan:list-taxonomy-vocabularies
```

Displays all available vocabularies with their IDs, names, descriptions, and term counts.

### Add Terms to Vocabulary

```bash
drush dkan:add-taxonomy-terms vocabulary_id "term1,term2,term3"
```

**Examples:**
```bash
# Add multiple terms
drush dkan:add-taxonomy-terms geographic_regions "North America,Europe,Asia,Africa"

# Add single term
drush dkan:add-taxonomy-terms data_sources "Government Database"
```

### Remove Taxonomy Field from Datasets

```bash
drush dkan:remove-taxonomy-from-dataset vocabulary_id [options]
```

**Options:**
- `--delete-vocabulary`: Also delete the vocabulary and all its terms

**Examples:**
```bash
# Remove field only (keeps vocabulary)
drush dkan:remove-taxonomy-from-dataset geographic_regions

# Remove field and delete vocabulary
drush dkan:remove-taxonomy-from-dataset geographic_regions --delete-vocabulary
```

### Test Taxonomy Fields

```bash
drush dkan:test-taxonomy-fields
```

Debugging command to verify custom taxonomy fields are correctly detected on the dataset node type.



## 🔄 Real-time Term Updates

The module automatically handles taxonomy term updates to ensure API responses remain current:

### How It Works

When a taxonomy term is updated (name changed, etc.), the module:

1. **Detects the change** via multiple hooks:
   - `hook_entity_presave()` - Catches all term saves
   - `hook_entity_update()` - Ensures updates are captured
   - `hook_entity_insert()` - Handles new terms
   - Form submit handlers - Catches UI form submissions
2. **Finds affected datasets** that use the updated term
3. **Triggers dataset saves** to update the JSON metadata
4. **Forces immediate processing** with cache clearing and queue processing
5. **Updates API responses** automatically

### Example: Term Name Update

```bash
# Initial state: term "Marine Fish" exists and is used in datasets
# API response shows: "data": "Marine Fish"

# Update the term name
drush php:eval "
\$term = Drupal::entityTypeManager()->getStorage('taxonomy_term')->load(TERM_ID);
\$term->setName('Marine Fishes');
\$term->save();
"

# Result: API response automatically updates to show: "data": "Marine Fishes"
```

### Benefits

- **Zero manual intervention**: All processing happens automatically on entity save/update
- **UI and programmatic updates**: Handles both UI form submissions and programmatic updates
- **Multiple hook coverage**: Ensures no updates are missed through comprehensive hook implementation
- **API consistency**: API responses always reflect the current term names immediately
- **Data integrity**: Ensures metadata stays synchronized with taxonomy changes
- **Performance optimized**: Only updates datasets that actually use the changed term
- **Comprehensive cache clearing**: Automatically clears all relevant caches for immediate API updates
- **Automatic queue processing**: Processes metastore queues immediately without manual intervention

### Automatic Processing

The module automatically handles all processing and cache clearing when taxonomy terms are updated:

- ✅ **Automatic queue processing** - Metastore import and storage queues are processed immediately
- ✅ **Automatic cache clearing** - All relevant caches are cleared automatically
- ✅ **Automatic API updates** - API responses are updated immediately
- ✅ **No manual intervention required** - Everything happens automatically on entity save/update

The system ensures that taxonomy term changes are reflected in the API immediately without any manual steps.

## 🏗️ Technical Architecture

### How It Works

1. **Field Creation**: Drush commands create taxonomy vocabularies and entity reference fields
2. **Data Integration**: `hook_entity_presave()` automatically integrates taxonomy data into JSON metadata
3. **API Exposure**: Custom fields appear in DKAN API responses alongside native fields
4. **Clean Removal**: Removal logic ensures metadata is cleaned up when fields are unassociated

### Integration Points

- **JSON Metadata**: Custom taxonomy fields are stored in `field_json_metadata` like native `theme` and `keyword` fields
- **API Response**: Fields appear in API responses as arrays of objects with `identifier` and `data` properties
- **Form Interface**: Uses `entity_reference_autocomplete_tags` widget for user-friendly term selection
- **Lifecycle Management**: Automatic integration and cleanup during entity save operations

### Field Naming Convention

- **Drupal Field**: `field_vocabulary_id` (e.g., `field_marine_species`)
- **API Field**: `vocabulary_id` (e.g., `marine_species`)
- **Vocabulary Name**: Generated from ID (e.g., `marine_species` → "Marine Species")

## 🔍 Troubleshooting

### Common Issues

**Q: Custom taxonomy fields don't appear in API responses**
A: Ensure the dataset has been saved after adding terms. The integration happens on entity save.

**Q: API returns 404 errors after enabling the module**
A: The module has been designed to avoid API conflicts. If issues persist, check for other module conflicts.

**Q: Terms don't appear in autocomplete**
A: Verify terms exist in the vocabulary using `drush dkan:list-taxonomy-vocabularies`

**Q: Field removal doesn't clean up metadata**
A: Save the dataset after removing the field to trigger the cleanup logic.

### Debugging Commands

```bash
# Check if custom fields are detected
drush dkan:test-taxonomy-fields

# View recent logs
drush watchdog:show --count=10 | grep dkan_taxonomy_integration

# Check JSON metadata for a dataset
drush sql-query "SELECT field_json_metadata_value FROM node__field_json_metadata WHERE entity_id = 1;"
```

## 📝 Best Practices

1. **Use Descriptive Vocabulary IDs**: Choose meaningful machine names (e.g., `geographic_regions`, `data_sources`)
2. **Plan Term Structure**: Design your taxonomy hierarchy before adding terms
3. **Test API Integration**: Always verify custom fields appear in API responses
4. **Clean Up Properly**: Use the removal commands when vocabularies are no longer needed
5. **Monitor Performance**: Large numbers of terms may impact autocomplete performance

## 🔄 Migration and Deployment

### For New Installations

1. Install and enable the module
2. Create vocabularies using Drush commands
3. Add terms to vocabularies
4. Associate terms with existing datasets

### For Existing Installations

1. Install the module
2. Create vocabularies for existing taxonomy structures
3. Migrate existing taxonomy data using Drush commands
4. Verify API integration

## 🧪 Testing

Tests run against the project's Docker dev stack: start it with `./run.sh`
from the project root (site at http://localhost:8080). The integration scripts
use `./run.sh drush` and `./run.sh exec`; set `BASE_URL` to test another URL.

### Unit Tests

```bash
# Run all unit tests (64 tests, 300+ assertions)
./run.sh exec env 'SYMFONY_DEPRECATIONS_HELPER=max[self]=0&max[direct]=0' vendor/bin/phpunit docroot/modules/custom/dkan_taxonomy_integration/tests/src/Unit/ --bootstrap docroot/core/tests/bootstrap.php --testdox

# Run single test file
./run.sh exec env 'SYMFONY_DEPRECATIONS_HELPER=max[self]=0&max[direct]=0' vendor/bin/phpunit docroot/modules/custom/dkan_taxonomy_integration/tests/src/Unit/DkanTaxonomyIntegrationModuleTest.php --bootstrap docroot/core/tests/bootstrap.php --testdox
```

`SYMFONY_DEPRECATIONS_HELPER` makes PHPUnit fail on deprecations in this module's
own code, but not on ones raised inside vendor libraries (e.g. drush's
dependencies under PHP 8.4), which Drupal's test setup would otherwise also fail on.

**Test Coverage:**
- ✅ **TaxonomyDataProcessorTest** (31 tests) - Core utility functions
- ✅ **DkanTaxonomyCommandsTest** (7 tests) - Drush commands
- ✅ **TaxonomyUnificationSubscriberTest** (3 tests) - Event handling
- ✅ **DkanTaxonomyIntegrationModuleTest** (1 test) - Uninstall cleanup
- ✅ **HookInstallTest** (4 tests) - Install hook and schema file creation
- ✅ **HookUninstallTest** (4 tests) - Uninstall hook and schema file removal
- ✅ **EndpointIsolationTest** (4 tests) - Endpoint isolation verification

### Integration Tests

⚠️ These change the dev database (enable/uninstall the module, add and remove
taxonomy data). Back it up first: `./run.sh db-export`.

```bash
# Run ALL tests (Unit + Integration) in one command
docroot/modules/custom/dkan_taxonomy_integration/tests/scripts/run_all_tests.sh

# OR run individual integration tests (from any directory):
T=docroot/modules/custom/dkan_taxonomy_integration/tests/scripts
$T/test_api__api-1-metastore-schemas.sh
$T/test_api__api-1-metastore-schemas-dataset-items__workflow.sh
$T/test_api__api-1-metastore-schemas-dataset-items__taxonomy-updates.sh
$T/test_api__api-1-metastore-schemas__multiple-endpoints.sh
$T/test_api__api-1-metastore-schemas__isolation.sh
$T/test_schema_file_error_handling.sh
$T/test_install_uninstall_file_operations.sh
$T/test_api__api-1-metastore-schemas__stress-test.sh          # ⚠️ uninstalls/reinstalls the module repeatedly
$T/test_api__api-1-metastore-schemas-taxonomy_term.sh
$T/test_api__api-1-metastore-schemas-taxonomy_term-items.sh
$T/test_api__api-1-metastore-schemas__cleanup.sh              # ⚠️ UNINSTALLS the module
```

**Integration Test Coverage:**
- ✅ **test_api__api-1-metastore-schemas.sh** - `/api/1/metastore/schemas` integration through the service provider
- ✅ **test_api__api-1-metastore-schemas-dataset-items__workflow.sh** - Complete workflow including term assignment
- ✅ **test_api__api-1-metastore-schemas-dataset-items__taxonomy-updates.sh** - `/api/1/metastore/schemas/dataset/items/{id}` - Real-time term updates (programmatic + UI)
- ✅ **test_api__api-1-metastore-schemas__multiple-endpoints.sh** - Taxonomy, keyword and dataset endpoints together
- ✅ **test_api__api-1-metastore-schemas__isolation.sh** - Endpoint isolation (no keyword/items pollution)
- ✅ **test_schema_file_error_handling.sh** - Error conditions and edge cases for schema files
- ✅ **test_install_uninstall_file_operations.sh** - Install/uninstall cycle with service provider verification
- ✅ **test_api__api-1-metastore-schemas__stress-test.sh** - Repeated install/uninstall cycles
- ✅ **test_api__api-1-metastore-schemas-taxonomy_term.sh** - End-to-end `taxonomy_term` schema behavior
- ✅ **test_api__api-1-metastore-schemas-taxonomy_term-items.sh** - `/api/1/metastore/schemas/taxonomy_term/items`
- ✅ **test_api__api-1-metastore-schemas__cleanup.sh** - Endpoints are removed after module uninstall
- ✅ **common_functions.sh** - Shared helpers: `drush`, `web_exec`, `BASE_URL`, output and dataset utilities

**Key Features:**
- **Live System Testing** - Tests against actual DKAN installation with real database operations
- **API Validation** - Verifies HTTP responses match expected structure and content
- **Complete Workflows** - Tests entire feature lifecycles from creation to cleanup
- **Real Entity Operations** - Creates, updates, and deletes actual Drupal entities
- **Error Handling** - Validates error conditions and edge cases
- **Performance Testing** - Verifies real-time updates and immediate processing
- **Cleanup Verification** - Ensures proper removal of test data
- **Endpoint Isolation** - Verifies module doesn't pollute other API endpoints (keyword, etc.)
- **Module Lifecycle** - Tests installation, functionality, and uninstall behavior

**API Endpoints Tested:**
- ✅ `/api/1/metastore/schemas/taxonomy_term` - Schema structure verification
- ✅ `/api/1/metastore/schemas/taxonomy_term/items` - Items structure validation
- ✅ `/api/1/metastore/schemas/keyword` - Non-pollution verification
- ✅ `/api/1/metastore/schemas/keyword/items` - Clean structure preservation
- ✅ `/api/1/metastore/schemas/dataset/items/{id}` - Dataset integration testing

**Schema Definition Management:**
- ✅ The `taxonomy_term` schema is available through the API while the module is installed and gone after uninstall
- 📁 **Schema Files**: The module provides `schema/taxonomy_term.json` and `schema/taxonomy_term.ui.json`. They stay in the module directory and are served by the module's service provider; nothing is copied into DKAN's schema collections directory
- 🗑️ **Clean Uninstall**: After uninstall the `taxonomy_term` schema endpoints return 404 and nothing is left behind in DKAN's directories

**Important:** Integration tests require a live DKAN system and do **NOT** run automatically with PHPUnit unit tests. They are separate testing mechanisms that validate real-world module behavior.

## 📄 License

This module follows the same license as DKAN.

## 🤝 Contributing

When contributing to this module:

1. Follow Drupal coding standards
2. Test API integration thoroughly
3. Ensure removal logic works correctly
4. Update documentation for new features
5. Test with multiple taxonomy vocabularies
6. Run the test scripts to verify functionality

---

**Note**: This module is designed to work seamlessly with DKAN's existing architecture without breaking the API. It follows the same patterns as native DKAN taxonomy fields (`theme` and `keyword`) for consistency and reliability.
