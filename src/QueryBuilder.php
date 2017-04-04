<?php

/*
 * Tools to use API as ActiveRecord for Yii2
 *
 * @link      https://github.com/apexwire/yii2-restclient
 * @package   yii2-restclient
 * @license   BSD-3-Clause
 * @copyright Copyright (c) 2016, ApexWire
 */

namespace apexwire\restclient;

use yii\db\Expression;
use yii\base\NotSupportedException;
use yii\base\Object;

/**
 * Class QueryBuilder builds an HiActiveResource query based on the specification given as a [[Query]] object.
 * @package apexwire\restclient
 */
class QueryBuilder extends Object
{
    /**
     * @type array
     */
    private $_sort = [
        SORT_ASC => '',
        SORT_DESC => '-',
    ];

    /**
     * @var Connection the database connection.
     */
    public $db;
    /**
     * @var string the separator between different fragments of a SQL statement.
     * Defaults to an empty space. This is mainly used by [[build()]] when generating a SQL statement.
     */
    public $separator = ' ';
    /**
     * @var array the abstract column types mapped to physical column types.
     * This is mainly used to support creating/modifying tables using DB-independent data type specifications.
     * Child classes should override this property to declare supported type mappings.
     */
    public $typeMap = [];

    /**
     * @var array map of query condition to builder methods.
     * These methods are used by [[buildCondition]] to build SQL conditions from array syntax.
     */
    protected $conditionBuilders = [
        'AND' => 'buildAndCondition',
    ];


    /**
     * Constructor.
     * @param Connection $connection the database connection.
     * @param array $config name-value pairs that will be used to initialize the object properties
     */
    public function __construct($connection, $config = [])
    {
        $this->db = $connection;
        parent::__construct($config);
    }


    /**
     * @param Query $query
     * @param array $params
     * @return array
     * @throws NotSupportedException
     */
    public function build($query, $params = [])
    {
        $query = $query->prepare($this);

        $this->buildSelect($query->select, $params);
        $this->buildPerPage($query->limit, $params);
        $this->buildPage($query->offset, $query->limit, $params);
        $this->buildFind($query->where, $query->searchModel, $params);
        $this->buildSort($query->orderBy, $params);

        return [
            'query' => $query,
            'queryParts' => $params,
            'index' => $query->from
        ];
    }

    /**
     * Устанавливаем количество записей на страницу
     *
     * @param $limit
     * @param $params
     */
    public function buildPerPage($limit, &$params)
    {
        if (is_int($limit)) {
            $params['per-page'] = $limit;
        }
    }

    /**
     * @param $offset
     * @param $limit
     * @param $params
     */
    public function buildPage($offset, $limit, &$params)
    {
        if ($offset > 0) {
            $params['page'] = ceil($offset / $limit) + 1;
        }
    }

    /**
     * Преобразуем массив параметров where в массив для поиска
     *
     * @param $condition
     * @param $searchModel
     * @param $params
     */
    public function buildFind($condition, $searchModel, &$params)
    {
        if (!empty($condition) && is_array($condition)) {

            $where = $this->buildCondition($condition, $params);
            $params = $this->getParams($searchModel, $where, $params);
        }
    }

    /**
     * @param string $searchModel
     * @param string|array $where
     * @param array $params
     * @return array
     */
    protected function getParams($searchModel, $where, $params = [])
    {
        if (is_array($where)) {
            foreach ($where as $key => $value) {

                if (is_array($value)) {
                    $params = $this->getParams($searchModel, $value, $params);
                } else {
                    $params[$searchModel . '[' . $key . ']'] = $value;
                    unset($params[$key]);
                }
            }
        }

        return $params;
    }

    /**
     * Устанавливаем параметр сортировки
     *
     * @param $orderBy
     * @param $params
     * @return array
     */
    public function buildSort($orderBy, &$params)
    {
        if (!empty($orderBy)) {
            $params['sort'] = $this->_sort[reset($orderBy)] . key($orderBy);
        }
    }

    /**
     * @inheritdoc
     */
    public function buildSelect($columns, &$params)
    {
        if (!empty($columns) AND is_array($columns)) {
            $params['fields'] = implode(',', $columns);
        }
    }

    /**
     * @param $condition
     * @param $params
     * @return array|string
     * @throws NotSupportedException
     */
    public function buildCondition($condition, &$params)
    {
        if ($condition instanceof Expression) {
            foreach ($condition->params as $n => $v) {
                $params[$n] = $v;
            }

            return $condition->expression;
        } elseif (!is_array($condition)) {
            return (string)$condition;
        } elseif (empty($condition)) {
            return '';
        }

        if (isset($condition[0])) { // operator format: operator, operand 1, operand 2, ...
            $operator = strtoupper($condition[0]);
            if (!isset($this->conditionBuilders[$operator])) {
                throw new NotSupportedException($operator . ' in is not supported.');
            }
            $method = $this->conditionBuilders[$operator];
            array_shift($condition);

            return $this->$method($operator, $condition, $params);
        } else { // hash format: 'column1' => 'value1', 'column2' => 'value2', ...
            return $this->buildHashCondition($condition);
        }
    }

    /**
     * @inheritdoc
     */
    public function buildHashCondition($condition)
    {
        //TODO: проверить работу
        $parts = [];
        foreach ($condition as $attribute => $value) {
            if (is_array($value)) { // IN condition
                $parts[$attribute . 's'] = implode(',', $value);
            } else {
                $parts[$attribute] = $value;
            }
        }

        return $parts;
    }

    /**
     * @inheritdoc
     */
    public function buildAndCondition($operator, $operands, &$params)
    {
        $parts = [];
        foreach ($operands as $operand) {
            if (is_array($operand)) {
                $operand = $this->buildCondition($operand, $params);
            }
            if ($operand instanceof Expression) {
                foreach ($operand->params as $n => $v) {
                    $params[$n] = $v;
                }
                $operand = $operand->expression;
            }
            if ($operand !== '') {
                $parts[] = $operand;
            }
        }

        return $parts;
    }
}
