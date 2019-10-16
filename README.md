INTRODUCTION
------------
The Probe Drupal module exposes Drupal system information to allow central
tracking of multiple Drupal installs. This makes it possible to keep track of
which modules and versions you have running.

This information is only exposed to those on the configurable IP whitelist AND
also sending the configured secret key.

The endpoint uses the xmlrpc standard for communication.

REQUIREMENTS
------------
Since Drupal 8 xmlrpc is no longer included in core. So you need this module:
https://www.drupal.org/project/xmlrpc

INSTALLATION
------------
1. Enable the module.
2. Configure the IP whitelist and secret key at /admin/config/services/probe
3. Test your local probe install using the probe self option at
/admin/config/services/probe/self

CONFIGURATION
-------------
The only configuration of this module is for what IP addresses are allowed to
probe your site and what secret key they should send.
For both see the admin page at /admin/config/services/probe

EXAMPLE
-------
An example request from Drupal to "probe" another Drupal installation can look
like this:
```php
$url = 'https://example.org/xmlrpc?probe_key=<secret_key>';
$args = [
  'probe' => [[
    'cron_last',
  ]],
];
$result = xmlrpc($url, $args);
print_r($result);
```
