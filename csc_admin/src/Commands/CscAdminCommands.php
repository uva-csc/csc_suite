<?php

namespace Drupal\csc_admin\Commands;

use Drush\Commands\DrushCommands;

/**
 * Provides Drush commands for the CSC Admin site.
 */
class CscAdminCommands extends DrushCommands {

  /**
   * List unused files in the system.
   *
   * @command csc-admin:unused_files
   * @aliases unused-files
   */
  public function unusedFiles() {
    $database = \Drupal::database();

    $query = $database->select('file_managed', 'fm');
    $query->leftJoin('file_usage', 'fu', 'fm.fid = fu.fid');
    $query->fields('fm', ['fid', 'filename', 'uri']);
    $query->isNull('fu.fid');
    $results = $query->execute()->fetchAll();

    if (empty($results)) {
      $this->output()->writeln('No unused files found. 🎉');
      return;
    }

    $this->output()->writeln('Unused files:');
    foreach ($results as $row) {
      $this->output()->writeln(sprintf(
        "FID: %d | File: %s | Path: %s",
        $row->fid,
        $row->filename,
        $row->uri
      ));
    }
  }

  /**
   * Test command to confirm Drush command discovery.
   *
   * @command csc_admin:test
   * @aliases test-csc
   */
  public function test() {
    $this->output()->writeln('CSC Admin test command works!');
  }

}
