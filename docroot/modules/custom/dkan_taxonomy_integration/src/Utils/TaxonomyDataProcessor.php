<?php

namespace Drupal\dkan_taxonomy_integration\Utils;

/**
 * Pure utility functions for processing taxonomy data.
 *
 * These functions have no dependencies and can be easily unit tested.
 */
class TaxonomyDataProcessor {

  /**
   * Extract taxonomy field data from an entity's field values.
   *
   * @param array $field_values
   *   Array of field values from entity.
   * @param array $custom_fields_info
   *   Information about custom taxonomy fields.
   *
   * @return array
   *   Processed taxonomy data.
   */
  public static function extractTaxonomyFieldData(array $field_values, array $custom_fields_info): array {
    $taxonomy_data = [];

    foreach ($custom_fields_info as $field_name => $field_info) {
      if (!isset($field_values[$field_name]) || !is_array($field_values[$field_name])) {
        continue;
      }

      $api_field_name = static::convertFieldNameToApiName($field_name);
      $taxonomy_data[$api_field_name] = static::processFieldTerms($field_values[$field_name]);
    }

    return $taxonomy_data;
  }

  /**
   * Convert field name to API field name.
   *
   * @param string $field_name
   *   The field name (e.g., 'field_country').
   *
   * @return string
   *   The API field name (e.g., 'country').
   */
  public static function convertFieldNameToApiName(string $field_name): string {
    if (strpos($field_name, 'field_') === 0) {
      return substr($field_name, 6); // Remove 'field_' prefix
    }
    return $field_name;
  }

  /**
   * Convert API field name to field name.
   *
   * @param string $api_field_name
   *   The API field name (e.g., 'country').
   *
   * @return string
   *   The field name (e.g., 'field_country').
   */
  public static function convertApiNameToFieldName(string $api_field_name): string {
    if (strpos($api_field_name, 'field_') !== 0) {
      return 'field_' . $api_field_name;
    }
    return $api_field_name;
  }

  /**
   * Process field terms into standardized format.
   *
   * @param array $field_terms
   *   Array of field term references.
   *
   * @return array
   *   Standardized terms array.
   */
  public static function processFieldTerms(array $field_terms): array {
    $processed_terms = [];

    foreach ($field_terms as $term_reference) {
      if (!is_array($term_reference)) {
        continue;
      }

      // Handle different term reference formats
      if (isset($term_reference['target_id'], $term_reference['entity'])) {
        $term = $term_reference['entity'];
        $processed_terms[] = [
          'identifier' => $term->uuid() ?? $term_reference['target_id'],
          'data' => $term->getName() ?? '',
        ];
      } elseif (isset($term_reference['identifier'], $term_reference['data'])) {
        $processed_terms[] = $term_reference;
      }
    }

    return $processed_terms;
  }

  /**
   * Update JSON metadata with taxonomy data.
   *
   * @param string $json_metadata
   *   Original JSON metadata.
   * @param array $taxonomy_data
   *   Taxonomy data to add.
   * @param array $processed_fields
   *   Fields that were processed.
   *
   * @return string|null
   *   Updated JSON metadata or null on error.
   */
  public static function updateJsonMetadata(string $json_metadata, array $taxonomy_data, array $processed_fields): ?string {
    $metadata = json_decode($json_metadata, TRUE);
    if (!is_array($metadata)) {
      return null;
    }

    // Add taxonomy data
    foreach ($taxonomy_data as $api_field_name => $terms) {
      if (!empty($terms)) {
        $metadata[$api_field_name] = $terms;
      }
    }

    // Remove fields that are no longer configured
    $metadata = static::removeObsoleteFields($metadata, $processed_fields);

    $encoded = json_encode($metadata);
    return $encoded !== FALSE ? $encoded : null;
  }

  /**
   * Remove obsolete taxonomy fields from metadata.
   *
   * @param array $metadata
   *   Metadata array.
   * @param array $current_fields
   *   Currently configured field names.
   *
   * @return array
   *   Cleaned metadata.
   */
  public static function removeObsoleteFields(array $metadata, array $current_fields): array {
    // Find fields that start with common taxonomy patterns but aren't in current fields
    $fields_to_remove = [];

    foreach (array_keys($metadata) as $field_name) {
      if (static::isTaxonomyField($field_name) && !in_array($field_name, $current_fields, TRUE)) {
        $fields_to_remove[] = $field_name;
      }
    }

    foreach ($fields_to_remove as $field_name) {
      unset($metadata[$field_name]);
    }

    return $metadata;
  }

  /**
   * Check if a field name looks like a taxonomy field.
   *
   * @param string $field_name
   *   Field name to check.
   *
   * @return bool
   *   TRUE if it looks like a taxonomy field.
   */
  public static function isTaxonomyField(string $field_name): bool {
    // This is a heuristic - you might want to make this more specific
    // based on your taxonomy field naming conventions
    $taxonomy_patterns = [
      'country', 'singer', 'category', 'tag', 'topic', 'region',
      'subject', 'type', 'format', 'status', 'level'
    ];

    foreach ($taxonomy_patterns as $pattern) {
      if (strpos($field_name, $pattern) !== FALSE) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
      * Unify taxonomy terms from multiple fields into a single structure.
   *
   * @param array $raw_data
   *   Raw dataset data.
   * @param array $custom_fields_info
   *   Information about custom taxonomy fields.
   *
   * @return array
   *   Array with unified taxonomy_term and cleaned raw data.
   */
  public static function unifyTaxonomyTerms(array $raw_data, array $custom_fields_info): array {
    $unified_terms = [];
    $fields_to_remove = [];

    foreach ($custom_fields_info as $field_name => $field_info) {
      $api_field_name = static::convertFieldNameToApiName($field_name);
      $fields_to_remove[] = $api_field_name;

      if (!isset($raw_data[$api_field_name]) || !is_array($raw_data[$api_field_name])) {
        continue;
      }

            foreach ($raw_data[$api_field_name] as $term) {
        if (!is_array($term) || !isset($term['identifier'], $term['data']) ||
            empty($term['identifier']) || empty($term['data'])) {
          continue;
        }

        $unified_terms[] = [
          'identifier' => $term['identifier'],
          'data' => $term['data'],
          'taxonomy_vocabulary' => $field_info['vocabulary_id'] ?? $api_field_name,
        ];
      }
    }

    // Remove individual taxonomy fields
    foreach ($fields_to_remove as $field_name) {
      unset($raw_data[$field_name]);
    }

    // Add unified taxonomy_term field
    $raw_data['taxonomy_term'] = $unified_terms;

    return $raw_data;
  }

  /**
   * Create taxonomy term schema data.
   *
   * @param string $term_uuid
   *   Term UUID.
   * @param string $term_name
   *   Term name.
   * @param string $vocabulary_id
   *   Vocabulary ID.
   *
   * @return array
   *   Schema data array.
   */
  public static function createTaxonomyTermSchemaData(string $term_uuid, string $term_name, string $vocabulary_id): array {
    return [
      'identifier' => $term_uuid,
      'data' => $term_name,
      'taxonomy_vocabulary' => $vocabulary_id,
    ];
  }

  /**
   * Validate taxonomy term data.
   *
   * @param array $term_data
   *   Term data to validate.
   *
   * @return bool
   *   TRUE if valid.
   */
  public static function validateTaxonomyTermData(array $term_data): bool {
    return !empty($term_data['identifier']) &&
           !empty($term_data['data']) &&
           !empty($term_data['taxonomy_vocabulary']);
  }

  /**
   * Filter custom fields to only include taxonomy entity reference fields.
   *
   * @param array $field_configs
   *   Array of field configuration data.
   *
   * @return array
   *   Filtered fields info.
   */
  public static function filterTaxonomyFields(array $field_configs): array {
    $taxonomy_fields = [];

    foreach ($field_configs as $field_config) {
      if (!static::isValidTaxonomyFieldConfig($field_config)) {
        continue;
      }

      $field_name = $field_config['name'] ?? '';
      $vocabulary_id = static::extractVocabularyId($field_config);
      $field_label = $field_config['label'] ?? ucfirst($vocabulary_id);

      if (!empty($field_name) && !empty($vocabulary_id)) {
        $taxonomy_fields[$field_name] = [
          'vocabulary_id' => $vocabulary_id,
          'vocabulary_name' => $field_label,
          'field_name' => $field_name,
        ];
      }
    }

    return $taxonomy_fields;
  }

  /**
   * Check if field config represents a valid taxonomy field.
   *
   * @param array $field_config
   *   Field configuration.
   *
   * @return bool
   *   TRUE if valid taxonomy field.
   */
  public static function isValidTaxonomyFieldConfig(array $field_config): bool {
    return !empty($field_config['field_type']) &&
           $field_config['field_type'] === 'entity_reference' &&
           !empty($field_config['target_type']) &&
           $field_config['target_type'] === 'taxonomy_term' &&
           !empty($field_config['name']) &&
           strpos($field_config['name'], 'field_') === 0;
  }

    /**
   * Extract vocabulary ID from field configuration.
   *
   * @param array $field_config
   *   Field configuration.
   *
   * @return string
   *   Vocabulary ID or empty string.
   */
  public static function extractVocabularyId(array $field_config): string {
    if (empty($field_config['handler_settings']['target_bundles'])) {
      return '';
    }

    $target_bundles = $field_config['handler_settings']['target_bundles'];
    if (!is_array($target_bundles)) {
      return '';
    }

    // Get the first vocabulary ID
    return array_key_first($target_bundles) ?: '';
  }

  /**
   * Validate API field names against reserved fields and naming rules.
   *
   * @param string $field_name
   *   Field name to validate.
   *
   * @return bool
   *   TRUE if valid.
   */
  public static function validateApiFieldName(string $field_name): bool {
    if (empty($field_name)) {
      return FALSE;
    }

    // Check against reserved DKAN fields
    $reserved_fields = [
      '@type', 'title', 'identifier', 'description', 'accessLevel', 'modified',
      'license', 'temporal', 'publisher', 'contactPoint', 'keyword', 'theme',
      'distribution', 'references', 'isPartOf', 'accrualPeriodicity', 'describedBy',
      'describedByType', 'issued', 'spatial', '%modified'
    ];

    if (in_array($field_name, $reserved_fields, TRUE)) {
      return FALSE;
    }

    // Check for reserved prefixes
    $reserved_prefixes = ['%', '@', 'Ref:', '%Ref:', 'Ref'];
    foreach ($reserved_prefixes as $prefix) {
      if (strpos($field_name, $prefix) === 0) {
        return FALSE;
      }
    }

    // Only allow alphanumeric characters and underscores, must start with letter
    return preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $field_name) === 1;
  }

  /**
   * Generate cache tags for dataset metastore invalidation.
   *
   * @param string $dataset_uuid
   *   Dataset UUID.
   * @param int $dataset_id
   *   Dataset node ID.
   *
   * @return array
   *   Array of cache tags.
   */
  public static function generateDatasetCacheTags(string $dataset_uuid, int $dataset_id): array {
    $tags = [];

    if (!empty($dataset_uuid)) {
      $tags[] = 'metastore:' . $dataset_uuid;
    }

    if (!empty($dataset_id)) {
      $tags[] = 'node:' . $dataset_id;
    }

    $tags[] = 'rest_resource';

    return $tags;
  }

  /**
   * Determine which vocabularies are relevant for a given custom field list.
   *
   * @param array $custom_fields
   *   Custom taxonomy fields configuration.
   * @param string $vocabulary_id
   *   Vocabulary ID to check.
   *
   * @return bool
   *   TRUE if vocabulary is used in custom fields.
   */
  public static function isVocabularyRelevant(array $custom_fields, string $vocabulary_id): bool {
    if (empty($vocabulary_id) || empty($custom_fields)) {
      return FALSE;
    }

    foreach ($custom_fields as $field_info) {
      if (!empty($field_info['vocabulary_id']) && $field_info['vocabulary_id'] === $vocabulary_id) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Extract entity field values into a standardized format.
   *
   * @param array $entity_fields
   *   Entity field data in various formats.
   *
   * @return array
   *   Standardized field data.
   */
  public static function extractEntityFieldValues(array $entity_fields): array {
    $extracted_data = [];

    foreach ($entity_fields as $field_name => $field_data) {
      if (!is_array($field_data)) {
        continue;
      }

      $terms = [];
      foreach ($field_data as $item) {
        if (is_object($item) && method_exists($item, 'uuid') && method_exists($item, 'getName')) {
          // Direct entity reference
          $uuid = $item->uuid();
          $name = $item->getName();
          if (!empty($uuid) && !empty($name)) {
            $terms[] = [
              'identifier' => $uuid,
              'data' => $name,
            ];
          }
        } elseif (is_array($item) && isset($item['entity']) && is_object($item['entity'])) {
          // Field reference with entity
          $entity = $item['entity'];
          if (method_exists($entity, 'uuid') && method_exists($entity, 'getName')) {
            $uuid = $entity->uuid();
            $name = $entity->getName();
            if (!empty($uuid) && !empty($name)) {
              $terms[] = [
                'identifier' => $uuid,
                'data' => $name,
              ];
            }
          }
        }
      }

      if (!empty($terms)) {
        $extracted_data[$field_name] = $terms;
      }
    }

    return $extracted_data;
  }

  /**
   * Process metadata cleanup requirements for vocabulary deletion.
   *
   * @param string $vocabulary_id
   *   Vocabulary ID being deleted.
   * @param array $dataset_metadata_list
   *   List of dataset metadata arrays.
   *
   * @return array
   *   Cleanup results with modified metadata.
   */
  public static function processVocabularyMetadataCleanup(string $vocabulary_id, array $dataset_metadata_list): array {
    $cleanup_results = [
      'modified_datasets' => [],
      'cleanup_count' => 0,
    ];

    if (empty($vocabulary_id)) {
      return $cleanup_results;
    }

    $api_field_name = $vocabulary_id;

    foreach ($dataset_metadata_list as $dataset_id => $metadata) {
      if (!is_array($metadata) || !isset($metadata[$api_field_name])) {
        continue;
      }

      // Remove the vocabulary field from metadata
      unset($metadata[$api_field_name]);

      $cleanup_results['modified_datasets'][$dataset_id] = $metadata;
      $cleanup_results['cleanup_count']++;
    }

    return $cleanup_results;
  }

  /**
   * Determine schema operations needed based on term changes.
   *
   * @param array $existing_schema_items
   *   Existing schema items.
   * @param array $current_terms
   *   Current taxonomy terms.
   * @param string $vocabulary_id
   *   Vocabulary ID to filter by.
   *
   * @return array
   *   Operations needed (create, update, delete).
   */
  public static function determineSchemaOperations(array $existing_schema_items, array $current_terms, string $vocabulary_id): array {
    $operations = [
      'create' => [],
      'update' => [],
      'delete' => [],
    ];

    // Build current terms lookup
    $current_terms_lookup = [];
    foreach ($current_terms as $term) {
      if (isset($term['identifier'])) {
        $current_terms_lookup[$term['identifier']] = $term;
      }
    }

    // Build existing items lookup for this vocabulary
    $existing_items_lookup = [];
    foreach ($existing_schema_items as $item) {
      if (isset($item['identifier'], $item['taxonomy_vocabulary']) &&
          $item['taxonomy_vocabulary'] === $vocabulary_id) {
        $existing_items_lookup[$item['identifier']] = $item;
      }
    }

    // Find items to create or update (only for the specified vocabulary)
    foreach ($current_terms_lookup as $identifier => $term) {
      // Only process terms for the specified vocabulary
      $term_vocabulary = $term['taxonomy_vocabulary'] ?? $vocabulary_id;
      if ($term_vocabulary !== $vocabulary_id) {
        continue;
      }

      if (isset($existing_items_lookup[$identifier])) {
        // Check if update needed
        $existing = $existing_items_lookup[$identifier];
        if ($existing['data'] !== $term['data'] ||
            $existing['taxonomy_vocabulary'] !== $vocabulary_id) {
          $operations['update'][] = $term;
        }
      } else {
        // New term, needs creation
        $operations['create'][] = $term;
      }
    }

    // Find items to delete (exist in schema but not in current terms)
    foreach ($existing_items_lookup as $identifier => $item) {
      if (!isset($current_terms_lookup[$identifier])) {
        $operations['delete'][] = $item;
      }
    }

    return $operations;
  }

  /**
   * Filter and validate queue names for processing.
   *
   * @param array $queue_candidates
   *   Queue names to validate.
   *
   * @return array
   *   Valid queue names.
   */
  public static function filterValidQueueNames(array $queue_candidates): array {
    $valid_queues = [];
    $allowed_patterns = [
      'dkan_metastore_import',
      'dkan_metastore_storage',
      'dkan_metastore_*',
    ];

    foreach ($queue_candidates as $queue_name) {
      if (!is_string($queue_name)) {
        continue;
      }

      foreach ($allowed_patterns as $pattern) {
        if ($pattern === $queue_name ||
            (strpos($pattern, '*') !== FALSE &&
             strpos($queue_name, str_replace('*', '', $pattern)) === 0)) {
          $valid_queues[] = $queue_name;
          break;
        }
      }
    }

    return array_unique($valid_queues);
  }

}
