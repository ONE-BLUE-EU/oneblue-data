<?php

namespace Drupal\dkan_importer_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

// Define file status constants if not already defined
if (!defined('FILE_STATUS_PERMANENT')) {
  define('FILE_STATUS_PERMANENT', 1);
}
if (!defined('FILE_STATUS_TEMPORARY')) {
  define('FILE_STATUS_TEMPORARY', 0);
}

/**
 * Controller for handling CSV file uploads.
 */
class ImporterController extends ControllerBase {

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The file upload directory.
   */
  const UPLOAD_DIRECTORY = 'public://uploaded_resources';

  /**
   * Constructs a new ImporterController object.
   *
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(
    FileSystemInterface $file_system,
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    ConfigFactoryInterface $config_factory
  ) {
    $this->fileSystem = $file_system;
    $this->entityTypeManager = $entity_type_manager;
    $this->loggerFactory = $logger_factory;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('file_system'),
      $container->get('entity_type.manager'),
      $container->get('logger.factory'),
      $container->get('config.factory')
    );
  }

  /**
   * Handles CSV file upload.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response.
   */
  public function uploadCsv(Request $request) {
    $logger = $this->loggerFactory->get('dkan_importer_api');

    try {
      // Check if a file was uploaded.
      $uploaded_files = $request->files->all();
      if (empty($uploaded_files)) {
        return new JsonResponse([
          'error' => 'No file uploaded.',
          'status' => 'error',
        ], 400);
      }

      // Get the first uploaded file.
      $uploaded_file = reset($uploaded_files);
      if (!$uploaded_file || !$uploaded_file->isValid()) {
        return new JsonResponse([
          'error' => 'Invalid file upload.',
          'status' => 'error',
        ], 400);
      }

      // Check if it's a CSV file.
      $filename = $uploaded_file->getClientOriginalName();
      $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
      $mime_type = $uploaded_file->getMimeType();

      if ($extension !== 'csv' && !in_array($mime_type, ['text/csv', 'text/plain', 'application/csv'])) {
        return new JsonResponse([
          'error' => 'Only CSV files are allowed.',
          'status' => 'error',
        ], 400);
      }

      // Prepare the destination directory.
      $destination_dir = 'public://uploaded_resources';
      $this->fileSystem->prepareDirectory($destination_dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

      // Move the uploaded file to the destination.
      $destination = $destination_dir . '/' . $filename;
      $uploaded_file->move($this->fileSystem->realpath($destination_dir), $filename);

      // Create a file entity.
      $file = File::create([
        'filename' => $filename,
        'uri' => $destination,
        'status' => 1,
        'uid' => $this->currentUser()->id(),
      ]);
      $file->save();

      // Generate the public URL.
      $file_url = \Drupal::service('file_url_generator')->generateAbsoluteString($destination);

      $logger->info('CSV file uploaded successfully: @filename by user @uid', [
        '@filename' => $filename,
        '@uid' => $this->currentUser()->id(),
      ]);

      return new JsonResponse([
        'status' => 'success',
        'data' => [
          'file_id' => $file->id(),
          'filename' => $filename,
          'file_path' => $destination,
          'file_url' => $file_url,
          'file_size' => $file->getSize(),
          'upload_time' => date('c'),
          'uploaded_by' => $this->currentUser()->getAccountName(),
        ],
      ], 200);

    }
    catch (\Exception $e) {
      $logger->error('Error uploading CSV file: @message', ['@message' => $e->getMessage()]);

      return new JsonResponse([
        'error' => 'An error occurred while uploading the file.',
        'status' => 'error',
        'details' => $e->getMessage(),
      ], 500);
    }
  }

  /**
   * Handles CSV file deletion.
   *
   * @param string $filename
   *   The filename to delete.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response.
   */
  public function deleteCsv($filename, Request $request) {
    $logger = $this->loggerFactory->get('dkan_importer_api');

    try {
      // Validate filename to prevent directory traversal.
      if (strpos($filename, '..') !== FALSE || strpos($filename, '/') !== FALSE) {
        return new JsonResponse([
          'error' => 'Invalid filename.',
          'status' => 'error',
        ], 400);
      }

      // Construct the file URI.
      $file_uri = self::UPLOAD_DIRECTORY . '/' . $filename;

      // Find the file entity by URI.
      $file_storage = $this->entityTypeManager->getStorage('file');
      $files = $file_storage->loadByProperties(['uri' => $file_uri]);

      if (empty($files)) {
        return new JsonResponse([
          'error' => 'File not found.',
          'status' => 'error',
        ], 404);
      }

      // Get the file entity.
      $file = reset($files);

      // Check if the current user uploaded this file or has admin permissions.
      if (!$this->currentUser()->hasPermission('delete csv files')) {
        return new JsonResponse([
          'error' => 'You do not have permission to delete this file.',
          'status' => 'error',
        ], 403);
      }

      // Delete the physical file.
      if ($this->fileSystem->delete($file_uri)) {
        // Delete the file entity.
        $file->delete();

        $logger->info('CSV file deleted successfully: @filename by user @uid', [
          '@filename' => $filename,
          '@uid' => $this->currentUser()->id(),
        ]);

        return new JsonResponse([
          'status' => 'success',
          'message' => 'File deleted successfully.',
          'data' => [
            'filename' => $filename,
            'deleted_by' => $this->currentUser()->getAccountName(),
            'delete_time' => date('c'),
          ],
        ], 200);
      }
      else {
        // If physical file deletion failed but entity exists, still delete the entity.
        $file->delete();

        return new JsonResponse([
          'status' => 'warning',
          'message' => 'File entity deleted but physical file removal failed.',
          'data' => [
            'filename' => $filename,
            'deleted_by' => $this->currentUser()->getAccountName(),
            'delete_time' => date('c'),
          ],
        ], 200);
      }

    }
    catch (\Exception $e) {
      $logger->error('Error deleting CSV file @filename: @message', [
        '@filename' => $filename,
        '@message' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'error' => 'An error occurred while deleting the file.',
        'status' => 'error',
        'details' => $e->getMessage(),
      ], 500);
    }
  }

}
