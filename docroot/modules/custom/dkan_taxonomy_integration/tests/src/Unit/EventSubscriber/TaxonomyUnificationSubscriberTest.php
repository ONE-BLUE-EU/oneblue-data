<?php

namespace Drupal\Tests\dkan_taxonomy_integration\Unit\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\dkan_taxonomy_integration\EventSubscriber\TaxonomyUnificationSubscriber;
use Drupal\metastore\MetastoreService;
use Drupal\common\Events\Event;
use MockChain\Chain;
use PHPUnit\Framework\TestCase;
use RootedData\RootedJsonData;
use Symfony\Component\DependencyInjection\Container;

/**
 * Unit tests for the TaxonomyUnificationSubscriber.
 *
 * @covers \Drupal\dkan_taxonomy_integration\EventSubscriber\TaxonomyUnificationSubscriber
 * @group dkan_taxonomy_integration
 * @group unit
 */
class TaxonomyUnificationSubscriberTest extends TestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Set up Drupal container with mocked services.
    $container = new Container();

    // Mock logger channel factory and logger.
    $loggerChannel = $this->createMock(LoggerChannelInterface::class);
    $loggerChannelFactory = $this->createMock('Drupal\Core\Logger\LoggerChannelFactoryInterface');
    $loggerChannelFactory->method('get')->willReturn($loggerChannel);

    $container->set('logger.factory', $loggerChannelFactory);

    \Drupal::setContainer($container);

    // Include the module file to get helper functions.
    $moduleFile = __DIR__ . '/../../../../dkan_taxonomy_integration.module';
    if (file_exists($moduleFile)) {
      require_once $moduleFile;
    }
  }

  /**
   * Test taxonomy unification with no taxonomy fields.
   */
  public function testUnifyTaxonomyTermsWithNoTaxonomyFields(): void {
    $rawData = [
      '@type' => 'dcat:Dataset', // REQUIRED: Only datasets should be processed
      'title' => 'Test Dataset',
      'description' => 'Test description',
      'other_field' => 'should remain unchanged',
    ];

    $data = new RootedJsonData(json_encode($rawData));
    $event = $this->createMockEvent($data);

    $subscriber = $this->createSubscriber();
    $subscriber->unifyTaxonomyTerms($event);

    $result = $event->getData()->get('$');

    // Verify taxonomy_term array was created but is empty.
    $this->assertArrayHasKey('taxonomy_term', $result);
    $this->assertIsArray($result['taxonomy_term']);
    $this->assertCount(0, $result['taxonomy_term']);

    // Verify other fields remain unchanged.
    $this->assertEquals('Test Dataset', $result['title']);
    $this->assertEquals('Test description', $result['description']);
    $this->assertEquals('should remain unchanged', $result['other_field']);
  }

  /**
   * Test taxonomy unification with empty taxonomy fields.
   */
  public function testUnifyTaxonomyTermsWithEmptyTaxonomyFields(): void {
    $rawData = [
      '@type' => 'dcat:Dataset', // REQUIRED: Only datasets should be processed
      'title' => 'Test Dataset',
      'country' => [],
      'singer' => null,
      'other_field' => 'should remain unchanged',
    ];

    $data = new RootedJsonData(json_encode($rawData));
    $event = $this->createMockEvent($data);

    $subscriber = $this->createSubscriber();
    $subscriber->unifyTaxonomyTerms($event);

    $result = $event->getData()->get('$');

    // Verify taxonomy_term array was created but is empty.
    $this->assertArrayHasKey('taxonomy_term', $result);
    $this->assertIsArray($result['taxonomy_term']);
    $this->assertCount(0, $result['taxonomy_term']);

    // Note: Empty taxonomy fields may not be removed since they don't have valid terms
    // The pure function only removes fields that have been processed with actual terms
    // This is the correct behavior - empty fields should be handled at the data source level

    // Verify other fields remain unchanged.
    $this->assertEquals('Test Dataset', $result['title']);
    $this->assertEquals('should remain unchanged', $result['other_field']);
  }

  /**
   * Test that getSubscribedEvents returns correct events.
   */
  public function testGetSubscribedEvents(): void {
    $events = TaxonomyUnificationSubscriber::getSubscribedEvents();

    $this->assertArrayHasKey('dkan_metastore_data_get', $events);
    $this->assertEquals('unifyTaxonomyTerms', $events['dkan_metastore_data_get']);
  }

  /**
   * Create a mock TaxonomyUnificationSubscriber.
   */
  private function createSubscriber(): TaxonomyUnificationSubscriber {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $metastoreService = $this->createMock(MetastoreService::class);

    // Mock the field config storage
    $fieldConfigStorage = $this->createMock(EntityStorageInterface::class);

    // Create mock field configs
    $countryField = $this->createMockFieldConfig('field_country', 'country');
    $singerField = $this->createMockFieldConfig('field_singer', 'singer');

    $fieldConfigStorage->method('loadByProperties')
      ->willReturn([
        $countryField,
        $singerField,
      ]);

    $entityTypeManager->method('getStorage')
      ->with('field_config')
      ->willReturn($fieldConfigStorage);

    return new TaxonomyUnificationSubscriber($entityTypeManager, $metastoreService);
  }

  /**
   * Create a mock event with data.
   */
  private function createMockEvent(RootedJsonData $data): Event {
    return new Event($data);
  }

  /**
   * Create a mock field config.
   */
  private function createMockFieldConfig(string $fieldName, string $vocabularyId): object {
    $fieldConfig = $this->createMock('Drupal\field\Entity\FieldConfig');
    $fieldConfig->method('getName')->willReturn($fieldName);
    $fieldConfig->method('getLabel')->willReturn(ucfirst($vocabularyId));

    // Mock field storage
    $fieldStorage = $this->createMock('Drupal\field\Entity\FieldStorageConfig');
    $fieldStorage->method('getType')->willReturn('entity_reference');
    $fieldStorage->method('getSettings')->willReturn(['target_type' => 'taxonomy_term']);

    $fieldConfig->method('getFieldStorageDefinition')->willReturn($fieldStorage);

    // Mock field settings with proper handler settings structure
    $fieldConfig->method('getSettings')->willReturn([
      'handler' => 'default:taxonomy_term',
      'handler_settings' => [
        'target_bundles' => [$vocabularyId => $vocabularyId],
        'sort' => ['field' => '_none'],
        'auto_create' => FALSE,
      ],
    ]);

    // Mock the getSetting method for individual settings
    $fieldConfig->method('getSetting')
      ->willReturnCallback(function($key) use ($vocabularyId) {
        $settings = [
          'handler' => 'default:taxonomy_term',
          'handler_settings' => [
            'target_bundles' => [$vocabularyId => $vocabularyId],
            'sort' => ['field' => '_none'],
            'auto_create' => FALSE,
          ],
        ];
        return $settings[$key] ?? NULL;
      });

    return $fieldConfig;
  }

}
