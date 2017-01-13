<?php
/**
 * @file
 * Contains \Drupal\probe\Form\ProbeConfigForm.
 */

namespace Drupal\probe\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

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

    $form['probe_xmlrpc_ips'] = array(
      '#title' => $this->t('Allowed IP addresses for Probe XMLRPC calls'),
      '#type' => 'textarea',
      '#default_value' => $config->get('probe_xmlrpc_ips'),
      '#description' => $this->t('Put each IP addres on a new line. Wildcards not allowed.'),
      '#required' => TRUE,
    );

    // @todo make it work maybe with \Settings instead of \Config.
    $form['probe_key'] = array(
      '#title' => $this->t('Secret key'),
      '#type' => 'textfield',
      '#disabled' => TRUE,
      '#default_value' => $config->get('probe_key'),
      '#description' => $this->t('Work in Progress'),
    );

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
    return array(
      'probe.settings',
    );
  }

}
