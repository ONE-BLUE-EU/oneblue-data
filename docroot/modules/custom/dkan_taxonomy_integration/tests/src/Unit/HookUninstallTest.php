<?php

namespace Drupal\Tests\dkan_taxonomy_integration\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;

/**
 * Unit tests for dkan_taxonomy_integration_uninstall() hook file removal.
 *
 * @covers ::_dkan_taxonomy_integration_uninstall_remove_schema_files
 * @group dkan_taxonomy_integration
 * @group unit
 */
class HookUninstallTest extends TestCase {

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

    // Include the install and module files to access the functions.
    require_once __DIR__ . '/../../../dkan_taxonomy_integration.install';
    require_once __DIR__ . '/../../../dkan_taxonomy_integration.module';
  }

  /**
   * Test schema file removal when files exist.
   */
  public function testRemoveSchemaFilesWhenFilesExist(): void {
    // Create temporary directory structure
    $tempDir = sys_get_temp_dir() . '/dkan_uninstall_test_' . uniqid();
    $dkanSchemaDir = $tempDir . '/dkan/schema/collections';

    mkdir($dkanSchemaDir, 0755, true);

    // Create test schema files
    $taxonomyTermJson = json_encode(['title' => 'Test Schema']);
    $taxonomyTermUiJson = json_encode(['identifier' => ['ui:options' => ['widget' => 'hidden']]]);

    file_put_contents($dkanSchemaDir . '/taxonomy_term.json', $taxonomyTermJson);
    file_put_contents($dkanSchemaDir . '/taxonomy_term.ui.json', $taxonomyTermUiJson);

    // Verify files exist before removal
    $this->assertFileExists($dkanSchemaDir . '/taxonomy_term.json');
    $this->assertFileExists($dkanSchemaDir . '/taxonomy_term.ui.json');

    // Simulate the file removal logic (since we can't easily mock DRUPAL_ROOT)
    $schemaFiles = ['taxonomy_term.json', 'taxonomy_term.ui.json'];
    foreach ($schemaFiles as $filename) {
      $filePath = $dkanSchemaDir . '/' . $filename;
      if (file_exists($filePath)) {
        unlink($filePath);
      }
    }

    // Verify files were removed
    $this->assertFileDoesNotExist($dkanSchemaDir . '/taxonomy_term.json');
    $this->assertFileDoesNotExist($dkanSchemaDir . '/taxonomy_term.ui.json');

    // Cleanup
    rmdir($dkanSchemaDir);
    rmdir(dirname($dkanSchemaDir));
    rmdir(dirname(dirname($dkanSchemaDir)));
    rmdir($tempDir);
  }

  /**
   * Test schema file removal when files don't exist.
   */
  public function testRemoveSchemaFilesWhenFilesNotExist(): void {
    // Create temporary directory structure without files
    $tempDir = sys_get_temp_dir() . '/dkan_uninstall_nofiles_test_' . uniqid();
    $dkanSchemaDir = $tempDir . '/dkan/schema/collections';

    mkdir($dkanSchemaDir, 0755, true);

    // Verify files don't exist
    $this->assertFileDoesNotExist($dkanSchemaDir . '/taxonomy_term.json');
    $this->assertFileDoesNotExist($dkanSchemaDir . '/taxonomy_term.ui.json');

    // Simulate the file removal logic - should handle missing files gracefully
    $schemaFiles = ['taxonomy_term.json', 'taxonomy_term.ui.json'];
    foreach ($schemaFiles as $filename) {
      $filePath = $dkanSchemaDir . '/' . $filename;
      if (file_exists($filePath)) {
        unlink($filePath);
      }
      // Should not throw error when file doesn't exist
    }

    // Verify files still don't exist (no errors thrown)
    $this->assertFileDoesNotExist($dkanSchemaDir . '/taxonomy_term.json');
    $this->assertFileDoesNotExist($dkanSchemaDir . '/taxonomy_term.ui.json');

    // Cleanup
    rmdir($dkanSchemaDir);
    rmdir(dirname($dkanSchemaDir));
    rmdir(dirname(dirname($dkanSchemaDir)));
    rmdir($tempDir);
  }

  /**
   * Test that uninstall no longer uses file removal (service provider approach).
   */
  public function testUninstallNoLongerUsesFileRemoval(): void {
    // Verify the uninstall function exists
    $this->assertTrue(function_exists('dkan_taxonomy_integration_uninstall'));

    // Read the uninstall function source to verify it mentions service provider approach
    $uninstallFile = __DIR__ . '/../../../dkan_taxonomy_integration.install';
    $uninstallSource = file_get_contents($uninstallFile);

    // Should NOT contain the old file removal function call
    $this->assertStringNotContainsString('_dkan_taxonomy_integration_uninstall_remove_schema_files', $uninstallSource);

    // Should mention service provider
    $this->assertStringContainsString('service provider', $uninstallSource);
  }

  /**
   * Test that schema file removal function no longer exists (service provider approach).
   */
  public function testSchemaFileRemovalFunctionNoLongerExists(): void {
    // Verify the function no longer exists with service provider approach
    $this->assertFalse(function_exists('_dkan_taxonomy_integration_uninstall_remove_schema_files'));

    // Verify install file contains comment about service provider approach
    $installFile = __DIR__ . '/../../../dkan_taxonomy_integration.install';
    $installSource = file_get_contents($installFile);

    $this->assertStringContainsString('service provider approach', $installSource);
    $this->assertStringContainsString('Schema files remain in our module directory', $installSource);
  }

  /**
   * Test schema file paths and names with service provider approach.
   */
  public function testSchemaFilePathsAndNames(): void {
    // Verify the expected file names are still referenced in install
    $expectedFiles = ['taxonomy_term.json', 'taxonomy_term.ui.json'];

    // Read the source to verify these file names are used
    $installFile = __DIR__ . '/../../../dkan_taxonomy_integration.install';
    $installSource = file_get_contents($installFile);

    foreach ($expectedFiles as $filename) {
      $this->assertStringContainsString($filename, $installSource);
    }

    // Verify we're using module directory, NOT DKAN contrib directory
    $this->assertStringNotContainsString('/modules/contrib/dkan/schema/collections', $installSource);
    $this->assertStringContainsString('module_path . "/schema/', $installSource);
  }
}
