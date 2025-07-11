<?php

namespace Drupal\moderated_content_bulk_publish\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['moderated_content_bulk_publish.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'moderated_content_bulk_publish_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('moderated_content_bulk_publish.settings');

    $form['disable_toolbar_language_switcher'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Disable toolbar language switcher'),
      '#default_value' => $config->get('disable_toolbar_language_switcher') ?? false,
      '#description' => $this->t('Hide the language switcher in the toolbar, for sites that have more than one language.'),
    ];
    $form['enable_dialog_node_edit_form'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable dialog on node edit form'),
      '#default_value' => $config->get('enable_dialog_node_edit_form') ?? true,
      '#description' => $this->t('It shows a confirmation dialog when publishing a node from a node form.'),
    ];
    $form['enable_dialog_admin_content'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable dialog on admin content listing'),
      '#default_value' => $config->get('enable_dialog_admin_content') ?? true,
      '#description' => $this->t('It shows a confirmation dialog in the admin/content listing to all bulk operations.'),
    ];
    $form['retain_revision_info'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Retain revision authoring information'),
      '#default_value' => $config->get('retain_revision_info') ?? false,
      '#description' => $this->t('When publishing/unpublishing a revision, retain the original revision information and append bulk publishing context to the revision log message.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('moderated_content_bulk_publish.settings')
      ->set('disable_toolbar_language_switcher', $form_state->getValue('disable_toolbar_language_switcher'))
      ->set('enable_dialog_node_edit_form', $form_state->getValue('enable_dialog_node_edit_form'))
      ->set('enable_dialog_admin_content', $form_state->getValue('enable_dialog_admin_content'))
      ->set('retain_revision_info', $form_state->getValue('retain_revision_info'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
