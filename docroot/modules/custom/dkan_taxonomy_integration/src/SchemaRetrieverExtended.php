<?php

namespace Drupal\dkan_taxonomy_integration;

use Contracts\RetrieverInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\metastore\SchemaRetriever;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Extended Schema Retriever that includes taxonomy_term schemas.
 *
 * This class extends the DKAN SchemaRetriever to include our custom
 * taxonomy_term schemas without patching the original contrib module file.
 */
class SchemaRetrieverExtended extends SchemaRetriever {

  /**
   * {@inheritdoc}
   *
   * Overrides the parent getAllIds method to include taxonomy_term schemas.
   */
  public function getAllIds() {
    $ids = parent::getAllIds();

    // Insert taxonomy_term schemas after keyword.ui and before data-dictionary
    $index = array_search('data-dictionary', $ids);
    if ($index !== FALSE) {
      // Insert our schemas before data-dictionary
      array_splice($ids, $index, 0, ['taxonomy_term', 'taxonomy_term.ui']);
    } else {
      // Fallback: add at the end if data-dictionary not found
      $ids[] = 'taxonomy_term';
      $ids[] = 'taxonomy_term.ui';
    }

    return $ids;
  }

  /**
   * {@inheritdoc}
   *
   * Override to handle taxonomy_term schemas from our module directory.
   * Falls back to parent for standard DKAN schemas.
   */
  public function retrieve(string $id): ?string {
    // Check if this is one of our taxonomy schemas
    if ($id === 'taxonomy_term' || $id === 'taxonomy_term.ui') {
      $module_path = \Drupal::service('extension.list.module')->getPath('dkan_taxonomy_integration');
      $filename = DRUPAL_ROOT . '/' . $module_path . "/schema/{$id}.json";

      if (is_readable($filename)) {
        return file_get_contents($filename);
      }
      throw new \Exception("Schema {$id} not found in module directory.");
    }

    // For all other schemas, use parent implementation
    return parent::retrieve($id);
  }

  /**
   * {@inheritdoc}
   *
   * Uses the parent's getSchemaDirectory method - no changes needed.
   */
  public function getSchemaDirectory() {
    return parent::getSchemaDirectory();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $appRoot = $container->getParameter('app.root');
    $moduleExtension = $container->get('extension.list.module');

    return new static($appRoot, $moduleExtension);
  }

}
