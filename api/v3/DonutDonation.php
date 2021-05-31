<?php
use CRM_Donutapp_ExtensionUtil as E;

/**
 * DonutDonation.import API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_donut_donation_import_spec(&$spec) {
  $spec['limit'] = [
    'name'         => 'limit',
    'title'        => 'Maximum number of donations to process',
    'type'         => CRM_Utils_TYPE::T_INT,
    'api.required' => 0,
    'api.default'  => 0,
  ];

  $spec['client_id'] = [
    'name'         => 'client_id',
    'title'        => 'Donutapp Client ID',
    'type'         => CRM_Utils_TYPE::T_STRING,
    'api.required' => 1,
  ];

  $spec['client_secret'] = [
    'name'         => 'client_secret',
    'title'        => 'Donutapp Client Secret',
    'type'         => CRM_Utils_TYPE::T_STRING,
    'api.required' => 1,
  ];

  $spec['campaign_id'] = [
    'name'         => 'campaign_id',
    'title'        => 'Campaign ID',
    'type'         => CRM_Utils_TYPE::T_STRING,
    'api.required' => 1,
  ];

  $spec['confirm'] = [
    'name'         => 'confirm',
    'title'        => 'Confirm donation retrieval?',
    'type'         => CRM_Utils_TYPE::T_BOOLEAN,
    'api.required' => 0,
    'api.default'  => 1,
  ];

  $spec['processor'] = [
      'name'         => 'processor',
      'title'        => 'Processor Implementation',
      'type'         => CRM_Utils_TYPE::T_STRING,
      'api.required' => 0,
      'api.default'  => 'Greenpeace',
  ];
}

/**
 * DonutDonation.import API
 *
 * @param $params API parameters
 *
 * @return array API result
 * @throws \Exception
 * @throws \GuzzleHttp\Exception\GuzzleException
 */
function civicrm_api3_donut_donation_import($params) {
  $params['limit'] = abs($params['limit']);
  $processor_class_name = "CRM_Donutapp_Processor_{$params['processor']}_Donation";
  if (!class_exists($processor_class_name)) {
    return civicrm_api3_create_error(
        "Processor '{$params['processor']}' incomplete, class {$processor_class_name} not found."
    );
  }

  // run processing
  /** @var CRM_Donutapp_Processor_Base $processor */
  $processor = new $processor_class_name($params);
  $processor->verifySetup();
  $processor->process();
  return civicrm_api3_create_success();
}

/**
 * DonutDonation.confirm API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_donut_donation_confirm_spec(&$spec) {
  $spec['uid'] = [
    'name'         => 'uid',
    'title'        => 'UID',
    'type'         => CRM_Utils_TYPE::T_INT,
    'api.required' => 1,
  ];

  $spec['client_id'] = [
    'name'         => 'client_id',
    'title'        => 'Donutapp Client ID',
    'type'         => CRM_Utils_TYPE::T_STRING,
    'api.required' => 1,
  ];

  $spec['client_secret'] = [
    'name'         => 'client_secret',
    'title'        => 'Donutapp Client Secret',
    'type'         => CRM_Utils_TYPE::T_STRING,
    'api.required' => 1,
  ];
}

/**
 * DonutDonation.confirm API
 *
 * @param $params API parameters
 *
 * @return array API result
 * @throws \Exception
 * @throws \GuzzleHttp\Exception\GuzzleException
 */
function civicrm_api3_donut_donation_confirm($params) {
  CRM_Donutapp_API_Client::setClientId($params['client_id']);
  CRM_Donutapp_API_Client::setClientSecret($params['client_secret']);
  $donation = new CRM_Donutapp_API_Donation(['uid' => $params['uid']]);
  $donation->confirm();
  return civicrm_api3_create_success();
}
