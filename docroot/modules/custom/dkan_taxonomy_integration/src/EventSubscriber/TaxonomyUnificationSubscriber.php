<?php

namespace Drupal\dkan_taxonomy_integration\EventSubscriber;

use Drupal\common\Events\Event;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\dkan_taxonomy_integration\Utils\TaxonomyDataProcessor;
use Drupal\metastore\MetastoreService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event subscriber for unifying taxonomy terms in metastore API responses.
 */
class TaxonomyUnificationSubscriber implements EventSubscriberInterface {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The metastore service.
   */
  protected MetastoreService $metastoreService;

  /**
   * Constructor.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, MetastoreService $metastore_service) {
    $this->entityTypeManager = $entity_type_manager;
    $this->metastoreService = $metastore_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      'dkan_metastore_data_get' => 'unifyTaxonomyTerms',
    ];
  }

  /**
   * Unifies taxonomy terms in the API response.
   *
   * @param \Drupal\common\Events\Event $event
   *   The event object containing the dataset data.
   */
  public function unifyTaxonomyTerms(Event $event) {
    try {
      $data = $event->getData();

      // Ensure we have a RootedJsonData object.
      if (!is_object($data) || !method_exists($data, 'get')) {
        return;
      }

      // Get the raw data array.
      $rawData = $data->get('$');
      if (!is_array($rawData)) {
        return;
      }

      // CRITICAL FIX: Only process dataset items, not keyword/theme/other items
      // Check if this is a dataset by looking for @type field
      if (!isset($rawData['@type']) || $rawData['@type'] !== 'dcat:Dataset') {
        return;
      }

            // Get all custom taxonomy fields.
      $customFields = $this->getCustomTaxonomyFields();

      // Use pure function to unify taxonomy terms
      $rawData = TaxonomyDataProcessor::unifyTaxonomyTerms($rawData, $customFields);

      // Create or update taxonomy_term schema items for all terms
      $this->createTaxonomyTermSchemaItems($rawData['taxonomy_term'] ?? []);

      // Update the data in the event.
      $data->set('$', $rawData);
      $event->setData($data);

    } catch (\Exception $e) {
      _dkan_taxonomy_integration_logger()->error('Error unifying taxonomy terms: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Get custom taxonomy fields from the data node type.
   */
  private function getCustomTaxonomyFields() {
    $fields = [];

    try {
      // Get all field configurations for the data node type.
      $fieldConfigs = $this->entityTypeManager
        ->getStorage('field_config')
        ->loadByProperties([
          'entity_type' => 'node',
          'bundle' => 'data',
        ]);

      // Convert field configs to array format for processing
      $fieldConfigsArray = [];
      foreach ($fieldConfigs as $fieldConfig) {
        $fieldStorage = $fieldConfig->getFieldStorageDefinition();

        $fieldConfigsArray[] = [
          'name' => $fieldConfig->getName(),
          'field_type' => $fieldStorage->getType(),
          'target_type' => $fieldStorage->getSetting('target_type'),
          'handler_settings' => $fieldConfig->getSetting('handler_settings') ?: [],
          'label' => $fieldConfig->getLabel(),
        ];
      }

      // Use pure function to filter taxonomy fields
      $fields = TaxonomyDataProcessor::filterTaxonomyFields($fieldConfigsArray);
    } catch (\Exception $e) {
      _dkan_taxonomy_integration_logger()->error('Error getting custom taxonomy fields: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return $fields;
  }



  /**
   * Create or update taxonomy_term schema items for multiple terms.
   */
  private function createTaxonomyTermSchemaItems(array $unifiedTerms) {
    foreach ($unifiedTerms as $termData) {
      if (!TaxonomyDataProcessor::validateTaxonomyTermData($termData)) {
        continue;
      }

      $vocabularyKey = $termData['taxonomy_vocabulary'];

      $this->createTaxonomyTermSchemaItem(
        $termData['identifier'],
        $termData['data'],
        $vocabularyKey
      );
    }
  }

  /**
   * Create or update a taxonomy_term schema item in the metastore.
   *
   * @param string $identifier
   *   The taxonomy term UUID.
   * @param string $termName
   *   The taxonomy term name.
   * @param string $vocabularyId
   *   The vocabulary machine name.
   */
  private function createTaxonomyTermSchemaItem($identifier, $termName, $vocabularyId) {
    try {
      // Use pure function to create schema data
      $taxonomyTermData = TaxonomyDataProcessor::createTaxonomyTermSchemaData($identifier, $termName, $vocabularyId);

      // Check if this taxonomy term already exists in the metastore.
      try {
        $existingTerm = $this->metastoreService->get('taxonomy_term', $identifier);

        // If it exists, update it if the data has changed.
        $existingData = $existingTerm->get('$');
        if ($existingData['data'] !== $termName || $existingData['taxonomy_vocabulary'] !== $vocabularyId) {
          $validData = $this->metastoreService->getValidMetadataFactory()->get(json_encode($taxonomyTermData), 'taxonomy_term');
          $this->metastoreService->put('taxonomy_term', $identifier, $validData);
        }
      } catch (\Exception $e) {
        // Term doesn't exist, create it.
        $validData = $this->metastoreService->getValidMetadataFactory()->get(json_encode($taxonomyTermData), 'taxonomy_term');
        $this->metastoreService->post('taxonomy_term', $validData);
      }

    } catch (\Exception $e) {
      _dkan_taxonomy_integration_logger()->error('Error creating taxonomy_term schema item: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

}
