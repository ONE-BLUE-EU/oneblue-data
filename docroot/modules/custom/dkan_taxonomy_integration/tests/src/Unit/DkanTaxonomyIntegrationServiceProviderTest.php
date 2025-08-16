<?php

namespace Drupal\Tests\dkan_taxonomy_integration\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\dkan_taxonomy_integration\DkanTaxonomyIntegrationServiceProvider;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Tests for the DkanTaxonomyIntegrationServiceProvider class.
 *
 * @coversDefaultClass \Drupal\dkan_taxonomy_integration\DkanTaxonomyIntegrationServiceProvider
 * @group dkan_taxonomy_integration
 */
class DkanTaxonomyIntegrationServiceProviderTest extends UnitTestCase {

  /**
   * The service provider instance.
   *
   * @var \Drupal\dkan_taxonomy_integration\DkanTaxonomyIntegrationServiceProvider
   */
  protected $serviceProvider;

  /**
   * Mock container builder.
   *
   * @var \Drupal\Core\DependencyInjection\ContainerBuilder|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $containerBuilder;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->serviceProvider = new DkanTaxonomyIntegrationServiceProvider();
    $this->containerBuilder = $this->createMock(ContainerBuilder::class);
  }

  /**
   * Tests alter method overrides DKAN schema retriever service when it exists.
   *
   * @covers ::alter
   */
  public function testAlterOverridesSchemaRetrieverWhenExists(): void {
    $mockDefinition = $this->createMock(Definition::class);

    // Mock container to return true for hasDefinition and return mock definition
    $this->containerBuilder->method('hasDefinition')
      ->with('dkan.metastore.schema_retriever')
      ->willReturn(TRUE);

    $this->containerBuilder->method('getDefinition')
      ->with('dkan.metastore.schema_retriever')
      ->willReturn($mockDefinition);

    // Expect setClass to be called with our extended class
    $mockDefinition->expects($this->once())
      ->method('setClass')
      ->with('Drupal\dkan_taxonomy_integration\SchemaRetrieverExtended');

    // Expect setShared to be called with FALSE (same as original service)
    $mockDefinition->expects($this->once())
      ->method('setShared')
      ->with(FALSE);

    $this->serviceProvider->alter($this->containerBuilder);
  }

  /**
   * Tests alter method does nothing when DKAN schema retriever service doesn't exist.
   *
   * @covers ::alter
   */
  public function testAlterDoesNothingWhenServiceDoesNotExist(): void {
    // Mock container to return false for hasDefinition
    $this->containerBuilder->method('hasDefinition')
      ->with('dkan.metastore.schema_retriever')
      ->willReturn(FALSE);

    // Expect getDefinition to never be called
    $this->containerBuilder->expects($this->never())
      ->method('getDefinition');

    $this->serviceProvider->alter($this->containerBuilder);
  }

  /**
   * Tests that service provider preserves original service arguments.
   *
   * @covers ::alter
   */
  public function testAlterPreservesOriginalServiceArguments(): void {
    $mockDefinition = $this->createMock(Definition::class);

    // Mock container to return true for hasDefinition and return mock definition
    $this->containerBuilder->method('hasDefinition')
      ->with('dkan.metastore.schema_retriever')
      ->willReturn(TRUE);

    $this->containerBuilder->method('getDefinition')
      ->with('dkan.metastore.schema_retriever')
      ->willReturn($mockDefinition);

    // Expect only setClass and setShared to be called - arguments should not be modified
    $mockDefinition->expects($this->never())
      ->method('setArguments');

    $mockDefinition->expects($this->never())
      ->method('addArgument');

    $this->serviceProvider->alter($this->containerBuilder);
  }

  /**
   * Tests service provider extends ServiceProviderBase.
   */
  public function testServiceProviderExtendsServiceProviderBase(): void {
    $this->assertInstanceOf(
      'Drupal\Core\DependencyInjection\ServiceProviderBase',
      $this->serviceProvider
    );
  }

  /**
   * Tests service provider has proper namespace and class name.
   */
  public function testServiceProviderClassStructure(): void {
    $reflection = new \ReflectionClass($this->serviceProvider);

    $this->assertEquals(
      'Drupal\dkan_taxonomy_integration\DkanTaxonomyIntegrationServiceProvider',
      $reflection->getName()
    );

    $this->assertTrue($reflection->hasMethod('alter'));
    $this->assertTrue($reflection->getMethod('alter')->isPublic());
  }

}
