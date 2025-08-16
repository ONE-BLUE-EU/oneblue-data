<?php

namespace Drupal\Tests\dkan_taxonomy_integration\Unit;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\dkan_taxonomy_integration\SchemaRetrieverExtended;
use Drupal\Tests\UnitTestCase;

/**
 * Tests for the SchemaRetrieverExtended class.
 *
 * @coversDefaultClass \Drupal\dkan_taxonomy_integration\SchemaRetrieverExtended
 * @group dkan_taxonomy_integration
 */
class SchemaRetrieverExtendedTest extends UnitTestCase {

  /**
   * The schema retriever extended instance.
   *
   * @var \Drupal\dkan_taxonomy_integration\SchemaRetrieverExtended
   */
  protected $schemaRetriever;

  /**
   * Mock module extension list.
   *
   * @var \Drupal\Core\Extension\ModuleExtensionList|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $moduleExtensionList;

  /**
   * Temporary directory for testing.
   *
   * @var string
   */
  protected $tempDir;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create a temporary directory structure for testing
    $this->tempDir = sys_get_temp_dir() . '/dkan_test_' . uniqid();
    mkdir($this->tempDir, 0755, true);

    // Create the schema directory structure
    $schemaDir = $this->tempDir . '/schema';
    mkdir($schemaDir, 0755, true);

    // Mock the module extension list
    $this->moduleExtensionList = $this->createMock(ModuleExtensionList::class);
    $this->moduleExtensionList->method('getPathname')
      ->with('dkan')
      ->willReturn('modules/contrib/dkan/dkan.info.yml');

    // Create instance with test app root and mocked extension list
    $this->schemaRetriever = new SchemaRetrieverExtended(
      $this->tempDir,
      $this->moduleExtensionList
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // Clean up temporary directory
    if (isset($this->tempDir) && is_dir($this->tempDir)) {
      $this->removeDirectory($this->tempDir);
    }
    parent::tearDown();
  }

  /**
   * Recursively removes a directory and its contents.
   *
   * @param string $dir
   *   The directory to remove.
   */
  private function removeDirectory(string $dir): void {
    if (is_dir($dir)) {
      $files = scandir($dir);
      foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
          $path = $dir . '/' . $file;
          if (is_dir($path)) {
            $this->removeDirectory($path);
          } else {
            unlink($path);
          }
        }
      }
      rmdir($dir);
    }
  }

  /**
   * Tests that getAllIds includes taxonomy_term schemas.
   *
   * @covers ::getAllIds
   */
  public function testGetAllIdsIncludesTaxonomyTerms(): void {
    $ids = $this->schemaRetriever->getAllIds();

    // Should include taxonomy_term schemas
    $this->assertContains('taxonomy_term', $ids);
    $this->assertContains('taxonomy_term.ui', $ids);

    // Should still include original DKAN schemas
    $this->assertContains('dataset', $ids);
    $this->assertContains('catalog', $ids);
    $this->assertContains('data-dictionary', $ids);

    // Verify order: taxonomy_term schemas should come before data-dictionary
    $taxonomyTermIndex = array_search('taxonomy_term', $ids);
    $taxonomyTermUiIndex = array_search('taxonomy_term.ui', $ids);
    $dataDictionaryIndex = array_search('data-dictionary', $ids);

    $this->assertNotFalse($taxonomyTermIndex);
    $this->assertNotFalse($taxonomyTermUiIndex);
    $this->assertNotFalse($dataDictionaryIndex);
    $this->assertLessThan($dataDictionaryIndex, $taxonomyTermIndex);
    $this->assertLessThan($dataDictionaryIndex, $taxonomyTermUiIndex);
  }

  /**
   * Tests retrieving taxonomy_term schema throws exception when file not found.
   *
   * @covers ::retrieve
   */
  public function testRetrieveTaxonomyTermSchemaFileNotFound(): void {
    // Mock the Drupal service container for extension list
    $container = $this->createMock('\Symfony\Component\DependencyInjection\ContainerInterface');
    $extensionList = $this->createMock(ModuleExtensionList::class);
    $extensionList->method('getPath')
      ->with('dkan_taxonomy_integration')
      ->willReturn('nonexistent/path');

    $container->method('get')
      ->with('extension.list.module')
      ->willReturn($extensionList);

    \Drupal::setContainer($container);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Schema taxonomy_term not found in module directory');

    $this->schemaRetriever->retrieve('taxonomy_term');
  }

  /**
   * Tests that non-taxonomy schemas fall back to parent implementation.
   *
   * @covers ::retrieve
   */
  public function testRetrieveNonTaxonomySchemaFallsBackToParent(): void {
    // This should call the parent's retrieve method for non-taxonomy schemas
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Schema dataset not found');

    // Since we don't have actual DKAN schema files in our test environment,
    // this should throw an exception from the parent class
    $this->schemaRetriever->retrieve('dataset');
  }



  /**
   * Tests getSchemaDirectory delegates to parent.
   *
   * @covers ::getSchemaDirectory
   */
  public function testGetSchemaDirectoryDelegatesToParent(): void {
    $result = $this->schemaRetriever->getSchemaDirectory();

    // Should return the DKAN schema directory (from parent implementation)
    $this->assertIsString($result);
    $this->assertStringEndsWith('/schema', $result);
  }

}
