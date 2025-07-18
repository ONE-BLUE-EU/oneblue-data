<?php

namespace Drupal\Tests\datastore_mysql_import\Kernel\Service;

use Drupal\common\DataResource;
use Drupal\datastore\Service\ImportService;
use Drupal\datastore_mysql_import\Factory\MysqlImportFactory;
use Drupal\datastore_mysql_import\Service\MysqlImport;
use Drupal\datastore_mysql_import\Storage\MySqlDatabaseTable;
use Drupal\KernelTests\KernelTestBase;
use Procrastinator\Result;

/**
 * @covers \Drupal\datastore_mysql_import\Service\MysqlImport
 * @coversDefaultClass \Drupal\datastore_mysql_import\Service\MysqlImport
 *
 * @group datastore_mysql_import
 */
class MysqlImportTest extends KernelTestBase {

  protected const HOST = 'http://example.org';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'common',
    'datastore',
    'datastore_mysql_import',
    'metastore',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    // This setUp method is used so we have access to $this->config.
    parent::setUp();
  }

  public function testTableDuplicateException() {
    $identifier = 'my_id';
    $file_path = dirname(__FILE__, 4) . '/data/columnspaces.csv';
    $data_resource = new DataResource($file_path, 'text/csv');

    $import_factory = $this->container->get('dkan.datastore.service.factory.import');
    $this->assertInstanceOf(MysqlImportFactory::class, $import_factory);

    /** @var \Drupal\datastore_mysql_import\Service\MysqlImport $mysql_import */
    $mysql_import = $import_factory->getInstance(
      $identifier,
      ['resource' => $data_resource]
    )->getImporter();
    $this->assertInstanceOf(MysqlImport::class, $mysql_import);
    $this->assertInstanceOf(MySqlDatabaseTable::class, $mysql_import->getStorage());

    // Store the table.
    $result = $mysql_import->run();
    $this->assertEquals(Result::DONE, $result->getStatus(), $result->getError());

    // Do it again...
    $mysql_import = $import_factory->getInstance(
      $identifier,
      ['resource' => $data_resource]
    )->getImporter();
    // The import job aggressively keeps track of what's already done, so we
    // have to reset that.
    $mysql_import->getResult()->setStatus(Result::IN_PROGRESS);
    // Try to import again. The table should already exist, but no exceptions
    // should be thrown.
    $result = $mysql_import->run();
    $this->assertEquals(Result::DONE, $result->getStatus(), $result->getError());
  }

  /**
   * Test MysqlImport importer.
   */
  public function testMysqlImporter() {
    $identifier = 'my_id';
    $file_path = dirname(__FILE__, 7) . '/tests/data/countries.csv';
    $data_resource = new DataResource($file_path, 'text/csv');

    $import_factory = $this->container->get('dkan.datastore.service.factory.import');
    $this->assertInstanceOf(MysqlImportFactory::class, $import_factory);

    /** @var \Drupal\datastore_mysql_import\Service\MysqlImport $mysql_import */
    $mysql_import = $import_factory->getInstance(
      $identifier,
      ['resource' => $data_resource]
    )->getImporter();
    $this->assertInstanceOf(MysqlImport::class, $mysql_import);
    $this->assertInstanceOf(MySqlDatabaseTable::class, $mysql_import->getStorage());

    // Store the table.
    $result = $mysql_import->run();
    $this->assertEquals(Result::DONE, $result->getStatus(), $result->getError());
  }

  /**
   * Test MysqlImport importer with a CSV file with new lines in it's headers.
   */
  public function testMysqlImporterWithCSVFileWithNewLinesInHeaders() {
    $identifier = 'my_id';
    $file_path = dirname(__FILE__, 7) . '/tests/data/newlines_in_headers.csv';
    $data_resource = new DataResource($file_path, 'text/csv');

    $import_factory = $this->container->get('dkan.datastore.service.factory.import');
    $this->assertInstanceOf(MysqlImportFactory::class, $import_factory);

    $import_service = $import_factory->getInstance(
      $identifier,
      ['resource' => $data_resource]
    );
    $this->assertInstanceOf(ImportService::class, $import_service);
    $import_service->setImporterClass(MockQueryVisibilityImport::class);
    $mysql_import = $import_service->getImporter();
    $this->assertInstanceOf(MockQueryVisibilityImport::class, $mysql_import);
    $this->assertInstanceOf(MySqlDatabaseTable::class, $mysql_import->getStorage());

    // Store the table.
    $result = $mysql_import->run();
    $this->assertEquals(Result::DONE, $result->getStatus(), $result->getError());

    // Two assertions because the table name can change.
    $this->assertStringContainsString(
      'LOAD DATA LOCAL INFILE \'' . $file_path . '\' INTO TABLE {',
      $mysql_import->sqlStatement
    );
    $this->assertStringContainsString(implode(' ', [
      'FIELDS TERMINATED BY \',\'',
      'OPTIONALLY ENCLOSED BY \'"\'',
      'ESCAPED BY \'\'',
      'LINES TERMINATED BY \'\n\'',
      'IGNORE 2 LINES',
      '(a_b,c)',
      'SET record_number = NULL;',
    ]), $mysql_import->sqlStatement);
  }

  /**
   * Tests that the import job can detect when the dataset already exists in the db.
   */
  public function testHasBeenImported() {
    $identifier = 'my_id';
    $file_path = dirname(__FILE__, 7) . '/tests/data/countries.csv';
    $data_resource = new DataResource($file_path, 'text/csv');

    $import_factory = $this->container->get('dkan.datastore.service.factory.import');
    $this->assertInstanceOf(MysqlImportFactory::class, $import_factory);

    /** @var \Drupal\datastore_mysql_import\Service\MysqlImport $mysql_import */
    $mysql_import = $import_factory->getInstance(
      $identifier,
      ['resource' => $data_resource]
    )->getImporter();
    $this->assertInstanceOf(MysqlImport::class, $mysql_import);
    $this->assertInstanceOf(MySqlDatabaseTable::class, $mysql_import->getStorage());

    // Store the table.
    $result = $mysql_import->run();
    $this->assertEquals(Result::DONE, $result->getStatus(), $result->getError());

    // Set up to run the import again, getting a fresh factory and a fresh
    // importer object.
    $import_factory = $this->container->get('dkan.datastore.service.factory.import');
    /** @var \Drupal\datastore_mysql_import\Service\MysqlImport $mysql_import */
    $mysql_import = $import_factory->getInstance(
      $identifier,
      ['resource' => $data_resource]
    )->getImporter();

    // Ensure the table already exists.
    $this->assertTrue($mysql_import->getStorage()->hasBeenImported());
    // Set the status so it's not 'done,' and we can make sure it was changed to
    // 'done' later.
    $mysql_import->getResult()->setStatus(Result::IN_PROGRESS);
    $this->assertEquals(Result::IN_PROGRESS, $mysql_import->getResult()->getStatus());

    // Re-run the import.
    $result = $mysql_import->run();
    $this->assertEquals(Result::DONE, $result->getStatus(), $result->getError());
  }

  /**
   * Test MysqlImport with a CSV file with multiple empty rows and removes.
   */
  public function testMysqlImporterWithRemoveMultipleEmptyRows() {
    $identifier = 'my_id';
    $file_path = dirname(__FILE__, 7) . '/modules/datastore_mysql_import/tests/data/multiple_empty_rows.csv';
    $data_resource = new DataResource($file_path, 'text/csv');

    $import_factory = $this->container->get('dkan.datastore.service.factory.import');
    $this->assertInstanceOf(MysqlImportFactory::class, $import_factory);

    /** @var \Drupal\datastore_mysql_import\Service\MysqlImport $mysql_import */
    $import_service = $import_factory->getInstance(
      $identifier,
      ['resource' => $data_resource]
    );
    $this->assertInstanceOf(ImportService::class, $import_service);
    $import_service->setImporterClass(MockQueryVisibilityImport::class);
    $mysql_import = $import_service->getImporter();
    $this->assertInstanceOf(MockQueryVisibilityImport::class, $mysql_import);
    $this->assertInstanceOf(MySqlDatabaseTable::class, $mysql_import->getStorage());
    $this->toggleEmptyRowRemoval(TRUE);

    // Store the table.
    $mysql_import->run();
    // The row cleaner runs as part of run().
    // Set the config back to off.
    $this->toggleEmptyRowRemoval(FALSE);
    $sql_row_count = $mysql_import->getStorage()->count();

    $this->assertEquals(2, $sql_row_count, "There should be only 2 rows.");
  }

  /**
   * Test MysqlImport with a CSV file with multiple empty rows no removal.
   */
  public function testMysqlImporterWithoutRemoveMultipleEmptyRows() {
    $identifier = 'my_id';
    $file_path = dirname(__FILE__, 7) . '/modules/datastore_mysql_import/tests/data/multiple_empty_rows.csv';
    $data_resource = new DataResource($file_path, 'text/csv');

    $import_factory = $this->container->get('dkan.datastore.service.factory.import');
    $this->assertInstanceOf(MysqlImportFactory::class, $import_factory);

    /** @var \Drupal\datastore_mysql_import\Service\MysqlImport $mysql_import */
    $import_service = $import_factory->getInstance(
      $identifier,
      ['resource' => $data_resource]
    );
    $this->assertInstanceOf(ImportService::class, $import_service);
    $import_service->setImporterClass(MockQueryVisibilityImport::class);
    $mysql_import = $import_service->getImporter();
    $this->assertInstanceOf(MockQueryVisibilityImport::class, $mysql_import);
    $this->assertInstanceOf(MySqlDatabaseTable::class, $mysql_import->getStorage());
    $this->toggleEmptyRowRemoval(FALSE);
    // Store the table.
    $mysql_import->run();
    $sql_row_count = $mysql_import->getStorage()->count();

    $this->assertEquals(5, $sql_row_count, "There should be 5 rows.");
  }

  /**
   * Toggle the empty row remover setting on or off.
   *
   * @param bool $on
   * The value TRUE or FALSE. TRUE to enable the row removal.
   */
  protected function toggleEmptyRowRemoval(bool $on):void {
    $config = $this->config('datastore_mysql_import.settings');
    $config->set('remove_empty_rows', $on);
    $config->save();
  }

  /**
   * Test MysqlImport importer with an empty CSV file.
   */
  public function testMysqlImporterWithEmptyCSVFile() {
    $identifier = 'my_id';
    $file_path = dirname(__FILE__, 7) . '/tests/data/empty.csv';
    $data_resource = new DataResource($file_path, 'text/csv');

    $import_factory = $this->container->get('dkan.datastore.service.factory.import');
    $this->assertInstanceOf(MysqlImportFactory::class, $import_factory);

    $import_service = $import_factory->getInstance(
      $identifier,
      ['resource' => $data_resource]
    );
    $this->assertInstanceOf(ImportService::class, $import_service);
    $import_service->setImporterClass(MockQueryVisibilityImport::class);
    $mysql_import = $import_service->getImporter();
    $this->assertInstanceOf(MockQueryVisibilityImport::class, $mysql_import);
    $this->assertInstanceOf(MySqlDatabaseTable::class, $mysql_import->getStorage());

    // Run the import and confirm result is valid albeit an error.
    $result = $mysql_import->run();
    $this->assertEquals(Result::ERROR, $result->getStatus());
    $this->assertStringStartsWith("Can't get size from file", $result->getError());
  }

}

class MockQueryVisibilityImport extends MysqlImport {

  public $sqlStatement = '';

  protected function getSqlStatement(string $file_path, string $table_name, array $headers, string $eol, int $header_line_count, string $delimiter): string {
    $this->sqlStatement = parent::getSqlStatement($file_path, $table_name, $headers, $eol, $header_line_count, $delimiter);
    return $this->sqlStatement;
  }

}
