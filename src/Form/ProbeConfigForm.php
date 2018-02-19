<?php

namespace Drupal\probe\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Class ProbeConfigForm.
 *
 * @package Drupal\probe\Form
 */
class ProbeConfigForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'probe_config_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('probe.settings');

    $form['probe_xmlrpc_ips'] = [
      '#title' => $this->t('Allowed IP addresses for Probe XMLRPC calls'),
      '#type' => 'textarea',
      '#default_value' => $config->get('probe_xmlrpc_ips'),
      '#description' => $this->t('Enter one IP address per line. Wildcards not allowed.'),
      '#required' => TRUE,
    ];

    $form['probe_key'] = [
      '#title' => $this->t('Secret key'),
      '#type' => 'textfield',
      '#default_value' => $config->get('probe_key'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('probe.settings')
      ->set('probe_xmlrpc_ips', $form_state->getValue('probe_xmlrpc_ips'))
      ->set('probe_key', $form_state->getValue('probe_key'))
      ->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getEditableConfigNames() {
    return [
      'probe.settings',
    ];
  }

}
