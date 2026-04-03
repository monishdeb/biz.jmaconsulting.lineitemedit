<?php

use CRM_Lineitemedit_ExtensionUtil as E;

return [
  'line_item_number' => [
    'name' => 'line_item_number',
    'type' => 'Integer',
    'default' => 10,
    'html_type' => 'text',
    'title' => E::ts('Maximum additional line items'),
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => E::ts('Limits the number of lines shown'),
    'settings_pages' => ['contribute' => ['weight' => 10]],
    'on_change' => ['CRM_Lineitemedit_Util::settingChange'],
  ]
];
