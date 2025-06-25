<?php

namespace Drupal\Tests\datastore\Unit\Form;

use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\DependencyInjection\Container;
use Drupal\Core\Form\FormState;
use Drupal\Core\Pager\Pager;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Path\PathValidator;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\Core\StringTranslation\TranslationManager;
use Drupal\Tests\metastore\Unit\MetastoreServiceTest;
use Drupal\common\DatasetInfo;
use Drupal\Core\Database\Connection;
use Drupal\datastore\Form\DashboardForm;
use Drupal\datastore\Service\PostImport;
use Drupal\harvest\Entity\HarvestRunRepository;
use Drupal\harvest\HarvestService;
use Drupal\metastore\MetastoreService;
use MockChain\Chain;
use MockChain\Options;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\metastore\ResourceMapper;
use Drupal\datastore\PostImportResult;
use Drupal\datastore\PostImportResultFactory;
use Drupal\common\DataResource;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\node\NodeInterface;

/**
 * @group dkan
 * @group datastore
 * @group unit
 */
class DashboardFormTest extends TestCase {

  /**
   * The ValidMetadataFactory class used for testing.
   *
   * @var \Drupal\metastore\ValidMetadataFactory|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $validMetadataFactory;

  public function setUp(): void {
    parent::setUp();
    $this->validMetadataFactory = MetastoreServiceTest::getValidMetadataFactory($this);
  }

  /**
   * Test that the correct form filter fields are added.
   */
  public function testBuildFilters(): void {
    $container = $this->buildContainerChain()
      ->add(RequestStack::class, 'getCurrentRequest', new Request(['uuid' => 'test']))
      ->getMock();
    \Drupal::setContainer($container);

    $form = DashboardForm::create($container);
    $form = $form->buildForm([], new FormState());

    // Assert
    $this->assertEquals('textfield', $form['filters']['uuid']['#type']);
    $this->assertEquals('select', $form['filters']['harvest_id']['#type']);
    $this->assertEquals('actions', $form['filters']['actions']['#type']);
    $this->assertEquals('submit', $form['filters']['actions']['submit']['#type']);
  }

  /**
   * Test that the correct form table elements are added.
   */
  public function testBuildTable(): void {
    $container = $this->buildContainerChain()
      ->add(RequestStack::class, 'getCurrentRequest', new Request(['uuid' => 'test']))
      ->getMock();
    \Drupal::setContainer($container);

    $form = DashboardForm::create($container)->buildForm([], new FormState());

    // Assert
    $this->assertEquals('table', $form['table']['#theme']);
    $this->assertEquals('pager', $form['pager']['#type']);
  }

  /**
   * Test that a table built with no datasets has no rows.
   */
  public function testBuildTableRowsWithNoDatasets(): void {
    $container = $this->buildContainerChain()->getMock();
    \Drupal::setContainer($container);

    $form = DashboardForm::create($container)->buildForm([], new FormState());

    $this->assertEmpty($form['table']['#rows']);
  }

  /**
   * Test building the dashboard table with a harvest ID filter.
   */
  public function testBuildTableRowsWithHarvestIdFilter() {
    $info = [
      'uuid' => 'dataset-1',
      'title' => 'Dataset 1',
      'revision_id' => '2',
      'moderation_state' => 'published',
      'modified_date_metadata' => '2020-01-15',
      'modified_date_dkan' => '2021-02-11',
    ];
    $distribution = [
      'distribution_uuid' => 'dist-1',
      'resource_id' => '9ad17d45894f823c6a8e4f6d32b9535f',
      'resource_version' => '1679508886',
      'fetcher_status' => 'done',
      'mime_type' => 'text/csv',
      'fetcher_percent_done' => 100,
      'importer_status' => 'done',
      'importer_percent_done' => 100,
      'importer_error' => '',
      'source_path' => 'http://example.com/file.csv',
    ];

    $postImportInfo = [
      'resource_version' => '1679508886',
      'post_import_status' => 'done',
      'post_import_error' => NULL,
    ];

    $connectionMock = $this->createMock(Connection::class);
    $resourceMappermock = $this->createMock(ResourceMapper::class);
    $dataResourceMock = $this->createMock(DataResource::class);
    $postImportResultMock = $this->getMockBuilder(PostImportResult::class)
      ->setConstructorArgs(['', '', NULL, $dataResourceMock, $connectionMock, $resourceMappermock])
      ->onlyMethods(['retrieveJobStatus'])
      ->getMock();

    $postImportResultMock->method('retrieveJobStatus')->willReturn($postImportInfo);

    $container = $this->buildContainerChain()
      ->add(RequestStack::class, 'getCurrentRequest', new Request(['harvest_id' => 'dataset-1']))
      ->add(DatasetInfo::class, 'gather', ['latest_revision' => $info + ['distributions' => [$distribution]]])
      ->add(PostImportResultFactory::class, 'initializeFromDistribution', $postImportResultMock)
      ->getMock();
    \Drupal::setContainer($container);
    $form = DashboardForm::create($container)->buildForm([], new FormState());

    $this->assertEquals(1, count($form['table']['#rows']));
    $this->assertEquals('dataset-1', $form['table']['#rows'][0][0]['data']['#uuid']);
    $this->assertEquals('Dataset 1', $form['table']['#rows'][0][0]['data']['#title']);
    $this->assertEquals('NEW', $form['table']['#rows'][0][2]['data']);
    $this->assertEquals('done', $form['table']['#rows'][0][6]['data']['#status']);
    $this->assertEquals(NULL, $form['table']['#rows'][0][6]['data']['#error']);
  }

  /**
   * Test building the dashboard table with a Dataset Title filter.
   */
  public function testBuildTableRowsWithDatasetTitleFilter() {
    $info = [
      'uuid' => 'dataset-1',
      'title' => 'Dataset 1',
      'revision_id' => '2',
      'moderation_state' => 'published',
      'modified_date_metadata' => '2020-01-15',
      'modified_date_dkan' => '2021-02-11',
    ];
    $distribution = [
      'distribution_uuid' => 'dist-1',
      'resource_id' => '9ad17d45894f823c6a8e4f6d32b9535f',
      'resource_version' => '1679508886',
      'fetcher_status' => 'done',
      'fetcher_percent_done' => 100,
      'mime_type' => 'text/csv',
      'importer_status' => 'done',
      'importer_percent_done' => 100,
      'importer_error' => '',
      'source_path' => 'http://example.com/file.csv',
    ];

    $postImportInfo = [
      'resource_version' => '1679508886',
      'post_import_status' => 'done',
      'post_import_error' => NULL,
    ];

    $connectionMock = $this->createMock(Connection::class);
    $resourceMappermock = $this->createMock(ResourceMapper::class);
    $dataResourceMock = $this->createMock(DataResource::class);
    $postImportResultMock = $this->getMockBuilder(PostImportResult::class)
      ->setConstructorArgs(['', '', NULL, $dataResourceMock, $connectionMock, $resourceMappermock])
      ->onlyMethods(['retrieveJobStatus'])
      ->getMock();

    $postImportResultMock->method('retrieveJobStatus')->willReturn($postImportInfo);

    $nodeMock = (new Chain($this))
      ->add(NodeInterface::class, 'uuid', 'dataset-1')
      ->getMock();

    $container = $this->buildContainerChain()
      ->add(EntityTypeManagerInterface::class, 'getStorage', EntityStorageInterface::class)
      ->add(RequestStack::class, 'getCurrentRequest', new Request(['dataset_title' => 'Dataset 1']))
      ->add(EntityStorageInterface::class, 'getQuery', QueryInterface::class)
      ->add(EntityStorageInterface::class, 'loadMultiple', [$nodeMock])
      ->add(QueryInterface::class, 'accessCheck', QueryInterface::class)
      ->add(QueryInterface::class, 'condition', QueryInterface::class)
      ->add(QueryInterface::class, 'execute', [$nodeMock])
      ->add(DatasetInfo::class, 'gather', ['latest_revision' => $info + ['distributions' => [$distribution]]])
      ->add(PostImportResultFactory::class, 'initializeFromDistribution', $postImportResultMock)
      ->getMock();
    \Drupal::setContainer($container);
    $form = DashboardForm::create($container)->buildForm([], new FormState());

    $this->assertEquals(1, count($form['table']['#rows']));
    $this->assertEquals('dataset-1', $form['table']['#rows'][0][0]['data']['#uuid']);
    $this->assertEquals('Dataset 1', $form['table']['#rows'][0][0]['data']['#title']);
    $this->assertEquals('NEW', $form['table']['#rows'][0][2]['data']);
    $this->assertEquals('done', $form['table']['#rows'][0][6]['data']['#status']);
    $this->assertEquals(NULL, $form['table']['#rows'][0][6]['data']['#error']);
  }

  /**
   * Test building the dashboard table with a UUID filter.
   */
  public function testBuildTableRowsWithUuidFilter() {
    $info = [
      'uuid' => 'test',
      'title' => 'Title',
      'revision_id' => '2',
      'moderation_state' => 'published',
      'modified_date_metadata' => '2020-01-15',
      'modified_date_dkan' => '2021-02-11',
    ];
    $distribution = [
      'distribution_uuid' => 'dist-1',
      'resource_id' => '9ad17d45894f823c6a8e4f6d32b9535f',
      'resource_version' => '1679508886',
      'fetcher_status' => 'done',
      'fetcher_percent_done' => 100,
      'mime_type' => 'text/csv',
      'importer_status' => 'done',
      'importer_percent_done' => 100,
      'importer_error' => '',
      'source_path' => 'http://example.com/file.csv',
    ];

    $postImportInfo = [
      'resource_version' => '1679508885',
      'post_import_status' => 'N/A',
      'post_import_error' => 'Data-Dictionary Disabled',
    ];

    $connectionMock = $this->createMock(Connection::class);
    $resourceMappermock = $this->createMock(ResourceMapper::class);
    $dataResourceMock = $this->createMock(DataResource::class);
    $postImportResultMock = $this->getMockBuilder(PostImportResult::class)
      ->setConstructorArgs(['', '', NULL, $dataResourceMock, $connectionMock, $resourceMappermock])
      ->onlyMethods(['retrieveJobStatus'])
      ->getMock();

    $postImportResultMock->method('retrieveJobStatus')->willReturn($postImportInfo);

    $container = $this->buildContainerChain()
      ->add(RequestStack::class, 'getCurrentRequest', new Request(['uuid' => 'test']))
      ->add(DatasetInfo::class, 'gather', ['latest_revision' => $info + ['distributions' => [$distribution]]])
      ->add(PostImportResultFactory::class, 'initializeFromDistribution', $postImportResultMock)
      ->getMock();
    \Drupal::setContainer($container);
    $form = DashboardForm::create($container)->buildForm([], new FormState());

    $this->assertEquals(1, count($form['table']['#rows']));
    $this->assertEquals('test', $form['table']['#rows'][0][0]['data']['#uuid']);
    $this->assertEquals('Title', $form['table']['#rows'][0][0]['data']['#title']);
    $this->assertEquals('N/A', $form['table']['#rows'][0][2]['data']);

    // Assert that the post import failed because the data dictionary mode is disabled.
    $this->assertEquals('N/A', $form['table']['#rows'][0][6]['data']['#status']);
    $this->assertEquals($postImportInfo['post_import_error'], $form['table']['#rows'][0][6]['data']['#error']);
  }

  /**
   * Test building the dashboard table without a filter.
   */
  public function testBuildTableRowsWithAllDatasets() {
    $datasetInfo = [
      'latest_revision' => [
        'uuid' => 'dataset-1',
        'revision_id' => '1',
        'moderation_state' => 'published',
        'title' => 'Dataset 1',
        'modified_date_metadata' => '2019-08-12',
        'modified_date_dkan' => '2021-07-08',
        'distributions' => [
          [
            'distribution_uuid' => 'dist-1',
            'resource_id' => '9ad17d45894f823c6a8e4f6d32b9535f',
            'resource_version' => '1679508886',
            'fetcher_status' => 'waiting',
            'fetcher_percent_done' => 0,
            'mime_type' => 'text/csv',
            'importer_status' => 'waiting',
            'importer_percent_done' => 0,
            'importer_error' => '',
            'source_path' => 'http://example.com/file.csv',
          ],
        ],
      ],
    ];

    $nonHarvestDatasetInfo = [
      'latest_revision' => [
        'uuid' => 'non-harvest-dataset',
        'revision_id' => '1',
        'moderation_state' => 'published',
        'title' => 'Non-Harvest Dataset',
        'modified_date_metadata' => '2019-08-12',
        'modified_date_dkan' => '2021-07-08',
        'distributions' => [
          [
            'distribution_uuid' => 'dist-2',
            'resource_id' => '9ad17d45894f823c6a8e4f6d32b9535e',
            'resource_version' => '1679508885',
            'fetcher_status' => 'done',
            'fetcher_percent_done' => 100,
            'mime_type' => 'text/csv',
            'importer_status' => 'done',
            'importer_percent_done' => 100,
            'importer_error' => '',
            'source_path' => 'http://example.com/file2.csv',
          ],
        ],
      ],
    ];

    $postImportInfo = [
      'resource_version' => '1679508885',
      'post_import_status' => 'error',
      'post_import_error' => "SQLSTATE[HY000]: General error: 1411 Incorrect datetime value: '09/07/2017 12:00:00 AM' for function str_to_date: UPDATE 'datastore_7c3d88c04bb011fa80d6b4612978c9b1' SET 'reactivation_date'=STR_TO_DATE(reactivation_date, :date_format); Array ( [:date_format] => %m/%d/%Y %H:%i:%s %p )",
    ];

    $connectionMock = $this->createMock(Connection::class);
    $resourceMappermock = $this->createMock(ResourceMapper::class);
    $dataResourceMock = $this->createMock(DataResource::class);
    $postImportResultMock = $this->getMockBuilder(PostImportResult::class)
      ->setConstructorArgs(['', '', NULL, $dataResourceMock, $connectionMock, $resourceMappermock])
      ->onlyMethods(['retrieveJobStatus'])
      ->getMock();

    $postImportResultMock->method('retrieveJobStatus')->willReturn($postImportInfo);

    $datasetInfoOptions = (new Options())
      ->add('dataset-1', $datasetInfo)
      ->add('non-harvest-dataset', $nonHarvestDatasetInfo);

    $container = $this->buildContainerChain()
      ->add(MetastoreService::class, 'count', 2)
      ->add(MetastoreService::class, 'getIdentifiers', [$datasetInfo['latest_revision']['uuid'], $nonHarvestDatasetInfo['latest_revision']['uuid']])
      ->add(DatasetInfo::class, 'gather', $datasetInfoOptions)
      ->add(PostImportResultFactory::class, 'initializeFromDistribution', $postImportResultMock);

    \Drupal::setContainer($container->getMock());
    $form = DashboardForm::create($container->getMock())->buildForm([], new FormState());

    // Assert that there are both datasets: harvested and non-harvested.
    $this->assertEquals(2, count($form['table']['#rows']));

    $this->assertEquals('dataset-1', $form['table']['#rows'][0][0]['data']['#uuid']);
    $this->assertEquals('Dataset 1', $form['table']['#rows'][0][0]['data']['#title']);
    $this->assertEquals('NEW', $form['table']['#rows'][0][2]['data']);

    // Assert that the post import process failed with an error
    $this->assertEquals('error', $form['table']['#rows'][0][6]['data']['#status']);
    $this->assertEquals($postImportInfo['post_import_error'], $form['table']['#rows'][0][6]['data']['#error']);

    $this->assertEquals('non-harvest-dataset', $form['table']['#rows'][1][0]['data']['#uuid']);
    $this->assertEquals('Non-Harvest Dataset', $form['table']['#rows'][1][0]['data']['#title']);
    $this->assertEquals('N/A', $form['table']['#rows'][1][2]['data']);
  }

  /**
   * Test building the dashboard table for a dataset without a distribution.
   */
  public function testBuildTableRowsDatasetWithNoDistribution() {
    $datasetInfo = [
      'latest_revision' => [
        'uuid' => 'dataset-1',
        'revision_id' => '1',
        'moderation_state' => 'published',
        'title' => 'Dataset 1',
        'modified_date_metadata' => '2019-08-12',
        'modified_date_dkan' => '2021-07-08',
        'distributions' => ['Not found'],
      ],
    ];

    $container = $this->buildContainerChain()
      ->add(MetastoreService::class, 'count', 1)
      ->add(MetastoreService::class, 'getIdentifiers', [$datasetInfo['latest_revision']['uuid']])
      ->add(DatasetInfo::class, 'gather', $datasetInfo)
      ->getMock();
    \Drupal::setContainer($container);

    $form = DashboardForm::create($container)->buildForm([], new FormState());
    $this->assertEmpty($form['table']['#rows'][0][3]);
  }

  /**
   * Test building the dashboard table for a dataset w/ unsupported resource types.
   */
  public function testBuildTableRowsWithUnsupportedResourceTypes() {
    $datasetInfo = [
      'latest_revision' => [
        'uuid' => 'dataset-1',
        'revision_id' => '1',
        'moderation_state' => 'published',
        'title' => 'Dataset 1',
        'modified_date_metadata' => '2019-08-12',
        'modified_date_dkan' => '2021-07-08',
        'distributions' => [
          [
            'distribution_uuid' => 'dist-1',
            'resource_id' => '9ad17d45894f823c6a8e4f6d32b9535g',
            'resource_version' => '1679508886',
            'fetcher_status' => 'waiting',
            'fetcher_percent_done' => 0,
            'mime_type' => 'application/pdf',
            'importer_status' => 'waiting',
            'importer_percent_done' => 0,
            'importer_error' => '',
            'source_path' => 'http://example.com/file.pdf',
          ],
          [
            'distribution_uuid' => 'dist-2',
            'resource_id' => '9ad17d45894f823c6a8e4f6d32b9535g',
            'resource_version' => '1679508886',
            'fetcher_status' => 'waiting',
            'fetcher_percent_done' => 0,
            'mime_type' => 'text/csv',
            'importer_status' => 'waiting',
            'importer_percent_done' => 0,
            'importer_error' => '',
            'source_path' => 'http://example.com/file2.csv',
          ],
        ],
      ],
    ];

    $postImportInfo = [
      'resource_version' => '1679508885',
      'post_import_status' => 'done',
      'post_import_error' => NULL,
    ];

    $connectionMock = $this->createMock(Connection::class);
    $resourceMappermock = $this->createMock(ResourceMapper::class);
    $dataResourceMock = $this->createMock(DataResource::class);
    $postImportResultMock = $this->getMockBuilder(PostImportResult::class)
      ->setConstructorArgs(['', '', NULL, $dataResourceMock, $connectionMock, $resourceMappermock])
      ->onlyMethods(['retrieveJobStatus'])
      ->getMock();

    $postImportResultMock->method('retrieveJobStatus')->willReturn($postImportInfo);

    $container = $this->buildContainerChain()
      ->add(MetastoreService::class, 'count', 1)
      ->add(MetastoreService::class, 'getIdentifiers', [$datasetInfo['latest_revision']['uuid']])
      ->add(DatasetInfo::class, 'gather', $datasetInfo)
      ->add(PostImportResultFactory::class, 'initializeFromDistribution', $postImportResultMock)
      ->getMock();
    \Drupal::setContainer($container);

    $form = DashboardForm::create($container)->buildForm([], new FormState());

    // Table has two rows.
    $this->assertEquals(2, count($form['table']['#rows']));

    // Table uuid for first row is correct.
    $this->assertEquals('dist-1', $form['table']['#rows'][0][3]['data']['#uuid']);
    // First row has six columns.
    $this->assertEquals(5, count($form['table']['#rows'][0]));
    // Rowspan on first two columns.
    $this->assertEquals(2, $form['table']['#rows'][0][1]['rowspan']);
    // Unsupported mime type class on resource column.
    $this->assertEquals("unsupported", $form["table"]["#rows"][0][3]["class"]);
    // Colspan on last 3 columns.
    $this->assertEquals(3, $form["table"]["#rows"][0][4]["colspan"]);
    // Unsupported mime type class on last column.
    $this->assertEquals("unsupported", $form["table"]["#rows"][0][4]["class"]);
    // Status on last column displays correct status for unsupported mime type.
    $this->assertEquals("Data import is not supported for this resource type", $form["table"]["#rows"][0][4]["data"]["#status"]);

    // The second row has only four columns.
    $this->assertEquals(4, count($form['table']['#rows'][1]));
    // Table uuid for first second row is correct.
    $this->assertEquals('dist-2', $form['table']['#rows'][1][0]['data']['#uuid']);
    // The second row fetch status is correct.
    $this->assertEquals('waiting', $form["table"]["#rows"][1][1]["data"]["#status"]);
    // The second row fetch class is correct.
    $this->assertEquals('waiting', $form["table"]["#rows"][1][1]["class"]);
    // The second row store status is correct.
    $this->assertEquals('waiting', $form["table"]["#rows"][1][2]["data"]["#status"]);
    // The second row store class is correct.
    $this->assertEquals('waiting', $form["table"]["#rows"][1][2]["class"]);
    // The second row post import status is correct.
    $this->assertEquals('done', $form["table"]["#rows"][1][3]["data"]["#status"]);
    // The second row post import class is correct.
    $this->assertEquals('done', $form["table"]["#rows"][1][3]["class"]);
  }

  /**
   * Test building the dashboard table for a dataset without a distribution.
   */
  public function testBuildTableRowsDatasetMultipleDistribution() {
    $datasetInfo = [
      'latest_revision' => [
        'uuid' => 'dataset-1',
        'revision_id' => '1',
        'moderation_state' => 'published',
        'title' => 'Dataset 1',
        'modified_date_metadata' => '2019-08-12',
        'modified_date_dkan' => '2021-07-08',
        'distributions' => [
          [
            'distribution_uuid' => 'dist-1',
            'resource_id' => '9ad17d45894f823c6a8e4f6d32b9535g',
            'resource_version' => '1679508886',
            'fetcher_status' => 'waiting',
            'fetcher_percent_done' => 0,
            'mime_type' => 'text/csv',
            'importer_status' => 'waiting',
            'importer_percent_done' => 0,
            'importer_error' => '',
            'source_path' => 'http://example.com/file.csv',
          ],
          [
            'distribution_uuid' => 'dist-2',
            'resource_id' => '9ad17d45894f823c6a8e4f6d32b9535g',
            'resource_version' => '1679508886',
            'fetcher_status' => 'done',
            'fetcher_percent_done' => 100,
            'mime_type' => 'text/csv',
            'importer_status' => 'done',
            'importer_percent_done' => 100,
            'importer_error' => '',
            'source_path' => 'http://example.com/file2.csv',
          ],
        ],
      ],
    ];

    $postImportInfo = [
      'resource_version' => '1679508885',
      'post_import_status' => 'done',
      'post_import_error' => NULL,
    ];

    $connectionMock = $this->createMock(Connection::class);
    $resourceMappermock = $this->createMock(ResourceMapper::class);
    $dataResourceMock = $this->createMock(DataResource::class);
    $postImportResultMock = $this->getMockBuilder(PostImportResult::class)
      ->setConstructorArgs(['', '', NULL, $dataResourceMock, $connectionMock, $resourceMappermock])
      ->onlyMethods(['retrieveJobStatus'])
      ->getMock();

    $postImportResultMock->method('retrieveJobStatus')->willReturn($postImportInfo);

    $container = $this->buildContainerChain()
      ->add(MetastoreService::class, 'count', 1)
      ->add(MetastoreService::class, 'getIdentifiers', [$datasetInfo['latest_revision']['uuid']])
      ->add(DatasetInfo::class, 'gather', $datasetInfo)
      ->add(PostImportResultFactory::class, 'initializeFromDistribution', $postImportResultMock)
      ->getMock();
    \Drupal::setContainer($container);

    $form = DashboardForm::create($container)->buildForm([], new FormState());
    $this->assertEquals(2, count($form['table']['#rows']));
    // First row has six columns and rowspan on first two
    $this->assertEquals(7, count($form['table']['#rows'][0]));
    $this->assertEquals(2, $form['table']['#rows'][0][1]['rowspan']);
    // The second row has only three columns.
    $this->assertEquals(4, count($form['table']['#rows'][1]));

    $this->assertEquals('dist-1', $form['table']['#rows'][0][3]['data']['#uuid']);
    $this->assertEquals('dist-2', $form['table']['#rows'][1][0]['data']['#uuid']);
    $this->assertEquals('done', $form['table']['#rows'][0][6]['data']['#status']);
    $this->assertEquals(NULL, $form['table']['#rows'][0][6]['data']['#error']);
  }

  /**
   * Build container mock chain object.
   */
  private function buildContainerChain(): Chain {
    $options = (new Options())
      ->add('dkan.harvest.service', HarvestService::class)
      ->add('dkan.harvest.storage.harvest_run_repository', HarvestRunRepository::class)
      ->add('dkan.common.dataset_info', DatasetInfo::class)
      ->add('dkan.metastore.service', MetastoreService::class)
      ->add('pager.manager', PagerManagerInterface::class)
      ->add('request_stack', RequestStack::class)
      ->add('string_translation', TranslationManager::class)
      ->add('date.formatter', DateFormatter::class)
      ->add('path.validator', PathValidator::class)
      ->add('stream_wrapper_manager', StreamWrapperManager::class)
      ->add('dkan.datastore.service.post_import', PostImport::class)
      ->add('dkan.harvest.storage.harvest_run_repository', HarvestRunRepository::class)
      ->add('database', Connection::class)
      ->add('dkan.datastore.post_import_result_factory', PostImportResultFactory::class)
      ->add('entity.storage.interface', EntityStorageInterface::class)
      ->add('entity_type.manager', EntityTypeManagerInterface::class)
      ->index(0);

    $runStatus = [
      'status' => [
        'extract' => 'SUCCESS',
        'load' => [
          'dataset-1' => 'NEW',
        ],
      ],
    ];

    $runInfo = (new Options())
      ->add(['dataset-1', 'test'], json_encode($runStatus))
      ->add(['test', 'test'], json_encode([]));

    return (new Chain($this))
      ->add(Container::class, 'get', $options)
      ->add(DatasetInfo::class, 'gather', ['notice' => 'Not found'])
      ->add(HarvestService::class, 'getAllHarvestIds', ['test', 'dataset-1'])
      ->add(HarvestService::class, 'getRunIdsForHarvest', ['test'])
      ->add(HarvestService::class, 'getHarvestRunInfo', $runInfo)
      ->add(HarvestService::class, 'getLastHarvestRunId', 'huh')
      ->add(HarvestService::class, 'getHarvestRunResult', $runStatus)
      ->add(MetastoreService::class, 'count', 0)
      ->add(MetastoreService::class, 'getIdentifiers', [])
      ->add(PagerManagerInterface::class, 'createPager', Pager::class)
      ->add(DateFormatter::class, 'format', '12/31/2021')
      ->add(PathValidator::class, 'getUrlIfValidWithoutAccessCheck', NULL)
      ->add(StreamWrapperManager::class, 'getViaUri', PublicStream::class)
      ->add(PublicStream::class, 'getExternalUrl', 'http://example.com')
      ->add(Pager::class, 'getCurrentPage', 0)
      ->add(EntityTypeManagerInterface::class, 'getStorage', EntityStorageInterface::class);
  }

}
