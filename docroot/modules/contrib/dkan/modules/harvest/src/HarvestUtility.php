<?php

namespace Drupal\harvest;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Database\Connection;
use Drupal\harvest\Entity\HarvestRunRepository;
use Drupal\harvest\Storage\DatabaseTableFactory;
use Drupal\harvest\Storage\HarvestHashesDatabaseTableFactory;
use Psr\Log\LoggerInterface;

/**
 * DKAN Harvest utility service for maintenance tasks.
 *
 * These methods generally exist to support a thin Drush layer or hook_update_n.
 * These are methods that we don't need in the HarvestService object.
 */
class HarvestUtility {

  /**
   * Harvest service.
   */
  private HarvestService $harvestService;

  /**
   * Service to instantiate storage objects for Harvest tables.
   */
  private DatabaseTableFactory $storeFactory;

  /**
   * Database connection.
   */
  private Connection $connection;

  /**
   * Harvest run entity repository service.
   */
  private HarvestRunRepository $runRepository;

  /**
   * The harvest hashes database table factory service.
   */
  private HarvestHashesDatabaseTableFactory $hashesFactory;

  /**
   * Logger channel service.
   */
  private LoggerInterface $logger;

  /**
   * Uuid service.
   *
   * @var \Drupal\Component\Uuid\UuidInterface
   */
  private UuidInterface $uuidService;

  /**
   * Constructor.
   */
  public function __construct(
    HarvestService $harvestService,
    DatabaseTableFactory $storeFactory,
    HarvestHashesDatabaseTableFactory $hashesFactory,
    HarvestRunRepository $runRepository,
    Connection $connection,
    LoggerInterface $loggerChannel,
    UuidInterface $uuid_service
  ) {
    $this->harvestService = $harvestService;
    $this->storeFactory = $storeFactory;
    $this->hashesFactory = $hashesFactory;
    $this->runRepository = $runRepository;
    $this->connection = $connection;
    $this->logger = $loggerChannel;
    $this->uuidService = $uuid_service;
  }

  /**
   * Get the plan ID from a given harvest table name.
   *
   * Harvest table names are assumed to look like this:
   * harvest_planID_that_might_have_underscores_[type], where [type] is one of
   * hashes, items, or runs. For example: 'harvest_ABC_123_runs'.
   *
   * @param string $table_name
   *   The table name.
   *
   * @return string
   *   The ID gleaned from the table name. If no ID could be gleaned, returns
   *   an empty string.
   */
  public static function planIdFromTableName(string $table_name): string {
    $name_explode = explode('_', $table_name);
    if (count($name_explode) < 3) {
      return '';
    }
    // Remove first and last item.
    array_shift($name_explode);
    array_pop($name_explode);
    return implode('_', $name_explode);
  }

  /**
   * Find harvest IDs with data tables that aren't in the harvest_plans table.
   *
   * @return array
   *   Array of orphan plan ids, as both key and value. Empty if there are no
   *   orphaned plan ids.
   */
  public function findOrphanedHarvestDataIds(): array {
    $orphan_ids = [];

    // Plan IDs from the plans table.
    $existing_plans = $this->harvestService->getAllHarvestIds();

    // Potential orphan plan IDs in the runs table.
    $run_ids = $this->runRepository->getUniqueHarvestPlanIds();
    foreach (array_diff($run_ids, $existing_plans) as $run_id) {
      $orphan_ids[$run_id] = $run_id;
    }

    // Use harvest data table names to glean more potential orphan harvest plan
    // ids.
    foreach ($this->findAllHarvestDataTables() as $table_name) {
      $plan_id = static::planIdFromTableName($table_name);
      if (!in_array($plan_id, $existing_plans)) {
        $orphan_ids[$plan_id] = $plan_id;
      }
    }
    return $orphan_ids;
  }

  /**
   * Find all the potential harvest data tables names in the database.
   *
   * @return array
   *   All the table names that might be harvest data tables.
   */
  protected function findAllHarvestDataTables(): array {
    $tables = [];
    foreach ([
      'harvest_%_runs',
      'harvest_%_items',
      'harvest_%_hashes',
    ] as $table_expression) {
      if ($found_tables = $this->connection->schema()->findTables($table_expression)) {
        $tables = array_merge($tables, $found_tables);
      }
    }
    return $tables;
  }

  /**
   * Remove existing harvest data tables for the given plan identifier.
   *
   * Will not remove data tables for existing plans.
   *
   * @param string $plan_id
   *   Plan identifier to work with.
   */
  public function destructOrphanTables(string $plan_id): void {
    if (!in_array($plan_id, $this->harvestService->getAllHarvestIds())) {
      foreach ([
        'harvest_' . $plan_id . '_runs',
        'harvest_' . $plan_id . '_items',
        'harvest_' . $plan_id . '_hashes',
      ] as $table) {
        $this->storeFactory->getInstance($table)->destruct();
      }
    }
  }

  /**
   * Convert a table to use the harvest_hash entity.
   *
   * @param string $plan_id
   *   Harvest plan ID to convert.
   */
  public function convertHashTable(string $plan_id) {
    $old_hash_table = $this->storeFactory->getInstance('harvest_' . $plan_id . '_hashes');
    $hash_table = $this->hashesFactory->getInstance($plan_id);
    foreach ($old_hash_table->retrieveAll() as $id) {
      if ($data = $old_hash_table->retrieve($id)) {
        $hash_table->store($data, $id);
      }
    }
  }

  /**
   * Update all the harvest hash tables to use entities.
   *
   * This will move all harvest hash information to the updated schema,
   * including data which does not have a corresponding hash plan ID.
   *
   * Outdated tables will be removed.
   */
  public function harvestHashUpdate() {
    $plan_ids = array_merge(
      $this->harvestService->getAllHarvestIds(),
      array_values($this->findOrphanedHarvestDataIds())
    );
    foreach ($plan_ids as $plan_id) {
      $this->logger->notice('Converting hashes for ' . $plan_id);
      $this->convertHashTable($plan_id);
      $this->storeFactory->getInstance('harvest_' . $plan_id . '_hashes')
        ->destruct();
    }
  }

  /**
   * Convert a table to use the harvest_run entity.
   *
   * @param string $plan_id
   *   Harvest plan ID to convert.
   */
  public function convertRunTable(string $plan_id) {
    $old_runs_table = $this->storeFactory->getInstance('harvest_' . $plan_id . '_runs');
    foreach ($old_runs_table->retrieveAll() as $timestamp) {
      if ($data = $old_runs_table->retrieve($timestamp)) {
        // Explicitly decode the data as an array.
        $this->runRepository->storeRun(json_decode((string) $data, TRUE), $plan_id, $timestamp);
      }
    }
  }

  /**
   * Update all the harvest run tables to use entities.
   *
   * Outdated tables will be removed.
   */
  public function harvestRunsUpdate() {
    $plan_ids = array_merge(
      $this->harvestService->getAllHarvestIds(),
      array_values($this->findOrphanedHarvestDataIds())
    );
    foreach ($plan_ids as $plan_id) {
      $this->logger->notice('Converting runs for ' . $plan_id);
      $this->convertRunTable($plan_id);
      $this->storeFactory->getInstance('harvest_' . $plan_id . '_runs')
        ->destruct();
    }
  }

  /**
   * Update extract type namespace.
   *
   * @param string $plan_id
   *   The harvest plan to update.
   */
  public function updateTypeNamespace(string $plan_id) {
    try {
      $plan = $this->harvestService->getHarvestPlanObject($plan_id);
      $this->logger->notice(json_encode($plan));

      if (isset($plan->extract->type)) {
        $plan->extract->type = str_replace('\\Harvest\\ETL', '\\Drupal\\harvest\\ETL', $plan->extract->type);
        $this->logger->notice($plan->extract->type);
        $this->harvestService->registerHarvest($plan);
      }
    }
    catch (\Exception $exception) {
      $this->logger->notice("Namespace update failed for %id: %msg",
        ['%id' => $plan_id, '%msg' => $exception->getMessage()]);
    }
  }

  /**
   * Update the extract type namespace for all plans.
   */
  public function harvestNammespaceUpdate() {
    $plan_ids = array_merge(
      $this->harvestService->getAllHarvestIds(),
      array_values($this->findOrphanedHarvestDataIds())
    );
    foreach ($plan_ids as $plan_id) {
      $this->logger->notice('Updating namespace for ' . $plan_id);
      $this->updateTypeNamespace($plan_id);
    }
  }

  /**
   * Get the ids from the temp harvest run table.
   *
   * Only needed for harvest_update_8010.
   *
   * @param mixed $table_name_temp
   *   The name of the temp table.
   *
   * @return array
   *   The ids of all the harvest runs in the table sorted oldest to newest.
   */
  public function getTempRunIdsForUpdate($table_name_temp) : array {
    $query = $this->connection->select($table_name_temp, 'hrt')
      ->fields('hrt', ['id'])
      ->orderBy('id', 'ASC');
    $result = $query->execute()->fetchCol(0);
    // Can't rely on orderBy as the sort ends up natural, not numeric.
    asort($result, SORT_NUMERIC);

    return $result ?? [];
  }

  /**
   * Reads a single harvest row from the harvest run temp table.
   *
   * Only needed for harvest_update_8010.
   *
   * @param string $table_name_temp
   *   Name of the table to read from.
   * @param string $timestamp
   *   The id to read from, which was also the timestamp.
   *
   * @return array
   *   Elements from the row['id', 'harvest_plan_id', 'data', 'extract_status'].
   */
  public function readTempHarvestRunForUpdate(string $table_name_temp, string $timestamp): array {
    $query = $this->connection->select($table_name_temp, 'hrt')
      ->fields('hrt', ['id', 'harvest_plan_id', 'data', 'extract_status'])
      ->condition('id', $timestamp, '=')
      ->orderBy('id', 'ASC');
    $result = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
    return reset($result);
  }

  /**
   * Creates a new entry in harvest_run based on data from harvest run temp.
   *
   * Only needed for harvest_update_8010.
   *
   * @param string $timestamp
   *   The id from the old harvest run, which was a timestamp.
   * @param string $harvest_plan_id
   *   The harvest plan id.
   * @param string $data
   *   Data about the harvest.
   * @param string $extract_status
   *   The status of the harvest.
   */
  public function writeHarvestRunFromUpdate(string $timestamp, string $harvest_plan_id, string $data, string $extract_status): void {
    $this->connection->insert('harvest_runs')
      ->fields([
        'timestamp' => (int) $timestamp,
        'harvest_plan_id' => $harvest_plan_id,
        'uuid' => $this->uuidService->generate(),
        'data' => $data,
        'extract_status' => $extract_status,
      ])
      ->execute();
  }

}
