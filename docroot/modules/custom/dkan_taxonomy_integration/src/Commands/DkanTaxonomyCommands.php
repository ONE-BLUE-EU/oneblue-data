<?php

namespace Drupal\dkan_taxonomy_integration\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\Entity\Term;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for DKAN taxonomy integration.
 */
class DkanTaxonomyCommands extends DrushCommands {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Constructs a new DkanTaxonomyCommands object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, MessengerInterface $messenger) {
    parent::__construct();
    $this->entityTypeManager = $entity_type_manager;
    $this->messenger = $messenger;
  }

  /**
   * Add taxonomy vocabulary to dataset node type.
   *
   * @command dkan:add-taxonomy-to-dataset
   * @aliases dkan-add-tax
   * @param string $vocabulary_id
   *   The machine name for the vocabulary (e.g., dataset_topics, geographic_regions).
   * @option description
   *   Description for the vocabulary.
   * @option required
   *   Whether the field is required.
   * @usage dkan:add-taxonomy-to-dataset dataset_topics
   *   Creates a vocabulary called "Dataset Topics" and adds it as a field to datasets.
   * @usage dkan:add-taxonomy-to-dataset geographic_regions --description="Geographic coverage of datasets"
   *   Creates a vocabulary with a custom description.
   */
  public function addTaxonomyToDataset($vocabulary_id, $options = ['description' => '', 'required' => FALSE]) {
    try {
      // Validate inputs
      if (empty($vocabulary_id) || !is_string($vocabulary_id)) {
        $this->output()->writeln(dt('Error: Vocabulary ID is required and must be a string.'));
        return;
      }

      if (!is_array($options)) {
        $options = ['description' => '', 'required' => FALSE];
      }

      // Validate vocabulary ID format
      if (!preg_match('/^[a-z][a-z0-9_]*$/', $vocabulary_id)) {
        $this->output()->writeln(dt('Error: Vocabulary ID must contain only lowercase letters, numbers, and underscores, and must start with a letter.'));
        return;
      }

      // Check if vocabulary already exists
      $existing_vocabulary = Vocabulary::load($vocabulary_id);
      $vocabulary_exists = FALSE;

      if (!empty($existing_vocabulary) && is_object($existing_vocabulary)) {
        $vocabulary_exists = TRUE;
        $vocabulary_name = $existing_vocabulary->label();
        if (empty($vocabulary_name)) {
          $vocabulary_name = $vocabulary_id;
        }
        $this->output()->writeln(dt('Vocabulary @id already exists, checking if field is added to dataset node type...', ['@id' => $vocabulary_id]));
      }

      // Generate vocabulary name from ID (convert underscores to spaces and capitalize)
      if (!$vocabulary_exists) {
        $vocabulary_name = ucwords(str_replace('_', ' ', $vocabulary_id));
      }

      // Generate field name from vocabulary ID
      $field_name = 'field_' . $vocabulary_id;

      // Generate field label (same as vocabulary name)
      $field_label = $vocabulary_name;

      // Check if field already exists on dataset node type
      $existing_field = \Drupal\field\Entity\FieldConfig::loadByName('node', 'data', $field_name);
      if (!empty($existing_field) && is_object($existing_field)) {
        $this->output()->writeln(dt('Field @field already exists on dataset node type.', ['@field' => $field_name]));
        return;
      }

      // Create vocabulary if it doesn't exist
      if (!$vocabulary_exists) {
        $vocabulary = Vocabulary::create([
          'name' => $vocabulary_name,
          'description' => isset($options['description']) ? $options['description'] : '',
          'vid' => $vocabulary_id,
        ]);

        if (empty($vocabulary) || !is_object($vocabulary)) {
          $this->output()->writeln(dt('Error: Failed to create vocabulary.'));
          return;
        }

        $vocabulary->save();
        $this->output()->writeln(dt('Created vocabulary "@name".', ['@name' => $vocabulary_name]));
      }

      // Create field storage
      $field_storage = \Drupal\field\Entity\FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'type' => 'entity_reference',
        'settings' => [
          'target_type' => 'taxonomy_term',
        ],
        'cardinality' => -1,
      ]);

      if (empty($field_storage) || !is_object($field_storage)) {
        $this->output()->writeln(dt('Error: Failed to create field storage.'));
        return;
      }

      $field_storage->save();

      // Create field instance
      $field = \Drupal\field\Entity\FieldConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'bundle' => 'data',
        'label' => $field_label,
        'required' => isset($options['required']) ? (bool) $options['required'] : FALSE,
        'settings' => [
          'handler' => 'default:taxonomy_term',
          'handler_settings' => [
            'target_bundles' => [
              $vocabulary_id => $vocabulary_id,
            ],
            'sort' => [
              'field' => '_none',
            ],
            'auto_create' => FALSE,
            'auto_create_bundle' => '',
          ],
        ],
      ]);

      if (empty($field) || !is_object($field)) {
        $this->output()->writeln(dt('Error: Failed to create field instance.'));
        return;
      }

      $field->save();

      // Update form display
      try {
        $form_display = \Drupal\Core\Entity\Entity\EntityFormDisplay::collectRenderDisplay(\Drupal\node\Entity\Node::create(['type' => 'data']), 'default');
        if (!empty($form_display) && is_object($form_display)) {
          $form_display->setComponent($field_name, [
            'type' => 'entity_reference_autocomplete_tags',
            'weight' => 10,
            'settings' => [
              'match_operator' => 'CONTAINS',
              'match_limit' => 10,
              'size' => 60,
              'placeholder' => 'Start typing to search...',
            ],
          ]);
          $form_display->save();
        }
      } catch (\Exception $e) {
        $this->output()->writeln(dt('Warning: Failed to update form display: @message', ['@message' => $e->getMessage()]));
      }

      // Update view display
      try {
        $view_display = \Drupal\Core\Entity\Entity\EntityViewDisplay::collectRenderDisplay(\Drupal\node\Entity\Node::create(['type' => 'data']), 'default');
        if (!empty($view_display) && is_object($view_display)) {
          $view_display->setComponent($field_name, [
            'type' => 'entity_reference_label',
            'weight' => 10,
            'label' => 'above',
            'settings' => [
              'link' => TRUE,
            ],
          ]);
          $view_display->save();
        }
      } catch (\Exception $e) {
        $this->output()->writeln(dt('Warning: Failed to update view display: @message', ['@message' => $e->getMessage()]));
      }

      // Clear caches
      try {
        \Drupal::service('entity_type.manager')->clearCachedDefinitions();
        \Drupal::service('plugin.manager.field.field_type')->clearCachedDefinitions();
      } catch (\Exception $e) {
        $this->output()->writeln(dt('Warning: Failed to clear caches: @message', ['@message' => $e->getMessage()]));
      }

      $this->output()->writeln(dt('Successfully created vocabulary "@name" and added field "@field" to dataset node type.', [
        '@name' => $vocabulary_name,
        '@field' => $field_name,
      ]));
      $this->output()->writeln(dt('Vocabulary ID: @id', ['@id' => $vocabulary_id]));
      $this->output()->writeln(dt('Field name: @field', ['@field' => $field_name]));

    } catch (\Exception $e) {
      $this->output()->writeln(dt('Error: @message', ['@message' => $e->getMessage()]));
    }
  }

  /**
   * List all taxonomy vocabularies.
   *
   * @command dkan:list-taxonomy-vocabularies
   * @aliases dkan-list-vocab
   * @usage dkan:list-taxonomy-vocabularies
   *   Lists all available taxonomy vocabularies with their details.
   */
  public function listTaxonomyVocabularies() {
    try {
      $vocabularies = Vocabulary::loadMultiple();

      if (empty($vocabularies) || !is_array($vocabularies)) {
        $this->output()->writeln(dt('No taxonomy vocabularies found.'));
        return;
      }

      $this->output()->writeln(dt('Available taxonomy vocabularies:'));
      $this->output()->writeln('');

      foreach ($vocabularies as $vocabulary) {
        if (empty($vocabulary) || !is_object($vocabulary)) {
          continue;
        }

        $vid = $vocabulary->id();
        $name = $vocabulary->label();
        $description = $vocabulary->getDescription();

        if (empty($vid)) {
          continue;
        }

        if (empty($name)) {
          $name = $vid;
        }

        if (empty($description)) {
          $description = '-';
        }

        // Count terms in this vocabulary
        try {
          $term_count = $this->entityTypeManager
            ->getStorage('taxonomy_term')
            ->getQuery()
            ->condition('vid', $vid)
            ->accessCheck(FALSE)
            ->count()
            ->execute();
        } catch (\Exception $e) {
          $term_count = 0;
        }

        $this->output()->writeln(dt('ID: @id', ['@id' => $vid]));
        $this->output()->writeln(dt('Name: @name', ['@name' => $name]));
        $this->output()->writeln(dt('Description: @desc', ['@desc' => $description]));
        $this->output()->writeln(dt('Terms Count: @count', ['@count' => $term_count]));
        $this->output()->writeln('');
      }

    } catch (\Exception $e) {
      $this->output()->writeln(dt('Error: @message', ['@message' => $e->getMessage()]));
    }
  }

  /**
   * Add terms to a taxonomy vocabulary.
   *
   * @command dkan:add-taxonomy-terms
   * @aliases dkan-add-terms
   * @param string $vocabulary_id
   *   The machine name of the vocabulary.
   * @param string $terms
   *   Comma-separated list of terms to add.
   * @usage dkan:add-taxonomy-terms dataset_topics "Agriculture,Health,Education"
   *   Adds three terms to the dataset_topics vocabulary.
   */
  public function addTaxonomyTerms($vocabulary_id, $terms) {
    try {
      // Validate inputs
      if (empty($vocabulary_id) || !is_string($vocabulary_id)) {
        $this->output()->writeln(dt('Error: Vocabulary ID is required and must be a string.'));
        return;
      }

      if (empty($terms) || !is_string($terms)) {
        $this->output()->writeln(dt('Error: Terms are required and must be a string.'));
        return;
      }

      // Load the vocabulary
      $vocabulary = Vocabulary::load($vocabulary_id);
      if (empty($vocabulary) || !is_object($vocabulary)) {
        $this->output()->writeln(dt('Error: Vocabulary "@id" not found.', ['@id' => $vocabulary_id]));
        return;
      }

      // Split terms by comma and trim whitespace
      $term_names = array_map('trim', explode(',', $terms));
      $created_count = 0;

      foreach ($term_names as $term_name) {
        if (empty($term_name) || !is_string($term_name)) {
          continue;
        }

        // Check if term already exists
        try {
          $existing_terms = $this->entityTypeManager
            ->getStorage('taxonomy_term')
            ->loadByProperties([
              'name' => $term_name,
              'vid' => $vocabulary_id,
            ]);

          if (!empty($existing_terms) && is_array($existing_terms)) {
            $vocabulary_label = $vocabulary->label();
            if (empty($vocabulary_label)) {
              $vocabulary_label = $vocabulary_id;
            }

            $this->output()->writeln(dt('Term "@name" already exists in vocabulary "@vocab".', [
              '@name' => $term_name,
              '@vocab' => $vocabulary_label,
            ]));
            continue;
          }
        } catch (\Exception $e) {
          $this->output()->writeln(dt('Warning: Error checking existing terms: @message', ['@message' => $e->getMessage()]));
          continue;
        }

        // Create the term
        try {
          $term = Term::create([
            'name' => $term_name,
            'vid' => $vocabulary_id,
          ]);

          if (empty($term) || !is_object($term)) {
            $this->output()->writeln(dt('Error: Failed to create term "@name".', ['@name' => $term_name]));
            continue;
          }

          $term->save();

          $this->output()->writeln(dt('Created term "@name" in vocabulary @vocab.', [
            '@name' => $term_name,
            '@vocab' => $vocabulary_id,
          ]));
          $created_count++;
        } catch (\Exception $e) {
          $this->output()->writeln(dt('Error: Failed to create term "@name": @message', [
            '@name' => $term_name,
            '@message' => $e->getMessage()
          ]));
        }
      }

      if ($created_count > 0) {
        $this->output()->writeln(dt('Successfully created @count new terms.', ['@count' => $created_count]));
      } else {
        $this->output()->writeln(dt('No new terms were created.'));
      }

    } catch (\Exception $e) {
      $this->output()->writeln(dt('Error: @message', ['@message' => $e->getMessage()]));
    }
  }

  /**
   * Remove taxonomy field from dataset node type.
   *
   * @command dkan:remove-taxonomy-from-dataset
   * @aliases dkan-remove-tax
   * @param string $vocabulary_id
   *   The machine name of the vocabulary to remove.
   * @option delete-vocabulary
   *   Also delete the vocabulary and all its terms.
   * @usage dkan:remove-taxonomy-from-dataset dataset_topics
   *   Removes the field from datasets but keeps the vocabulary.
   * @usage dkan:remove-taxonomy-from-dataset dataset_topics --delete-vocabulary
   *   Removes the field and deletes the vocabulary completely.
   */
  public function removeTaxonomyFromDataset($vocabulary_id, $options = ['delete-vocabulary' => FALSE]) {
    try {
      // Validate inputs
      if (empty($vocabulary_id) || !is_string($vocabulary_id)) {
        $this->output()->writeln(dt('Error: Vocabulary ID is required and must be a string.'));
        return;
      }

      if (!is_array($options)) {
        $options = ['delete-vocabulary' => FALSE];
      }

      // Generate field name from vocabulary ID
      $field_name = 'field_' . $vocabulary_id;

      // Check if field exists on dataset node type
      $existing_field = \Drupal\field\Entity\FieldConfig::loadByName('node', 'data', $field_name);
      $field_removed = FALSE;

      if (!empty($existing_field) && is_object($existing_field)) {
        // Remove field instance
        try {
          $existing_field->delete();
          $this->output()->writeln(dt('Removed field @field from dataset node type.', ['@field' => $field_name]));
          $field_removed = TRUE;
        } catch (\Exception $e) {
          $this->output()->writeln(dt('Error: Failed to remove field @field: @message', [
            '@field' => $field_name,
            '@message' => $e->getMessage()
          ]));
        }

        // Check if field storage is used by other bundles
        try {
          $field_storage = \Drupal\field\Entity\FieldStorageConfig::loadByName('node', $field_name);
          if (!empty($field_storage) && is_object($field_storage)) {
            $field_instances = \Drupal\field\Entity\FieldConfig::loadMultipleByProperties([
              'field_name' => $field_name,
              'entity_type' => 'node',
            ]);

            if (empty($field_instances) || !is_array($field_instances)) {
              // No other instances exist, remove field storage
              try {
                $field_storage->delete();
                $this->output()->writeln(dt('Removed field storage @field.', ['@field' => $field_name]));
              } catch (\Exception $e) {
                $this->output()->writeln(dt('Warning: Failed to remove field storage @field: @message', [
                  '@field' => $field_name,
                  '@message' => $e->getMessage()
                ]));
              }
            }
          }
        } catch (\Exception $e) {
          $this->output()->writeln(dt('Warning: Error checking field storage: @message', ['@message' => $e->getMessage()]));
        }

        // Clear caches
        try {
          \Drupal::service('entity_type.manager')->clearCachedDefinitions();
          \Drupal::service('plugin.manager.field.field_type')->clearCachedDefinitions();
        } catch (\Exception $e) {
          $this->output()->writeln(dt('Warning: Failed to clear caches: @message', ['@message' => $e->getMessage()]));
        }

        $this->output()->writeln(dt('Successfully removed taxonomy field from dataset node type.'));
      } else {
        $this->output()->writeln(dt('Field @field does not exist on dataset node type.', ['@field' => $field_name]));
      }

      // Clean up metadata from all datasets
      $this->cleanupMetadataFromDatasets($vocabulary_id);

      // Optionally delete the vocabulary (regardless of whether field existed)
      if (isset($options['delete-vocabulary']) && $options['delete-vocabulary']) {
        try {
          $vocabulary = Vocabulary::load($vocabulary_id);
          if (!empty($vocabulary) && is_object($vocabulary)) {
            $vocabulary_label = $vocabulary->label();
            if (empty($vocabulary_label)) {
              $vocabulary_label = $vocabulary_id;
            }

            $vocabulary->delete();
            $this->output()->writeln(dt('Deleted vocabulary "@name" and all its terms.', ['@name' => $vocabulary_label]));
          } else {
            $this->output()->writeln(dt('Vocabulary @id not found.', ['@id' => $vocabulary_id]));
          }
        } catch (\Exception $e) {
          $this->output()->writeln(dt('Error: Failed to delete vocabulary @id: @message', [
            '@id' => $vocabulary_id,
            '@message' => $e->getMessage()
          ]));
        }
      }

    } catch (\Exception $e) {
      $this->output()->writeln(dt('Error: @message', ['@message' => $e->getMessage()]));
    }
  }

  /**
   * Clean up metadata from all datasets when a taxonomy field is removed.
   */
  protected function cleanupMetadataFromDatasets($vocabulary_id) {
    try {
      if (empty($vocabulary_id) || !is_string($vocabulary_id)) {
        return;
      }

      $api_field_name = $vocabulary_id; // Remove 'field_' prefix

      // Check if the metastore service is available
      if (!\Drupal::hasService('dkan.metastore.metastore_item_factory')) {
        $this->output()->writeln(dt('Warning: DKAN metastore service not available for metadata cleanup.'));
        return;
      }

      $metadata_field = \Drupal::service('dkan.metastore.metastore_item_factory')::getMetadataField();
      if (empty($metadata_field) || !is_string($metadata_field)) {
        $this->output()->writeln(dt('Warning: Metadata field name is empty or invalid.'));
        return;
      }

      // Get all dataset nodes
      try {
        $nodes = $this->entityTypeManager->getStorage('node')->loadByProperties(['type' => 'data']);
      } catch (\Exception $e) {
        $this->output()->writeln(dt('Warning: Failed to load dataset nodes: @message', ['@message' => $e->getMessage()]));
        return;
      }

      if (empty($nodes) || !is_array($nodes)) {
        return;
      }

      $cleaned_count = 0;

      foreach ($nodes as $node) {
        if (empty($node) || !is_object($node)) {
          continue;
        }

        try {
          if ($node->hasField($metadata_field)) {
            $json_value = $node->get($metadata_field)->value;
            if (!empty($json_value) && is_string($json_value)) {
              $metadata = json_decode($json_value, TRUE);
              if (!empty($metadata) && is_array($metadata) && isset($metadata[$api_field_name])) {
                // Remove the field from metadata
                unset($metadata[$api_field_name]);
                $encoded_metadata = json_encode($metadata);
                if ($encoded_metadata !== FALSE) {
                  $node->set($metadata_field, $encoded_metadata);
                  $node->save();
                  $cleaned_count++;
                }
              }
            }
          }
        } catch (\Exception $e) {
          $this->output()->writeln(dt('Warning: Error cleaning metadata for node @id: @message', [
            '@id' => $node->id(),
            '@message' => $e->getMessage()
          ]));
        }
      }

      if ($cleaned_count > 0) {
        $this->output()->writeln(dt('Cleaned up metadata from @count datasets.', ['@count' => $cleaned_count]));
      }

    } catch (\Exception $e) {
      $this->output()->writeln(dt('Warning: Error cleaning up metadata: @message', ['@message' => $e->getMessage()]));
    }
  }

  /**
   * Test taxonomy fields on dataset node type.
   *
   * @command dkan:test-taxonomy-fields
   * @aliases dkan-test-fields
   * @usage dkan:test-taxonomy-fields
   *   Tests and displays information about custom taxonomy fields on datasets.
   */
  public function testTaxonomyFields() {
    try {
      $this->output()->writeln(dt('Testing custom taxonomy fields on dataset node type...'));
      $this->output()->writeln('');

      // Get all field configurations for the data node type
      try {
        $field_configs = $this->entityTypeManager
          ->getStorage('field_config')
          ->loadByProperties([
            'entity_type' => 'node',
            'bundle' => 'data',
          ]);
      } catch (\Exception $e) {
        $this->output()->writeln(dt('Error: Failed to load field configurations: @message', ['@message' => $e->getMessage()]));
        return;
      }

      if (empty($field_configs) || !is_array($field_configs)) {
        $this->output()->writeln(dt('No field configurations found for dataset node type.'));
        return;
      }

      $custom_taxonomy_fields = [];

      foreach ($field_configs as $field_config) {
        if (empty($field_config) || !is_object($field_config)) {
          continue;
        }

        $field_name = $field_config->getName();
        if (empty($field_name) || !is_string($field_name)) {
          continue;
        }

        // Only include custom taxonomy fields (field_* that reference taxonomy_term)
        if (strpos($field_name, 'field_') === 0) {
          try {
            $field_storage = $field_config->getFieldStorageDefinition();

            if (!empty($field_storage) && is_object($field_storage)) {
              $field_type = $field_storage->getType();
              if (!empty($field_type) && $field_type === 'entity_reference') {
                $settings = $field_storage->getSettings();

                if (!empty($settings) && is_array($settings) &&
                    isset($settings['target_type']) &&
                    $settings['target_type'] === 'taxonomy_term') {

                  $vocabulary_id = $this->getVocabularyIdFromField($field_config);
                  $field_label = $field_config->getLabel();

                  $custom_taxonomy_fields[] = [
                    'name' => $field_name,
                    'label' => !empty($field_label) ? $field_label : $field_name,
                    'vocabulary_id' => !empty($vocabulary_id) ? $vocabulary_id : '',
                  ];
                }
              }
            }
          } catch (\Exception $e) {
            $this->output()->writeln(dt('Warning: Error processing field @field: @message', [
              '@field' => $field_name,
              '@message' => $e->getMessage()
            ]));
          }
        }
      }

      if (empty($custom_taxonomy_fields)) {
        $this->output()->writeln(dt('No custom taxonomy fields found on dataset node type.'));
        return;
      }

      $this->output()->writeln(dt('Found @count custom taxonomy fields:', ['@count' => count($custom_taxonomy_fields)]));
      $this->output()->writeln('');

      foreach ($custom_taxonomy_fields as $field) {
        if (!empty($field) && is_array($field)) {
          $this->output()->writeln(dt('Field Name: @name', ['@name' => $field['name']]));
          $this->output()->writeln(dt('Field Label: @label', ['@label' => $field['label']]));
          $this->output()->writeln(dt('Vocabulary ID: @vid', ['@vid' => $field['vocabulary_id']]));
          $this->output()->writeln('');
        }
      }

    } catch (\Exception $e) {
      $this->output()->writeln(dt('Error: @message', ['@message' => $e->getMessage()]));
    }
  }

  /**
   * Gets the vocabulary ID from a field configuration.
   *
   * @param \Drupal\field\Entity\FieldConfig $field_config
   *   The field configuration.
   *
   * @return string|null
   *   The vocabulary ID or null if not found.
   */
  protected function getVocabularyIdFromField($field_config) {
    if (empty($field_config) || !is_object($field_config)) {
      return NULL;
    }

    try {
      $settings = $field_config->getSettings();

      if (!empty($settings) && is_array($settings) &&
          isset($settings['handler_settings']['target_bundles']) &&
          is_array($settings['handler_settings']['target_bundles'])) {
        $target_bundles = $settings['handler_settings']['target_bundles'];
        $vocabulary_id = reset($target_bundles);
        return !empty($vocabulary_id) ? $vocabulary_id : NULL;
      }
    } catch (\Exception $e) {
      // Log error but don't break the process
      \Drupal::logger('dkan_taxonomy_integration')->error('Error getting vocabulary ID from field: @message', ['@message' => $e->getMessage()]);
    }

    return NULL;
  }

  /**
   * Create taxonomy term schema items for existing terms.
   *
   * @command dkan:create-taxonomy-schema-items
   * @aliases dkan-create-schema
   * @usage dkan:create-taxonomy-schema-items
   *   Creates taxonomy term schema items for all existing terms in vocabularies used by custom taxonomy fields.
   */
  public function createTaxonomySchemaItems() {
    try {
      $this->output()->writeln(dt('Creating taxonomy term schema items for existing terms...'));

      // Include the install file to access the function
      require_once __DIR__ . '/../../dkan_taxonomy_integration.install';

      // Call the function to create schema items
      _dkan_taxonomy_integration_create_schema_items_for_existing_terms();

      $this->output()->writeln(dt('✅ Successfully created taxonomy term schema items for existing terms.'));
      $this->output()->writeln(dt('The /api/1/metastore/schemas/taxonomy_term/items endpoint should now return all available terms.'));

    } catch (\Exception $e) {
      $this->output()->writeln(dt('Error: @message', ['@message' => $e->getMessage()]));
    }
  }

}
