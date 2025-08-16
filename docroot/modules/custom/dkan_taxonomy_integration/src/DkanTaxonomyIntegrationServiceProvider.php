<?php

namespace Drupal\dkan_taxonomy_integration;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/**
 * Service provider for DKAN Taxonomy Integration.
 *
 * This service provider overrides DKAN's schema retriever service to include
 * taxonomy_term schemas. It uses the Service Provider pattern instead of YAML
 * service overrides to avoid container locking issues with DKAN.
 */
class DkanTaxonomyIntegrationServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    // Only override if the DKAN metastore schema retriever service exists
    if ($container->hasDefinition('dkan.metastore.schema_retriever')) {
      $definition = $container->getDefinition('dkan.metastore.schema_retriever');

      // Replace the class with our extended version
      $definition->setClass('Drupal\dkan_taxonomy_integration\SchemaRetrieverExtended');

      // Keep the same arguments as the original service
      // The service definition should already have the correct arguments:
      // - '%app.root%'
      // - '@extension.list.module'

      // Ensure the service is not shared (same as original)
      $definition->setShared(FALSE);
    }
  }

}
