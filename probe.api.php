<?php

/**
 * Expose API info to the probe module.
 * Implements hook_probe_api_info().
 */
function hook_probe_api_info() {
  return array(
    'ideal' => array(
      'name' => 'iDEAL',
      'extra_info' => array( // Optional array with additional information that might be useful to know.
        'bank' => 'ing',
      ),
      'version' => 3,
      'implementing_module' => 'ideal_payments', // Module that implements this API.
    ),
    'gtm' => array(
      'name' => 'Google tag manager',
      'version' => 2,
      'implementing_module' => 'google_tag',
    ),
  );
}
