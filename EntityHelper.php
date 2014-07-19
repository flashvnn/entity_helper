<?php
/**
 * @file
 * Implement of EntityHelper.php.
 */

class EntityHelper {
  private static function fieldData($entity, $field_name, $langcode = LANGUAGE_NONE) {
    if($fields = isset($entity->{$field_name}[$langcode]) ? $entity->{$field_name}[$langcode] : FALSE){
      return $fields;
    }

    $langcode = $entity->language;
    return isset($entity->{$field_name}[$langcode]) ? $entity->{$field_name}[$langcode] : FALSE;
  }

  /**
   * Get field items of Entity. An extend of field_get_items function.
   *
   * @param $entity
   *  The entity containing the data to be displayed or entity id.
   * @param $field_name
   *  The field to be displayed.
   * @param null $column
   *  (optional) The column name to get value data.
   * @param $langcode
   *  (optional) The language code $entity->{$field_name} has to be displayed in.
   *  Defaults to the current language.
   *
   * @return array|bool
   * @see field_get_items
   */
  public static function getItems($entity, $field_name, $column = NULL, $langcode = LANGUAGE_NONE) {
    if (is_array($field_name)) {
      $rs = array();
      foreach ($field_name as $fname) {
        $rs[$fname] = self::fieldData($entity, $fname, $langcode);
      }

      return $rs;
    } else {
      if ($field_data = self::fieldData($entity, $field_name, $langcode)) {
        return ($column) ?  array_get($field_data, $column) : $field_data;
      }
    }

    return array();
  }


  /**
   * Get field values of Entity. An extend of field_get_items function.
   *
   * @param $entity_type
   *  The type of $entity; e.g., 'node' or 'user'.
   * @param $entity
   *  The entity containing the data to be displayed or entity id.
   * @param $field_name
   *  The field to be displayed.
   * @param null $column
   *  (optional) The column name to get value data.
   * @param $langcode
   *  (optional) The language code $entity->{$field_name} has to be displayed in.
   *  Defaults to the current language.
   *
   * @return array|bool
   * @see field_get_items
   */
  public static function fieldValues($entity_type, $entity, $field_name, $column = NULL, $langcode = LANGUAGE_NONE) {
    if (is_numeric($entity)) {
      if ($entity = entity_load($entity_type, array( $entity ))) {
        $entity = reset($entity);

        return self::getItems($entity, $field_name, $column, $langcode);
      }
      else {
        return FALSE;
      }
    }
    elseif (is_array($entity)) {
      $entities = entity_load($entity_type, $entity);
      $rs       = array();
      foreach ($entities as $eid => $entity) {
        $rs[$eid] = self::getItems($entity, $field_name, $column, $langcode);
      }

      return $rs;
    }
    else {
      return self::getItems($entity, $field_name, $column, $langcode);
    }
  }
}
