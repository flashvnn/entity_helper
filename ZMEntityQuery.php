<?php

class ZMEntityFieldOr{
  public $fields = array();
  public $fieldConditions = array();

  public function fieldCondition($field, $column = NULL, $value = NULL, $operator = NULL, $delta_group = NULL, $language_group = NULL){
    return $this->addFieldCondition($this->fieldConditions, $field, $column, $value, $operator, $delta_group, $language_group);
  }

  protected function addFieldCondition(&$conditions, $field, $column = NULL, $value = NULL, $operator = NULL, $delta_group = NULL, $language_group = NULL) {
    // The '!=' operator is deprecated in favour of the '<>' operator since the
    // latter is ANSI SQL compatible.
    if ($operator == '!=') {
      $operator = '<>';
    }
    if (is_scalar($field)) {
      $field_definition = field_info_field($field);
      if (empty($field_definition)) {
        throw new EntityFieldQueryException(t('Unknown field: @field_name', array('@field_name' => $field)));
      }
      $field = $field_definition;
    }
    // Ensure the same index is used for field conditions as for fields.
    $index = count($this->fields);
    $this->fields[$index] = $field;
    if (isset($column)) {
      $conditions[$index] = array(
        'field' => $field,
        'column' => $column,
        'value' => $value,
        'operator' => $operator,
        'delta_group' => $delta_group,
        'language_group' => $language_group,
      );
    }
    return $this;
  }
}

/**
 * {@inheritDoc}
 */
class ZMEntityQuery extends EntityFieldQuery{

  private $propertiesOr = array();
  private $preExeIterator;
  private $addedFields = array();
  /**
   * {@inheritDoc}
   * @return ZMEntityQuery
   */
  public function entityCondition($name, $value, $operator = NULL) {
    return parent::entityCondition($name, $value, $operator);
  }
  /**
   * {@inheritDoc}
   * @return ZMEntityQuery
   */
  public function fieldCondition($field, $column = NULL, $value = NULL, $operator = NULL, $delta_group = NULL, $language_group = NULL) {
    return parent::fieldCondition($field, $column, $value, $operator, $delta_group, $language_group);
  }
  /**
   * {@inheritDoc}
   * @return ZMEntityQuery
   */
  public function propertyCondition($column, $value, $operator = NULL) {
    return parent::propertyCondition($column, $value, $operator);
  }
  /**
   * {@inheritDoc}
   * @return ZMEntityQuery
   */
  public function entityOrderBy($name, $direction = 'ASC') {
    return parent::entityOrderBy($name, $direction);
  }
  /**
   * {@inheritDoc}
   * @return ZMEntityQuery
   */
  public function fieldOrderBy($field, $column, $direction = 'ASC') {
    return parent::fieldOrderBy($field, $column, $direction);
  }
  /**
   * {@inheritDoc}
   * @return ZMEntityQuery
   */
  public function propertyOrderBy($column, $direction = 'ASC') {
    return parent::propertyOrderBy($column, $direction);
  }

  /**
   * Add property or condition with db_or.
   *
   * @param $or
   * @return ZMEntityQuery
   */
  public function propertyConditionOr($or) {
    $this->proertiesOr[] = $or;
    return $this;
  }

  /**
   * Finishes the query.
   *
   * Adds tags, metaData, range and returns the requested list or count.
   *
   * @param SelectQuery $select_query
   *   A SelectQuery which has entity_type, entity_id, revision_id and bundle
   *   fields added.
   * @param string $id_key
   *   Which field's values to use as the returned array keys.
   *
   * @return array See EntityFieldQuery::execute().
   */
  function finishQuery($select_query, $id_key = 'entity_id') {
    // http://drupal.org/node/1226622#comment-6809826 - adds support for IS NULL
    // Iterate through all fields.  If the query is trying to fetch results
    // where a field is null, then alter the query to use a LEFT OUTER join.
    // Otherwise the query will always return 0 results.

    // Add property OR condition.
    foreach ($this->propertiesOr as $or) {
      $select_query->condition($or);
    }

    $tables =& $select_query->getTables();
    foreach ($this->fieldConditions as $key => $fieldCondition) {
      if ($fieldCondition['operator'] == 'IS NULL' && isset($this->fields[$key]['storage']['details']['sql'][FIELD_LOAD_CURRENT])) {
        $keys = array_keys($this->fields[$key]['storage']['details']['sql'][FIELD_LOAD_CURRENT]);
        $sql_table = reset($keys);
        foreach ($tables as $table_id => $table) {
          if ($table['table'] == $sql_table) {
            $tables[$table_id]['join type'] = 'LEFT OUTER';
          }
        }
      }
    }

    foreach ($this->tags as $tag) {
      $select_query->addTag($tag);
    }
    foreach ($this->metaData as $key => $object) {
      $select_query->addMetaData($key, $object);
    }
    $select_query->addMetaData('entity_field_query', $this);
    if ($this->range) {
      $select_query->range($this->range['start'], $this->range['length']);
    }
    if ($this->count) {
      if($this->preExeIterator){
        call_user_func($this->preExeIterator, $select_query);
      }
      return $select_query->countQuery()->execute()->fetchField();
    }
    $return = array();

    foreach($this->addedFields as $addedField) {
      $fields = $select_query->getFields();
      if (!empty($addedField['field_name'])) {
        $tables = $select_query->getTables();
        $clean_tables = $this->cleanTables($tables);
        // hardcoded as it is also hardcoded in the fields module
        $table = 'field_data_' . $addedField['field_name'];
        // Get our alias for the selected field
        if (isset($clean_tables[$table])) {
          $addedField['table'] = $clean_tables[$table]['alias'];
        }

        // Set our name and alias
        $column = $addedField['field_name'] . '_' . $addedField['column'];
        $column_alias = $addedField['field_name'] . '_' . $addedField['column_alias'];
      }
      else {
        // Not from a field, so probably a direct entity property
        $column = $addedField['column'];
        $column_alias = $addedField['column_alias'];
      }
      if (!empty($addedField['table'])) {
        // if we know the exact table, set it
        $select_query->addField($addedField['table'], $column, $column_alias);
      }
      else {
        // If not, use the main selected table to fetch the extra field from
        $select_query->addField($fields['entity_id']['table'], $column, $column_alias);
      }
    }

    $tables = $select_query->getTables();
    $clean_tables = $this->cleanTables($tables);

    foreach($this->fieldsConditionOr as $conditionOr){
      $or = db_or();
      foreach ($conditionOr->fieldConditions as $condition) {
        $table = 'field_data_' . $condition['field']['field_name'];
        if($table_alias = $this->getTableAlias($table, $clean_tables)){
          $field_query = $table_alias . "." . $condition['field']['field_name'] . "_" . $condition["column"];
          $or->condition($field_query, $condition['value'], $condition['operator']);
        }
      }
      if (count($or->conditions()) > 0) {
        $select_query->condition($or);
      }
    }

    if($this->preExeIterator){
      call_user_func($this->preExeIterator, $select_query);
    }

    foreach ($select_query->execute() as $partial_entity) {
      $bundle = isset($partial_entity->bundle) ? $partial_entity->bundle : NULL;
      $entity = entity_create_stub_entity($partial_entity->entity_type, array($partial_entity->entity_id, $partial_entity->revision_id, $bundle));
      // This is adding the file id using our metaData field.
      $entity->extraFields = $partial_entity;

      // if the id already exists, merge the data in a smart way. This
      // is completely based on the assumption that we expect a similar entity
      if (isset($return[$partial_entity->entity_type][$partial_entity->$id_key])) {
        $previous_entity = $return[$partial_entity->entity_type][$partial_entity->$id_key];
        foreach ($previous_entity->extraFields as $id => $child) {
          // if the key is the same but the value is not, make it into an array
          if ($entity->extraFields->{$id} != $previous_entity->extraFields->{$id}) {
            $result = array($entity->extraFields->{$id}, $previous_entity->extraFields->{$id});
            $entity->extraFields->{$id} = $result;
          }
        }
      }

      // Add the entitiy to the result set to return
      $return[$partial_entity->entity_type][$partial_entity->$id_key] = $entity;
      $this->ordered_results[] = $partial_entity;
    }
    return $return;
  }

  private function getTableAlias($table, $tables = array()){
    static $addedTables = array();
    if(isset($addedTables[$table])){
      return $addedTables[$table];
    }
    foreach ($tables as $alias => $item) {
      if($item['table'] == $table){
        $addedTables[$table] = $item['alias'];
        return $addedTables[$table];
      }
    }

    return FALSE;
  }

  /**
   * @param $field_name
   * @param $column
   * @param string $column_alias
   * @param string $table
   * @return $this
   */
  public function addExtraField($field_name, $column, $column_alias = NULL, $table = NULL) {
    if (!empty($field_name) && !$this->checkFieldExists($field_name)) {
      // Add the field as a condition, so we generate the join
      $this->fieldCondition($field_name);
    }

    $this->addedFields[] = array(
      'field_name' => $field_name,
      'column' => $column,
      'column_alias' => $column_alias,
      'table' => $table,
    );
    return $this;
  }

  /**
   * Give the values in the array the name of the real table instead of the
   * alias, so we can look up the alias quicker
   * @param string $tables
   * @return array
   */
  private function cleanTables($tables) {
    if (!is_array($tables)) {
      return array();
    }
    foreach ($tables as $table_id => $table) {
      if ($table['join type'] == 'INNER') {
        $tables[$table['table']] = $table;
        unset($tables[$table_id]);
      }
    }
    return $tables;
  }
  public function preExecuteQuery($iterator = NULL) {
    $this->preExeIterator = $iterator;
    return $this;
  }

  private $fieldsConditionOr = array();
  public function fieldConditionOr(ZMEntityFieldOr $or) {
    $this->fieldsConditionOr[] = $or;
    foreach($or->fields as $field){
      $field_name = $field['field_name'];
      if (!empty($field_name) && !$this->checkFieldExists($field_name)) {
        $this->fieldCondition($field_name);
      }
    }
    return $this;
  }
  /**
   * Check if the field already has a table that does a join.
   * @param type $field_name
   * @return boolean
   */
  private function checkFieldExists($field_name) {
    $fields = $this->fields;
    foreach($fields as $field) {
      if (isset($field['field_name']) && $field['field_name'] == $field_name) {
        return TRUE;
      }
    }
    return FALSE;
  }
}
