<?php
/**
 * @file
 * Contains \Drupal\probe\Form\ProbeConfigForm.
 */

namespace Drupal\probe\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

class ProbeConfigForm extends ConfigFormBase {

  private $configName = 'probe.settings';

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
    $form = parent::buildForm($form, $form_state);
    $config = $this->config($this->configName);
    $form['probe_xmlrpc_ips'] = array(
      '#title' => t('Allowed IP addresses for Probe XMLRPC calls'),
      '#type' => 'textarea',
      '#default_value' => $config->get($this->getEditableConfigNames()[0]),
      '#description' => t('Put each IP addres on a new line. Wildcards not allowed.'),
    );
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = \Drupal::configFactory()->getEditable('probe.settings');
    $config->set($this->getEditableConfigNames()[0], $form_state->getValue('probe_xmlrpc_ips'));
    $config->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getEditableConfigNames() {
    return array(
      'probe_xmlrpc_ips',
    );
  }

}
