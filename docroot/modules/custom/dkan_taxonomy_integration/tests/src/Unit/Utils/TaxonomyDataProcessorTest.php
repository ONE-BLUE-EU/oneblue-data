<?php

namespace Drupal\Tests\dkan_taxonomy_integration\Unit\Utils;

use Drupal\dkan_taxonomy_integration\Utils\TaxonomyDataProcessor;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TaxonomyDataProcessor utility functions.
 *
 * @group dkan_taxonomy_integration
 * @group unit
 */
class TaxonomyDataProcessorTest extends TestCase {

  /**
   * Test convertFieldNameToApiName function.
   */
  public function testConvertFieldNameToApiName(): void {
    // Test field name with field_ prefix
    $this->assertEquals('country', TaxonomyDataProcessor::convertFieldNameToApiName('field_country'));
    $this->assertEquals('marine_species', TaxonomyDataProcessor::convertFieldNameToApiName('field_marine_species'));

    // Test field name without field_ prefix
    $this->assertEquals('country', TaxonomyDataProcessor::convertFieldNameToApiName('country'));
    $this->assertEquals('', TaxonomyDataProcessor::convertFieldNameToApiName(''));
  }

  /**
   * Test convertApiNameToFieldName function.
   */
  public function testConvertApiNameToFieldName(): void {
    // Test API name without field_ prefix
    $this->assertEquals('field_country', TaxonomyDataProcessor::convertApiNameToFieldName('country'));
    $this->assertEquals('field_marine_species', TaxonomyDataProcessor::convertApiNameToFieldName('marine_species'));

    // Test API name with field_ prefix (should remain unchanged)
    $this->assertEquals('field_country', TaxonomyDataProcessor::convertApiNameToFieldName('field_country'));
    $this->assertEquals('field_', TaxonomyDataProcessor::convertApiNameToFieldName(''));
  }

  /**
   * Test processFieldTerms function.
   */
  public function testProcessFieldTerms(): void {
    // Create mock term objects
    $term1 = $this->createMockTerm('uuid-1', 'Term 1');
    $term2 = $this->createMockTerm('uuid-2', 'Term 2');

    // Test with proper term references
    $field_terms = [
      [
        'target_id' => 1,
        'entity' => $term1,
      ],
      [
        'target_id' => 2,
        'entity' => $term2,
      ],
    ];

    $result = TaxonomyDataProcessor::processFieldTerms($field_terms);

    $this->assertCount(2, $result);
    $this->assertEquals('uuid-1', $result[0]['identifier']);
    $this->assertEquals('Term 1', $result[0]['data']);
    $this->assertEquals('uuid-2', $result[1]['identifier']);
    $this->assertEquals('Term 2', $result[1]['data']);
  }

  /**
   * Test processFieldTerms with pre-formatted data.
   */
  public function testProcessFieldTermsWithPreFormattedData(): void {
    $field_terms = [
      [
        'identifier' => 'uuid-1',
        'data' => 'Term 1',
      ],
      [
        'identifier' => 'uuid-2',
        'data' => 'Term 2',
      ],
    ];

    $result = TaxonomyDataProcessor::processFieldTerms($field_terms);

    $this->assertCount(2, $result);
    $this->assertEquals('uuid-1', $result[0]['identifier']);
    $this->assertEquals('Term 1', $result[0]['data']);
  }

  /**
   * Test processFieldTerms with invalid data.
   */
  public function testProcessFieldTermsWithInvalidData(): void {
    $field_terms = [
      'invalid_data',
      [],
      [
        'target_id' => 1,
        // Missing entity
      ],
    ];

    $result = TaxonomyDataProcessor::processFieldTerms($field_terms);

    $this->assertEmpty($result);
  }

  /**
   * Test updateJsonMetadata function.
   */
  public function testUpdateJsonMetadata(): void {
    $original_metadata = json_encode([
      'title' => 'Test Dataset',
      'description' => 'Test description',
      'old_taxonomy_field' => ['old data'],
    ]);

    $taxonomy_data = [
      'country' => [
        ['identifier' => 'uuid-1', 'data' => 'USA'],
      ],
      'singer' => [
        ['identifier' => 'uuid-2', 'data' => 'Taylor Swift'],
      ],
    ];

    $processed_fields = ['country', 'singer'];

    $result = TaxonomyDataProcessor::updateJsonMetadata($original_metadata, $taxonomy_data, $processed_fields);
    $decoded_result = json_decode($result, TRUE);

    $this->assertNotNull($result);
    $this->assertEquals('Test Dataset', $decoded_result['title']);
    $this->assertEquals('Test description', $decoded_result['description']);
    $this->assertArrayHasKey('country', $decoded_result);
    $this->assertArrayHasKey('singer', $decoded_result);
    $this->assertEquals('USA', $decoded_result['country'][0]['data']);
    $this->assertEquals('Taylor Swift', $decoded_result['singer'][0]['data']);
  }

  /**
   * Test updateJsonMetadata with invalid JSON.
   */
  public function testUpdateJsonMetadataWithInvalidJson(): void {
    $result = TaxonomyDataProcessor::updateJsonMetadata('invalid json', [], []);
    $this->assertNull($result);
  }

  /**
   * Test removeObsoleteFields function.
   */
  public function testRemoveObsoleteFields(): void {
    $metadata = [
      'title' => 'Test Dataset',
      'country' => ['data'],
      'singer' => ['data'],
      'old_category' => ['old data'],
      'description' => 'Test description',
    ];

    $current_fields = ['country', 'singer'];

    $result = TaxonomyDataProcessor::removeObsoleteFields($metadata, $current_fields);

    $this->assertArrayHasKey('title', $result);
    $this->assertArrayHasKey('country', $result);
    $this->assertArrayHasKey('singer', $result);
    $this->assertArrayHasKey('description', $result);
    $this->assertArrayNotHasKey('old_category', $result);
  }

  /**
   * Test isTaxonomyField function.
   */
  public function testIsTaxonomyField(): void {
    // Test recognized taxonomy patterns
    $this->assertTrue(TaxonomyDataProcessor::isTaxonomyField('country'));
    $this->assertTrue(TaxonomyDataProcessor::isTaxonomyField('singer'));
    $this->assertTrue(TaxonomyDataProcessor::isTaxonomyField('category'));
    $this->assertTrue(TaxonomyDataProcessor::isTaxonomyField('marine_category'));

    // Test non-taxonomy fields
    $this->assertFalse(TaxonomyDataProcessor::isTaxonomyField('title'));
    $this->assertFalse(TaxonomyDataProcessor::isTaxonomyField('description'));
    $this->assertFalse(TaxonomyDataProcessor::isTaxonomyField('unknown_field'));
  }

  /**
   * Test unifyTaxonomyTerms function.
   */
  public function testUnifyTaxonomyTerms(): void {
    $raw_data = [
      'title' => 'Test Dataset',
      'country' => [
        ['identifier' => 'uuid-1', 'data' => 'USA'],
        ['identifier' => 'uuid-2', 'data' => 'Canada'],
      ],
      'singer' => [
        ['identifier' => 'uuid-3', 'data' => 'Taylor Swift'],
      ],
      'description' => 'Test description',
    ];

    $custom_fields_info = [
      'field_country' => [
        'vocabulary_id' => 'country',
        'vocabulary_name' => 'Country',
      ],
      'field_singer' => [
        'vocabulary_id' => 'singer',
        'vocabulary_name' => 'Singer',
      ],
    ];

    $result = TaxonomyDataProcessor::unifyTaxonomyTerms($raw_data, $custom_fields_info);

    // Check that original fields are preserved
    $this->assertEquals('Test Dataset', $result['title']);
    $this->assertEquals('Test description', $result['description']);

    // Check that individual taxonomy fields are removed
    $this->assertArrayNotHasKey('country', $result);
    $this->assertArrayNotHasKey('singer', $result);

    // Check that unified taxonomy_term is created
    $this->assertArrayHasKey('taxonomy_term', $result);
    $this->assertCount(3, $result['taxonomy_term']);

    // Check first country term
    $this->assertEquals('uuid-1', $result['taxonomy_term'][0]['identifier']);
    $this->assertEquals('USA', $result['taxonomy_term'][0]['data']);
    $this->assertEquals('country', $result['taxonomy_term'][0]['taxonomy_vocabulary']);

    // Check second country term
    $this->assertEquals('uuid-2', $result['taxonomy_term'][1]['identifier']);
    $this->assertEquals('Canada', $result['taxonomy_term'][1]['data']);

    // Check singer term
    $this->assertEquals('uuid-3', $result['taxonomy_term'][2]['identifier']);
    $this->assertEquals('Taylor Swift', $result['taxonomy_term'][2]['data']);
    $this->assertEquals('singer', $result['taxonomy_term'][2]['taxonomy_vocabulary']);
  }

  /**
   * Test unifyTaxonomyTerms with empty taxonomy fields.
   */
  public function testUnifyTaxonomyTermsWithEmptyFields(): void {
    $raw_data = [
      'title' => 'Test Dataset',
      'country' => [],
      'singer' => null,
      'description' => 'Test description',
    ];

    $custom_fields_info = [
      'field_country' => [
        'vocabulary_id' => 'country',
        'vocabulary_name' => 'Country',
      ],
      'field_singer' => [
        'vocabulary_id' => 'singer',
        'vocabulary_name' => 'Singer',
      ],
    ];

    $result = TaxonomyDataProcessor::unifyTaxonomyTerms($raw_data, $custom_fields_info);

    // Check that original fields are preserved
    $this->assertEquals('Test Dataset', $result['title']);
    $this->assertEquals('Test description', $result['description']);

    // Check that individual taxonomy fields are removed
    $this->assertArrayNotHasKey('country', $result);
    $this->assertArrayNotHasKey('singer', $result);

    // Check that unified taxonomy_term is created but empty
    $this->assertArrayHasKey('taxonomy_term', $result);
    $this->assertEmpty($result['taxonomy_term']);
  }

  /**
   * Test createTaxonomyTermSchemaData function.
   */
  public function testCreateTaxonomyTermSchemaData(): void {
    $result = TaxonomyDataProcessor::createTaxonomyTermSchemaData('uuid-123', 'Term Name', 'vocab_id');

    $this->assertEquals('uuid-123', $result['identifier']);
    $this->assertEquals('Term Name', $result['data']);
    $this->assertEquals('vocab_id', $result['taxonomy_vocabulary']);
  }

  /**
   * Test that taxonomy_vocabulary is always returned as a string (machine name only).
   */
  public function testTaxonomyVocabularyStructureIsStringOnly(): void {
    // Test with animal vocabulary
    $animal_result = TaxonomyDataProcessor::createTaxonomyTermSchemaData('uuid-animal', 'Lion', 'animal');
    $this->assertIsString($animal_result['taxonomy_vocabulary']);
    $this->assertEquals('animal', $animal_result['taxonomy_vocabulary']);
    $this->assertArrayNotHasKey('key', $animal_result);
    $this->assertArrayNotHasKey('name', $animal_result);

    // Test with country vocabulary
    $country_result = TaxonomyDataProcessor::createTaxonomyTermSchemaData('uuid-country', 'USA', 'country');
    $this->assertIsString($country_result['taxonomy_vocabulary']);
    $this->assertEquals('country', $country_result['taxonomy_vocabulary']);

    // Test with custom vocabulary
    $custom_result = TaxonomyDataProcessor::createTaxonomyTermSchemaData('uuid-custom', 'Custom Term', 'my_custom_vocab');
    $this->assertIsString($custom_result['taxonomy_vocabulary']);
    $this->assertEquals('my_custom_vocab', $custom_result['taxonomy_vocabulary']);
  }

  /**
   * Test unified taxonomy terms structure with simplified vocabulary format.
   */
  public function testUnifyTaxonomyTermsSimplifiedVocabularyStructure(): void {
    $raw_data = [
      'title' => 'Test Dataset',
      'animal' => [
        [
          'identifier' => 'uuid-lion',
          'data' => 'Lion',
        ],
        [
          'identifier' => 'uuid-tiger',
          'data' => 'Tiger',
        ]
      ],
      'country' => [
        [
          'identifier' => 'uuid-usa',
          'data' => 'USA',
        ]
      ]
    ];

    $custom_fields_info = [
      'field_animal' => [
        'vocabulary_id' => 'animal',
        'vocabulary_name' => 'Animal'
      ],
      'field_country' => [
        'vocabulary_id' => 'country',
        'vocabulary_name' => 'Country'
      ]
    ];

    $result = TaxonomyDataProcessor::unifyTaxonomyTerms($raw_data, $custom_fields_info);

    // Should have taxonomy_term field
    $this->assertArrayHasKey('taxonomy_term', $result);
    $this->assertCount(3, $result['taxonomy_term']);

    // Check animal terms have string vocabulary
    $this->assertEquals('uuid-lion', $result['taxonomy_term'][0]['identifier']);
    $this->assertEquals('Lion', $result['taxonomy_term'][0]['data']);
    $this->assertIsString($result['taxonomy_term'][0]['taxonomy_vocabulary']);
    $this->assertEquals('animal', $result['taxonomy_term'][0]['taxonomy_vocabulary']);

    $this->assertEquals('uuid-tiger', $result['taxonomy_term'][1]['identifier']);
    $this->assertEquals('Tiger', $result['taxonomy_term'][1]['data']);
    $this->assertIsString($result['taxonomy_term'][1]['taxonomy_vocabulary']);
    $this->assertEquals('animal', $result['taxonomy_term'][1]['taxonomy_vocabulary']);

    // Check country term has string vocabulary
    $this->assertEquals('uuid-usa', $result['taxonomy_term'][2]['identifier']);
    $this->assertEquals('USA', $result['taxonomy_term'][2]['data']);
    $this->assertIsString($result['taxonomy_term'][2]['taxonomy_vocabulary']);
    $this->assertEquals('country', $result['taxonomy_term'][2]['taxonomy_vocabulary']);

    // Ensure no nested objects exist
    foreach ($result['taxonomy_term'] as $term) {
      $this->assertIsString($term['taxonomy_vocabulary']);
      $this->assertArrayNotHasKey('key', $term);
      $this->assertArrayNotHasKey('name', $term);
    }
  }

  /**
   * Test validateTaxonomyTermData function.
   */
  public function testValidateTaxonomyTermData(): void {
    // Test valid data
    $valid_data = [
      'identifier' => 'uuid-123',
      'data' => 'Term Name',
      'taxonomy_vocabulary' => 'vocab_id',
    ];
    $this->assertTrue(TaxonomyDataProcessor::validateTaxonomyTermData($valid_data));

    // Test invalid data - missing identifier
    $invalid_data1 = [
      'data' => 'Term Name',
      'taxonomy_vocabulary' => 'vocab_id',
    ];
    $this->assertFalse(TaxonomyDataProcessor::validateTaxonomyTermData($invalid_data1));

    // Test invalid data - empty data
    $invalid_data2 = [
      'identifier' => 'uuid-123',
      'data' => '',
      'taxonomy_vocabulary' => 'vocab_id',
    ];
    $this->assertFalse(TaxonomyDataProcessor::validateTaxonomyTermData($invalid_data2));
  }

  /**
   * Test filterTaxonomyFields function.
   */
  public function testFilterTaxonomyFields(): void {
    $field_configs = [
      [
        'name' => 'field_country',
        'field_type' => 'entity_reference',
        'target_type' => 'taxonomy_term',
        'handler_settings' => [
          'target_bundles' => [
            'country' => 'country',
          ],
        ],
      ],
      [
        'name' => 'field_singer',
        'field_type' => 'entity_reference',
        'target_type' => 'taxonomy_term',
        'handler_settings' => [
          'target_bundles' => [
            'singer' => 'singer',
          ],
        ],
      ],
      [
        'name' => 'field_text',
        'field_type' => 'text',
        'target_type' => '',
      ],
    ];

    $result = TaxonomyDataProcessor::filterTaxonomyFields($field_configs);

    $this->assertCount(2, $result);
    $this->assertArrayHasKey('field_country', $result);
    $this->assertArrayHasKey('field_singer', $result);
    $this->assertArrayNotHasKey('field_text', $result);

    $this->assertEquals('country', $result['field_country']['vocabulary_id']);
    $this->assertEquals('Country', $result['field_country']['vocabulary_name']);
    $this->assertEquals('singer', $result['field_singer']['vocabulary_id']);
    $this->assertEquals('Singer', $result['field_singer']['vocabulary_name']);
  }

  /**
   * Test isValidTaxonomyFieldConfig function.
   */
  public function testIsValidTaxonomyFieldConfig(): void {
    // Test valid taxonomy field config
    $valid_config = [
      'name' => 'field_country',
      'field_type' => 'entity_reference',
      'target_type' => 'taxonomy_term',
    ];
    $this->assertTrue(TaxonomyDataProcessor::isValidTaxonomyFieldConfig($valid_config));

    // Test invalid configs
    $invalid_config1 = [
      'name' => 'field_country',
      'field_type' => 'text',
      'target_type' => 'taxonomy_term',
    ];
    $this->assertFalse(TaxonomyDataProcessor::isValidTaxonomyFieldConfig($invalid_config1));

    $invalid_config2 = [
      'name' => 'country', // Missing field_ prefix
      'field_type' => 'entity_reference',
      'target_type' => 'taxonomy_term',
    ];
    $this->assertFalse(TaxonomyDataProcessor::isValidTaxonomyFieldConfig($invalid_config2));
  }

  /**
   * Test extractVocabularyId function.
   */
  public function testExtractVocabularyId(): void {
    // Test with valid target bundles
    $config_with_bundles = [
      'handler_settings' => [
        'target_bundles' => [
          'country' => 'country',
          'other' => 'other',
        ],
      ],
    ];
    $this->assertEquals('country', TaxonomyDataProcessor::extractVocabularyId($config_with_bundles));

    // Test with empty target bundles
    $config_empty = [
      'handler_settings' => [
        'target_bundles' => [],
      ],
    ];
    $this->assertEquals('', TaxonomyDataProcessor::extractVocabularyId($config_empty));

    // Test with missing handler settings
    $config_missing = [];
    $this->assertEquals('', TaxonomyDataProcessor::extractVocabularyId($config_missing));
  }

  /**
   * Test extractTaxonomyFieldData function.
   */
  public function testExtractTaxonomyFieldData(): void {
    $term1 = $this->createMockTerm('uuid-1', 'USA');
    $term2 = $this->createMockTerm('uuid-2', 'Taylor Swift');

    $field_values = [
      'field_country' => [
        [
          'target_id' => 1,
          'entity' => $term1,
        ],
      ],
      'field_singer' => [
        [
          'target_id' => 2,
          'entity' => $term2,
        ],
      ],
      'field_text' => 'some text', // Non-array field
    ];

    $custom_fields_info = [
      'field_country' => [
        'vocabulary_id' => 'country',
        'vocabulary_name' => 'Country',
      ],
      'field_singer' => [
        'vocabulary_id' => 'singer',
        'vocabulary_name' => 'Singer',
      ],
    ];

    $result = TaxonomyDataProcessor::extractTaxonomyFieldData($field_values, $custom_fields_info);

    $this->assertArrayHasKey('country', $result);
    $this->assertArrayHasKey('singer', $result);
    $this->assertArrayNotHasKey('text', $result);

    $this->assertEquals('uuid-1', $result['country'][0]['identifier']);
    $this->assertEquals('USA', $result['country'][0]['data']);
    $this->assertEquals('uuid-2', $result['singer'][0]['identifier']);
    $this->assertEquals('Taylor Swift', $result['singer'][0]['data']);
  }

    /**
   * Test validateApiFieldName function.
   */
  public function testValidateApiFieldName(): void {
    // Test valid field names
    $this->assertTrue(TaxonomyDataProcessor::validateApiFieldName('country'));
    $this->assertTrue(TaxonomyDataProcessor::validateApiFieldName('marine_species'));
    $this->assertTrue(TaxonomyDataProcessor::validateApiFieldName('field_name'));
    $this->assertTrue(TaxonomyDataProcessor::validateApiFieldName('custom123'));
    $this->assertTrue(TaxonomyDataProcessor::validateApiFieldName('field_name_2'));

    // Test invalid field names - empty
    $this->assertFalse(TaxonomyDataProcessor::validateApiFieldName(''));

    // Test invalid field names - reserved DKAN fields
    $this->assertFalse(TaxonomyDataProcessor::validateApiFieldName('title'));
    $this->assertFalse(TaxonomyDataProcessor::validateApiFieldName('identifier'));
    $this->assertFalse(TaxonomyDataProcessor::validateApiFieldName('description'));
    $this->assertFalse(TaxonomyDataProcessor::validateApiFieldName('@type'));
    $this->assertFalse(TaxonomyDataProcessor::validateApiFieldName('keyword'));
    $this->assertFalse(TaxonomyDataProcessor::validateApiFieldName('theme'));

    // Test invalid field names - reserved prefixes
    $this->assertFalse(TaxonomyDataProcessor::validateApiFieldName('%modified'));
    $this->assertFalse(TaxonomyDataProcessor::validateApiFieldName('@special'));
    $this->assertFalse(TaxonomyDataProcessor::validateApiFieldName('Ref:something'));
    $this->assertFalse(TaxonomyDataProcessor::validateApiFieldName('%Ref:test'));
    $this->assertFalse(TaxonomyDataProcessor::validateApiFieldName('Reftest'));

    // Test invalid field names - bad characters/format
    $this->assertFalse(TaxonomyDataProcessor::validateApiFieldName('123invalid')); // starts with number
    $this->assertFalse(TaxonomyDataProcessor::validateApiFieldName('field-name')); // contains dash
    $this->assertFalse(TaxonomyDataProcessor::validateApiFieldName('field name')); // contains space
    $this->assertFalse(TaxonomyDataProcessor::validateApiFieldName('field.name')); // contains dot
    $this->assertFalse(TaxonomyDataProcessor::validateApiFieldName('field/name')); // contains slash
  }

  /**
   * Test generateDatasetCacheTags function.
   */
  public function testGenerateDatasetCacheTags(): void {
    // Test with both UUID and ID
    $tags = TaxonomyDataProcessor::generateDatasetCacheTags('test-uuid-123', 456);
    $expected = [
      'metastore:test-uuid-123',
      'node:456',
      'rest_resource'
    ];
    $this->assertEquals($expected, $tags);

    // Test with only UUID
    $tags = TaxonomyDataProcessor::generateDatasetCacheTags('test-uuid-123', 0);
    $expected = [
      'metastore:test-uuid-123',
      'rest_resource'
    ];
    $this->assertEquals($expected, $tags);

    // Test with only ID
    $tags = TaxonomyDataProcessor::generateDatasetCacheTags('', 456);
    $expected = [
      'node:456',
      'rest_resource'
    ];
    $this->assertEquals($expected, $tags);

    // Test with neither
    $tags = TaxonomyDataProcessor::generateDatasetCacheTags('', 0);
    $expected = ['rest_resource'];
    $this->assertEquals($expected, $tags);
  }

  /**
   * Test isVocabularyRelevant function.
   */
  public function testIsVocabularyRelevant(): void {
    $custom_fields = [
      'field_country' => [
        'vocabulary_id' => 'country',
        'vocabulary_name' => 'Country',
      ],
      'field_singer' => [
        'vocabulary_id' => 'singer',
        'vocabulary_name' => 'Singer',
      ],
    ];

    // Test relevant vocabularies
    $this->assertTrue(TaxonomyDataProcessor::isVocabularyRelevant($custom_fields, 'country'));
    $this->assertTrue(TaxonomyDataProcessor::isVocabularyRelevant($custom_fields, 'singer'));

    // Test irrelevant vocabulary
    $this->assertFalse(TaxonomyDataProcessor::isVocabularyRelevant($custom_fields, 'category'));

    // Test empty inputs
    $this->assertFalse(TaxonomyDataProcessor::isVocabularyRelevant([], 'country'));
    $this->assertFalse(TaxonomyDataProcessor::isVocabularyRelevant($custom_fields, ''));
    $this->assertFalse(TaxonomyDataProcessor::isVocabularyRelevant([], ''));
  }

  /**
   * Test extractEntityFieldValues function.
   */
  public function testExtractEntityFieldValues(): void {
    // Create mock entities
    $entity1 = $this->createMockTerm('uuid-1', 'Term 1');
    $entity2 = $this->createMockTerm('uuid-2', 'Term 2');
    $entity3 = $this->createMockTerm('uuid-3', 'Term 3');

    $entity_fields = [
      'field_country' => [
        $entity1, // Direct entity
        ['entity' => $entity2], // Entity in array
      ],
      'field_singer' => [
        ['entity' => $entity3],
      ],
      'field_invalid' => [
        'not an entity',
        ['not_entity' => 'invalid'],
      ],
      'not_array_field' => 'invalid data',
    ];

    $result = TaxonomyDataProcessor::extractEntityFieldValues($entity_fields);

    $this->assertArrayHasKey('field_country', $result);
    $this->assertArrayHasKey('field_singer', $result);
    $this->assertArrayNotHasKey('field_invalid', $result);
    $this->assertArrayNotHasKey('not_array_field', $result);

    // Check field_country results
    $this->assertCount(2, $result['field_country']);
    $this->assertEquals('uuid-1', $result['field_country'][0]['identifier']);
    $this->assertEquals('Term 1', $result['field_country'][0]['data']);
    $this->assertEquals('uuid-2', $result['field_country'][1]['identifier']);
    $this->assertEquals('Term 2', $result['field_country'][1]['data']);

    // Check field_singer results
    $this->assertCount(1, $result['field_singer']);
    $this->assertEquals('uuid-3', $result['field_singer'][0]['identifier']);
    $this->assertEquals('Term 3', $result['field_singer'][0]['data']);
  }

  /**
   * Test processVocabularyMetadataCleanup function.
   */
  public function testProcessVocabularyMetadataCleanup(): void {
    $dataset_metadata_list = [
      'dataset-1' => [
        'title' => 'Dataset 1',
        'country' => [
          ['identifier' => 'uuid-1', 'data' => 'USA'],
        ],
        'singer' => [
          ['identifier' => 'uuid-2', 'data' => 'Taylor Swift'],
        ],
      ],
      'dataset-2' => [
        'title' => 'Dataset 2',
        'country' => [
          ['identifier' => 'uuid-3', 'data' => 'Canada'],
        ],
      ],
      'dataset-3' => [
        'title' => 'Dataset 3',
        'singer' => [
          ['identifier' => 'uuid-4', 'data' => 'Ed Sheeran'],
        ],
      ],
    ];

    // Test cleaning up 'country' vocabulary
    $result = TaxonomyDataProcessor::processVocabularyMetadataCleanup('country', $dataset_metadata_list);

    $this->assertEquals(2, $result['cleanup_count']);
    $this->assertArrayHasKey('dataset-1', $result['modified_datasets']);
    $this->assertArrayHasKey('dataset-2', $result['modified_datasets']);
    $this->assertArrayNotHasKey('dataset-3', $result['modified_datasets']);

    // Check that 'country' was removed but other fields remain
    $modified1 = $result['modified_datasets']['dataset-1'];
    $this->assertEquals('Dataset 1', $modified1['title']);
    $this->assertArrayNotHasKey('country', $modified1);
    $this->assertArrayHasKey('singer', $modified1); // Singer should remain

    $modified2 = $result['modified_datasets']['dataset-2'];
    $this->assertEquals('Dataset 2', $modified2['title']);
    $this->assertArrayNotHasKey('country', $modified2);

    // Test with empty vocabulary_id
    $result = TaxonomyDataProcessor::processVocabularyMetadataCleanup('', $dataset_metadata_list);
    $this->assertEquals(0, $result['cleanup_count']);
    $this->assertEmpty($result['modified_datasets']);
  }

  /**
   * Test determineSchemaOperations function.
   */
  public function testDetermineSchemaOperations(): void {
    $existing_schema_items = [
      [
        'identifier' => 'uuid-1',
        'data' => 'USA',
        'taxonomy_vocabulary' => 'country',
      ],
      [
        'identifier' => 'uuid-2',
        'data' => 'Old Singer Name',
        'taxonomy_vocabulary' => 'singer',
      ],
      [
        'identifier' => 'uuid-3',
        'data' => 'Canada',
        'taxonomy_vocabulary' => 'country',
      ],
    ];

    $current_terms = [
      [
        'identifier' => 'uuid-1',
        'data' => 'USA', // Same, no update needed
        'taxonomy_vocabulary' => 'country',
      ],
      [
        'identifier' => 'uuid-2',
        'data' => 'New Singer Name', // Changed, needs update
        'taxonomy_vocabulary' => 'singer',
      ],
      [
        'identifier' => 'uuid-4',
        'data' => 'Mexico', // New, needs creation
        'taxonomy_vocabulary' => 'country',
      ],
      // uuid-3 (Canada) is missing, should be deleted
    ];

    $operations = TaxonomyDataProcessor::determineSchemaOperations($existing_schema_items, $current_terms, 'country');

    // Should create uuid-4
    $this->assertCount(1, $operations['create']);
    $this->assertEquals('uuid-4', $operations['create'][0]['identifier']);
    $this->assertEquals('Mexico', $operations['create'][0]['data']);

    // Should update uuid-2 (but it's singer vocabulary, so shouldn't appear)
    $this->assertCount(0, $operations['update']);

    // Should delete uuid-3
    $this->assertCount(1, $operations['delete']);
    $this->assertEquals('uuid-3', $operations['delete'][0]['identifier']);
    $this->assertEquals('Canada', $operations['delete'][0]['data']);

    // Test with singer vocabulary
    $operations = TaxonomyDataProcessor::determineSchemaOperations($existing_schema_items, $current_terms, 'singer');

    $this->assertCount(0, $operations['create']);
    $this->assertCount(1, $operations['update']); // uuid-2 changed name
    $this->assertCount(0, $operations['delete']);

    $this->assertEquals('uuid-2', $operations['update'][0]['identifier']);
    $this->assertEquals('New Singer Name', $operations['update'][0]['data']);
  }

  /**
   * Test filterValidQueueNames function.
   */
  public function testFilterValidQueueNames(): void {
    $queue_candidates = [
      'dkan_metastore_import',
      'dkan_metastore_storage',
      'dkan_metastore_custom',
      'dkan_metastore_export',
      'other_queue',
      'invalid_queue',
      'dkan_other_queue',
      123, // Non-string
      null,
    ];

    $valid_queues = TaxonomyDataProcessor::filterValidQueueNames($queue_candidates);

    $expected = [
      'dkan_metastore_import',
      'dkan_metastore_storage',
      'dkan_metastore_custom',
      'dkan_metastore_export',
    ];

    $this->assertEquals($expected, array_values($valid_queues));

    // Test with empty array
    $this->assertEmpty(TaxonomyDataProcessor::filterValidQueueNames([]));

    // Test with only invalid names
    $invalid_only = ['invalid_queue', 'other_queue', 123];
    $this->assertEmpty(TaxonomyDataProcessor::filterValidQueueNames($invalid_only));
  }

  /**
   * Test edge cases for validateApiFieldName function.
   */
  public function testValidateApiFieldNameEdgeCases(): void {
    // Test boundary conditions
    $this->assertTrue(TaxonomyDataProcessor::validateApiFieldName('a')); // Single letter
    $this->assertTrue(TaxonomyDataProcessor::validateApiFieldName('A')); // Single uppercase
    $this->assertTrue(TaxonomyDataProcessor::validateApiFieldName('field_name_with_many_underscores'));

    // Test Unicode and special cases
    $this->assertFalse(TaxonomyDataProcessor::validateApiFieldName('field_name-with-dashes'));
    $this->assertFalse(TaxonomyDataProcessor::validateApiFieldName('field name with spaces'));
    $this->assertFalse(TaxonomyDataProcessor::validateApiFieldName('field.name.with.dots'));

    // Test very long names
    $long_name = str_repeat('a', 255);
    $this->assertTrue(TaxonomyDataProcessor::validateApiFieldName($long_name));

    // Test names that start with reserved words but aren't exactly reserved
    $this->assertTrue(TaxonomyDataProcessor::validateApiFieldName('titled')); // starts with 'title'
    $this->assertTrue(TaxonomyDataProcessor::validateApiFieldName('keywordly')); // starts with 'keyword'
    $this->assertTrue(TaxonomyDataProcessor::validateApiFieldName('themes')); // starts with 'theme'
  }

  /**
   * Test edge cases for unifyTaxonomyTerms function.
   */
  public function testUnifyTaxonomyTermsEdgeCases(): void {
    // Test with malformed terms in taxonomy fields
    $raw_data = [
      'title' => 'Test Dataset',
      'country' => [
        ['identifier' => 'uuid-1', 'data' => 'USA'], // Good term
        ['identifier' => '', 'data' => 'Empty ID'], // Bad term - empty identifier
        ['data' => 'Missing ID'], // Bad term - missing identifier
        ['identifier' => 'uuid-2'], // Bad term - missing data
        null, // Bad term - null value
        'string instead of array', // Bad term - wrong type
      ],
      'singer' => [], // Empty array
      'category' => null, // Null field
    ];

    $custom_fields_info = [
      'field_country' => [
        'vocabulary_id' => 'country',
        'vocabulary_name' => 'Country',
      ],
      'field_singer' => [
        'vocabulary_id' => 'singer',
        'vocabulary_name' => 'Singer',
      ],
      'field_category' => [
        'vocabulary_id' => 'category',
        'vocabulary_name' => 'Category',
      ],
    ];

    $result = TaxonomyDataProcessor::unifyTaxonomyTerms($raw_data, $custom_fields_info);

    // Should only include the valid term
    $this->assertArrayHasKey('taxonomy_term', $result);
    $this->assertCount(1, $result['taxonomy_term']);
    $this->assertEquals('uuid-1', $result['taxonomy_term'][0]['identifier']);
    $this->assertEquals('USA', $result['taxonomy_term'][0]['data']);

    // Original fields should be removed
    $this->assertArrayNotHasKey('country', $result);
    $this->assertArrayNotHasKey('singer', $result);
    $this->assertArrayNotHasKey('category', $result);
  }

  /**
   * Test edge cases for processVocabularyMetadataCleanup function.
   */
  public function testProcessVocabularyMetadataCleanupEdgeCases(): void {
    // Test with malformed dataset metadata
    $dataset_metadata_list = [
      'valid-dataset' => [
        'title' => 'Valid Dataset',
        'country' => ['some data'],
      ],
      'null-metadata' => null,
      'string-metadata' => 'not an array',
      'empty-array' => [],
      'dataset-without-field' => [
        'title' => 'No Country Field',
        'singer' => ['other data'],
      ],
    ];

    $result = TaxonomyDataProcessor::processVocabularyMetadataCleanup('country', $dataset_metadata_list);

    $this->assertEquals(1, $result['cleanup_count']);
    $this->assertArrayHasKey('valid-dataset', $result['modified_datasets']);
    $this->assertArrayNotHasKey('null-metadata', $result['modified_datasets']);
    $this->assertArrayNotHasKey('string-metadata', $result['modified_datasets']);
    $this->assertArrayNotHasKey('empty-array', $result['modified_datasets']);
    $this->assertArrayNotHasKey('dataset-without-field', $result['modified_datasets']);
  }

  /**
   * Test edge cases for determineSchemaOperations function.
   */
  public function testDetermineSchemaOperationsEdgeCases(): void {
    // Test with empty arrays
    $empty_operations = TaxonomyDataProcessor::determineSchemaOperations([], [], 'country');
    $this->assertEmpty($empty_operations['create']);
    $this->assertEmpty($empty_operations['update']);
    $this->assertEmpty($empty_operations['delete']);

    // Test with malformed schema items
    $malformed_schema_items = [
      ['identifier' => 'uuid-1'], // Missing data and vocabulary
      ['data' => 'USA'], // Missing identifier and vocabulary
      ['taxonomy_vocabulary' => 'country'], // Missing identifier and data
      null, // Null item
      'string instead of array', // Wrong type
    ];

    $valid_current_terms = [
      [
        'identifier' => 'uuid-2',
        'data' => 'Canada',
        'taxonomy_vocabulary' => 'country',
      ],
    ];

    $operations = TaxonomyDataProcessor::determineSchemaOperations($malformed_schema_items, $valid_current_terms, 'country');

    // Should only create the valid term (malformed items ignored)
    $this->assertCount(1, $operations['create']);
    $this->assertEquals('uuid-2', $operations['create'][0]['identifier']);
    $this->assertEmpty($operations['update']);
    $this->assertEmpty($operations['delete']);
  }

  /**
   * Test comprehensive field processing scenarios.
   */
  public function testExtractEntityFieldValuesComprehensive(): void {
    // Test with various entity formats and edge cases
    $mockTerm = $this->createMockTerm('uuid-1', 'Valid Term');
    $mockTermWithNullUuid = $this->createMockTermWithNulls(null, 'Term with null UUID');
    $mockTermWithNullName = $this->createMockTermWithNulls('uuid-2', null);

    $entity_fields = [
      'field_with_mixed_data' => [
        $mockTerm, // Valid direct entity
        ['entity' => $mockTerm], // Valid entity in array
        ['entity' => $mockTermWithNullUuid], // Entity with null UUID
        ['entity' => $mockTermWithNullName], // Entity with null name
        ['entity' => null], // Null entity
        ['other_key' => $mockTerm], // Wrong key
        null, // Null item
        'string', // Wrong type
      ],
      'field_empty' => [],
      'field_null' => null,
    ];

    $result = TaxonomyDataProcessor::extractEntityFieldValues($entity_fields);

    // Should only extract valid terms
    $this->assertArrayHasKey('field_with_mixed_data', $result);
    $this->assertCount(2, $result['field_with_mixed_data']); // Only 2 valid terms

    $this->assertEquals('uuid-1', $result['field_with_mixed_data'][0]['identifier']);
    $this->assertEquals('Valid Term', $result['field_with_mixed_data'][0]['data']);
    $this->assertEquals('uuid-1', $result['field_with_mixed_data'][1]['identifier']);
    $this->assertEquals('Valid Term', $result['field_with_mixed_data'][1]['data']);

    $this->assertArrayNotHasKey('field_empty', $result);
    $this->assertArrayNotHasKey('field_null', $result);
  }

  /**
   * Create a mock term object.
   */
  private function createMockTerm(string $uuid, string $name): object {
    return new class($uuid, $name) {
      private $uuid, $name;

      public function __construct($uuid, $name) {
        $this->uuid = $uuid;
        $this->name = $name;
      }

      public function uuid() {
        return $this->uuid;
      }

      public function getName() {
        return $this->name;
      }
    };
  }

  /**
   * Create a mock term object that can return null values.
   */
  private function createMockTermWithNulls($uuid, $name): object {
    return new class($uuid, $name) {
      private $uuid, $name;

      public function __construct($uuid, $name) {
        $this->uuid = $uuid;
        $this->name = $name;
      }

      public function uuid() {
        return $this->uuid;
      }

      public function getName() {
        return $this->name;
      }
    };
  }

}
