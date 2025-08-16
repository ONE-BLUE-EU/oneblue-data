<?php

namespace Drupal\Tests\dkan_taxonomy_integration\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\dkan_taxonomy_integration\EventSubscriber\TaxonomyUnificationSubscriber;
use Drupal\common\Events\Event;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\metastore\MetastoreService;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Tests endpoint isolation to prevent keyword pollution.
 *
 * @group dkan_taxonomy_integration
 */
class EndpointIsolationTest extends UnitTestCase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * The metastore service.
   *
   * @var \Drupal\metastore\MetastoreService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $metastoreService;

  /**
   * The taxonomy unification subscriber.
   *
   * @var \Drupal\dkan_taxonomy_integration\EventSubscriber\TaxonomyUnificationSubscriber
   */
  protected $subscriber;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->metastoreService = $this->createMock(MetastoreService::class);

    $this->subscriber = new TaxonomyUnificationSubscriber(
      $this->entityTypeManager,
      $this->metastoreService
    );
  }

  /**
   * Tests that keyword items are NOT processed by taxonomy unification.
   */
  public function testKeywordItemsNotProcessed() {
    // Mock keyword item data (what comes from keyword/items endpoint)
    $keywordData = [
      'identifier' => '5476fffc-8bfa-57fd-820a-b9fd1807d33b',
      'data' => 'column water',
      // No @type field (keywords don't have it)
    ];

    // Mock RootedJsonData object
    $mockData = $this->createMock('\RootedData\RootedJsonData');
    $mockData->method('get')->with('$')->willReturn($keywordData);

    // Mock event
    $mockEvent = $this->createMock(Event::class);
    $mockEvent->method('getData')->willReturn($mockData);

    // The key test: setData should NOT be called for keyword items
    $mockEvent->expects($this->never())->method('setData');

    // Call the method
    $this->subscriber->unifyTaxonomyTerms($mockEvent);
  }

  /**
   * Tests that non-dataset items without @type are NOT processed.
   */
  public function testNonDatasetItemsNotProcessed() {
    // Mock theme/publisher/other item data
    $nonDatasetData = [
      'identifier' => 'some-uuid',
      'data' => 'some value',
      '@type' => 'skos:Concept', // Not dcat:Dataset
    ];

    // Mock RootedJsonData object
    $mockData = $this->createMock('\RootedData\RootedJsonData');
    $mockData->method('get')->with('$')->willReturn($nonDatasetData);

    // Mock event
    $mockEvent = $this->createMock(Event::class);
    $mockEvent->method('getData')->willReturn($mockData);

    // The key test: setData should NOT be called for non-dataset items
    $mockEvent->expects($this->never())->method('setData');

    // Call the method
    $this->subscriber->unifyTaxonomyTerms($mockEvent);
  }

  /**
   * Tests that dataset items ARE processed by taxonomy unification.
   */
  public function testDatasetItemsAreProcessed() {
    // Mock dataset item data (what comes from dataset/items endpoint)
    $datasetData = [
      '@type' => 'dcat:Dataset',
      'identifier' => 'dataset-uuid',
      'title' => 'Test Dataset',
      'description' => 'Test dataset description',
      // Other dataset fields...
    ];

    // Mock RootedJsonData object
    $mockData = $this->createMock('\RootedData\RootedJsonData');
    $mockData->method('get')->with('$')->willReturn($datasetData);

    // Mock event
    $mockEvent = $this->createMock(Event::class);
    $mockEvent->method('getData')->willReturn($mockData);

    // For datasets, setData SHOULD be called (after processing)
    $mockEvent->expects($this->once())->method('setData');

    // Mock EntityTypeManager and storage for getCustomTaxonomyFields
    $mockStorage = $this->createMock('\Drupal\Core\Entity\EntityStorageInterface');
    $mockStorage->method('loadByProperties')->willReturn([]);
    $this->entityTypeManager->method('getStorage')->willReturn($mockStorage);

    // Call the method
    $this->subscriber->unifyTaxonomyTerms($mockEvent);
  }

  /**
   * Tests the critical fix: @type field checking.
   */
  public function testDatasetTypeChecking() {
    $testCases = [
      // Should NOT be processed
      ['data' => ['identifier' => 'test'], 'should_process' => false, 'description' => 'No @type field'],
      ['data' => ['@type' => 'skos:Concept', 'identifier' => 'test'], 'should_process' => false, 'description' => 'Wrong @type'],
      ['data' => ['@type' => 'org:Organization', 'identifier' => 'test'], 'should_process' => false, 'description' => 'Publisher @type'],
      ['data' => ['@type' => '', 'identifier' => 'test'], 'should_process' => false, 'description' => 'Empty @type'],

      // Should BE processed
      ['data' => ['@type' => 'dcat:Dataset', 'identifier' => 'test'], 'should_process' => true, 'description' => 'Correct dataset @type'],
    ];

    foreach ($testCases as $testCase) {
      $mockData = $this->createMock('\RootedData\RootedJsonData');
      $mockData->method('get')->with('$')->willReturn($testCase['data']);

      $mockEvent = $this->createMock(Event::class);
      $mockEvent->method('getData')->willReturn($mockData);

      if ($testCase['should_process']) {
        // Should call setData for datasets
        $mockEvent->expects($this->once())->method('setData');

        // Mock required services for processing
        $mockStorage = $this->createMock('\Drupal\Core\Entity\EntityStorageInterface');
        $mockStorage->method('loadByProperties')->willReturn([]);
        $this->entityTypeManager->method('getStorage')->willReturn($mockStorage);
      } else {
        // Should NOT call setData for non-datasets
        $mockEvent->expects($this->never())->method('setData');
      }

      // Call the method
      $this->subscriber->unifyTaxonomyTerms($mockEvent);

      // Reset for next iteration
      $this->setUp();
    }
  }
}
