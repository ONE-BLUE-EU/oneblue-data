<?php

namespace Drupal\Tests\dkan_taxonomy_integration\Unit;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\metastore\MetastoreService;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use MockChain\Chain;
use MockChain\Options;
use PHPUnit\Framework\TestCase;
use RootedData\RootedJsonData;
use Symfony\Component\DependencyInjection\Container;

/**
 * Unit tests for dkan_taxonomy_integration module functions.
 *
 * @covers ::_dkan_taxonomy_integration_uninstall_is_taxonomy_field
 * @group dkan_taxonomy_integration
 * @group unit
 */
class DkanTaxonomyIntegrationModuleTest extends TestCase {

  /**
   * The container.
   *
   * @var \Symfony\Component\DependencyInjection\Container
   */
  protected $container;

    /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Set up container with mocked services.
    $this->container = new Container();

    // Mock logger channel factory and logger.
    $loggerChannel = $this->createMock('Drupal\Core\Logger\LoggerChannelInterface');
    $loggerChannelFactory = $this->createMock('Drupal\Core\Logger\LoggerChannelFactoryInterface');
    $loggerChannelFactory->method('get')->willReturn($loggerChannel);

    $this->container->set('logger.factory', $loggerChannelFactory);

    \Drupal::setContainer($this->container);

    // Include the module file so we can test the functions.
    require_once __DIR__ . '/../../../dkan_taxonomy_integration.module';
    require_once __DIR__ . '/../../../dkan_taxonomy_integration.install';
  }





      /**
   * Create a mock taxonomy term.
   */
  private function createMockTaxonomyTerm(string $uuid, string $name, string $bundle): object {
    // Create a simple test double without implementing EntityInterface
    return new class($uuid, $name, $bundle) {
      private $uuid, $name, $bundle;

      public function __construct($uuid, $name, $bundle) {
        $this->uuid = $uuid;
        $this->name = $name;
        $this->bundle = $bundle;
      }

      public function getEntityTypeId() { return 'taxonomy_term'; }
      public function uuid() { return $this->uuid; }
      public function getName() { return $this->name; }
      public function bundle() { return $this->bundle; }
    };
  }

  /**
   * Create a mock ValidMetadataFactory.
   */
  private function createMockValidMetadataFactory() {
    $factory = $this->createMock('Drupal\metastore\ValidMetadataFactory');
    $factory->method('get')
      ->willReturn(new RootedJsonData(json_encode([])));

    return $factory;
  }

    /**
   * Create a mock EntityTypeManager.
   */
  private function createMockEntityTypeManager(): EntityTypeManagerInterface {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    // Remove the clearCachedDefinitions expectation as it might be final/static

    return $entityTypeManager;
  }

  /**
   * Mock the _dkan_taxonomy_integration_get_custom_taxonomy_fields function.
   */
  private function mockGetCustomTaxonomyFields(): void {
    // This would need to be mocked using runkit or similar in a real test environment.
    // For now, we assume the function returns the expected structure.
  }

  /**
   * Mock static field methods.
   */
  private function mockStaticFieldMethods($fieldConfig, $fieldStorage): void {
    // In a real test environment, we would need to mock the static methods
    // FieldConfig::loadByName and FieldStorageConfig::loadByName
    // This might require using test doubles or dependency injection.
  }



  /**
   * Test _dkan_taxonomy_integration_uninstall_is_taxonomy_field.
   */
  public function testUninstallIsTaxonomyField(): void {
    // Test with valid taxonomy field data.
    $taxonomyFieldData = [
      [
        'identifier' => 'term-uuid-1',
        'data' => 'Term Name',
      ],
      [
        'identifier' => 'term-uuid-2',
        'data' => 'Another Term',
      ],
    ];

    $this->assertTrue(_dkan_taxonomy_integration_uninstall_is_taxonomy_field('country', $taxonomyFieldData));

    // Test with non-taxonomy field data.
    $nonTaxonomyFieldData = [
      'title' => 'Some Title',
      'description' => 'Some Description',
    ];

    $this->assertFalse(_dkan_taxonomy_integration_uninstall_is_taxonomy_field('title', $nonTaxonomyFieldData));

    // Test with empty field name.
    $this->assertFalse(_dkan_taxonomy_integration_uninstall_is_taxonomy_field('', $taxonomyFieldData));

    // Test with non-array field data.
    $this->assertFalse(_dkan_taxonomy_integration_uninstall_is_taxonomy_field('field_name', 'string_data'));
  }



}
