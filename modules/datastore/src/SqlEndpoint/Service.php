<?php

namespace Drupal\datastore\SqlEndpoint;

use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\datastore\Service\Factory\Resource;
use Drupal\datastore\Storage\DatabaseTable;
use Drupal\datastore\Storage\DatabaseTableFactory;
use Drupal\datastore\SqlEndpoint\Helper\GetStringsFromStateMachineExecution;
use SqlParser\SqlParser;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\datastore\Storage\Query;
use Maquina\StateMachine\Machine;

/**
 * Class Service.
 */
class Service implements ContainerInjectionInterface {
  private $configFactory;
  private $databaseTableFactory;
  private $resourceServiceFactory;

  /**
   * Inherited.
   *
   * @inheritDoc
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('datastore.database_table_factory'),
      $container->get('datastore.service.factory.resource')
    );
  }

  /**
   * Constructor.
   */
  public function __construct(ConfigFactory $configFactory, DatabaseTableFactory $databaseTableFactory, Resource $resourceServiceFactory) {
    $this->configFactory = $configFactory;
    $this->databaseTableFactory = $databaseTableFactory;
    $this->resourceServiceFactory = $resourceServiceFactory;
  }

  /**
   * Run query.
   */
  public function runQuery(string $queryString, $showDbColumns = FALSE): array {
    $queryObject = $this->getQueryObject($queryString);
    $databaseTable = $this->getDatabaseTable($this->getResourceUuid($queryString));

    $result = $databaseTable->query($queryObject);

    $schema = $databaseTable->getSchema();
    $fields = $schema['fields'];

    return array_map(function ($row) use ($fields, $showDbColumns) {
      if (!$showDbColumns) {
        unset($row->record_number);
      }

      $arrayRow = (array) $row;

      $newRow = [];

      foreach ($arrayRow as $fieldName => $value) {
        if (!$showDbColumns && isset($fields[$fieldName]['description']) && !empty($fields[$fieldName]['description'])) {
          $newRow[$fields[$fieldName]['description']] = $value;
        }
        else {
          $newRow[$fieldName] = $value;
        }
      }

      return (object) $newRow;
    }, $result);
  }

  /**
   * Get resource UUID.
   *
   * @param string $sqlString
   *   A string with an sql statement.
   *
   * @return string
   *   The table name from the sql statement,
   *   which is equivalent to the resource's UUID.
   *
   * @throws \Exception
   */
  public function getResourceUuid(string $sqlString): string {
    $stateMachine = $this->validate($sqlString);
    return $this->getTableNameFromSelect($stateMachine->gsm('select'));
  }

  /**
   * Private.
   */
  private function getDatabaseTable(string $uuid): DatabaseTable {
    $resource = $this->getResource($uuid);
    if (!$resource) {
      throw new \Exception("Resource not found.");
    }

    return $this->databaseTableFactory->getInstance($resource->getId(), ['resource' => $resource]);
  }

  /**
   * Private.
   */
  private function getResource(string $uuid) {
    /* @var $resourceService \Drupal\datastore\Service\Resource */
    $resourceService = $this->resourceServiceFactory->getInstance($uuid);
    return $resourceService->get();
  }

  /**
   * Private.
   */
  private function getTableNameFromSelect(Machine $selectMachine): string {
    $machine = $selectMachine->gsm('table_var');
    $strings = $this->getStringsFromStringMachine($machine);
    if (empty($strings)) {
      throw new \Exception("No table name");
    }
    return $strings[0];
  }

  /**
   * Get a query object from a sql string.
   *
   * @param string $sqlString
   *   A string with a sql statement.
   *
   * @return \Drupal\datastore\Storage\Query
   *   A query object.
   */
  public function getQueryObject(string $sqlString): Query {
    return $this->getQueryObjectFromStateMachine($this->validate($sqlString));
  }

  /**
   * Private.
   */
  private function validate(string $sqlString): Machine {
    $parser = new SqlParser();
    if ($parser->validate($sqlString) === FALSE) {
      throw new \Exception("Invalid query string.");
    }

    return $parser->getValidatingMachine();
  }

  /**
   * Private.
   */
  private function getQueryObjectFromStateMachine(Machine $state_machine): Query {
    $object = new Query();
    $this->setQueryObjectSelect($object, $state_machine->gsm('select'));
    $this->setQueryObjectWhere($object, $state_machine->gsm('where'));
    $this->setQueryObjectOrderBy($object, $state_machine->gsm('order_by'));
    $this->setQueryObjectLimit($object, $state_machine->gsm('limit'));

    return $object;
  }

  /**
   * Private.
   */
  private function setQueryObjectSelect(Query $object, Machine $state_machine) {
    $strings = $this->getStringsFromStringMachine($state_machine->gsm('select_count_all'));
    if (!empty($strings)) {
      $object->count();
      return;
    }

    $strings = $this->getStringsFromStringMachine($state_machine->gsm('select_var_all'));
    if (!empty($strings)) {
      return;
    }

    $strings = $this->getStringsFromStringMachine($state_machine->gsm('select_var'));
    foreach ($strings as $property) {
      $object->filterByProperty($property);
    }
  }

  /**
   * Private.
   */
  private function setQueryObjectWhere(Query $object, Machine $state_machine) {
    $properties = $this->getStringsFromStringMachine($state_machine->gsm('where_column'));
    $values = $this->getStringsFromStringMachine($state_machine->gsm('quoted_string')->gsm('string'));

    foreach ($properties as $index => $property) {
      $value = $values[$index];
      if ($value) {
        $object->conditionByIsEqualTo($property, $value);
      }
    }
  }

  /**
   * Private.
   */
  private function setQueryObjectOrderBy(Query $object, Machine $state_machine) {
    $properties = $this->getStringsFromStringMachine($state_machine->gsm('order_var'));

    $direction = $this->getStringsFromStringMachine($state_machine->gsm('order_asc'));
    $sortMethod = (!empty($direction)) ? "sortByAscending" : "sortByDescending";

    foreach ($properties as $property) {
      $object->$sortMethod($property);
    }
  }

  /**
   * Private.
   */
  private function setQueryObjectLimit(Query $object, Machine $state_machine) {
    $rows_limit = $this->configFactory->get('datastore.settings')->get('rows_limit');

    $limit = $this->getStringsFromStringMachine($state_machine->gsm('numeric1'));
    if (!empty($limit) && $limit[0] <= $rows_limit) {
      $object->limitTo($limit[0]);
    }
    elseif ($object->count == FALSE) {
      $object->limitTo($rows_limit);
    }

    $offset = $this->getStringsFromStringMachine($state_machine->gsm('numeric2'));
    if (!empty($offset)) {
      $object->offsetBy($offset[0]);
    }
  }

  /**
   * Private.
   */
  private function getStringsFromStringMachine(Machine $machine): array {
    return (new GetStringsFromStateMachineExecution($machine->execution))->get();
  }

}
