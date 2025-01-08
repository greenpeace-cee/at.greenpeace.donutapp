<?php

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Test petition import
 *
 * @group headless
 */
abstract class CRM_Donutapp_Processor_Greenpeace_BaseTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {
  use \Civi\Test\Api3TestTrait;

  protected $campaignId;
  protected $mailingActivityTypeID;

  public function setUp(): void {
    parent::setUp();

    // required to add e.g. custom fields for "Petition" activity type as
    // CRM_Core_PseudoConstant::activityType() only returns activity types
    // associated with enabled components
    CRM_Core_BAO_ConfigSetting::enableComponent('CiviCampaign');

    // test dates against UTC
    date_default_timezone_set('UTC');

    // use mock URLs for DonutApp API
    CRM_Donutapp_API_Client::setAPIEndpoint('https://donutapp.mock/api/v1/');
    CRM_Donutapp_API_Client::setOAuth2Endpoint('https://donutapp.mock/o/token/?grant_type=client_credentials');

    // pretend we're userID 1
    $session = CRM_Core_Session::singleton();
    $session->set('userID', 1);

    $this->setUpXcm();
    $this->setupFieldsAndData();
  }

  private function setUpXcm() {
    $profiles = CRM_Xcm_Configuration::getProfileList();
    if (!array_key_exists('engagement', $profiles)) {
      $config = CRM_Xcm_Configuration::getConfigProfile();
      $options = $config->getOptions();
      $options['default_location_type'] = $this->callAPISuccess(
        'LocationType',
        'get',
        ['is_default' => 1]
      );
      $options['fill_details'] = ['email', 'phone'];
      $options['fill_details_primary'] = 1;
      $options['match_contact_id'] = 1;
      $config->setOptions($options);
      $config->cloneProfile('engagement');
      $config->cloneProfile('DD');
    }
  }

  public function tearDown(): void {
    parent::tearDown();
  }

  protected function getLastImportError() {
    return reset(civicrm_api3('Activity', 'get', [
      'activity_type_id' => 'streetimport_error',
      'options'          => [
        'limit' => 1,
        'sort'  => 'activity_date_time DESC'
      ],
    ])['values']);
  }

  protected function setupFieldsAndData() {
    $this->callApiSuccess('OptionValue', 'create', [
      'option_group_id' => 'activity_type',
      'name'            => 'streetimport_error',
      'label'           => 'Import Error',
      'is_active'       => 1
    ]);

    if ($this->callAPISuccess('ContactType', 'getcount', ['name' => 'Dialoger']) == 0) {
      $this->callAPISuccess('ContactType', 'create', [
        'parent_id' => 'Individual',
        'name'      => 'Dialoger',
      ]);
    }

    $this->campaignId = reset($this->callAPISuccess('Campaign', 'create', [
      'name'                => 'DD',
      'title'               => 'Direct Dialog',
      'external_identifier' => 'DD',
    ])['values'])['id'];

    $this->callAPISuccess('Group', 'create', [
      'title' => 'Community NL',
      'name'  => 'Community_NL',
    ]);

    $this->mailingActivityTypeID = reset($this->callAPISuccess('OptionValue', 'create', [
      'option_group_id' => 'activity_type',
      'name'            => 'Online_Mailing',
      'label'           => 'Online Mailing',
    ])['values'])['value'];

    $this->callAPISuccess('CustomGroup', 'create', [
      'title' => 'Email Information',
      'name' => 'email_information',
      'extends' => 'Activity',
      'extends_entity_column_value' => $this->mailingActivityTypeID,
    ]);

    $this->callAPISuccess('CustomField', 'create', [
      'custom_group_id' => 'email_information',
      'label' => 'Email',
      'name' => 'email',
      'data_type' => 'String',
      'html_type' => 'Text',
    ]);

    $this->callAPISuccess('CustomField', 'create', [
      'custom_group_id' => 'email_information',
      'label' => 'Email Provider',
      'name' => 'email_provider',
      'data_type' => 'String',
      'html_type' => 'Text',
    ]);

    $this->callAPISuccess('CustomField', 'create', [
      'custom_group_id' => 'email_information',
      'label' => 'Mailing Subject',
      'name' => 'mailing_subject',
      'data_type' => 'String',
      'html_type' => 'Text',
    ]);

    $this->callAPISuccess('CustomField', 'create', [
      'custom_group_id' => 'email_information',
      'label' => 'Mailing Type',
      'name' => 'mailing_type',
      'data_type' => 'String',
      'html_type' => 'Text',
    ]);

    $this->callAPISuccess('CustomGroup', 'create', [
      'title' => 'Dialoger Information',
      'name' => 'dialoger_data',
      'extends' => 'Individual',
      'extends_entity_column_value' => 'Dialoger',
    ]);

    $this->callAPISuccess('CustomField', 'create', [
      'custom_group_id' => 'dialoger_data',
      'name' => 'dialoger_id',
      'data_type' => 'String',
      'html_type' => 'Text',
      'label' => 'Dialoger',
    ]);
  }

}
