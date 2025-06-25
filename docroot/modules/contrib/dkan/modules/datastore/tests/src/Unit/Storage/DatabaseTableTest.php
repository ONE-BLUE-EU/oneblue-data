<?php

namespace Drupal\Tests\datastore\Unit\Storage;

use Drupal\common\DataResource;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Database\Query\Insert;
use Drupal\Core\Database\Query\Select;
use Drupal\common\Storage\Query;
use Drupal\Core\Database\StatementInterface;
use Drupal\datastore\Storage\DatabaseTable;
use Drupal\mysql\Driver\Database\mysql\Schema;
use MockChain\Chain;
use MockChain\Sequence;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @coversDefaultClass \Drupal\datastore\Storage\DatabaseTable
 *
 * @group dkan
 * @group datastore
 * @group unit
 */
class DatabaseTableTest extends TestCase {

  /**
   * @covers ::translateType()
   * @dataProvider translateTypeProvider
   */
  public function testTranslateType($type, $extra, $return) {
    $databaseTable = new DatabaseTable(
      $this->getConnectionChain()->getMock(),
      $this->getResource(),
      $this->createStub(LoggerInterface::class),
      $this->createStub(EventDispatcherInterface::class)
    );

    $reflection = new \ReflectionClass($databaseTable);
    $translateType = $reflection->getMethod('translateType');
    $this->assertEquals($return, $translateType->invokeArgs($databaseTable, [$type, $extra]));
  }

  public static function translateTypeProvider() {
    return [
      [
        'int unsigned',
        'auto_increment',
        [
          'type' => 'serial',
          'unsigned' => TRUE,
          'not null' => TRUE,
          'mysql_type' => 'int',
        ],
      ],
      [
        'int unsigned',
        NULL,
        [
          'type' => 'int',
          'unsigned' => TRUE,
          'mysql_type' => 'int',
        ],
      ],
      [
        'int(10)',
        NULL,
        [
          'type' => 'int',
          'mysql_type' => 'int',
        ],
      ],
      [
        'int (10) unsigned',
        'auto_increment',
        [
          'type' => 'serial',
          'unsigned' => TRUE,
          'not null' => TRUE,
          'mysql_type' => 'int',
        ],
      ],
      [
        'varchar(10)',
        NULL,
        [
          'type' => 'varchar',
          'length' => 10,
          'mysql_type' => 'varchar',
        ],
      ],
      [
        'text',
        '',
        [
          'type' => 'text',
          'mysql_type' => 'text',
        ],
      ],
      [
        'tinyint(1)',
        NULL,
        [
          'type' => 'int',
          'size' => 'tiny',
          'mysql_type' => 'tinyint',
        ],
      ],
      [
        'decimal(3,2)',
        NULL,
        [
          'type' => 'numeric',
          'precision' => 3,
          'scale' => 2,
          'mysql_type' => 'decimal',
        ],
      ],
    ];
  }

  /**
   *
   */
  public function testConstruction() {

    $databaseTable = new DatabaseTable(
      $this->getConnectionChain()->getMock(),
      $this->getResource(),
      $this->createStub(LoggerInterface::class),
      $this->createStub(EventDispatcherInterface::class)
    );
    $this->assertTrue(is_object($databaseTable));
  }

  /**
   *
   */
  public function testGetSchema() {
    $connectionChain = $this->getConnectionChain();

    $databaseTable = new DatabaseTable(
      $connectionChain->getMock(),
      $this->getResource(),
      $this->createStub(LoggerInterface::class),
      $this->createStub(EventDispatcherInterface::class)
    );

    $schema = $databaseTable->getSchema();

    $expectedSchema = [
      "fields" => [
        "record_number" => [
          "type" => "serial",
          "unsigned" => TRUE,
          "not null" => TRUE,
          'mysql_type' => 'int',
        ],
        "first_name" => [
          "type" => "varchar",
          "description" => "First Name",
          'length' => 10,
          'mysql_type' => 'varchar',
        ],
        "last_name" => [
          "type" => "text",
          "description" => "lAST nAME",
          "mysql_type" => "text",
        ],
      ],
      "indexes" => [
        "idx1" => [
          "first_name",
        ],
      ],
      "fulltext indexes" => [
        "ftx1" => [
          "first_name",
          "last_name",
        ],
      ],
    ];

    $this->assertEquals($expectedSchema['fields'], $schema['fields']);
  }

  /**
   *
   */
  public function testRetrieveAll() {

    $fieldInfo = [
      (object) ['Field' => "first_name", 'Type' => "varchar(10)"],
      (object) ['Field' => "last_name", 'Type' => 'text']
    ];

    $sequence = (new Sequence())
      ->add($fieldInfo)
      ->add([]);

    $connection = $this->getConnectionChain()
      ->add(Connection::class, "select", Select::class)
      ->add(Select::class, "fields", Select::class)
      ->add(Select::class, "execute", StatementInterface::class)
      ->add(StatementInterface::class, 'fetchAll', $sequence)
      ->getMock();

    $databaseTable = new DatabaseTable(
      $connection,
      $this->getResource(),
      $this->createStub(LoggerInterface::class),
      $this->createStub(EventDispatcherInterface::class)
    );
    $this->assertEquals([], $databaseTable->retrieveAll());
  }

  /**
   *
   */
  public function testStore() {
    $connectionChain = $this->getConnectionChain()
      ->add(Connection::class, 'insert', Insert::class)
      ->add(Insert::class, 'fields', Insert::class)
      ->add(Insert::class, 'values', Insert::class)
      ->add(Insert::class, 'execute', "1")
      ->add(Connection::class, 'select', Select::class, 'select_1')
      ->add(Select::class, 'fields', Select::class)
      ->add(Select::class, 'condition', Select::class)
      ->add(Select::class, 'execute', StatementInterface::class)
      ->add(StatementInterface::class, 'fetch', NULL);

    $databaseTable = new DatabaseTable(
      $connectionChain->getMock(),
      $this->getResource(),
      $this->createStub(LoggerInterface::class),
      $this->createStub(EventDispatcherInterface::class)
    );
    $this->assertEquals("1", $databaseTable->store('["Gerardo", "Gonzalez"]', "1"));
  }

  /**
   *
   */
  public function testStoreFieldCountException() {
    $connectionChain = $this->getConnectionChain()
      ->add(Connection::class, 'insert', Insert::class)
      ->add(Insert::class, 'fields', Insert::class)
      ->add(Insert::class, 'values', Insert::class)
      ->add(Insert::class, 'execute', "1")
      ->add(Connection::class, 'select', Select::class, 'select_1')
      ->add(Select::class, 'fields', Select::class)
      ->add(Select::class, 'condition', Select::class)
      ->add(Select::class, 'execute', StatementInterface::class)
      ->add(StatementInterface::class, 'fetch', NULL);

    $databaseTable = new DatabaseTable(
      $connectionChain->getMock(),
      $this->getResource(),
      $this->createStub(LoggerInterface::class),
      $this->createStub(EventDispatcherInterface::class)
    );
    $this->expectExceptionMessageMatches("/The number of fields and data given do not match:/");
    $this->assertEquals("1", $databaseTable->store('["Foobar"]', "1"));
  }

  /**
   *
   */
  public function testStoreMultiple() {
    $connectionChain = $this->getConnectionChain()
      ->add(Connection::class, 'insert', Insert::class)
      ->add(Insert::class, 'fields', Insert::class)
      ->add(Insert::class, 'values', Insert::class)
      ->add(Insert::class, 'execute', "1")
      ->add(Connection::class, 'select', Select::class, 'select_1')
      ->add(Select::class, 'fields', Select::class)
      ->add(Select::class, 'condition', Select::class)
      ->add(Select::class, 'execute', StatementInterface::class)
      ->add(StatementInterface::class, 'fetch', NULL);

    $databaseTable = new DatabaseTable(
      $connectionChain->getMock(),
      $this->getResource(),
      $this->createStub(LoggerInterface::class),
      $this->createStub(EventDispatcherInterface::class)
    );
    $data = [
      '["Gerardo", "Gonzalez"]',
      '["Thierry", "Dallacroce"]',
      '["Foo", "Bar"]',
    ];
    $this->assertEquals("1", $databaseTable->storeMultiple($data, "1"));
  }

  /**
   *
   */
  public function testStoreMultipleFieldCountException() {
    $connectionChain = $this->getConnectionChain()
      ->add(Connection::class, 'insert', Insert::class)
      ->add(Insert::class, 'fields', Insert::class)
      ->add(Insert::class, 'values', Insert::class)
      ->add(Insert::class, 'execute', "1")
      ->add(Connection::class, 'select', Select::class, 'select_1')
      ->add(Select::class, 'fields', Select::class)
      ->add(Select::class, 'condition', Select::class)
      ->add(Select::class, 'execute', StatementInterface::class)
      ->add(StatementInterface::class, 'fetch', NULL);

    $databaseTable = new DatabaseTable(
      $connectionChain->getMock(),
      $this->getResource(),
      $this->createStub(LoggerInterface::class),
      $this->createStub(EventDispatcherInterface::class)
    );
    $data = [
      '["One"]',
      '["Two"]',
      '["Three"]',
    ];
    $this->expectExceptionMessageMatches("/The number of fields and data given do not match:/");
    $this->assertEquals("1", $databaseTable->storeMultiple($data, "1"));
  }

  /**
   *
   */
  public function testCount() {
    $connectionChain = $this->getConnectionChain()
      ->add(Connection::class, 'select', Select::class, 'select_1')
      ->add(Select::class, 'fields', Select::class)
      ->add(Select::class, 'countQuery', Select::class)
      ->add(Select::class, 'execute', StatementInterface::class)
      ->add(StatementInterface::class, 'fetchField', 1);

    $databaseTable = new DatabaseTable(
      $connectionChain->getMock(),
      $this->getResource(),
      $this->createStub(LoggerInterface::class),
      $this->createStub(EventDispatcherInterface::class)
    );
    $this->assertEquals(1, $databaseTable->count());
  }

  /**
   *
   */
  public function testGetSummary() {
    $connectionChain = $this->getConnectionChain()
      ->add(Connection::class, 'select', Select::class, 'select_1')
      ->add(Select::class, 'fields', Select::class)
      ->add(Select::class, 'countQuery', Select::class)
      ->add(Select::class, 'execute', StatementInterface::class)
      ->add(StatementInterface::class, 'fetchField', 1);

    $databaseTable = new DatabaseTable(
      $connectionChain->getMock(),
      $this->getResource(),
      $this->createStub(LoggerInterface::class),
      $this->createStub(EventDispatcherInterface::class)
    );

    $actual = json_decode(json_encode(
      $databaseTable->getSummary()
    ));

    $this->assertEquals(3, $actual->numOfColumns);
    $this->assertEquals(1, $actual->numOfRows);
    $this->assertEquals(["record_number", "first_name", "last_name"],
      array_keys((array) $actual->columns));
  }

  /**
   *
   */
  public function testDestruct() {
    $connectionChain = $this->getConnectionChain();

    $databaseTable = new DatabaseTable(
      $connectionChain->getMock(),
      $this->getResource(),
      $this->createStub(LoggerInterface::class),
      $this->createStub(EventDispatcherInterface::class)
    );
    $databaseTable->destruct();
    $this->assertTrue(TRUE);

  }

  /**
   *
   */
  public function testPrepareDataJsonDecodeNull() {
    $connectionChain = $this->getConnectionChain()
      ->add(Connection::class, 'insert', Insert::class)
      ->add(Insert::class, 'fields', Insert::class)
      ->add(Insert::class, 'values', Insert::class)
      ->add(Insert::class, 'execute', "1")
      ->add(Connection::class, 'select', Select::class, 'select_1')
      ->add(Select::class, 'fields', Select::class)
      ->add(Select::class, 'condition', Select::class)
      ->add(Select::class, 'execute', StatementInterface::class)
      ->add(StatementInterface::class, 'fetch', NULL);

    $databaseTable = new DatabaseTable(
      $connectionChain->getMock(),
      $this->getResource(),
      $this->createStub(LoggerInterface::class),
      $this->createStub(EventDispatcherInterface::class)
    );
    $this->expectExceptionMessage('Import for 1 returned an error when preparing table header: {"foo":"bar"}');
    $this->assertEquals("1", $databaseTable->store('{"foo":"bar"}', "1"));
  }

  /**
   *
   */
  public function testPrepareDataNonArray() {
    $connectionChain = $this->getConnectionChain()
      ->add(Connection::class, 'insert', Insert::class)
      ->add(Insert::class, 'fields', Insert::class)
      ->add(Insert::class, 'values', Insert::class)
      ->add(Insert::class, 'execute', "1")
      ->add(Connection::class, 'select', Select::class, 'select_1')
      ->add(Select::class, 'fields', Select::class)
      ->add(Select::class, 'condition', Select::class)
      ->add(Select::class, 'execute', StatementInterface::class)
      ->add(StatementInterface::class, 'fetch', NULL);

    $databaseTable = new DatabaseTable(
      $connectionChain->getMock(),
      $this->getResource(),
      $this->createStub(LoggerInterface::class),
      $this->createStub(EventDispatcherInterface::class)
    );
    $this->expectExceptionMessage("Import for 1 error when decoding foobar");
    $this->assertEquals("1", $databaseTable->store("foobar", "1"));
  }

  /**
   *
   */
  public function testQuery() {
    $query = new Query();

    $connectionChain = $this->getConnectionChain()
      ->add(Connection::class, 'select', Select::class, 'select_1')
      ->add(Select::class, 'fields', Select::class)
      ->add(Select::class, 'condition', Select::class)
      ->add(Select::class, 'execute', StatementInterface::class)
      ->add(StatementInterface::class, 'fetchAll', []);

    $databaseTable = new DatabaseTable(
      $connectionChain->getMock(),
      $this->getResource(),
      $this->createStub(LoggerInterface::class),
      $this->createStub(EventDispatcherInterface::class)
    );

    $this->assertEquals([], $databaseTable->query($query));
  }

  /**
   *
   */
  public function testQueryExceptionDatabaseInternalError() {
    $query = new Query();

    $connectionChain = $this->getConnectionChain()
      ->add(Connection::class, 'select', Select::class, 'select_1')
      ->add(Select::class, 'fields', Select::class)
      ->add(Select::class, 'condition', Select::class)
      ->add(Select::class, 'execute', new DatabaseExceptionWrapper("Integrity constraint violation"));

    $databaseTable = new DatabaseTable(
      $connectionChain->getMock(),
      $this->getResource(),
      $this->createStub(LoggerInterface::class),
      $this->createStub(EventDispatcherInterface::class)
    );

    $this->expectExceptionMessage("Database internal error.");
    $databaseTable->query($query);
  }

  /**
   *
   */
  public function testQueryColumnNotFound() {
    $query = new Query();

    $connectionChain = $this->getConnectionChain()
      ->add(Connection::class, 'select', Select::class, 'select_1')
      ->add(Select::class, 'fields', Select::class)
      ->add(Select::class, 'condition', Select::class)
      ->add(Select::class, 'execute', new DatabaseExceptionWrapper("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'sensitive_information'..."));

    $databaseTable = new DatabaseTable(
      $connectionChain->getMock(),
      $this->getResource(),
      $this->createStub(LoggerInterface::class),
      $this->createStub(EventDispatcherInterface::class)
    );

    $this->expectExceptionMessage("Column not found");
    $databaseTable->query($query);
  }

  /**
   *
   */
  public function testNoFulltextIndexFound() {
    $query = new Query();

    $connectionChain = $this->getConnectionChain()
      ->add(Connection::class, 'select', Select::class, 'select_1')
      ->add(Select::class, 'fields', Select::class)
      ->add(Select::class, 'condition', Select::class)
      ->add(Select::class, 'execute', new DatabaseExceptionWrapper("SQLSTATE[HY000]: General error: 1191 Can't find FULLTEXT index matching the column list..."));

    $databaseTable = new DatabaseTable(
      $connectionChain->getMock(),
      $this->getResource(),
      $this->createStub(LoggerInterface::class),
      $this->createStub(EventDispatcherInterface::class)
    );

    $this->expectExceptionMessage("You have attempted a fulltext match against a column that is not indexed for fulltext searching");
    $databaseTable->query($query);
  }

  /**
   * Private.
   */
  private function getConnectionChain() {
    $fieldInfo = [
      (object) [
        'Field' => "record_number",
        'Type' => "int(10)",
        'Extra' => "auto_increment",
      ],
      (object) [
        'Field' => "first_name",
        'Type' => "varchar(10)"
      ],
      (object) [
        'Field' =>
        "last_name",
        'Type' => 'text'
      ]
    ];

    $indexInfo = [
      (object) [
        'Key_name' => "idx1",
        'Column_name' => 'first_name',
        'Index_type' => 'FOO',
      ],
      (object) [
        'Key_name' => "ftx1",
        'Column_name' => 'first_name',
        'Index_type' => 'FULLTEXT',
      ],
      (object) [
        'Key_name' => "ftx2",
        'Column_name' => 'first_name',
        'Index_type' => 'FULLTEXT',
      ],
    ];

    return (new Chain($this))
      // Construction.
      ->add(Connection::class, "schema", Schema::class)
      ->add(Connection::class, 'query', StatementInterface::class)
      ->add(Connection::class, 'getConnectionOptions', ['driver' => 'mysql'])
      ->add(StatementInterface::class, 'fetchAll',
        (new Sequence())->add($fieldInfo)->add($indexInfo)
      )
      ->add(Schema::class, "tableExists", TRUE)
      ->add(Schema::class, 'getComment',
        (new Sequence())->add(NULL)->add('First Name')->add('lAST nAME')
      )
      ->add(Schema::class, 'dropTable', NULL);
  }

  /**
   * Private.
   */
  private function getResource() {
    return new DataResource("", "text/csv");
  }

}
