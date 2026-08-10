<?php

namespace Drupal\csc_admin\Commands;

use Drupal\Core\Database\Connection;
use Drupal\Core\File\FileUrlGenerator; // <-- correct class
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\file\Entity\File;

class CscStaleFilesCommands extends DrushCommands {

  protected Connection $database;
  protected FileUrlGenerator $fileUrlGenerator;

  public function __construct(Connection $database, FileUrlGenerator $file_url_generator) {
    parent::__construct();
    $this->database = $database;
    $this->fileUrlGenerator = $file_url_generator;
  }

  public static function create(ContainerInterface $container) {
    return new self(
      $container->get('database'),
      $container->get('file_url_generator') // returns Drupal\Core\File\FileUrlGenerator
    );
  }

  /**
   * List or delete stale file references in media entities.
   *
   * @command csc:stale-files
   * @aliases stale-files
   * @option delete Delete stale files instead of just listing them.
   * @usage drush csc:stale-files
   *   Lists stale files.
   * @usage drush csc:stale-files --delete
   *   Deletes stale files.
   */
  public function listStaleFiles(array $options = ['delete' => false]) {
    $query = <<<SQL
SELECT fu.fid,
       fu.id AS media_id,
       fm.uri AS file_uri
FROM drwt_file_usage fu
JOIN drwt_file_managed fm ON fm.fid = fu.fid
WHERE fu.type = 'media'
  AND fu.id IN (SELECT mid FROM drwt_media_field_data)
  AND fu.fid NOT IN (
    SELECT field_media_image_target_id
    FROM drwt_media__field_media_image
  )
ORDER BY fu.id
SQL;

    $results = $this->database->query($query)->fetchAll();

    if (empty($results)) {
      $this->io()->success('No stale files found.');
      return;
    }

    $delete = $options['delete'] ?? FALSE;
    $rows = [];

    foreach ($results as $row) {
      $fid = $row->fid;
      $media_id = $row->media_id;
      $uri = $row->file_uri;
      $url = '';

      $file = File::load($fid);
      if ($file) {
        $url = $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
      }

      if ($delete && $file) {
        $file->delete();
        $this->logger()->notice("Deleted stale file $fid from media $media_id");
      }

      $rows[] = [
        'fid' => $fid,
        'media_id' => $media_id,
        'uri' => $uri,
        'url' => $url,
      ];
    }

    if (!$delete) {
      $this->io()->table(['FID', 'Media ID', 'URI', 'URL'], $rows);
      $this->io()->warning('Run with --delete to remove these files.');
    }
    else {
      $this->io()->success('Stale files deleted.');
    }
  }

}
