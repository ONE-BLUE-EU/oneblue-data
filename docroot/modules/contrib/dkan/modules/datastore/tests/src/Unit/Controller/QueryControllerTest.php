<?php

namespace Drupal\Tests\datastore\Unit\Controller;

use Drupal\common\DataResource;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\common\DatasetInfo;
use Drupal\datastore\Controller\QueryController;
use Drupal\datastore\DatastoreService;
use Drupal\datastore\Service\Query;
use Drupal\datastore\Storage\SqliteDatabaseTable;
use Drupal\metastore\MetastoreApiResponse;
use Drupal\metastore\NodeWrapper\Data;
use Drupal\metastore\NodeWrapper\NodeDataFactory;
use Drupal\metastore\Storage\DataFactory;
use Drupal\sqlite\Driver\Database\sqlite\Connection as SqliteConnection;
use Ilbee\CSVResponse\CSVResponse as CsvResponse;
use MockChain\Chain;
use MockChain\Options;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * @group dkan
 * @group datastore
 * @group unit
 */
class QueryControllerTest extends TestCase {

  const FILE_DIR = __DIR__ . "/../../../data/";

  /**
   * Resources to be used in tests.
   */
  private DataResource $resource;

  protected function setUp(): void {
    parent::setUp();
    // Set cache services.
    $options = (new Options)
      ->add('cache_contexts_manager', CacheContextsManager::class)
      ->add('event_dispatcher', EventDispatcher::class)
      ->index(0);
    $chain = (new Chain($this))
      ->add(ContainerInterface::class, 'get', $options)
      ->add(CacheContextsManager::class, 'assertValidTokens', TRUE);
    \Drupal::setContainer($chain->getMock());

    $this->resource = new DataResource(self::FILE_DIR . 'states_with_dupes.csv', 'text/csv');
  }

  public function testQueryJson() {
    $data = json_encode([
      "resources" => [
        [
          "id" => $this->resource->getIdentifier(),
          "alias" => "t",
        ],
      ],
    ]);

    $result = $this->getQueryResult($data);

    $this->assertTrue($result instanceof JsonResponse);
    $this->assertEquals(200, $result->getStatusCode());
  }

  /**
   * Do some things differently to throw exception on metastore retrieval.
   */
  public function testQueryWrongIdentifier() {
    $data = json_encode([
      "results" => TRUE,
      "resources" => [
        [
          "id" => "9",
          "alias" => "t",
        ],
      ],
    ]);

    $options = (new Options())
      ->add("dkan.metastore.storage", DataFactory::class)
      ->index(0);
    $container = (new Chain($this))
      ->add(Container::class, "get", $options)
      ->add(DataFactory::class, 'getInstance', MockStorage::class)
      ->getMock();
    \Drupal::setContainer($container);

    $chain = $this->getQueryContainer([], FALSE);
    $webServiceApi = QueryController::create($chain->getMock());
    $request = $this->mockRequest($data);
    $result = $webServiceApi->query($request);

    $this->assertTrue($result instanceof JsonResponse);
    $this->assertEquals(404, $result->getStatusCode());
    $this->assertStringContainsString('Error retrieving published dataset', $result->getContent());
  }

  public function testQueryRowIdProperty() {
    // Try simple string properties:
    $data = json_encode(["properties" => ["record_number", "state"]]);

    $result = $this->getQueryResult($data, $this->resource->getIdentifier());

    $this->assertEquals(400, $result->getStatusCode());
    $this->assertStringContainsString('The record_number property is for internal use', $result->getContent());

    // Now try with rowIds plus an arbitrary property:
    $data = json_encode(["properties" => ["state"], "rowIds" => TRUE]);

    $result = $this->getQueryResult($data, $this->resource->getIdentifier());

    $this->assertEquals(400, $result->getStatusCode());
    $this->assertStringContainsString('The rowIds property cannot be set to true', $result->getContent());

    // Now try with resource properties:
    $data = json_encode([
      "properties" => [
        [
          "resource" => "t",
          "property" => "state",
        ],
        [
          "resource" => "t",
          "property" => "record_number",
        ],
      ],
    ]);

    $result = $this->getQueryResult($data, $this->resource->getIdentifier());

    $this->assertEquals(400, $result->getStatusCode());
    $this->assertStringContainsString('The record_number property is for internal use', $result->getContent());
  }

  public function testQueryRowIdSort() {
    $data = json_encode([
      "sorts" => [
        [
          "resource" => "t",
          "property" => "state",
          "order" => "desc",
        ],
        [
          "resource" => "t",
          "property" => "record_number",
          "order" => "asc",
        ],
      ],
    ]);

    $result = $this->getQueryResult($data, $this->resource->getIdentifier());

    $this->assertTrue($result instanceof JsonResponse);
    $this->assertEquals(200, $result->getStatusCode());
  }

  // Make sure nothing fails with no resources.
  public function testQueryJsonNoResources() {
    $data = json_encode([
      "properties" => [
        [
          "resource" => "t",
          "property" => "field",
        ],
      ],
    ]);

    $result = $this->getQueryResult($data);

    $this->assertTrue($result instanceof JsonResponse);
    $this->assertEquals(200, $result->getStatusCode());
  }

  public function testQueryInvalid() {
    $data = json_encode([
      "resources" => "nope",
    ]);

    $result = $this->getQueryResult($data);

    $this->assertTrue($result instanceof JsonResponse);
    $this->assertEquals(400, $result->getStatusCode());
  }


  public function testResourceQueryInvalidJson() {
    $data = "{[";

    $result = $this->getQueryResult($data);

    $this->assertTrue($result instanceof JsonResponse);
    $this->assertEquals(400, $result->getStatusCode());
  }

  public function testResourceQueryInvalidQuery() {
    $data = json_encode([
      "conditions" => "nope",
    ]);
    $result = $this->getQueryResult($data, $this->resource->getIdentifier());

    $this->assertTrue($result instanceof JsonResponse);
    $this->assertEquals(400, $result->getStatusCode());
  }

  public function testResourceQueryWithJoin() {
    $data = json_encode([
      "joins" => [
        "resource" => "t",
        "condition" => "t.field1 = s.field1",
      ],
    ]);
    $result = $this->getQueryResult($data, $this->resource->getIdentifier());

    $this->assertTrue($result instanceof JsonResponse);
    $this->assertEquals(400, $result->getStatusCode());
  }

  /**
   *
   */
  public function testResourceQueryJson() {
    $data = json_encode([
      "results" => TRUE,
    ]);
    $result = $this->getQueryResult($data, $this->resource->getIdentifier());

    $this->assertTrue($result instanceof JsonResponse);
    $this->assertEquals(200, $result->getStatusCode());
  }

  /**
   * Do some things differently to throw exception on metastore retrieval.
   */
  public function testResourceQueryWrongIdentifier() {
    $data = json_encode([
      "results" => TRUE,
    ]);

    $options = (new Options())
      ->add("dkan.metastore.storage", DataFactory::class)
      ->index(0);
    $container = (new Chain($this))
      ->add(Container::class, "get", $options)
      ->add(DataFactory::class, 'getInstance', MockStorage::class)
      ->getMock();
    \Drupal::setContainer($container);

    $chain = $this->getQueryContainer([], FALSE);
    $webServiceApi = QueryController::create($chain->getMock());
    $request = $this->mockRequest($data);
    $result = $webServiceApi->queryResource("9", $request);

    $this->assertTrue($result instanceof JsonResponse);
    $this->assertEquals(404, $result->getStatusCode());
    $this->assertStringContainsString('Error retrieving published dataset', $result->getContent());
  }

  /**
   *
   */
  public function testResourceQueryJoins() {
    $data = json_encode([
      "results" => TRUE,
      "joins" => [
        [
          "resource" => "t",
          "condition" => [
            "resource" => "t",
            "property" => "record_number",
            "value" => [
              "resource" => "t",
              "property" => "record_number",
            ],
          ],
        ],
      ],
    ]);
    $result = $this->getQueryResult($data, $this->resource->getIdentifier());

    $this->assertTrue($result instanceof JsonResponse);
    $this->assertEquals(400, $result->getStatusCode());
    $this->assertStringContainsString('Joins are not available', $result->getContent());
  }

  /**
   *
   */
  public function testQueryCsv() {
    $data = json_encode([
      "resources" => [
        [
          "id" => $this->resource->getIdentifier(),
          "alias" => "t",
        ],
      ],
      "format" => "csv",
    ]);

    $result = $this->getQueryResult($data);

    $this->assertTrue($result instanceof CsvResponse);
    $this->assertEquals(200, $result->getStatusCode());

    $csv = explode("\n", $result->getContent());
    $this->assertEquals('State,Year', $csv[0]);
    $this->assertEquals('Alabama,2010', $csv[1]);
    $this->assertStringContainsString('data.csv', $result->headers->get('Content-Disposition'));
  }

  private function getQueryResult($data, $id = NULL, $index = NULL, $info = []) {
    $container = $this->getQueryContainer($info, true)->getMock();
    $webServiceApi = QueryController::create($container);
    $request = $this->mockRequest($data);
    if ($id === NULL && $index === NULL) {
      return $webServiceApi->query($request);
    }
    if ($index === NULL) {
      return $webServiceApi->queryResource($id, $request);
    }
    return $webServiceApi->queryDatasetResource($id, $index, $request);
  }

  public function testResourceQueryCsv() {
    $data = json_encode([
      "properties" => [
        [
          "resource" => "t",
          "property" => "state",
        ],
      ],
      "results" => TRUE,
      "format" => "csv",
    ]);
    $result = $this->getQueryResult($data, $this->resource->getIdentifier());
    $this->assertTrue($result instanceof CsvResponse);
    $csv = explode("\n", $result->getContent());
    $this->assertEquals('State', $csv[0]);
    $this->assertEquals('Alabama', $csv[1]);
    $this->assertEquals(200, $result->getStatusCode());
  }


  public function testResourceExpressionQueryCsv() {
    $data = json_encode([
      "properties" => [
        "state",
        "year",
        [
          "expression" => [
            "operator" => "+",
            "operands" => [
              "year",
              1,
            ],
          ],
          "alias" => "year_plus_1",
        ],
      ],
      "results" => TRUE,
      "format" => "csv",
    ]);
    $result = $this->getQueryResult($data, $this->resource->getIdentifier());
    $this->assertTrue($result instanceof CsvResponse);

    $csv = explode("\n", $result->getContent());
    $this->assertEquals('State,Year,year_plus_1', $csv[0]);
    $this->assertEquals('Alabama,2010,2011', $csv[1]);
    $this->assertEquals(200, $result->getStatusCode());
  }

  public function testQuerySchema() {
    $container = $this->getQueryContainer()->getMock();
    $webServiceApi = QueryController::create($container);
    $result = $webServiceApi->querySchema();

    $this->assertTrue($result instanceof JsonResponse);
    $this->assertEquals(200, $result->getStatusCode());
    $this->assertStringContainsString("json-schema.org", $result->getContent());
  }

  /**
   *
   */
  public function testDistributionIndexWrongIdentifier() {
    $data = json_encode([
      "results" => TRUE,
    ]);

    $result = $this->getQueryResult($data, "2", 0);

    $this->assertTrue($result instanceof JsonResponse);
    $this->assertEquals(404, $result->getStatusCode());
  }

  /**
   *
   */
  public function testDistributionIndexWrongIndex() {
    $data = json_encode([
      "results" => TRUE,
    ]);
    $info['latest_revision']['distributions'][0]['distribution_uuid'] = '123';

    $result = $this->getQueryResult($data, "2", 1, $info);

    $this->assertTrue($result instanceof JsonResponse);
    $this->assertEquals(404, $result->getStatusCode());
  }

  /**
   *
   */
  public function testDistributionIndex() {
    $data = json_encode([
      "results" => TRUE,
    ]);
    $info['latest_revision']['distributions'][0]['distribution_uuid'] = '123';

    $result = $this->getQueryResult($data, "2", 0, $info);

    $this->assertTrue($result instanceof JsonResponse);
    $this->assertEquals(200, $result->getStatusCode());
  }

  /**
   *
   */
  public function testQueryCsvCacheHeaders() {
    $data = json_encode([
      "resources" => [
        [
          "id" => $this->resource->getIdentifier(),
          "alias" => "t",
        ],
      ],
      "format" => "csv",
    ]);

    // Create a container with caching turned on.
    $containerChain = $this->getQueryContainer()
      ->add(Container::class, 'has', TRUE)
      ->add(ConfigFactoryInterface::class, 'get', ImmutableConfig::class)
      ->add(ImmutableConfig::class, 'get', 600);
    $container = $containerChain->getMock();
    \Drupal::setContainer($container);

    // CSV. Caching on.
    $webServiceApi = QueryController::create($container);
    $request = $this->mockRequest($data);
    $response = $webServiceApi->query($request);
    $headers = $response->headers;

    $this->assertEquals('text/csv', $headers->get('content-type'));
    $this->assertEquals('max-age=600, public', $headers->get('cache-control'));
    $this->assertNotEmpty($headers->get('last-modified'));

    // Turn caching off.
    $containerChain->add(ImmutableConfig::class, 'get', 0)->getMock();
    $container = $containerChain->getMock();
    \Drupal::setContainer($container);

    // CSV. No caching.
    $webServiceApi = QueryController::create($container);
    $request = $this->mockRequest($data);
    $response = $webServiceApi->query($request);
    $headers = $response->headers;

    $this->assertEquals('text/csv', $headers->get('content-type'));
    $this->assertEquals('no-cache, private', $headers->get('cache-control'));
    $this->assertEmpty($headers->get('vary'));
    $this->assertEmpty($headers->get('last-modified'));
  }

  private function getQueryContainer(array $info = [], $mockMap = TRUE) {

    $options = (new Options())
      ->add("dkan.metastore.storage", DataFactory::class)
      ->add("dkan.datastore.service", DatastoreService::class)
      ->add("dkan.datastore.query", Query::class)
      ->add("dkan.common.dataset_info", DatasetInfo::class)
      ->add('config.factory', ConfigFactoryInterface::class)
      ->add('dkan.metastore.metastore_item_factory', NodeDataFactory::class)
      ->add('dkan.metastore.api_response', MetastoreApiResponse::class)
      ->index(0);

    $chain = (new Chain($this))
      ->add(Container::class, "get", $options)
      ->add(DatasetInfo::class, "gather", $info)
      ->add(MetastoreApiResponse::class, 'getMetastoreItemFactory', NodeDataFactory::class)
      ->add(MetastoreApiResponse::class, 'addReferenceDependencies', NULL)
      ->add(NodeDataFactory::class, 'getInstance', Data::class)
      ->add(Data::class, 'getCacheContexts', ['url'])
      ->add(Data::class, 'getCacheTags', ['node:1'])
      ->add(Data::class, 'getCacheMaxAge', 0)
      ->add(ConfigFactoryInterface::class, 'get', ImmutableConfig::class)
      ->add(ImmutableConfig::class, 'get', 500);

    if ($mockMap) {
      $chain->add(Query::class, "getQueryStorageMap", ['t' => $this->mockDatastoreTable()]);
    }

    return $chain;
  }

  /**
   * We just test POST requests; logic for other methods is tested elsewhere.
   *
   * @param string $data
   *   Request body.
   */
  public function mockRequest($data = '') {
    return Request::create("http://example.com", 'POST', [], [], [], [], $data);
  }

  /**
   * Create a mock datastore table in memory with SQLite.
   *
   * The table will be based on states_with_dupes.csv, which contains the
   * columns "record_number", "state" and "year". The record_number column
   * is in ascending order but skips many numbers, and both other columns
   * contain duplicate values.
   *
   * @return \Drupal\common\Storage\DatabaseTableInterface
   *   A database table storage class useable for datastore queries.
   */
  public function mockDatastoreTable() {
    $connection = new SqliteConnection(new \PDO('sqlite::memory:'), []);
    // $connection->query('CREATE TABLE `datastore_2` (`record_number` INTEGER NOT NULL, state TEXT, year INT);');
    $storage = new SqliteDatabaseTable(
      $connection,
      $this->resource,
      $this->createStub(LoggerInterface::class),
      $this->createStub(EventDispatcherInterface::class)
    );
    $storage->setSchema([
      'fields' => [
        'record_number' => ['type' => 'int', 'description' => 'Record Number', 'not null' => TRUE],
        'state' => ['type' => 'text', 'description' => 'State'],
        'year' => ['type' => 'int', 'description' => 'Year'],
      ],
    ]);
    $storage->setTable();

    $fp = fopen($this->resource->getFilePath(), 'rb');
    $sampleData = [];
    while (!feof($fp)) {
      $sampleData[] = fgetcsv($fp, escape: '\\');
    }
    fclose($fp);

    $table_name = $storage->getTableName();
    foreach ($sampleData as $row) {
      $connection->query("INSERT INTO `$table_name` VALUES ($row[0], '$row[1]', $row[2]);");
    }

    return $storage;
  }

}
