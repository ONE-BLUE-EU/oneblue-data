<?php

namespace Drupal\Tests\datastore\Unit\Service;

use Drupal\Core\Database\Connection;
use Drupal\metastore\ResourceMapper;
use PHPUnit\Framework\TestCase;
use Drupal\common\DataResource;
use Drupal\datastore\PostImportResultFactory;

/**
 * @covers \Drupal\datastore\PostImportResult
 * @coversDefaultClass \Drupal\datastore\PostImportResult
 *
 * @group dkan
 * @group datastore
 * @group unit
 */
class PostImportResultTest extends TestCase {

  /**
   * Test storeJobStatus() succeeds.
   *
   * @covers ::storeJobStatus
   */
  public function testStoreJobStatus() {
    $resource = new DataResource('test.csv', 'text/csv');

    $resourceMapperMock = $this->createMock(ResourceMapper::class);

    $queryMock = $this->getMockBuilder('stdClass')
      ->addMethods(['fields', 'execute'])
      ->getMock();

    $queryMock->expects($this->any())
      ->method('fields')
      ->with([
        'resource_identifier' => $resource->getIdentifier(),
        'resource_version' => $resource->getVersion(),
        'post_import_status' => 'done',
        'post_import_error' => 'N/A',
        'timestamp' => 1700000000,
      ])
      ->willReturnSelf();

    $connectionMock = $this->createMock(Connection::class);
    $connectionMock->expects($this->once())
      ->method('insert')
      ->with('dkan_post_import_job_status')
      ->willReturnOnConsecutiveCalls($queryMock);

    $postImportResultFactoryMock = $this->getMockBuilder(PostImportResultFactory::class)
      ->setConstructorArgs([$connectionMock, $resourceMapperMock])
      ->onlyMethods(['getCurrentTime'])
      ->getMock();

    $postImportResultFactoryMock->expects($this->once())
      ->method('getCurrentTime')
      ->willReturn(1700000000);

    $postImportResult = $postImportResultFactoryMock->initializeFromResource('done', 'N/A', $resource);
    $resultStore = $postImportResult->storeJobStatus();
    $this->assertTrue($resultStore);
  }

  /**
   * Test retrieveJobStatus() succeeds.
   *
   * @covers ::retrieveJobStatus
   */
  public function testRetrieveJobStatus() {
    $resource = new DataResource('test.csv', 'text/csv');

    $import_info = [
      '#resource_version' => $resource->getVersion(),
      '#post_import_status' => 'test_status',
      '#post_import_error' => 'test_error',
    ];

    $distribution = [
      'resource_id' => $resource->getIdentifier(),
    ];

    $resourceMapperMock = $this->createMock(ResourceMapper::class);
    $resourceMapperMock->expects($this->once())
      ->method('get')
      ->withAnyParameters()
      ->willReturn($resource);

    $resultMock = $this->getMockBuilder('stdClass')
      ->addMethods(['fetchAssoc'])
      ->getMock();

    $resultMock->expects($this->once())
      ->method('fetchAssoc')
      ->willReturn($import_info);

    $queryMock = $this->getMockBuilder('stdClass')
      ->addMethods(['condition', 'fields', 'orderBy', 'range', 'execute'])
      ->getMock();

    $queryMock->expects($this->exactly(2))
      ->method('condition')
      ->willReturnSelf();

    $queryMock->expects($this->once())
      ->method('orderBy')
      ->with('timestamp', 'DESC')
      ->willReturnSelf();

    $queryMock->expects($this->once())
      ->method('range')
      ->with(0, 1)
      ->willReturnSelf();

    $queryMock->expects($this->once())
      ->method('fields')
      ->with('dkan_post_import_job_status', [
        'resource_version',
        'post_import_status',
        'post_import_error',
      ])
      ->willReturnSelf();

    $queryMock->expects($this->once())
      ->method('execute')
      ->willReturn($resultMock);

    $connectionMock = $this->createMock(Connection::class);
    $connectionMock ->expects($this->once())
      ->method('select')
      ->with('dkan_post_import_job_status')
      ->willReturn($queryMock);

    $postImportResultFactory = new PostImportResultFactory($connectionMock, $resourceMapperMock);

    $postImportResult = $postImportResultFactory->initializeFromDistribution($distribution);

    $result_store = $postImportResult->retrieveJobStatus();

    $this->assertSame($result_store, $import_info);
  }

  /**
   * Test removeJobStatus() succeeds.
   *
   * @covers ::removeJobStatus
   */
  public function testRemoveJobStatus() {
    $resource = new DataResource('test.csv', 'text/csv');

    $distribution = [
      'resource_id' => $resource->getIdentifier(),
    ];

    $resourceMapperMock = $this->createMock(ResourceMapper::class);
    $resourceMapperMock->expects($this->once())
      ->method('get')
      ->withAnyParameters()
      ->willReturn($resource);

    $queryMock = $this->getMockBuilder('stdClass')
      ->addMethods(['condition', 'execute'])
      ->getMock();

    $queryMock->expects($this->exactly(2))
      ->method('condition')
      ->willReturnSelf();

    $connectionMock = $this->createMock(Connection::class);
    $connectionMock ->expects($this->once())
      ->method('delete')
      ->with('dkan_post_import_job_status')
      ->willReturn($queryMock);

    $postImportResultFactory = new PostImportResultFactory($connectionMock, $resourceMapperMock);

    $postImportResult = $postImportResultFactory->initializeFromDistribution($distribution);

    $result_store = $postImportResult->removeJobStatus();

    $this->assertTrue($result_store);
  }

}
