<?php

namespace Drupal\json_form_widget;

use Drupal\Core\Datetime\DrupalDateTime;

/**
 * JSON form widget value helper service.
 */
class ValueHandler {

  /**
   * Flatten values.
   */
  public function flattenValues($formValues, $property, $schema) {
    $data = [];

    switch ($schema->type) {
      case 'string':
        $data = $this->handleStringValues($formValues, $property);
        if ($property === 'hasEmail' && is_string($data)) {
          $data = 'mailto:' . str_replace('mailto:', '', $data);
        }
        break;

      case 'object':
        $data = $this->handleObjectValues($formValues[$property][$property], $property, $schema);
        break;

      case 'array':
        $data = $this->handleArrayValues($formValues, $property, $schema);
        break;

      case 'integer':
        $data = $this->handleIntegerValues($formValues, $property);
    }
    return $data;
  }

  /**
   * Flatten values for string properties.
   */
  public function handleStringValues($formValues, $property) {

    if (!isset($formValues[$property])) {
      return FALSE;
    }

    return match (TRUE) {
      $formValues[$property] instanceof DrupalDateTime => $formValues[$property]->format('c', ['timezone' => 'UTC']),
      isset($formValues[$property]['date_range']) => $formValues[$property]['date_range'],
      isset($formValues[$property]['select']) => $formValues[$property][0] ?? NULL,
      isset($formValues[$property]['value']) => $formValues[$property]['value'],
      is_string($formValues[$property] ?? NULL) => $this->cleanSelectId($formValues[$property]),
      default => FALSE,
    };

  }

  /**
   * Extract integer values from form submission.
   */
  public function handleIntegerValues($formValues, $property) {
    return isset($formValues[$property]) ? intval($formValues[$property]) : NULL;
  }

  /**
   * Flatten values for object properties.
   */
  public function handleObjectValues($formValues, $property, $schema) {
    if (!isset($formValues)) {
      return FALSE;
    }

    if (isset($formValues['@type'])) {
      $formValues = $this->processTypeValue($formValues);
    }

    $properties = array_keys((array) $schema->properties);
    $data = [];
    foreach ($properties as $sub_property) {
      $value = $this->flattenValues($formValues, $sub_property, $schema->properties->$sub_property);
      if ($value) {
        $data[$sub_property] = $value;
      }
    }
    return $data ?: FALSE;
  }

  /**
   * Sets "@type" to null if other fields are empty.
   *
   * @param array $formValues
   *   Form values.
   *
   * @return array
   *   Processed form values.
   */
  protected function processTypeValue(array $formValues): array {
    // $formValues without the '@type' key.
    $formValuesNoType = array_diff_key($formValues, array_flip(['@type']));

    foreach ($formValuesNoType as $value) {
      // If a single value is not empty - return the original $formValues array.
      if (!$this->isValueEmpty($value)) {
        return $formValues;
      }
    }

    // All values are empty. '@type' needs to be empty too.
    return array_merge(['@type' => NULL], $formValuesNoType);
  }

  /**
   * Check if a values id empty.
   *
   * @param mixed $value
   *   A form value.
   *
   * @return bool
   *   TRUE if the value is empty, FALSE if it is not.
   */
  protected function isValueEmpty(mixed $value): bool {
    if (is_scalar($value)) {
      return empty($value);
    }

    $value = (array) $value;
    return empty(array_filter($value));
  }

  /**
   * Flatten values for array properties.
   */
  public function handleArrayValues($formValues, $property, $schema) {
    $data = [];
    $subschema = $schema->items;
    if ($subschema->type === "object") {
      return $this->getObjectInArrayData($formValues, $property, $subschema);
    }
    if (isset($formValues[$property][$property])) {
      foreach ($formValues[$property][$property] as $value) {
        $data = array_merge($data, $this->flattenArraysInArrays($value));
      }
    }
    return !empty($data) ? $data : FALSE;
  }

  /**
   * Flatten values for arrays in arrays.
   */
  protected function flattenArraysInArrays($value) {
    $data = [];
    if (isset($value['actions'])) {
      unset($value['actions']);
    }
    if (is_array($value)) {
      foreach ($value as $item) {
        $data[] = is_array($item) ? $this->flattenArraysInArrays($item) : $this->cleanSelectId($item);
      }
    }
    elseif (!empty($value)) {
      $data[] = $this->cleanSelectId($value);
    }
    return $data;
  }

  /**
   * Clear item from select2 $ID.
   *
   * @param string $value
   *   Value that we want to clean.
   *
   * @return string
   *   String without $ID:.
   */
  protected function cleanSelectId($value) {
    if (str_starts_with($value, "\$ID:")) {
      return substr($value, 4);
    }
    return $value;
  }

  /**
   * Flatten values for objects in arrays.
   */
  protected function getObjectInArrayData($formValues, $property, $schema) {
    $data = [];
    if (isset($formValues[$property][$property])) {
      foreach ($formValues[$property][$property] as $key => $item) {
        $value = $this->handleObjectValues($formValues[$property][$property][$key][$property], $property, $schema);
        if ($value) {
          $data[$key] = $value;
        }
      }
    }
    return $data;
  }

}
