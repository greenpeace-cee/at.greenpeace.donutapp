<?php

return [
  'donutapp_campaign_map' => [
    'name'        => 'donutapp_campaign_map',
    'type'        => 'Array',
    'html_type'   => 'text',
    'default'     => [],
    'add'         => '1.3',
    'title'       => ts('DonutApp: Mapping of DonutApp campaigns to Civi campaigns'),
    'is_domain'   => 1,
    'is_contact'  => 0,
    'description' => ts('This maps campaigns in DonutApp to campaigns in CiviCRM.'),
  ],
];
