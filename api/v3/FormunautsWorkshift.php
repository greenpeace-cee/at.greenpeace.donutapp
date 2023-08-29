<?php

function _civicrm_api3_formunauts_workshift_sync_spec(&$spec) {
  $spec['client_id'] = [
    'name'         => 'client_id',
    'title'        => 'Formunauts Client ID',
    'type'         => CRM_Utils_TYPE::T_STRING,
    'api.required' => 1,
  ];

  $spec['client_secret'] = [
    'name'         => 'client_secret',
    'title'        => 'Formunauts Client Secret',
    'type'         => CRM_Utils_TYPE::T_STRING,
    'api.required' => 1,
  ];

  $spec['offset_date'] = [
    'name'         => 'offset_date',
    'title'        => 'Offset Date',
    'description'  => 'Only sync workshifts after this date',
    'type'         => CRM_Utils_TYPE::T_DATE,
    'api.required' => 0,
  ];
}

function civicrm_api3_formunauts_workshift_sync($params) {
  CRM_Donutapp_API_Client::setClientId($params['client_id']);
  CRM_Donutapp_API_Client::setClientSecret($params['client_secret']);

  $result = CRM_Donutapp_API_Workshift::sync($params['offset_date'] ?? NULL);

  return civicrm_api3_create_success($result);
}

