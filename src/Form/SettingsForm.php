<?php

namespace Drupal\fuzion_migrate\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Fuzion Migrate settings for this site.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'fuzion_migrate_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['fuzion_migrate.settings'];
  }

  private function getFieldModules(){
    // Get fields from Migrate database
    $db = \Drupal\Core\Database\Database::getConnection('default', 'migrate');
    $query = $db->query("select module from {field_config} where active group by module");
    return $query->fetchAll();
  }

  private function getFields(){
    // Get fields from Migrate database
    $db = \Drupal\Core\Database\Database::getConnection('default', 'migrate');
    $query = $db->query("select id, field_name, type, module from {field_config} where active");
    return $query->fetchAll();
  }

  private function getFieldInstances(){
    $db = \Drupal\Core\Database\Database::getConnection('default', 'migrate');
    $query = $db->query("select fc.id as field_id, fc.field_name, fc.type, fc.module, fci.id as field_config_id, fci.entity_type, fci.bundle from {field_config} as fc inner join {field_config_instance} as fci on fc.id = fci.field_id where fc.active and not fci.deleted;");
    return $query->fetchAll();
  }


  private function getContentTypes() {
    // Get content types from Migrate database
    $db = \Drupal\Core\Database\Database::getConnection('default', 'migrate');
    $query = $db->query("SELECT name, type from {node_type} order by name");
    return $query->fetchAll();
  }

  private function getBlockModules() {
    // Get list of modules that have blocks on d7 site.
    // So we can ignore blocks for modules we don't want to import.
    $db = \Drupal\Core\Database\Database::getConnection('default', 'migrate');
    $query = $db->query("SELECT module, count(bid) from {block} group by module order by module");
    return $query->fetchAll();
  }

  private function getViewList() {
    $db = \Drupal\Core\Database\Database::getConnection('default', 'migrate');
    $query = $db->query("SELECT vid, name, human_name, base_table FROM views_view;");
    return $query->fetchAll();
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $result = $this->getContentTypes();

    $fieldsets = [
      'content_types' => $this->t('Content types'),
      'block_modules' => $this->t('Block Modules'),
      'files' => $this->t('Files'),
      'field_config_modules' => $this->t('Field Config - Modules to Skip'),
      'field_configs' => $this->t('Field Configs - to Skip'),
      'field_config_instances' => $this->t('Field Config Instances to Skip'),
      'views' => $this->t('List of views to skip migrating'),
    ];
    foreach($fieldsets as $key => $title) {
      $form[$key] = [
        '#type' => 'fieldset',
        '#title' => $title,
        '#collapsible' => TRUE,
        '#collapsed' => FALSE,
      ];
    }

    $key = 'd7_public_files_url_origin';
    $form['files'][$key] = [
      '#title' => $this->t('Public Files Base URL - strreplace - existing'),
      '#type' => 'textfield',
      '#default_value' => $this->config('fuzion_migrate.settings')->get($key),
    ];
    $key = 'd7_public_files_url_dest';
    $form['files'][$key] = [
      '#title' => $this->t('Public Files Base URL - strreplace - replace'),
      '#type' => 'textfield',
      '#default_value' => $this->config('fuzion_migrate.settings')->get($key),
    ];

    
    $key = 'd7_private_files_url_origin';
    $form['files'][$key] = [
      '#title' => $this->t('Private Files Base URL - strreplace - existing'),
      '#type' => 'textfield',
      '#default_value' => $this->config('fuzion_migrate.settings')->get($key),
    ];
    $key = 'd7_private_files_url_dest';
    $form['files'][$key] = [
      '#title' => $this->t('Private Files Base URL (Should not be live) - strreplace - replace'),
      '#type' => 'textfield',
      '#default_value' => $this->config('fuzion_migrate.settings')->get($key),
    ];

    foreach($result as $row) {
      $key ='d7ct_'.$row->type;
      $form['content_types'][$key] = [
        '#title' => $this->t('Enable migrations for %ct', ['%ct' => $row->name]),
        '#type' => 'checkbox',
        '#return_value' => 1,
        '#default_value' => $this->config('fuzion_migrate.settings')->get($key),
      ];
    }

    $result = $this->getBlockModules();
    foreach($result as $row) {
      $key = 'd7bm_' . $row->module;
      $form['block_modules'][$key] = [
        '#title' => $this->t('Enable Block Migration for %bm', ['%bm' => $row->module]),
        '#type' => 'checkbox',
        '#return_value' => 1,
        '#default_value' => $this->config('fuzion_migrate.settings')->get($key),
      ];
    }
    $result = $this->getFieldModules();
    foreach($result as $row) {
      $key = 'd7fm_' . $row->module;
      $form['field_config_modules'][$key] = [
        '#title' => $this->t("Disable Migrations for fields with module %fm",[
          '%fm' => $row->module
        ]),
        '#type' => 'checkbox',
        '#return_value' => 1,
        '#default_value' => $this->config('fuzion_migrate.settings')->get($key),        
      ];
    }
    
    $result = $this->getFields();
    foreach($result as $row) {
      $key = 'd7fc_' . $row->id;
      $form['field_configs'][$key] = [
        '#title' => $this->t("Disable Migrations for field: %fn, type: %ft, module: %fm ",
                             ['%fn' => $row->field_name,
                              '%ft' => $row->type,
                              '%fm' => $row->module,
                             ]
        ),
        '#type' => 'checkbox',
        '#return_value' => 1,
        '#default_value' => $this->config('fuzion_migrate.settings')->get($key),
      ];
    }
    $result = $this->getFieldInstances();
    foreach($result as $row) {
      $key = 'd7fci_' . $row->field_config_id;
      $form['field_config_instances'][$key] = [
        '#title' => $this->t("Disable Migrations for field instance: %fn, type: %ft, module: %fm, entity_type: %fe, bundle: %fb",
                             ['%fn' => $row->field_name,
                              '%ft' => $row->type,
                              '%fm' => $row->module,
                              '%fe' => $row->entity_type,
                              '%fb' => $row->bundle,
                             ]),
        '#type' => 'checkbox',
        '#return_value' => 1,
        '#default_value' => $this->config('fuzion_migrate.settings')->get($key),
      ];
    }          
    $result = $this->getViewList();
    foreach($result as $row) {
      $key = 'd7vv_' . $row->vid . '_' . $row->name;
      $form['views'][$key] = [
        '#title' => $this->t("Disable Migrations for %vid, %vname, %vdesc, %vtable", 
                             ['%vid' => $row->vid,
                              '%vname' => $row->name,
                              '%vdesc' => $row->human_name,
                              '%vtable' => $row->base_table,
                             ]),
	    '#type' => 'checkbox',
        '#return_value' => 1,
        '#default_value' => $this->config('fuzion_migrate.settings')->get($key),
      ];
    }
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    foreach($this->getContentTypes() as $row) {
      $key ='d7ct_'.$row->type;
      $this->config('fuzion_migrate.settings')
        ->set($key, $form_state->getValue($key))
        ->save();

    }
    foreach($this->getBlockModules() as $row) {
      $key = 'd7bm_' . $row->module;
      $this->config('fuzion_migrate.settings')
        ->set($key, $form_state->getValue($key))
        ->save();
    }

    foreach($this->getFieldModules() as $row) {
      $key = 'd7fm_' . $row->module;
      $this->config('fuzion_migrate.settings')
        ->set($key, $form_state->getValue($key))
        ->save();
    }
    
    $result = $this->getFields();
    foreach($result as $row) {
      $key = 'd7fc_' . $row->id;
      $this->config('fuzion_migrate.settings')
           ->set($key, $form_state->getValue($key))
           ->save();
    }
    $result = $this->getFieldInstances();
    foreach($result as $row) {
      $key = 'd7fci_' . $row->field_config_id;
      $this->config('fuzion_migrate.settings')
           ->set($key, $form_state->getValue($key))
           ->save();
    }
    
    $keys = [
      'd7_public_files_url_origin',
      'd7_public_files_url_dest',
      'd7_private_files_url_origin',
      'd7_private_files_url_dest'
    ];
    foreach($keys as $key) {
      $this->config('fuzion_migrate.settings')
        ->set($key, $form_state->getValue($key))
        ->save();
    }
    
    $result = $this->getViewList();
    foreach($result as $row) {
	    $key = 'd7vv_' . $row->vid . '_' . $row->name;
	    $this->config('fuzion_migrate.settings') 
                 ->set($key, $form_state->getValue($key))
	         ->save();
    }

    parent::submitForm($form, $form_state);
  }
}
