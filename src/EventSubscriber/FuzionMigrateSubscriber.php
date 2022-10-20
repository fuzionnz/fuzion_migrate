<?php

namespace Drupal\fuzion_migrate\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

use Drupal\migrate_plus\Event\MigrateEvents as MigratePlusEvents;
use Drupal\migrate_plus\Event\MigratePrepareRowEvent;
use \Drupal\migrate\MigrateSkipRowException;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Fuzion Migrate event subscriber.
 */
class FuzionMigrateSubscriber implements EventSubscriberInterface {


  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;


  /**
   * Constructs a new EventSubscriberInterface object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(ConfigFactoryInterface $configFactory) {
    $this->configFactory = $configFactory;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      MigratePlusEvents::PREPARE_ROW => ['onPrepareRow'],
    ];
  }

  private function checkIfFieldInstanceSetToDisabledInSettings($instance,$fz_migrating) {
    $key = 'd7fci_' . $instance['id'];
    return !empty($fz_migrating[$key]);
  }
  
  private function checkIfFieldSetToDisabledInSettings($row,$fz_migrating) {
    $module_key = 'd7fm_' . $row->get('module');
    $field_key =  'd7fc_' . $row->get('id');
    $skipping_field_based_on_module = !empty($fz_migrating[$module_key]);
    $skipping_field_based_on_field_config = !empty($fz_migrating[$field_key]);
    if ($skipping_field_based_on_module || $skipping_field_based_on_field_config) {
      return TRUE;
    }
    else {
      return FALSE;
    }
  }

  private function checkIfImportingFieldInstance($row) {    
    $fz_migrating = $this->configFactory->get('fuzion_migrate.settings')->get();
    $field_instances = $row->get('instances');
    $skipping = $this->checkIfFieldInstanceSetToDisabledInSettings($instance, $fz_migrating);
    if ($skipping) {
      throw new MigrateSkipRowException("Skipping field instance: $field_name - based on settings");
    }
    $this->checkIfImportingField($row);
  }
    
  
  /**
   * Checks a Migration row (of type field/field instance) against
   * module config as to whether we should import. Throws skip
   * exception if not.
   *
   * @param $row Drupal\migrate\Row
   *
   * @throws \Drupal\migrate\MigrateSkipRowException
   */
  private function checkIfImportingField($row) {
   
    $fz_migrating = $this->configFactory->get('fuzion_migrate.settings')->get();    
    
    $field_instances_original = $row->get('instances');
    $field_instances = [];

    // Confirm this field hasn't been overriden and set to skip in
    // settings.        
    if ($this->checkIfFieldSetToDisabledInSettings($row, $fz_migrating)) {
      $field_name = $field_instances_original[0]['field_name'];
      throw new MigrateSkipRowException("Skipping field: $field_name - set to disabled in settings");
    }
    
    // Check for any field instances on content types we are
    // migrating, and fields that we are not skipping.

    foreach($field_instances_original as $index => $data) {
      if (empty($data['bundle'])) {
        // Catch missing field bundle - buggy source data.
        // We just skip.
        continue;
      }
      $key =  'd7ct_' . $data['bundle'];
      $not_migrating_bundle = empty($fz_migrating[$key]);
      if ( $not_migrating_bundle) {
        continue;
      }
      $not_migrating_instance = $this->checkIfFieldInstanceSetToDisabledInSettings($data, $fz_migrating);
      if ($not_migrating_instance) {
        continue;
      }
      $field_instances[] = $data;      
    }
    if (empty($field_instances))  {
      $field_name = $field_instances_original[0]['field_name'];
      throw new MigrateSkipRowException("Skipping field: $field_name - no content types using");
    }
    $row->setSourceProperty('instances', $field_instances);
  }

  /**
   * Throws exception if we are not importing content type.
   *
   * @param $content_type string
   * @throws \Drupal\migrate\MigrateSkipRowException
   */
  private function checkIfImportingContentType($content_type) {
    if (empty($content_type)) {
      return;
    }
    $key = 'd7ct_'. $content_type;
    if (empty($this->configFactory->get('fuzion_migrate.settings')->get($key))) {
      throw new MigrateSkipRowException("Skipping content type: $content_type based on configuration.");
    }
  }

  private function checkIfImportingAction($row) {
    $action = $row->get('aid');
    $skipping = [
      'user_block_ip_action',
      'node_export_drupal_action',
      'pathauto_file_update_action',
      'pathauto_user_update_action',
      'pathauto_taxonomy_term_update_action',
      'pathauto_node_update_action',
      'node_gallery_set_as_cover_action',
      'imagecache_generate_all_action',
      'imagecache_flush_action',
      'messaging_debug_block_msg',
      'messaging_debug_devlog_msg',
      'messaging_debug_watchdog_msg',
      'backup_migrate_backup_action',
      'node_export_dsv_action',
      'node_export_json_action',
      'node_export_serialize_action',
      'node_export_xml_action',
    ];

    if (in_array($action, $skipping)) {
      throw new MigrateSkipRowException("Skpping action plugin - $action on skip list");
    }
    $prefixes = [
      'og_' => "Organic group prefix",
      'views_bulk_operations_' => "Views Bulk Operations",
    ];

    foreach($prefixes as $prefix => $reason) {
      if (strpos($action, $prefix) === 0) {
        throw new MigrateSkipRowException("Skipping action plugin - $reason");
      }
    }
  }

  public function checkIfImportingBlock($row) {
    $module = $row->get('module');
    $key = 'd7bm_'. $module;
    if (empty($this->configFactory->get('fuzion_migrate.settings')->get($key))) {
      throw new MigrateSkipRowException("Skipping Blocks from module type: $module based on configuration.");
    }
  }

  public function copyPublicFiles($row) {
    $fz_config = $this->configFactory->get('fuzion_migrate.settings');    
    $search  = $fz_config->get('d7_public_files_url_origin');
    $replace = $fz_config->get('d7_public_files_url_dest');
    if (!empty($search) && !empty($replace)) {
      $row->setSourceProperty(
        'filepath',
        str_replace(
          $search,
          $replace,
          $row->get('filepath')
        )
      );
    }
  }

  public function copyPrivateFiles($row) {
    $fz_config = $this->configFactory->get('fuzion_migrate.settings');    
    $search  = $fz_config->get('d7_private_files_url_origin');
    $replace = $fz_config->get('d7_private_files_url_dest');
    if (!empty($search) && !empty($replace)) {
      $row->setSourceProperty(
        'filepath',
        str_replace(
          $search,
          $replace,
          $row->get('filepath')
        )
      );
    }
  }

  public function filterImageStyles($row) {
    $effects = array_filter($row->get('effects'), function($v) {
      if ($v['name'] == 'canvasactions_definecanvas') {
        return FALSE;
      }
      return TRUE;
    });
    $row->setSourceProperty('effects', $effects);
  }

  public function fieldInstanceDebug($row, $msg) {
    $instances = $row->get('instances');
    $debug_msg = [$msg];
    
    $debug_msg[] = "Field ID: " . $instances[0]['field_id'];
    foreach ($instances as $instance) {
      $debug_msg[] = "[" . $instance['id'] .  ', '. $instance['bundle'] . "]";
    }
    echo PHP_EOL . implode(' ', $debug_msg) . PHP_EOL;
  }

  public function checkIfImportingView($row) {
    $fz_config = $this->configFactory->get('fuzion_migrate.settings');
    $key = 'd7vv_' . $row->get('vid') . '_' . $row->get('name');
    if (!empty($this->configFactory->get('fuzion_migrate.settings')->get($key))) {
      $row_name = $row->name;
      throw new MigrateSkipRowException("Skipping view: $row_name based on configuration.");
    }
  }
  
  /**
   * React to a new row.
   *
   * @param \Drupal\migrate_plus\Event\MigratePrepareRowEvent $event
   *   The prepare-row event.
   *
   * @throws \Drupal\migrate\MigrateSkipRowException
   */
  public function onPrepareRow(MigratePrepareRowEvent $event) {

    $migration = $event->getMigration();
    $row = $event->getRow();
    $migration_id = $migration->id();

    // First check migration ids for exact matches
    // - then we look for pattern matches
    switch($migration_id) {
    case 'upgrade_mail_system_settings':
      throw new MigrateSkipRowException("This migration is broken and shouldn't have been merged...");
      break;
    case 'upgrade_d7_image_styles':
        $this->filterImageStyles($row);
        break;
    case 'upgrade_d7_node_type':
      $content_type = $row->get('type');
      $this->checkIfImportingContentType($content_type);
      break;
    case 'upgrade_d7_field':      
      $this->checkIfImportingField($row);
      break;
    case 'upgrade_d7_field_instance':
      $content_type = $row->get('bundle');
      $this->checkIfImportingContentType($content_type);
      $this->checkIfImportingFieldInstance($row);
      break;      
    case 'upgrade_d7_comment_type':
      $content_type = $row->get('type');
      $this->checkIfImportingContentType($content_type);
      break;
    case 'upgrade_d7_comment_field':
      $content_type = $row->get('type');
      $this->checkIfImportingContentType($content_type);
      break;
    case 'upgrade_d7_comment_field_instance':
      $content_type = $row->get('type');
      $this->checkIfImportingContentType($content_type);
      break;
    case 'upgrade_d7_comment_entity_display':
      $content_type = $row->get('type');
      $this->checkIfImportingContentType($content_type);
      break;
    case 'upgrade_d7_comment_entity_form_display':
      $content_type = $row->get('type');
      $this->checkIfImportingContentType($content_type);
      break;
    case 'upgrade_d7_comment_entity_form_display_subject':
      $content_type = $row->get('type');
      $this->checkIfImportingContentType($content_type);
      break;
    case 'upgrade_d7_field_formatter_settings':
      $content_type = $row->get('bundle');
      $this->checkIfImportingContentType($content_type);      

      
      if ($row->get('type') == 'text') {
          // Default format is saved for the field and for each
          // instance.
          $d = unserialize($row->get('data'));
          if ($d['display']['default']['type'] == 'default') {
              $d['display']['default']['type'] = 'text_default';
              echo "SETTING" . PHP_EOL;
              $row->setSourceProperty('data', serialize($d));
          }
          
          $instances = $row->get('instances');
          foreach($instances as &$instance) {
              $d = unserialize($instance['data']);
              if ($d['display']['default']['type'] == 'default') {
                  $d['display']['default']['type'] = 'text_default';
                  echo "SETTING" . PHP_EOL;
                  $instance['data'] = serialize($d);
              }
          }
          $row->setSourceProperty('instances', $instances);
      }      
      break;
    case 'upgrade_d7_action':
      $this->checkIfImportingAction($row);
      break;
    case 'upgrade_d7_block':
      $this->checkIfImportingBlock($row);
      break;
    case 'upgrade_d7_file':
      $this->copyPublicFiles($row);
      break;
    case 'upgrade_d7_file_private':
      $this->copyPrivateFiles($row);
      break;
    case 'upgrade_d7_comment':
      $this->checkIfImportingContentType($row->get('node_type'));
      break;
    case 'upgrade_d7_views_migration':
      var_export(['id' => $row->get('name'),
                  'name' => $row->get('human_name'),
                  ]
      );     
      $this->checkIfImportingView($row);
      break;
    }

    // For node content types migrations we pattern match.
    if (strpos($migration_id, 'upgrade_d7_node_complete_') === 0){
      // Check for null titles
      if (empty($row->get('title') && $row->hasSourceProperty('nid') && $row->hasSourceProperty('title'))) {
        $nid = $row->get('nid');
        trigger_error(sprintf("Missing title on node %s -> setting to default (Node: nid) to avoid warnings.", $row->get('nid')), E_USER_WARNING);

        $row->setSourceProperty('title', "Node: $nid");
      }
      $content_type = str_replace('upgrade_d7_node_complete_', '', $migration_id);
      $this->checkIfImportingContentType($content_type);
    }
  }
}
