<?php

abstract class CRM_Donutapp_Processor_Greenpeace_Base extends CRM_Donutapp_Processor_Base {

  public function verifySetup()
  {
    // make sure we have all the extensions
    $this->assertExtensionInstalled('de.systopia.xcm');
    $this->assertExtensionInstalled('de.systopia.contract');
    $this->assertExtensionInstalled('org.project60.sepa');
    $this->assertExtensionInstalled('com.cividesk.normalize');
  }

  /**
   * Determine the Civi Campaign ID for an API entity
   *
   * @param \CRM_Donutapp_API_Entity $entity
   *
   * @return int
   */
  protected function getCampaign(CRM_Donutapp_API_Entity $entity) {
    // hi. you might be thinking: why isn't this using the null coalescing
    // operator for external_campaign_id? that's because we don't trust
    // Formunauts not to send empty strings or other empty-ish values that are
    // not NULL, so empty() is safer here.
    $external_campaign_id = $entity->external_campaign_id;
    return empty($external_campaign_id) ?
      ($this->getMappedCampaign($entity) ?? $this->params['campaign_id']) :
      $external_campaign_id;
  }

  /**
   * Get the Civi campaign mapping to a DonutApp campaign
   *
   * @param \CRM_Donutapp_API_Entity $entity
   */
  protected function getMappedCampaign(CRM_Donutapp_API_Entity $entity) {
    $entity_campaign = $entity->campaign_id;
    $map = Civi::settings()->get('donutapp_campaign_map') ?? [];
    if (empty($entity_campaign) || empty($map[$entity_campaign])) {
      return NULL;
    }

    return $map[$entity_campaign];
  }

  /**
   * Find or create a dialoger based on the dialoger ID
   *
   * @param $entity
   *
   * @return |null
   * @throws \CiviCRM_API3_Exception
   */
  protected function findOrCreateDialoger($entity) {
    if (!preg_match('/^(\w+)\-(\d+)$/', $entity->fundraiser_code, $match)) {
      return NULL;
    }
    $dialoger_id = str_replace('gpat-', '', $entity->fundraiser_code);
    $dialoger = CRM_Donutapp_Util::getContactIdByDialogerId($dialoger_id);
    if (!empty($dialoger)) {
      return $dialoger;
    }
    // no matching dialoger found, create with dialoger_id and name
    $dialoger_id_field = 'custom_' . CRM_Core_BAO_CustomField::getCustomFieldID('dialoger_id', 'dialoger_data');
    $dialoger_start_field = 'custom_' . CRM_Core_BAO_CustomField::getCustomFieldID('dialoger_start_date', 'dialoger_data');
    $name = explode(',', $entity->fundraiser_name);
    $first_name = $last_name = NULL;
    if (count($name) == 2) {
      $first_name = trim($name[1]);
      $last_name = $name[0];
    }
    else {
      $last_name = $entity->fundraiser_name;
    }
    // fetch campaign title for contact source
    $source = civicrm_api3('Campaign', 'getvalue', [
      'external_identifier' => 'DD',
      'return'              => 'title',
    ]);

    // create dialoger. We assume they started on the first of this month
    $dialoger = civicrm_api3('Contact', 'create', [
      'contact_type'        => 'Individual',
      'contact_sub_type'    => 'Dialoger',
      'last_name'           => $last_name,
      'first_name'          => $first_name,
      'source'              => $source,
      $dialoger_id_field    => $dialoger_id,
      $dialoger_start_field => date('Y-m-01'),
    ]);
    return $dialoger['id'];
  }

  /**
   * Create activities for welcome/thank you email
   *
   * @param \CRM_Donutapp_API_Entity $entity
   * @param $contactId
   * @param $parentActivityId
   *
   * @throws \Exception
   */
  protected function processWelcomeEmail(CRM_Donutapp_API_Entity $entity, $contactId, $parentActivityId = NULL) {
    $create_date = new DateTime($entity->createtime);
    $create_date->setTimezone(new DateTimeZone(date_default_timezone_get()));
    $apiParams = [
      'activity_date_time' => $create_date->format('YmdHis'),
    ];

    $bounced = FALSE;
    $email_status = $entity->welcome_email_status;
    if (!empty($email_status)) {
      switch ($email_status) {
        case 'bounce':
        case 'hard_bounce':
          $bounced = TRUE;
          // no break
        case 'sent':
        case 'open':
        case 'spam':
          // spam means email was reported as spam by the recipient, not that
          // the receiving MTA flagged the message as spam, so delivery was
          // successful. see https://app.mailjet.com/docs/email_status
        case 'click':
          $email_activity = $this->addEmailActivity(
            $contactId,
            $parentActivityId,
            $this->getCampaign($entity),
            $entity->donor_email,
            $this->getEmailSubject($entity),
            $apiParams
          );
          break;

        case 'queued':
        case 'failed':
        case 'blocked':
        case 'retrying':
          // no-op
          break;

        default:
          throw new CRM_Donutapp_Processor_Exception("Unknown value '{$email_status}' for welcome_email_status");
      }
    }
    if ($bounced) {
      $bounce_type = 'Softbounce';
      if ($entity->welcome_email_status == 'hard_bounce') {
        $bounce_type = 'Hardbounce';
      }
      $this->addBounce(
        $bounce_type,
        $contactId,
        $email_activity['id'],
        $this->getCampaign($entity),
        $entity->donor_email,
        $apiParams
      );
    }
  }

  protected function addEmailActivity($contactId, $parentActivityId, $campaignId, $email, $subject, $apiParams = []) {
    $parent_field = 'custom_' . CRM_Core_BAO_CustomField::getCustomFieldID(
      'parent_activity_id',
      'activity_hierarchy'
    );
    $email_field = 'custom_' . CRM_Core_BAO_CustomField::getCustomFieldID(
      'email',
      'email_information'
    );
    $email_provider_field = 'custom_' . CRM_Core_BAO_CustomField::getCustomFieldID(
      'email_provider',
      'email_information'
    );
    $mailing_type_field = 'custom_' . CRM_Core_BAO_CustomField::getCustomFieldID(
      'mailing_type',
      'email_information'
    );
    $mailing_subject_field = 'custom_' . CRM_Core_BAO_CustomField::getCustomFieldID(
      'mailing_subject',
      'email_information'
    );

    $params = [
      'target_id'            => $contactId,
      'activity_type_id'     => 'Online_Mailing',
      'status_id'            => 'Completed',
      'medium_id'            => 'email',
      'campaign_id'          => $campaignId,
      'subject'              => $subject,
      $email_field           => $email,
      $mailing_subject_field => $subject,
      $parent_field          => $parentActivityId,
      $email_provider_field  => 'Formunauts',
      $mailing_type_field    => 'Transactional',
    ];

    // TODO: add to civicrm_activity_contact_email

    return civicrm_api3(
      'Activity',
      'create',
      array_merge($params, $apiParams)
    );
  }

  protected function addBounce($bounceType, $contactId, $parentActivityId, $campaignId, $email, $apiParams = []) {
    $parent_field = 'custom_' . CRM_Core_BAO_CustomField::getCustomFieldID(
      'parent_activity_id',
      'activity_hierarchy'
    );
    $bounce_type_field = 'custom_' . CRM_Core_BAO_CustomField::getCustomFieldID(
      'bounce_type',
      'bounce_information'
    );
    $email_provider_field = 'custom_' . CRM_Core_BAO_CustomField::getCustomFieldID(
      'email_provider',
      'bounce_information'
    );
    $email_field = 'custom_' . CRM_Core_BAO_CustomField::getCustomFieldID(
      'email',
      'bounce_information'
    );

    $params = [
      'target_id'           => $contactId,
      'activity_type_id'    => 'Bounce',
      'medium_id'           => 'email',
      'status_id'           => 'Completed',
      'campaign_id'         => $campaignId,
      'subject'             => $bounceType,
      $email_field          => $email,
      $bounce_type_field    => $bounceType,
      $parent_field         => $parentActivityId,
      $email_provider_field => 'Formunauts',
    ];

    return civicrm_api3(
      'Activity',
      'create',
      array_merge($params, $apiParams)
    );
  }

  protected function getGroupId($groupName) {
    return civicrm_api3('Group', 'getvalue', [
      'title'  => $groupName,
      'return' => 'id',
    ]);
  }

  protected function addGroup($contactId, $groupName) {
    return civicrm_api3('GroupContact', 'create', [
      'group_id' => $this->getGroupId($groupName),
      'contact_id' => $contactId,
    ]);
  }

  /**
   * Determine whether $entity is on hold
   *
   * This inspects the "status" property or the deprecated on_hold property if
   * it is set
   *
   * @param \CRM_Donutapp_API_Entity $entity
   *
   * @return bool
   */
  protected function isOnHold(CRM_Donutapp_API_Entity $entity) {
    if (is_null($entity->on_hold)) {
      // if status is not any of the listed values, it's on hold
      return !in_array($entity->status, [
        'none',
        'approved',
        'conditionally-approved',
        'live',
      ]);
    }
    else {
      return (bool) $entity->on_hold;
    }
  }

}
