<?php

use Civi\Api4\Contact;

class CRM_Donutapp_Util {
  // @TODO: use a more generic name/type
  static $IMPORT_ERROR_ACTIVITY_TYPE = 'streetimport_error';

  public static function createImportError($component, $message, $context = NULL) {
    $details = '<h3>Error:</h3>
                <pre>' . $message . "</pre>
                <h3>Context:</h3>
                <pre>" . print_r($context, TRUE) . '</pre>';
    if (is_object($message) && $message instanceof Exception) {
      $details .= '<h3>Backtrace:</h3><pre>' . $message->getTraceAsString() . '</pre>';
    }
    $params = [
      'activity_type_id' => self::$IMPORT_ERROR_ACTIVITY_TYPE,
      'subject'          => 'DonutApp ' . $component . ' Error',
      'status_id'        => 'Scheduled',
      'details'          => $details,
    ];
    civicrm_api3('Activity', 'create', $params);
  }

  public static function getGender(CRM_Donutapp_API_Entity $entity) {
    $salutation = $entity->donor_salutation;
    if (!empty($salutation)) {
      switch ($salutation) {
        case 1:
          return 'male';

        case 2:
          return 'female';

        case 5:
          return 'other';

      }
    }
    else {
      switch ($entity->donor_sex) {
        case 1:
          return 'male';

        case 2:
          return 'female';

      }
    }
    return NULL;
  }

  public static function getPrefix(CRM_Donutapp_API_Entity $entity) {
    $gender = self::getGender($entity);
    switch ($gender) {
      case 'male':
        return 3;

      case 'female':
        return 2;

    }
    return NULL;
  }

  public static function getContactIdByDialogerId($dialoger_id) {
    // try to find dialoger via identitytracker
    try {
      $result = civicrm_api3('Contact', 'findbyidentity', [
        'identifier' => $dialoger_id,
        'identifier_type' => 'dialoger_id',
      ]);
      if (!empty($result['id'])) {
        return $result['id'];
      }
    } catch (Exception $e) {
      Civi::log()->warning('Unable to identify dialoger using identitytracker: ' . $e->getMessage());
    }
    // couldn't find via identitytracker, possibly because of dupe ID
    // fall back to lookup via Dialoger ID field, preferring contact with most recent start date
    $contact = Contact::get(FALSE)
      ->addWhere('dialoger_data.dialoger_id', '=', $dialoger_id)
      ->addOrderBy('dialoger_data.dialoger_start_date', 'DESC')
      ->execute()
      ->first();
    if (!empty($contact['id'])) {
      return $contact['id'];
    }
    return NULL;
  }

}
