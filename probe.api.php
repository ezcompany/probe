<?php

/**
 * @file
 * Probe API documentation.
 */

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Add additional information to Probe.
 *
 * @return array
 *   An associative array of additional information.
 */
function hook_probe_api_info() {
  return [
    'ideal' => [
      'name' => 'iDEAL',
      // Optional array with information that might be useful to know.
      'extra_info' => [
        'bank' => 'ing',
      ],
      'version' => 3,
      // Module that implements this API.
      'implementing_module' => 'ideal_payments',
    ],
    'gtm' => [
      'name' => 'Google tag manager',
      'version' => 2,
      'implementing_module' => 'google_tag',
    ],
  ];
}

/**
 * @} End of "addtogroup hooks".
 */
