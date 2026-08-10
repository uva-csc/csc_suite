<?php

namespace Drupal\csc_admin\Commands;

use Drupal\node\Entity\Node;
use Drush\Commands\DrushCommands;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\node\Entity\NodeType;
use Drupal\Core\Database\Database;

/**
 * Custom Drush command to scan for direct file links in text fields.
 */
class CscScanFilesCommands extends DrushCommands {

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  protected $loggerFactory;

  public function __construct(EntityTypeManagerInterface $entityTypeManager, EntityFieldManagerInterface $entityFieldManager,
    LoggerChannelFactoryInterface $loggerFactory) {
    parent::__construct();
    $this->entityTypeManager = $entityTypeManager;
    $this->entityFieldManager = $entityFieldManager;
    $this->loggerFactory = $loggerFactory;
  }

  /**
   * Scan all entities for direct file references in text fields.
   *
   * @command csc-admin:scan-direct-files
   * @aliases scan-direct-files
   * @usage drush scan-direct-files
   */
  public function scanDirectFiles() {
    $regex = '#/sites/default/files/[^"\'\s<]+#';
    $found = [];

    // Loop over all entity types that are content entities.
    foreach ($this->entityTypeManager->getDefinitions() as $entity_type_id => $definition) {
      if (!$definition->entityClassImplements('\Drupal\Core\Entity\ContentEntityInterface')) {
        continue;
      }

      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      $ids = $storage->getQuery()->accessCheck(TRUE)->execute();
      if (empty($ids)) {
        continue;
      }

      $entities = $storage->loadMultiple($ids);
      $field_definitions = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $definition->getKey('bundle'));

      foreach ($entities as $entity) {
        foreach ($entity->getFieldDefinitions() as $field_name => $field_def) {
          if ($this->isTextField($field_def)) {
            foreach ($entity->get($field_name)->getValue() as $item) {
              foreach ($item as $value) {
                if (is_string($value) && preg_match_all($regex, $value, $matches)) {
                  foreach ($matches[0] as $file_url) {
                    $found[] = [
                      'entity_type' => $entity_type_id,
                      'id' => $entity->id(),
                      'field' => $field_name,
                      'file_url' => $file_url,
                    ];
                  }
                }
              }
            }
          }
        }
      }
    }

    if (empty($found)) {
      $this->io()->success('No direct file links found in text fields 🎉');
      return;
    }

    $this->io()->title('Direct file references found');
    foreach ($found as $entry) {
      $this->output()->writeln(sprintf(
        "%s:%s field=%s → %s",
        $entry['entity_type'],
        $entry['id'],
        $entry['field'],
        $entry['file_url']
      ));
    }
  }

  /**
   * Determine if a field is text-based.
   */
  protected function isTextField(FieldDefinitionInterface $field_def) {
    $text_types = ['string', 'string_long', 'text', 'text_long', 'text_with_summary'];
    return in_array($field_def->getType(), $text_types, TRUE);
  }

  /**
   * Get all text_long fields for nodes, paragraphs, and blocks.
   *
   * @return array
   *   An array structured as:
   *   [
   *     'node' => [
   *       'article' => ['body', 'field_extra_text'],
   *       ...
   *     ],
   *     'paragraph' => [
   *       'text_section' => ['field_text'],
   *       ...
   *     ],
   *   ]
   */
  protected function getTextLongFields() {
    $target_entity_types = ['node', 'paragraph', 'block_content'];
    $fields = [];

    $bundle_info_service = \Drupal::service('entity_type.bundle.info');

    foreach ($target_entity_types as $entity_type_id) {
      $bundles = $bundle_info_service->getBundleInfo($entity_type_id);

      foreach ($bundles as $bundle_name => $bundle_info) {
        $field_definitions = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle_name);

        foreach ($field_definitions as $field_name => $field_def) {
          if ($field_def->getType() === 'text_long') {
            $fields[$entity_type_id][$bundle_name][] = $field_name;
          }
        }
      }
    }

    return $fields;
  }

  /**
   * Gets node ids of nodes that use a specific paragraph given the PID
   *
   * @param $pid
   *
   * @return string
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function getNodesFromPid($pid) {
    $logger = $this->loggerFactory->get('csc_admin'); // channel name
    $logger->notice('PID: @pid', ['@pid' => $pid]);
    // Load the entity query service
    $entity_type_manager = \Drupal::entityTypeManager();

    // Query nodes that reference this paragraph
    $query = $entity_type_manager->getStorage('node')->getQuery();
    $query->accessCheck(FALSE);
    $query->condition('status', 1); // optional: only published
    $query->condition('field_paragraph_field_name.target_id', $pid);

    $nids = $query->execute();

    return implode(',', $nids);
  }

  protected function getParaNode($pid) {
    // Get the database connection
    $connection = Database::getConnection();

    // Query the active node field table
    $query = $connection->select('node__field_content_blocks', 'f')
      ->fields('f', ['entity_id'])
      ->condition('field_content_blocks_target_id', $pid);

    $nids = $query->execute()->fetchCol(); // returns array of node IDs
    $nnum = count($nids);
    if($nnum > 1) {
      print_r("$nnum nodes use paragraph $pid\n");
    }
    $node = Node::load($nids[0]);
    return "$nids[0]: " . $node->getTitle();
  }

  /**
   * Search for a specific filename in all text fields.
   *
   * @command csc-admin:search-file
   * @aliases search-file
   * @param string $filename
   *   The filename to search for (e.g., "example.pdf").
   * @usage drush search-file example.pdf
   */
  public function searchFile($filename) {
    $logger = $this->loggerFactory->get('csc_admin'); // channel name
    $regex = '#/sites/default/files/(.*/)?' . preg_quote($filename, '#') . '#';
    $regex = str_replace('\*', '(.*)', $regex);
    // print_r("regex: $regex\n");
    $found = [];

    $entity_type_manager = $this->entityTypeManager;
    $text_long_fields = $this->getTextLongFields();
    // print_r("Long fields: " . json_encode($text_long_fields));

    foreach ($text_long_fields as $entity_type_id => $bundles) {
      $storage = $entity_type_manager->getStorage($entity_type_id);

      foreach ($bundles as $bundle_name => $fields) {
        $ids = $storage->getQuery()
          ->accessCheck(TRUE)
          ->condition($entity_type_manager->getDefinition($entity_type_id)->getKey('bundle'), $bundle_name)
          ->execute();

        if (empty($ids)) {
          continue;
        }

        $entities = $storage->loadMultiple($ids);

        foreach ($entities as $entity) {
          foreach ($fields as $field_name) {
            if (!$entity->hasField($field_name)) {
              continue;
            }
            /*
            $logger->notice('Valid field name for file in @bundle: @fieldname', [
              '@bundle' => $entity->bundle(),
              '@fieldname' => $field_name,
            ]);
            */
            foreach ($entity->get($field_name)->getValue() as $item) {
              foreach ($item as $value) {
                /*if ($bundle_name === 'text_box') {
                  $logger->notice('Text Box Value: @value', [
                    '@value' => $value,
                  ]);
                }*/
                if (is_string($value) && preg_match($regex, $value)) {
                  $found[] = [
                    'nid' => $this->getParaNode($entity->id()),
                    'entity_type' => $entity_type_id,
                    'id' => $entity->id(),
                    'bundle' => $bundle_name,
                    'field' => $field_name,
                    'filename' => $filename,
                  ];
                }
              }
            }
          }
        }
      }
    }

    // Load all node IDs (or a subset if you want)
    $nids = \Drupal::entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->execute();

    $nodes = \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($nids);

    foreach ($nodes as $node) {
      if ($node->hasField('body')) {
        foreach ($node->get('body') as $item) {
          $text = $item->value ?? '';
          if (!empty($text) && preg_match($regex, $text)) {
            $found[] = [
              'nid' => $node->id() .': ' . $node->getTitle(),
              'entity_type' => 'node',
              'id' => $node->id(),
              'bundle' => $node->bundle(),
              'field' => 'body',
              'filename' => '',
            ];
            break; // found in this node, move to next
          }
        }
      }
    }

    if (empty($found)) {
      $this->output()->writeln("No occurrences of '$filename' found in text_long fields or node bodies.");
      return;
    }

    $this->output()->writeln("\n\nOccurrences of '$filename':");
    foreach ($found as $entry) {
      $this->output()->writeln(sprintf(
        "%s:%s (%s) field=%s in Node %s",
        $entry['entity_type'],
        $entry['id'],
        $entry['bundle'],
        $entry['field'],
        $entry['nid'],
      ));
    }
    print_r("\n\n");

  }

  // List Text Long fields
  /**
   * List all text_long fields in nodes, paragraphs, or blocks.
   *
   * @command csc-admin:list-text-long-fields
   * @aliases list-text-long-fields
   * @usage drush list-text-long-fields
   */
  public function listTextLongFields() {
    $target_entity_types = ['node', 'paragraph', 'block_content'];
    $output = [];

    $bundle_info_service = \Drupal::service('entity_type.bundle.info');

    foreach ($target_entity_types as $entity_type_id) {
      $bundles = $bundle_info_service->getBundleInfo($entity_type_id);

      foreach ($bundles as $bundle_name => $bundle_info) {
        $field_definitions = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle_name);

        foreach ($field_definitions as $field_name => $field_def) {
          if ($field_def->getType() === 'text_long') {
            $output[] = [
              'entity_type' => $entity_type_id,
              'bundle' => $bundle_name,
              'field_name' => $field_name,
            ];
          }
        }
      }
    }

    if (empty($output)) {
      $this->io()->warning('No text_long fields found in nodes, paragraphs, or blocks.');
      return;
    }

    $this->io()->title('text_long fields in nodes, paragraphs, and blocks');
    foreach ($output as $item) {
      $this->output()->writeln(sprintf(
        "Entity: %s, Bundle: %s, Field: %s",
        $item['entity_type'],
        $item['bundle'],
        $item['field_name']
      ));
    }
  }

  /**
   * List all paragraph fields for any node type.
   *
   * @command csc-admin:para-fields
   * @aliases para-fields
   * @usage drush para-fields
   */
  public function listParagraphFields() {
    $all_node_types = NodeType::loadMultiple();
    print_r("\n\tNodeType\t\tField\n");
    print_r("\t--------\t\t-----\n");
    foreach ($all_node_types as $type) {
      $field_definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', $type->id());
      foreach ($field_definitions as $field_name => $field_definition) {
        $targtype = $field_definition->getSetting('target_type');
        if ($targtype == 'paragraph') {
          $deftype = $field_definition->getType();
          if (!empty($deftype)) {
            print_r("deftype: $deftype\n");
          }
          if ($deftype === 'entity_reference_revisions') {
            $typid = $type->id();
            $tabs = (strlen($typid) < 8) ? "\t\t\t" : "\t\t";
            echo "\t{$type->id()}{$tabs}$field_name\n";
          }
        }
      }
    }
    print_r("\n");
  }


}
