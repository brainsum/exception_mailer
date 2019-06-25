<?php

namespace Drupal\exception_mailer\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Class ExceptionMailerConfigForm.
 */
class ExceptionMailerConfigForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'exception_mailer.exception_mailer_config',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'exception_mailer_config_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('exception_mailer.exception_mailer_config');
    $form['level_type'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Check the level type'),
      '#options' => [
        'EMERGENCY' => $this->t('Emergency'),
        'ALERT' => $this->t('Alert'),
        'CRITICAL' => $this->t('Critical'),
        'ERROR' => $this->t('Error'),
        'WARNING' => $this->t('Warning'),
        'NOTICE' => $this->t('Notice'),
        'INFO' => $this->t('Info'),
        'DEBUG' => $this->t('Debug'),
      ],
      '#default_value' => $config->get('level_type'),
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);

    $this->config('exception_mailer.exception_mailer_config')
      ->set('level_type', $form_state->getValue('level_type'))
      ->save();
  }

}
