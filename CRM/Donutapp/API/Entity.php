<?php

abstract class CRM_Donutapp_API_Entity {
  protected $data;

  public function __construct($data) {
    $this->data = $data;
  }

  public function getData()
  {
    return $this->data;
  }

  /**
   * Return the requested attribute of the API response
   *
   * @param $property
   *
   * @return mixed
   */
  public function __get($property) {
    if (array_key_exists($property, $this->data)) {
      return $this->data[$property];
    }
    return NULL;
  }

}
