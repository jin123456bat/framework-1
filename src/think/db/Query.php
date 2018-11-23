<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2018 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
declare (strict_types = 1);

namespace think\db;

use Closure;
use PDO;
use PDOStatement;
use think\App;
use think\Collection;
use think\Container;
use think\db\exception\BindParamException;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\Exception;
use think\exception\DbException;
use think\exception\PDOException;
use think\Model;
use think\model\Collection as ModelCollection;
use think\model\Relation;
use think\model\relation\OneToOne;
use think\Paginator;

class Query
{
    /**
     * 当前数据库连接对象
     * @var Connection
     */
    protected $connection;

    /**
     * 当前模型对象
     * @var Model
     */
    protected $model;

    /**
     * 当前数据表名称（不含前缀）
     * @var string
     */
    protected $name = '';

    /**
     * 当前数据表主键
     * @var string|array
     */
    protected $pk;

    /**
     * 当前数据表前缀
     * @var string
     */
    protected $prefix = '';

    /**
     * 当前查询参数
     * @var array
     */
    protected $options = [];

    /**
     * 当前参数绑定
     * @var array
     */
    protected $bind = [];

    /**
     * 事件回调
     * @var array
     */
    protected static $event = [];

    /**
     * 扩展查询方法
     * @var array
     */
    protected static $extend = [];

    /**
     * 日期查询表达式
     * @var array
     */
    protected $timeRule = [
        'today'      => ['today', 'tomorrow'],
        'yesterday'  => ['yesterday', 'today'],
        'week'       => ['this week 00:00:00', 'next week 00:00:00'],
        'last week'  => ['last week 00:00:00', 'this week 00:00:00'],
        'month'      => ['first Day of this month 00:00:00', 'first Day of next month 00:00:00'],
        'last month' => ['first Day of last month 00:00:00', 'first Day of this month 00:00:00'],
        'year'       => ['this year 1/1', 'next year 1/1'],
        'last year'  => ['last year 1/1', 'this year 1/1'],
    ];

    /**
     * 日期查询快捷定义
     * @var array
     */
    protected $timeExp = ['d' => 'today', 'w' => 'week', 'm' => 'month', 'y' => 'year'];

    /**
     * 架构函数
     * @access public
     */
    public function __construct(Connection $connection)
    {
        $this->connection = $connection;

        $this->prefix = $this->connection->getConfig('prefix');
    }

    /**
     * 创建一个新的查询对象
     * @access public
     * @return Query
     */
    public function newQuery()
    {
        return new static($this->connection);
    }

    /**
     * 利用__call方法实现一些特殊的Model方法
     * @access public
     * @param  string $method 方法名称
     * @param  array  $args   调用参数
     * @return mixed
     * @throws DbException
     * @throws Exception
     */
    public function __call(string $method, array $args)
    {
        if (isset(self::$extend[strtolower($method)])) {
            // 调用扩展查询方法
            array_unshift($args, $this);

            return Container::getInstance()->invoke(self::$extend[strtolower($method)], $args);
        } elseif (strtolower(substr($method, 0, 5)) == 'getby') {
            // 根据某个字段获取记录
            $field = App::parseName(substr($method, 5));
            return $this->where($field, '=', $args[0])->find();
        } elseif (strtolower(substr($method, 0, 10)) == 'getfieldby') {
            // 根据某个字段获取记录的某个值
            $name = App::parseName(substr($method, 10));
            return $this->where($name, '=', $args[0])->value($args[1]);
        } elseif (strtolower(substr($method, 0, 7)) == 'whereor') {
            $name = App::parseName(substr($method, 7));
            array_unshift($args, $name);
            return call_user_func_array([$this, 'whereOr'], $args);
        } elseif (strtolower(substr($method, 0, 5)) == 'where') {
            $name = App::parseName(substr($method, 5));
            array_unshift($args, $name);
            return call_user_func_array([$this, 'where'], $args);
        } elseif ($this->model && method_exists($this->model, 'scope' . $method)) {
            // 动态调用命名范围
            $method = 'scope' . $method;
            array_unshift($args, $this);

            call_user_func_array([$this->model, $method], $args);
            return $this;
        } else {
            throw new Exception('method not exist:' . static::class . '->' . $method);
        }
    }

    /**
     * 扩展查询方法
     * @access public
     * @param  string|array  $method     查询方法名
     * @param  callable      $callback
     * @return void
     */
    public static function extend($method, $callback = null): void
    {
        if (is_array($method)) {
            foreach ($method as $key => $val) {
                self::$extend[strtolower($key)] = $val;
            }
        } else {
            self::$extend[strtolower($method)] = $callback;
        }
    }

    /**
     * 获取当前的数据库Connection对象
     * @access public
     * @return Connection
     */
    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * 设置当前的数据库Connection对象
     * @access public
     * @param  Connection      $connection
     * @return $this
     */
    public function setConnection(Connection $connection)
    {
        $this->connection = $connection;

        return $this;
    }

    /**
     * 指定模型
     * @access public
     * @param  Model $model 模型对象实例
     * @return $this
     */
    public function model(Model $model)
    {
        $this->model = $model;
        return $this;
    }

    /**
     * 获取当前的模型对象
     * @access public
     * @return Model|null
     */
    public function getModel()
    {
        return $this->model ? $this->model->setQuery($this) : null;
    }

    /**
     * 指定当前数据表名（不含前缀）
     * @access public
     * @param  string $name
     * @return $this
     */
    public function name(string $name)
    {
        $this->name = $name;
        return $this;
    }

    /**
     * 获取当前的数据表名称
     * @access public
     * @return string
     */
    public function getName(): string
    {
        return $this->name ?: $this->model->getName();
    }

    /**
     * 获取数据库的配置参数
     * @access public
     * @param  string $name 参数名称
     * @return mixed
     */
    public function getConfig(string $name = '')
    {
        return $this->connection->getConfig($name);
    }

    /**
     * 得到当前或者指定名称的数据表
     * @access public
     * @param  string $name
     * @return string
     */
    public function getTable(string $name = ''): string
    {
        if (empty($name) && isset($this->options['table'])) {
            return $this->options['table'];
        }

        $name = $name ?: $this->name;

        return $this->prefix . App::parseName($name);
    }

    /**
     * 获取数据表字段信息
     * @access public
     * @param  string $tableName 数据表名
     * @return array
     */
    public function getTableFields(string $tableName = ''): array
    {
        if ('' == $tableName) {
            $tableName = $this->options['table'] ?? $this->getTable();
        }

        return $this->connection->getTableFields($tableName);
    }

    /**
     * 获取数据表字段类型
     * @access protected
     * @param  string $tableName 数据表名
     * @param  string $field    字段名
     * @return array|string
     */
    protected function getTableFieldsType(string $tableName = '', string $field = null)
    {
        if ('' == $tableName) {
            $tableName = $this->options['table'] ?? $this->getTable();
        }

        return $this->connection->getFieldsType($tableName, $field);
    }

    /**
     * 设置字段类型信息
     * @access public
     * @param  array $type 字段类型信息
     * @return $this
     */
    public function setFieldType(array $type)
    {
        $this->options['field_type'] = $type;
        return $this;
    }

    /**
     * 获取字段类型信息
     * @access public
     * @param  string $field 字段名
     * @return string|null
     */
    public function getFieldType(string $field = null)
    {
        $fieldType = !empty($this->options['field_type']) ? $this->options['field_type'] : $this->getTableFieldsType();

        if (is_null($field)) {
            return $fieldType;
        }

        return $fieldType[$field] ?? null;
    }

    /**
     * 获取字段类型信息
     * @access public
     * @param  string $field 字段名
     * @return string|null
     */
    public function getFieldBindType(string $field = null)
    {
        $fieldType = $this->getFieldType($field);

        if (is_null($field)) {
            return array_map([$this->connection, 'getFieldBindType'], $fieldType);
        }

        return $this->connection->getFieldBindType($fieldType);
    }

    /**
     * 执行查询 返回数据集
     * @access public
     * @param  string      $sql    sql指令
     * @param  array       $bind   参数绑定
     * @param  boolean     $master 是否在主服务器读操作
     * @param  bool        $pdo    是否返回PDO对象
     * @return mixed
     * @throws BindParamException
     * @throws PDOException
     */
    public function query(string $sql, array $bind = [], bool $master = false, bool $pdo = false)
    {
        return $this->connection->query($sql, $bind, $master, $pdo);
    }

    /**
     * 执行语句
     * @access public
     * @param  string $sql  sql指令
     * @param  array  $bind 参数绑定
     * @return int
     * @throws BindParamException
     * @throws PDOException
     */
    public function execute(string $sql, array $bind = []): int
    {
        return $this->connection->execute($sql, $bind, $this);
    }

    /**
     * 监听SQL执行
     * @access public
     * @param  callable $callback 回调方法
     * @return void
     */
    public function listen(callable $callback): void
    {
        $this->connection->listen($callback);
    }

    /**
     * 获取最近插入的ID
     * @access public
     * @param  string $sequence 自增序列名
     * @return string
     */
    public function getLastInsID(string $sequence = null): string
    {
        return $this->connection->getLastInsID($sequence);
    }

    /**
     * 获取返回或者影响的记录数
     * @access public
     * @return integer
     */
    public function getNumRows(): int
    {
        return $this->connection->getNumRows();
    }

    /**
     * 获取最近一次查询的sql语句
     * @access public
     * @return string
     */
    public function getLastSql(): string
    {
        return $this->connection->getLastSql();
    }

    /**
     * 执行数据库事务
     * @access public
     * @param  callable $callback 数据操作方法回调
     * @return mixed
     */
    public function transaction(callable $callback)
    {
        return $this->connection->transaction($callback);
    }

    /**
     * 启动事务
     * @access public
     * @return void
     */
    public function startTrans(): void
    {
        $this->connection->startTrans();
    }

    /**
     * 用于非自动提交状态下面的查询提交
     * @access public
     * @return void
     * @throws PDOException
     */
    public function commit(): void
    {
        $this->connection->commit();
    }

    /**
     * 事务回滚
     * @access public
     * @return void
     * @throws PDOException
     */
    public function rollback(): void
    {
        $this->connection->rollback();
    }

    /**
     * 批处理执行SQL语句
     * 批处理的指令都认为是execute操作
     * @access public
     * @param  array $sql SQL批处理指令
     * @return boolean
     */
    public function batchQuery(array $sql = []): bool
    {
        return $this->connection->batchQuery($sql);
    }

    /**
     * 得到分表的的数据表名
     * @access public
     * @param  array  $data  操作的数据
     * @param  string $field 分表依据的字段
     * @param  array  $rule  分表规则
     * @return mixed
     */
    public function getPartitionTableName(array $data, string $field, array $rule = [])
    {
        // 对数据表进行分区
        if ($field && isset($data[$field])) {
            $value = $data[$field];
            $type  = $rule['type'];
            switch ($type) {
                case 'id':
                    // 按照id范围分表
                    $step = $rule['expr'];
                    $seq  = floor($value / $step) + 1;
                    break;
                case 'year':
                    // 按照年份分表
                    if (!is_numeric($value)) {
                        $value = strtotime($value);
                    }
                    $seq = date('Y', $value) - $rule['expr'] + 1;
                    break;
                case 'mod':
                    // 按照id的模数分表
                    $seq = ($value % $rule['num']) + 1;
                    break;
                case 'md5':
                    // 按照md5的序列分表
                    $seq = (ord(substr(md5($value), 0, 1)) % $rule['num']) + 1;
                    break;
                default:
                    if (function_exists($type)) {
                        // 支持指定函数哈希
                        $seq = (ord(substr($type($value), 0, 1)) % $rule['num']) + 1;
                    } else {
                        // 按照字段的首字母的值分表
                        $seq = (ord($value{0}) % $rule['num']) + 1;
                    }
            }

            return $this->getTable() . '_' . $seq;
        }
        // 当设置的分表字段不在查询条件或者数据中
        // 进行联合查询，必须设定 partition['num']
        $tableName = [];

        for ($i = 0; $i < $rule['num']; $i++) {
            $tableName[] = 'SELECT * FROM ' . $this->getTable() . '_' . ($i + 1);
        }

        return ['( ' . implode(" UNION ", $tableName) . ' )' => $this->name];
    }

    /**
     * 得到某个字段的值
     * @access public
     * @param  string $field   字段名
     * @param  mixed  $default 默认值
     * @return mixed
     */
    public function value(string $field, $default = null)
    {
        return $this->connection->value($this, $field, $default);
    }

    /**
     * 得到某个列的数组
     * @access public
     * @param  string $field 字段名 多个字段用逗号分隔
     * @param  string $key   索引
     * @return array
     */
    public function column(string $field, string $key = ''): array
    {
        return $this->connection->column($this, $field, $key);
    }

    /**
     * 聚合查询
     * @access protected
     * @param  string $aggregate    聚合方法
     * @param  string $field        字段名
     * @param  bool   $force        强制转为数字类型
     * @return mixed
     */
    protected function aggregate(string $aggregate, string $field, bool $force = false)
    {
        return $this->connection->aggregate($this, $aggregate, $field, $force);
    }

    /**
     * COUNT查询
     * @access public
     * @param  string $field 字段名
     * @return int
     */
    public function count(string $field = '*'): int
    {
        if (!empty($this->options['group'])) {
            // 支持GROUP
            $options = $this->getOptions();
            $subSql  = $this->options($options)->field('count(' . $field . ') AS think_count')->bind($this->bind)->buildSql();

            $query = $this->newQuery()->table([$subSql => '_group_count_']);

            $count = $query->aggregate('COUNT', '*');
        } else {
            $count = $this->aggregate('COUNT', $field);
        }

        return (int) $count;
    }

    /**
     * SUM查询
     * @access public
     * @param  string $field 字段名
     * @return float
     */
    public function sum(string $field): float
    {
        return $this->aggregate('SUM', $field, true);
    }

    /**
     * MIN查询
     * @access public
     * @param  string $field    字段名
     * @param  bool   $force    强制转为数字类型
     * @return mixed
     */
    public function min(string $field, bool $force = true)
    {
        return $this->aggregate('MIN', $field, $force);
    }

    /**
     * MAX查询
     * @access public
     * @param  string $field    字段名
     * @param  bool   $force    强制转为数字类型
     * @return mixed
     */
    public function max(string $field, bool $force = true)
    {
        return $this->aggregate('MAX', $field, $force);
    }

    /**
     * AVG查询
     * @access public
     * @param  string $field 字段名
     * @return float
     */
    public function avg(string $field): float
    {
        return $this->aggregate('AVG', $field, true);
    }

    /**
     * 设置记录的某个字段值
     * @access public
     * @param  string       $field 字段名
     * @param  mixed        $value 字段值
     * @return integer
     */
    public function setField(string $field, $value): int
    {
        return $this->update([$field => $value]);
    }

    /**
     * 字段值(延迟)增长
     * @access public
     * @param  string  $field    字段名
     * @param  integer $step     增长值
     * @param  integer $lazyTime 延时时间(s)
     * @return integer|true
     * @throws Exception
     */
    public function setInc(string $field, int $step = 1, int $lazyTime = 0)
    {
        $condition = $this->options['where'] ?? [];

        if (empty($condition)) {
            // 没有条件不做任何更新
            throw new Exception('no data to update');
        }

        if ($lazyTime > 0) {
            // 延迟写入
            $guid = md5($this->getTable() . '_' . $field . '_' . serialize($condition));
            $step = $this->lazyWrite('inc', $guid, $step, $lazyTime);

            if (false === $step) {
                // 清空查询条件
                $this->options = [];
                return true;
            }
        }

        return $this->setField($field, ['INC', $step]);
    }

    /**
     * 字段值（延迟）减少
     * @access public
     * @param  string  $field    字段名
     * @param  integer $step     减少值
     * @param  integer $lazyTime 延时时间(s)
     * @return integer|true
     * @throws Exception
     */
    public function setDec(string $field, int $step = 1, int $lazyTime = 0)
    {
        $condition = $this->options['where'] ?? [];

        if (empty($condition)) {
            // 没有条件不做任何更新
            throw new Exception('no data to update');
        }

        if ($lazyTime > 0) {
            // 延迟写入
            $guid = md5($this->getTable() . '_' . $field . '_' . serialize($condition));
            $step = $this->lazyWrite('dec', $guid, $step, $lazyTime);

            if (false === $step) {
                // 清空查询条件
                $this->options = [];
                return true;
            }

            $value = ['INC', $step];
        } else {
            $value = ['DEC', $step];
        }

        return $this->setField($field, $value);
    }

    /**
     * 延时更新检查 返回false表示需要延时
     * 否则返回实际写入的数值
     * @access protected
     * @param  string  $type     自增或者自减
     * @param  string  $guid     写入标识
     * @param  integer $step     写入步进值
     * @param  integer $lazyTime 延时时间(s)
     * @return false|integer
     */
    protected function lazyWrite(string $type, string $guid, int $step, int $lazyTime)
    {
        $cache = Container::get('cache');

        if (!$cache->has($guid . '_time')) {
            // 计时开始
            $cache->set($guid . '_time', time(), 0);
            $cache->$type($guid, $step);
        } elseif (time() > $cache->get($guid . '_time') + $lazyTime) {
            // 删除缓存
            $value = $cache->$type($guid, $step);
            $cache->rm($guid);
            $cache->rm($guid . '_time');
            return 0 === $value ? false : $value;
        } else {
            // 更新缓存
            $cache->$type($guid, $step);
        }

        return false;
    }

    /**
     * 查询SQL组装 join
     * @access public
     * @param  mixed  $join      关联的表名
     * @param  mixed  $condition 条件
     * @param  string $type      JOIN类型
     * @return $this
     */
    public function join($join, $condition = null, string $type = 'INNER')
    {
        $table = $this->getJoinTable($join);

        $this->options['join'][] = [$table, strtoupper($type), $condition];

        return $this;
    }

    /**
     * LEFT JOIN
     * @access public
     * @param  mixed  $join      关联的表名
     * @param  mixed  $condition 条件
     * @return $this
     */
    public function leftJoin($join, $condition = null)
    {
        return $this->join($join, $condition, 'LEFT');
    }

    /**
     * RIGHT JOIN
     * @access public
     * @param  mixed  $join      关联的表名
     * @param  mixed  $condition 条件
     * @return $this
     */
    public function rightJoin($join, $condition = null)
    {
        return $this->join($join, $condition, 'RIGHT');
    }

    /**
     * FULL JOIN
     * @access public
     * @param  mixed  $join      关联的表名
     * @param  mixed  $condition 条件
     * @return $this
     */
    public function fullJoin($join, $condition = null)
    {
        return $this->join($join, $condition, 'FULL');
    }

    /**
     * 获取Join表名及别名 支持
     * ['prefix_table或者子查询'=>'alias'] 'table alias'
     * @access protected
     * @param  array|string $join
     * @param  string       $alias
     * @return string
     */
    protected function getJoinTable($join, &$alias = null)
    {
        if (is_array($join)) {
            $table = $join;
            $alias = array_shift($join);
            return $table;
        }

        $join = trim($join);

        if (false !== strpos($join, '(')) {
            // 使用子查询
            $table = $join;
        } elseif (strpos($join, ' ')) {
            // 使用别名
            list($table, $alias) = explode(' ', $join);
        } else {
            $table = $join;
        }

        if (isset($alias) && $table != $alias) {
            $table = [$table => $alias];
        }

        return $table;
    }

    /**
     * 查询SQL组装 union
     * @access public
     * @param  mixed   $union
     * @param  boolean $all
     * @return $this
     */
    public function union($union, bool $all = false)
    {
        $this->options['union']['type'] = $all ? 'UNION ALL' : 'UNION';

        if (is_array($union)) {
            $this->options['union'] = array_merge($this->options['union'], $union);
        } else {
            $this->options['union'][] = $union;
        }

        return $this;
    }

    /**
     * 查询SQL组装 union all
     * @access public
     * @param  mixed   $union
     * @return $this
     */
    public function unionAll($union)
    {
        return $this->union($union, true);
    }

    /**
     * 指定查询字段 支持字段排除和指定数据表
     * @access public
     * @param  mixed   $field
     * @param  boolean $except    是否排除
     * @param  string  $tableName 数据表名
     * @param  string  $prefix    字段前缀
     * @param  string  $alias     别名前缀
     * @return $this
     */
    public function field($field, bool $except = false, string $tableName = '', string $prefix = '', string $alias = '')
    {
        if (empty($field)) {
            return $this;
        } elseif ($field instanceof Expression) {
            $this->options['field'][] = $field;
            return $this;
        }

        if (is_string($field)) {
            if (preg_match('/[\<\'\"\(]/', $field)) {
                return $this->fieldRaw($field);
            }

            $field = array_map('trim', explode(',', $field));
        }

        if (true === $field) {
            // 获取全部字段
            $fields = $this->getTableFields($tableName);
            $field  = $fields ?: ['*'];
        } elseif ($except) {
            // 字段排除
            $fields = $this->getTableFields($tableName);
            $field  = $fields ? array_diff($fields, $field) : $field;
        }

        if ($tableName) {
            // 添加统一的前缀
            $prefix = $prefix ?: $tableName;
            foreach ($field as $key => $val) {
                if (is_numeric($key)) {
                    $val = $prefix . '.' . $val . ($alias ? ' AS ' . $alias . $val : '');
                }
                $field[$key] = $val;
            }
        }

        if (isset($this->options['field'])) {
            $field = array_merge((array) $this->options['field'], $field);
        }

        $this->options['field'] = array_unique($field);

        return $this;
    }

    /**
     * 表达式方式指定查询字段
     * @access public
     * @param  string $field    字段名
     * @return $this
     */
    public function fieldRaw(string $field)
    {
        $this->options['field'][] = $this->raw($field);

        return $this;
    }

    /**
     * 设置数据
     * @access public
     * @param  array $data 数据
     * @return $this
     */
    public function data(array $data)
    {
        $this->options['data'] = $data;

        return $this;
    }

    /**
     * 字段值增长
     * @access public
     * @param  string       $field 字段名
     * @param  integer      $step  增长值
     * @return $this
     */
    public function inc(string $field, int $step = 1, string $op = 'INC')
    {
        $this->options['data'][$field] = [$op, $step];

        return $this;
    }

    /**
     * 字段值减少
     * @access public
     * @param  string       $field 字段名
     * @param  integer      $step  增长值
     * @return $this
     */
    public function dec(string $field, int $step = 1)
    {
        return $this->inc($field, $step, 'DEC');
    }

    /**
     * 使用表达式设置数据
     * @access public
     * @param  string $field 字段名
     * @param  string $value 字段值
     * @return $this
     */
    public function exp(string $field, string $value)
    {
        $this->options['data'][$field] = $this->raw($value);
        return $this;
    }

    /**
     * 使用表达式设置数据
     * @access public
     * @param  string $value 表达式
     * @return Expression
     */
    public function raw(string $value): Expression
    {
        return new Expression($value);
    }

    /**
     * 指定JOIN查询字段
     * @access public
     * @param  string|array $table 数据表
     * @param  string|array $field 查询字段
     * @param  string|array $on    JOIN条件
     * @param  string       $type  JOIN类型
     * @return $this
     */
    public function view($join, $field = true, $on = null, string $type = 'INNER')
    {
        $this->options['view'] = true;

        $fields = [];
        $table  = $this->getJoinTable($join, $alias);

        if (true === $field) {
            $fields = $alias . '.*';
        } else {
            if (is_string($field)) {
                $field = explode(',', $field);
            }

            foreach ($field as $key => $val) {
                if (is_numeric($key)) {
                    $fields[] = $alias . '.' . $val;

                    $this->options['map'][$val] = $alias . '.' . $val;
                } else {
                    if (preg_match('/[,=\.\'\"\(\s]/', $key)) {
                        $name = $key;
                    } else {
                        $name = $alias . '.' . $key;
                    }

                    $fields[] = $name . ' AS ' . $val;

                    $this->options['map'][$val] = $name;
                }
            }
        }

        $this->field($fields);

        if ($on) {
            $this->join($table, $on, $type);
        } else {
            $this->table($table);
        }

        return $this;
    }

    /**
     * 设置分表规则
     * @access public
     * @param  array  $data  操作的数据
     * @param  string $field 分表依据的字段
     * @param  array  $rule  分表规则
     * @return $this
     */
    public function partition(array $data, string $field, array $rule = [])
    {
        $this->options['table'] = $this->getPartitionTableName($data, $field, $rule);

        return $this;
    }

    /**
     * 指定AND查询条件
     * @access public
     * @param  mixed $field     查询字段
     * @param  mixed $op        查询表达式
     * @param  mixed $condition 查询条件
     * @return $this
     */
    public function where($field, $op = null, $condition = null)
    {
        $param = func_get_args();
        array_shift($param);
        return $this->parseWhereExp('AND', $field, $op, $condition, $param);
    }

    /**
     * 指定OR查询条件
     * @access public
     * @param  mixed $field     查询字段
     * @param  mixed $op        查询表达式
     * @param  mixed $condition 查询条件
     * @return $this
     */
    public function whereOr($field, $op = null, $condition = null)
    {
        $param = func_get_args();
        array_shift($param);
        return $this->parseWhereExp('OR', $field, $op, $condition, $param);
    }

    /**
     * 指定XOR查询条件
     * @access public
     * @param  mixed $field     查询字段
     * @param  mixed $op        查询表达式
     * @param  mixed $condition 查询条件
     * @return $this
     */
    public function whereXor($field, $op = null, $condition = null)
    {
        $param = func_get_args();
        array_shift($param);
        return $this->parseWhereExp('XOR', $field, $op, $condition, $param);
    }

    /**
     * 指定Null查询条件
     * @access public
     * @param  mixed  $field 查询字段
     * @param  string $logic 查询逻辑 and or xor
     * @return $this
     */
    public function whereNull(string $field, string $logic = 'AND')
    {
        return $this->parseWhereExp($logic, $field, 'NULL', null, [], true);
    }

    /**
     * 指定NotNull查询条件
     * @access public
     * @param  mixed  $field 查询字段
     * @param  string $logic 查询逻辑 and or xor
     * @return $this
     */
    public function whereNotNull(string $field, string $logic = 'AND')
    {
        return $this->parseWhereExp($logic, $field, 'NOTNULL', null, [], true);
    }

    /**
     * 指定Exists查询条件
     * @access public
     * @param  mixed  $condition 查询条件
     * @param  string $logic     查询逻辑 and or xor
     * @return $this
     */
    public function whereExists($condition, string $logic = 'AND')
    {
        if (is_string($condition)) {
            $condition = $this->raw($condition);
        }

        $this->options['where'][strtoupper($logic)][] = ['', 'EXISTS', $condition];
        return $this;
    }

    /**
     * 指定NotExists查询条件
     * @access public
     * @param  mixed  $condition 查询条件
     * @param  string $logic     查询逻辑 and or xor
     * @return $this
     */
    public function whereNotExists($condition, string $logic = 'AND')
    {
        if (is_string($condition)) {
            $condition = $this->raw($condition);
        }

        $this->options['where'][strtoupper($logic)][] = ['', 'NOT EXISTS', $condition];
        return $this;
    }

    /**
     * 指定In查询条件
     * @access public
     * @param  mixed  $field     查询字段
     * @param  mixed  $condition 查询条件
     * @param  string $logic     查询逻辑 and or xor
     * @return $this
     */
    public function whereIn(string $field, $condition, string $logic = 'AND')
    {
        return $this->parseWhereExp($logic, $field, 'IN', $condition, [], true);
    }

    /**
     * 指定NotIn查询条件
     * @access public
     * @param  mixed  $field     查询字段
     * @param  mixed  $condition 查询条件
     * @param  string $logic     查询逻辑 and or xor
     * @return $this
     */
    public function whereNotIn(string $field, $condition, string $logic = 'AND')
    {
        return $this->parseWhereExp($logic, $field, 'NOT IN', $condition, [], true);
    }

    /**
     * 指定Like查询条件
     * @access public
     * @param  mixed  $field     查询字段
     * @param  mixed  $condition 查询条件
     * @param  string $logic     查询逻辑 and or xor
     * @return $this
     */
    public function whereLike(string $field, $condition, string $logic = 'AND')
    {
        return $this->parseWhereExp($logic, $field, 'LIKE', $condition, [], true);
    }

    /**
     * 指定NotLike查询条件
     * @access public
     * @param  mixed  $field     查询字段
     * @param  mixed  $condition 查询条件
     * @param  string $logic     查询逻辑 and or xor
     * @return $this
     */
    public function whereNotLike(string $field, $condition, string $logic = 'AND')
    {
        return $this->parseWhereExp($logic, $field, 'NOT LIKE', $condition, [], true);
    }

    /**
     * 指定Between查询条件
     * @access public
     * @param  mixed  $field     查询字段
     * @param  mixed  $condition 查询条件
     * @param  string $logic     查询逻辑 and or xor
     * @return $this
     */
    public function whereBetween(string $field, $condition, string $logic = 'AND')
    {
        return $this->parseWhereExp($logic, $field, 'BETWEEN', $condition, [], true);
    }

    /**
     * 指定NotBetween查询条件
     * @access public
     * @param  mixed  $field     查询字段
     * @param  mixed  $condition 查询条件
     * @param  string $logic     查询逻辑 and or xor
     * @return $this
     */
    public function whereNotBetween(string $field, $condition, string $logic = 'AND')
    {
        return $this->parseWhereExp($logic, $field, 'NOT BETWEEN', $condition, [], true);
    }

    /**
     * 比较两个字段
     * @access public
     * @param  string    $field1     查询字段
     * @param  string    $operator   比较操作符
     * @param  string    $field2     比较字段
     * @param  string    $logic      查询逻辑 and or xor
     * @return $this
     */
    public function whereColumn(string $field1, string $operator, string $field2 = null, string $logic = 'AND')
    {
        if (is_array($field1)) {
            foreach ($field1 as $item) {
                $this->whereColumn($item[0], $item[1], isset($item[2]) ? $item[2] : null);
            }
            return $this;
        }

        if (is_null($field2)) {
            $field2   = $operator;
            $operator = '=';
        }

        return $this->parseWhereExp($logic, $field1, 'COLUMN', [$operator, $field2], [], true);
    }

    /**
     * 设置软删除字段及条件
     * @access public
     * @param  string       $field     查询字段
     * @param  mixed        $condition 查询条件
     * @return $this
     */
    public function useSoftDelete(string $field, $condition = null)
    {
        if ($field) {
            $this->options['soft_delete'] = [$field, $condition];
        }

        return $this;
    }

    /**
     * 指定Exp查询条件
     * @access public
     * @param  mixed  $field     查询字段
     * @param  string $where     查询条件
     * @param  array  $bind      参数绑定
     * @param  string $logic     查询逻辑 and or xor
     * @return $this
     */
    public function whereExp(string $field, $where, array $bind = [], string $logic = 'AND')
    {
        if ($bind) {
            $this->bindParams($where, $bind);
        }

        $this->options['where'][$logic][] = [$field, 'EXP', $this->raw($where)];

        return $this;
    }

    /**
     * 指定表达式查询条件
     * @access public
     * @param  string $where  查询条件
     * @param  array  $bind   参数绑定
     * @param  string $logic  查询逻辑 and or xor
     * @return $this
     */
    public function whereRaw(string $where, array $bind = [], string $logic = 'AND')
    {
        if ($bind) {
            $this->bindParams($where, $bind);
        }

        $this->options['where'][$logic][] = $this->raw($where);

        return $this;
    }

    /**
     * 指定表达式查询条件 OR
     * @access public
     * @param  string $where  查询条件
     * @param  array  $bind   参数绑定
     * @return $this
     */
    public function whereOrRaw(string $where, array $bind = [])
    {
        return $this->whereRaw($where, $bind, 'OR');
    }

    /**
     * 分析查询表达式
     * @access protected
     * @param  string   $logic     查询逻辑 and or xor
     * @param  mixed    $field     查询字段
     * @param  mixed    $op        查询表达式
     * @param  mixed    $condition 查询条件
     * @param  array    $param     查询参数
     * @param  bool     $strict    严格模式
     * @return $this
     */
    protected function parseWhereExp(string $logic, $field, $op, $condition, array $param = [], bool $strict = false)
    {
        $logic = strtoupper($logic);

        if (is_string($field) && !empty($this->options['via']) && false === strpos($field, '.')) {
            $field = $this->options['via'] . '.' . $field;
        }

        if ($field instanceof Expression) {
            return $this->whereRaw($field, is_array($op) ? $op : []);
        } elseif ($strict) {
            // 使用严格模式查询
            $where = [$field, $op, $condition];
        } elseif (is_array($field)) {
            // 解析数组批量查询
            return $this->parseArrayWhereItems($field, $logic);
        } elseif ($field instanceof Closure) {
            $where = $field;
        } elseif (is_string($field)) {
            if (preg_match('/[,=\<\'\"\(\s]/', $field)) {
                return $this->whereRaw($field, $op);
            } elseif (is_string($op) && strtolower($op) == 'exp') {
                $bind = isset($param[2]) && is_array($param[2]) ? $param[2] : null;
                return $this->whereExp($field, $condition, $bind, $logic);
            }

            $where = $this->parseWhereItem($logic, $field, $op, $condition, $param);
        }

        if (!empty($where)) {
            $this->options['where'][$logic][] = $where;
        }

        return $this;
    }

    /**
     * 分析查询表达式
     * @access protected
     * @param  string   $logic     查询逻辑 and or xor
     * @param  mixed    $field     查询字段
     * @param  mixed    $op        查询表达式
     * @param  mixed    $condition 查询条件
     * @param  array    $param     查询参数
     * @return array
     */
    protected function parseWhereItem(string $logic, $field, $op, $condition, array $param = []): array
    {
        if (is_array($op)) {
            // 同一字段多条件查询
            array_unshift($param, $field);
            $where = $param;
        } elseif (!is_string($op)) {
            $where = [$field, '=', $op];
        } elseif ($field && is_null($condition)) {
            if (in_array(strtoupper($op), ['NULL', 'NOTNULL', 'NOT NULL'], true)) {
                // null查询
                $where = [$field, $op, ''];
            } elseif (in_array($op, ['=', 'eq', 'EQ', null], true)) {
                $where = [$field, 'NULL', ''];
            } elseif (in_array($op, ['<>', 'neq', 'NEQ'], true)) {
                $where = [$field, 'NOTNULL', ''];
            } else {
                // 字段相等查询
                $where = [$field, '=', $op];
            }
        } elseif (in_array(strtoupper($op), ['REGEXP', 'NOT REGEXP', 'EXISTS', 'NOT EXISTS', 'NOTEXISTS'], true)) {
            $where = [$field, $op, is_string($condition) ? $this->raw($condition) : $condition];
        } else {
            $where = $field ? [$field, $op, $condition, isset($param[2]) ? $param[2] : null] : [];
        }

        return $where;
    }

    /**
     * 数组批量查询
     * @access protected
     * @param  array    $field     批量查询
     * @param  string   $logic     查询逻辑 and or xor
     * @return $this
     */
    protected function parseArrayWhereItems(array $field, string $logic)
    {
        if (key($field) !== 0) {
            $where = [];
            foreach ($field as $key => $val) {
                if ($val instanceof Expression) {
                    $where[] = [$key, 'exp', $val];
                } else {
                    $where[] = is_null($val) ? [$key, 'NULL', ''] : [$key, is_array($val) ? 'IN' : '=', $val];
                }
            }
        } else {
            // 数组批量查询
            $where = $field;
        }

        if (!empty($where)) {
            $this->options['where'][$logic] = isset($this->options['where'][$logic]) ? array_merge($this->options['where'][$logic], $where) : $where;
        }

        return $this;
    }

    /**
     * 去除某个查询条件
     * @access public
     * @param  string $field 查询字段
     * @param  string $logic 查询逻辑 and or xor
     * @return $this
     */
    public function removeWhereField(string $field, string $logic = 'AND')
    {
        $logic = strtoupper($logic);

        if (isset($this->options['where'][$logic])) {
            foreach ($this->options['where'][$logic] as $key => $val) {
                if (is_array($val) && $val[0] == $field) {
                    unset($this->options['where'][$logic][$key]);
                }
            }
        }

        return $this;
    }

    /**
     * 去除查询参数
     * @access public
     * @param  string $option 参数名 留空去除所有参数
     * @return $this
     */
    public function removeOption(string $option = '')
    {
        if ('' === $option) {
            $this->options = [];
        } elseif (isset($this->options[$option])) {
            unset($this->options[$option]);
        }

        return $this;
    }

    /**
     * 条件查询
     * @access public
     * @param  mixed             $condition  满足条件（支持闭包）
     * @param  Closure|array    $query      满足条件后执行的查询表达式（闭包或数组）
     * @param  Closure|array    $otherwise  不满足条件后执行
     * @return $this
     */
    public function when($condition, $query, $otherwise = null)
    {
        if ($condition instanceof Closure) {
            $condition = $condition($this);
        }

        if ($condition) {
            if ($query instanceof Closure) {
                $query($this, $condition);
            } elseif (is_array($query)) {
                $this->where($query);
            }
        } elseif ($otherwise) {
            if ($otherwise instanceof Closure) {
                $otherwise($this, $condition);
            } elseif (is_array($otherwise)) {
                $this->where($otherwise);
            }
        }

        return $this;
    }

    /**
     * 指定查询数量
     * @access public
     * @param  int $offset 起始位置
     * @param  int $length 查询数量
     * @return $this
     */
    public function limit(int $offset, int $length = null)
    {
        $this->options['limit'] = $offset . ($length ? ',' . $length : '');

        return $this;
    }

    /**
     * 指定分页
     * @access public
     * @param  int $page     页数
     * @param  int $listRows 每页数量
     * @return $this
     */
    public function page(int $page, int $listRows = null)
    {
        $this->options['page'] = [$page, $listRows];

        return $this;
    }

    /**
     * 分页查询
     * @access public
     * @param  int|array $listRows 每页数量 数组表示配置参数
     * @param  int|bool  $simple   是否简洁模式或者总记录数
     * @param  array     $config   配置参数
     *                            page:当前页,
     *                            path:url路径,
     *                            query:url额外参数,
     *                            fragment:url锚点,
     *                            var_page:分页变量,
     *                            list_rows:每页数量
     *                            type:分页类名
     * @return \think\Paginator
     * @throws DbException
     */
    public function paginate($listRows = null, $simple = false, $config = [])
    {
        if (is_int($simple)) {
            $total  = $simple;
            $simple = false;
        }

        $paginate = Container::get('config')->pull('paginate');

        if (is_array($listRows)) {
            $config   = array_merge($paginate, $listRows);
            $listRows = intval($config['list_rows']);
        } else {
            $config   = array_merge($paginate, $config);
            $listRows = intval($listRows ?: $config['list_rows']);
        }

        /** @var Paginator $class */
        $class = false !== strpos($config['type'], '\\') ? $config['type'] : '\\think\\paginator\\driver\\' . ucwords($config['type']);
        $page  = isset($config['page']) ? (int) $config['page'] : call_user_func([
            $class,
            'getCurrentPage',
        ], $config['var_page']);

        $page = $page < 1 ? 1 : $page;

        $config['path'] = $config['path'] ?? call_user_func([$class, 'getCurrentPath']);

        if (!isset($total) && !$simple) {
            $options = $this->getOptions();

            unset($this->options['order'], $this->options['limit'], $this->options['page'], $this->options['field']);

            $bind    = $this->bind;
            $total   = $this->count();
            $results = $this->options($options)->bind($bind)->page($page, $listRows)->select();
        } elseif ($simple) {
            $results = $this->limit(($page - 1) * $listRows, $listRows + 1)->select();
            $total   = null;
        } else {
            $results = $this->page($page, $listRows)->select();
        }

        $this->removeOption('limit');
        $this->removeOption('page');

        return $class::make($results, $listRows, $page, $total, $simple, $config);
    }

    /**
     * 表达式方式指定当前操作的数据表
     * @access public
     * @param  mixed $table 表名
     * @return $this
     */
    public function tableRaw(string $table)
    {
        $this->options['table'] = $this->raw($table);

        return $this;
    }

    /**
     * 指定当前操作的数据表
     * @access public
     * @param  mixed $table 表名
     * @return $this
     */
    public function table($table)
    {
        if (is_string($table)) {
            if (strpos($table, ')')) {
                // 子查询
            } else {
                $tables = explode(',', $table);
                $table  = [];

                foreach ($tables as $item) {
                    list($item, $alias) = explode(' ', trim($item));
                    if ($alias) {
                        $this->alias([$item => $alias]);
                        $table[$item] = $alias;
                    } else {
                        $table[] = $item;
                    }
                }
            }
        } else {
            $tables = $table;
            $table  = [];

            foreach ($tables as $key => $val) {
                if (is_numeric($key)) {
                    $table[] = $val;
                } else {
                    $this->alias([$key => $val]);
                    $table[$key] = $val;
                }
            }
        }

        $this->options['table'] = $table;

        return $this;
    }

    /**
     * USING支持 用于多表删除
     * @access public
     * @param  mixed $using
     * @return $this
     */
    public function using($using)
    {
        $this->options['using'] = $using;
        return $this;
    }

    /**
     * 指定排序 order('id','desc') 或者 order(['id'=>'desc','create_time'=>'desc'])
     * @access public
     * @param  string|array $field 排序字段
     * @param  string       $order 排序
     * @return $this
     */
    public function order($field, string $order = '')
    {
        if (empty($field)) {
            return $this;
        } elseif ($field instanceof Expression) {
            $this->options['order'][] = $field;
            return $this;
        }

        if (is_string($field)) {
            if (!empty($this->options['via'])) {
                $field = $this->options['via'] . '.' . $field;
            }
            if (strpos($field, ',')) {
                $field = array_map('trim', explode(',', $field));
            } else {
                $field = empty($order) ? $field : [$field => $order];
            }
        } elseif (!empty($this->options['via'])) {
            foreach ($field as $key => $val) {
                if (is_numeric($key)) {
                    $field[$key] = $this->options['via'] . '.' . $val;
                } else {
                    $field[$this->options['via'] . '.' . $key] = $val;
                    unset($field[$key]);
                }
            }
        }

        if (!isset($this->options['order'])) {
            $this->options['order'] = [];
        }

        if (is_array($field)) {
            $this->options['order'] = array_merge($this->options['order'], $field);
        } else {
            $this->options['order'][] = $field;
        }

        return $this;
    }

    /**
     * 表达式方式指定Field排序
     * @access public
     * @param  string $field 排序字段
     * @param  array  $bind  参数绑定
     * @return $this
     */
    public function orderRaw(string $field, array $bind = [])
    {
        if ($bind) {
            $this->bindParams($field, $bind);
        }

        $this->options['order'][] = $this->raw($field);

        return $this;
    }

    /**
     * 指定Field排序 orderField('id',[1,2,3],'desc')
     * @access public
     * @param  string   $field 排序字段
     * @param  array    $values 排序值
     * @param  string   $order
     * @return $this
     */
    public function orderField(string $field, array $values, string $order = '')
    {
        if (!empty($values)) {
            $values['sort'] = $order;

            $this->options['order'][$field] = $values;
        }

        return $this;
    }

    /**
     * 随机排序
     * @access public
     * @return $this
     */
    public function orderRand()
    {
        $this->options['order'][] = '[rand]';
        return $this;
    }

    /**
     * 查询缓存
     * @access public
     * @param  mixed             $key    缓存key
     * @param  integer|\DateTime $expire 缓存有效期
     * @param  string            $tag    缓存标签
     * @return $this
     */
    public function cache($key = true, $expire = null, $tag = null)
    {
        // 增加快捷调用方式 cache(10) 等同于 cache(true, 10)
        if ($key instanceof \DateTime || (is_numeric($key) && is_null($expire))) {
            $expire = $key;
            $key    = true;
        }

        if (false !== $key) {
            $this->options['cache'] = ['key' => $key, 'expire' => $expire, 'tag' => $tag];
        }

        return $this;
    }

    /**
     * 指定group查询
     * @access public
     * @param  string|array $group GROUP
     * @return $this
     */
    public function group($group)
    {
        $this->options['group'] = $group;
        return $this;
    }

    /**
     * 指定having查询
     * @access public
     * @param  string $having having
     * @return $this
     */
    public function having(string $having)
    {
        $this->options['having'] = $having;
        return $this;
    }

    /**
     * 指定查询lock
     * @access public
     * @param  bool|string $lock 是否lock
     * @return $this
     */
    public function lock($lock = false)
    {
        $this->options['lock'] = $lock;

        if ($lock) {
            $this->options['master'] = true;
        }

        return $this;
    }

    /**
     * 指定distinct查询
     * @access public
     * @param  bool $distinct 是否唯一
     * @return $this
     */
    public function distinct(bool $distinct = true)
    {
        $this->options['distinct'] = $distinct;
        return $this;
    }

    /**
     * 指定数据表别名
     * @access public
     * @param  array|string $alias 数据表别名
     * @return $this
     */
    public function alias($alias)
    {
        if (is_array($alias)) {
            $this->options['alias'] = $alias;
        } else {
            $table = $this->getTable();

            $this->options['alias'][$table] = $alias;
        }

        return $this;
    }

    /**
     * 指定强制索引
     * @access public
     * @param  string $force 索引名称
     * @return $this
     */
    public function force(string $force)
    {
        $this->options['force'] = $force;
        return $this;
    }

    /**
     * 查询注释
     * @access public
     * @param  string $comment 注释
     * @return $this
     */
    public function comment(string $comment)
    {
        $this->options['comment'] = $comment;
        return $this;
    }

    /**
     * 获取执行的SQL语句而不进行实际的查询
     * @access public
     * @param  bool $fetch 是否返回sql
     * @return $this|Fetch
     */
    public function fetchSql(bool $fetch = true)
    {
        $this->options['fetch_sql'] = $fetch;

        if ($fetch) {
            return new Fetch($this);
        }

        return $this;
    }

    /**
     * 设置是否返回数据集对象
     * @access public
     * @param  bool|string  $collection  是否返回数据集对象
     * @return $this
     */
    public function fetchCollection($collection = true)
    {
        $this->options['collection'] = $collection;
        return $this;
    }

    /**
     * 设置是否返回数组
     * @access public
     * @param  bool  $asArray  是否返回数组
     * @return $this
     */
    public function fetchArray(bool $asArray = true)
    {
        if ($asArray) {
            $this->model = null;
        }
        return $this;
    }

    /**
     * 设置从主服务器读取数据
     * @access public
     * @param  bool $readMaster 是否从主服务器读取
     * @return $this
     */
    public function master(bool $readMaster = true)
    {
        $this->options['master'] = $readMaster;
        return $this;
    }

    /**
     * 设置是否严格检查字段名
     * @access public
     * @param  bool $strict 是否严格检查字段
     * @return $this
     */
    public function strict(bool $strict = true)
    {
        $this->options['strict'] = $strict;
        return $this;
    }

    /**
     * 设置查询数据不存在是否抛出异常
     * @access public
     * @param  bool $fail 数据不存在是否抛出异常
     * @return $this
     */
    public function failException(bool $fail = true)
    {
        $this->options['fail'] = $fail;
        return $this;
    }

    /**
     * 设置自增序列名
     * @access public
     * @param  string $sequence 自增序列名
     * @return $this
     */
    public function sequence(string $sequence = null)
    {
        $this->options['sequence'] = $sequence;
        return $this;
    }

    /**
     * 设置需要隐藏的输出属性
     * @access public
     * @param  array $hidden 需要隐藏的字段名
     * @return $this
     */
    public function hidden(array $hidden)
    {
        $this->options['hidden'] = $hidden;
        return $this;
    }

    /**
     * 设置需要输出的属性
     * @access public
     * @param  array $visible 需要输出的属性
     * @return $this
     */
    public function visible(array $visible)
    {
        $this->options['visible'] = $visible;
        return $this;
    }

    /**
     * 设置需要追加输出的属性
     * @access public
     * @param  array $append 需要追加的属性
     * @return $this
     */
    public function append(array $append)
    {
        $this->options['append'] = $append;
        return $this;
    }

    /**
     * 设置JSON字段信息
     * @access public
     * @param  array $json JSON字段
     * @param  bool  $assoc 是否取出数组
     * @return $this
     */
    public function json(array $json = [], bool $assoc = false)
    {
        $this->options['json']       = $json;
        $this->options['json_assoc'] = $assoc;
        return $this;
    }

    /**
     * 是否允许返回空数据（或空模型）
     * @access public
     * @param  bool $allowEmpty 是否允许为空
     * @return $this
     */
    public function allowEmpty(bool $allowEmpty = true)
    {
        $this->options['allow_empty'] = $allowEmpty;
        return $this;
    }

    /**
     * 添加查询范围
     * @access public
     * @param  array|string|Closure   $scope 查询范围定义
     * @param  array                   $args  参数
     * @return $this
     */
    public function scope($scope, ...$args)
    {
        // 查询范围的第一个参数始终是当前查询对象
        array_unshift($args, $this);

        if ($scope instanceof Closure) {
            call_user_func_array($scope, $args);
            return $this;
        }

        if (is_string($scope)) {
            $scope = explode(',', $scope);
        }

        if ($this->model) {
            // 检查模型类的查询范围方法
            foreach ($scope as $name) {
                $method = 'scope' . trim($name);

                if (method_exists($this->model, $method)) {
                    call_user_func_array([$this->model, $method], $args);
                }
            }
        }

        return $this;
    }

    /**
     * 指定数据表主键
     * @access public
     * @param  string $pk 主键
     * @return $this
     */
    public function pk(string $pk)
    {
        $this->pk = $pk;
        return $this;
    }

    /**
     * 添加日期或者时间查询规则
     * @access public
     * @param  string       $name  时间表达式
     * @param  string|array $rule  时间范围
     * @return $this
     */
    public function timeRule(string $name, $rule)
    {
        $this->timeRule[$name] = $rule;
        return $this;
    }

    /**
     * 查询日期或者时间
     * @access public
     * @param  string       $field 日期字段名
     * @param  string|array $op    比较运算符或者表达式
     * @param  string|array $range 比较范围
     * @param  string       $logic AND OR
     * @return $this
     */
    public function whereTime(string $field, $op, $range = null, string $logic = 'AND')
    {
        if (is_null($range)) {
            if (is_array($op)) {
                $range = $op;
            } else {
                if (isset($this->timeExp[strtolower($op)])) {
                    $op = $this->timeExp[strtolower($op)];
                }

                if (isset($this->timeRule[strtolower($op)])) {
                    $range = $this->timeRule[strtolower($op)];
                } else {
                    $range = $op;
                }
            }

            $op = is_array($range) ? 'between' : '>=';
        }

        return $this->parseWhereExp($logic, $field, strtolower($op) . ' time', $range, [], true);
    }

    /**
     * 查询日期或者时间范围
     * @access public
     * @param  string    $field 日期字段名
     * @param  string    $startTime    开始时间
     * @param  string    $endTime 结束时间
     * @param  string    $logic AND OR
     * @return $this
     */
    public function whereBetweenTime(string $field, $startTime, $endTime = null, string $logic = 'AND')
    {
        if (is_null($endTime)) {
            $time    = is_string($startTime) ? strtotime($startTime) : $startTime;
            $endTime = strtotime('+1 day', $time);
        }

        return $this->parseWhereExp($logic, $field, 'between time', [$startTime, $endTime], [], true);
    }

    /**
     * 查询当前时间在两个时间字段范围
     * @access public
     * @param  string    $startField    开始时间字段
     * @param  string    $endField      结束时间字段
     * @return $this
     */
    public function whereBetweenTimeField(string $startField, string $endField)
    {
        return $this->whereTime($startField, '<=', time())
            ->whereTime($endField, '>=', time());
    }

    /**
     * 查询当前时间不在两个时间字段范围
     * @access public
     * @param  string    $startField    开始时间字段
     * @param  string    $endField      结束时间字段
     * @return $this
     */
    public function whereNotBetweenTimeField(string $startField, string $endField)
    {
        return $this->whereTime($startField, '>', time())
            ->whereTime($endField, '<', time(), 'OR');
    }

    /**
     * 获取当前数据表的主键
     * @access public
     * @param  string|array $options 数据表名或者查询参数
     * @return string|array
     */
    public function getPk($options = '')
    {
        if (!empty($this->pk)) {
            $pk = $this->pk;
        } else {
            $pk = $this->connection->getPk(is_array($options) && isset($options['table']) ? $options['table'] : $this->getTable());
        }

        return $pk;
    }

    /**
     * 参数绑定
     * @access public
     * @param  mixed   $value 绑定变量值
     * @param  integer $type  绑定类型
     * @param  string  $name  绑定标识
     * @return $this|string
     */
    public function bind($value, int $type = PDO::PARAM_STR, string $name = null)
    {
        if (is_array($value)) {
            $this->bind = array_merge($this->bind, $value);
        } else {
            $name = $name ?: 'ThinkBind_' . (count($this->bind) + 1) . '_';

            $this->bind[$name] = [$value, $type];
            return $name;
        }

        return $this;
    }

    /**
     * 参数绑定
     * @access public
     * @param  string $sql    绑定的sql表达式
     * @param  array  $bind   参数绑定
     * @return void
     */
    protected function bindParams(string &$sql, array $bind = []): void
    {
        foreach ($bind as $key => $value) {
            if (is_array($value)) {
                $name = $this->bind($value[0], $value[1], $value[2] ?? null);
            } else {
                $name = $this->bind($value);
            }

            if (is_numeric($key)) {
                $sql = substr_replace($sql, ':' . $name, strpos($sql, '?'), 1);
            } else {
                $sql = str_replace(':' . $key, ':' . $name, $sql);
            }
        }
    }

    /**
     * 查询参数批量赋值
     * @access protected
     * @param  array $options 表达式参数
     * @return $this
     */
    protected function options(array $options)
    {
        $this->options = $options;
        return $this;
    }

    /**
     * 获取当前的查询参数
     * @access public
     * @param  string $name 参数名
     * @return mixed
     */
    public function getOptions(string $name = '')
    {
        if ('' === $name) {
            return $this->options;
        }

        return $this->options[$name] ?? null;
    }

    /**
     * 设置当前的查询参数
     * @access public
     * @param  string $option 参数名
     * @param  mixed  $value  参数值
     * @return $this
     */
    public function setOption(string $option, $value)
    {
        $this->options[$option] = $value;
        return $this;
    }

    /**
     * 设置关联查询JOIN预查询
     * @access public
     * @param  array $with 关联方法名称(数组)
     * @return $this
     */
    public function with(array $with)
    {
        if (empty($with)) {
            return $this;
        }

        $this->options['with'] = $with;

        return $this;
    }

    /**
     * 关联预载入 JOIN方式
     * @access protected
     * @param  array        $with 关联方法名
     * @param  string       $joinType JOIN方式
     * @return $this
     */
    public function withJoin(array $with, string $joinType = '')
    {
        if (empty($with)) {
            return $this;
        }

        $first = true;

        /** @var Model $class */
        $class = $this->model;
        foreach ($with as $key => $relation) {
            $closure = null;
            $field   = true;

            if ($relation instanceof Closure) {
                // 支持闭包查询过滤关联条件
                $closure  = $relation;
                $relation = $key;
            } elseif (is_array($relation)) {
                $field    = $relation;
                $relation = $key;
            } elseif (is_string($relation) && strpos($relation, '.')) {
                $relation = strstr($relation, '.', true);
            }

            /** @var Relation $model */
            $relation = App::parseName($relation, 1, false);
            $model    = $class->$relation();

            if ($model instanceof OneToOne) {
                $model->eagerly($this, $relation, $field, $joinType, $closure, $first);
                $first = false;
            } else {
                // 不支持其它关联
                unset($with[$key]);
            }
        }

        $this->via();

        $this->options['with_join'] = $with;

        return $this;
    }

    /**
     * 设置数据字段获取器
     * @access public
     * @param  string       $name       字段名
     * @param  callable     $callback   闭包获取器
     * @return $this
     */
    public function withAttr(string $name, callable $callback)
    {
        $this->options['with_attr'][$name] = $callback;

        return $this;
    }

    /**
     * 设置数据字段获取器
     * @access public
     * @param  array    $attrs       字段获取器
     * @return $this
     */
    public function withAttrs(array $attrs)
    {
        $this->options['with_attr'] = $attrs;

        return $this;
    }

    /**
     * 使用搜索器条件搜索字段
     * @access public
     * @param  array    $fields     搜索字段
     * @param  array    $data       搜索数据
     * @param  string   $prefix     字段前缀标识
     * @return $this
     */
    public function withSearch(array $fields, array $data = [], string $prefix = '')
    {
        foreach ($fields as $key => $field) {
            if ($field instanceof Closure) {
                $field($this, $data[$key] ?? null, $data, $prefix);
            } elseif ($this->model) {
                // 检测搜索器
                $fieldName = is_numeric($key) ? $field : $key;
                $method    = 'search' . App::parseName($fieldName, 1) . 'Attr';

                if (method_exists($this->model, $method)) {
                    $this->model->$method($this, $data[$field] ?? null, $data, $prefix);
                }
            }
        }

        return $this;
    }

    /**
     * 关联统计
     * @access protected
     * @param  array        $relations 关联方法名
     * @param  string       $aggregate 聚合查询方法
     * @param  string       $field 字段
     * @param  bool         $subQuery 是否使用子查询
     * @return $this
     */
    protected function withAggregate($relations, string $aggregate = 'count', $field = '*', bool $subQuery = true)
    {
        if (is_string($relations)) {
            $relations = explode(',', $relations);
        }

        if (!$subQuery) {
            $this->options['with_count'][] = [$relations, $aggregate, $field];
        } else {
            if (!isset($this->options['field'])) {
                $this->field('*');
            }

            foreach ($relations as $key => $relation) {
                $closure = $aggregateField = null;

                if ($relation instanceof Closure) {
                    $closure  = $relation;
                    $relation = $key;
                } elseif (!is_int($key)) {
                    $aggregateField = $relation;
                    $relation       = $key;
                }

                $relation = App::parseName($relation, 1, false);

                $count = '(' . $this->model->$relation()->getRelationCountQuery($closure, $aggregate, $field, $aggregateField) . ')';

                if (empty($aggregateField)) {
                    $aggregateField = App::parseName($relation) . '_' . $aggregate;
                }

                $this->field([$count => $aggregateField]);
            }
        }

        return $this;
    }

    /**
     * 关联统计
     * @access public
     * @param  string|array $relation 关联方法名
     * @param  bool         $subQuery 是否使用子查询
     * @return $this
     */
    public function withCount($relation, bool $subQuery = true)
    {
        return $this->withAggregate($relation, 'count', '*', $subQuery);
    }

    /**
     * 关联统计Sum
     * @access public
     * @param  string|array $relation 关联方法名
     * @param  string       $field 字段
     * @param  bool         $subQuery 是否使用子查询
     * @return $this
     */
    public function withSum($relation, string $field, bool $subQuery = true)
    {
        return $this->withAggregate($relation, 'sum', $field, $subQuery);
    }

    /**
     * 关联统计Max
     * @access public
     * @param  string|array $relation 关联方法名
     * @param  string       $field 字段
     * @param  bool         $subQuery 是否使用子查询
     * @return $this
     */
    public function withMax($relation, string $field, bool $subQuery = true)
    {
        return $this->withAggregate($relation, 'max', $field, $subQuery);
    }

    /**
     * 关联统计Min
     * @access public
     * @param  string|array $relation 关联方法名
     * @param  string       $field 字段
     * @param  bool         $subQuery 是否使用子查询
     * @return $this
     */
    public function withMin($relation, string $field, bool $subQuery = true)
    {
        return $this->withAggregate($relation, 'min', $field, $subQuery);
    }

    /**
     * 关联统计Avg
     * @access public
     * @param  string|array $relation 关联方法名
     * @param  string       $field 字段
     * @param  bool         $subQuery 是否使用子查询
     * @return $this
     */
    public function withAvg($relation, string $field, bool $subQuery = true)
    {
        return $this->withAggregate($relation, 'avg', $field, $subQuery);
    }

    /**
     * 关联预加载中 获取关联指定字段值
     * example:
     * Model::with(['relation' => function($query){
     *     $query->withField("id,name");
     * }])
     *
     * @access public
     * @param  string | array $field 指定获取的字段
     * @return $this
     */
    public function withField($field)
    {
        $this->options['with_field'] = $field;

        return $this;
    }

    /**
     * 设置当前字段添加的表别名
     * @access public
     * @param  string $via
     * @return $this
     */
    public function via(string $via = '')
    {
        $this->options['via'] = $via;

        return $this;
    }

    /**
     * 设置关联查询
     * @access public
     * @param  string|array $relation 关联名称
     * @return $this
     */
    public function relation(array $relation)
    {
        if (empty($relation)) {
            return $this;
        }

        $this->options['relation'] = $relation;

        return $this;
    }

    /**
     * 插入记录
     * @access public
     * @param  array   $data         数据
     * @param  boolean $replace      是否replace
     * @param  boolean $getLastInsID 返回自增主键
     * @param  string  $sequence     自增序列名
     * @return integer
     */
    public function insert(array $data = [], bool $replace = false, bool $getLastInsID = false, string $sequence = null)
    {
        $this->options['data'] = array_merge($this->options['data'] ?? [], $data);

        return $this->connection->insert($this, $replace, $getLastInsID, $sequence);
    }

    /**
     * 插入记录并获取自增ID
     * @access public
     * @param  array   $data     数据
     * @param  boolean $replace  是否replace
     * @param  string  $sequence 自增序列名
     * @return integer|string
     */
    public function insertGetId(array $data, bool $replace = false, string $sequence = null)
    {
        return $this->insert($data, $replace, true, $sequence);
    }

    /**
     * 批量插入记录
     * @access public
     * @param  array     $dataSet 数据集
     * @param  boolean   $replace 是否replace
     * @param  integer   $limit   每次写入数据限制
     * @return integer
     */
    public function insertAll(array $dataSet = [], bool $replace = false, int $limit = null): int
    {
        if (empty($dataSet)) {
            $dataSet = $this->options['data'] ?? [];
        }

        if (empty($limit) && !empty($this->options['limit'])) {
            $limit = (int) $this->options['limit'];
        }

        return $this->connection->insertAll($this, $dataSet, $replace, $limit);
    }

    /**
     * 通过Select方式插入记录
     * @access public
     * @param  array    $fields 要插入的数据表字段名
     * @param  string   $table  要插入的数据表名
     * @return integer
     * @throws PDOException
     */
    public function selectInsert(array $fields, string $table): int
    {
        return $this->connection->selectInsert($this, $fields, $table);
    }

    /**
     * 更新记录
     * @access public
     * @param  mixed $data 数据
     * @return integer
     * @throws Exception
     * @throws PDOException
     */
    public function update(array $data = []): int
    {
        $this->options['data'] = array_merge($this->options['data'] ?? [], $data);

        return $this->connection->update($this);
    }

    /**
     * 删除记录
     * @access public
     * @param  mixed $data 表达式 true 表示强制删除
     * @return int
     * @throws Exception
     * @throws PDOException
     */
    public function delete($data = null): int
    {
        if (!is_null($data) && true !== $data) {
            // AR模式分析主键条件
            $this->parsePkWhere($data);
        }

        if (!empty($this->options['soft_delete'])) {
            // 软删除
            list($field, $condition) = $this->options['soft_delete'];
            if ($condition) {
                unset($this->options['soft_delete']);
                $this->options['data'] = [$field => $condition];

                return $this->connection->update($this);
            }
        }

        $this->options['data'] = $data;

        return $this->connection->delete($this);
    }

    /**
     * 执行查询但只返回PDOStatement对象
     * @access public
     * @return PDOStatement
     */
    public function getPdo(): PDOStatement
    {
        return $this->connection->pdo($this);
    }

    /**
     * 使用游标查找记录
     * @access public
     * @param  mixed $data
     * @return \Generator
     */
    public function cursor($data = null)
    {
        if ($data instanceof Closure) {
            $data($this);
            $data = null;
        }

        if (!is_null($data)) {
            // 主键条件分析
            $this->parsePkWhere($data);
        }

        $this->options['data'] = $data;

        $connection = clone $this->connection;

        return $connection->cursor($this);
    }

    /**
     * 查找记录
     * @access public
     * @param  mixed $data
     * @return Collection|array|ModelCollection
     * @throws DbException
     * @throws ModelNotFoundException
     * @throws DataNotFoundException
     */
    public function select($data = null)
    {
        if ($data instanceof Closure) {
            $data($this);
            $data = null;
        }

        if (!is_null($data)) {
            // 主键条件分析
            $this->parsePkWhere($data);
        }

        $this->options['data'] = $data;

        $resultSet = $this->connection->select($this);

        // 返回结果处理
        if (!empty($this->options['fail']) && count($resultSet) == 0) {
            $this->throwNotFound($this->options);
        }

        // 数据列表读取后的处理
        if (!empty($this->model)) {
            // 生成模型对象
            $resultSet = $this->resultSetToModelCollection($resultSet);
        } else {
            $this->resultSet($resultSet);
        }

        return $resultSet;
    }

    /**
     * 查询数据转换为模型数据集对象
     * @access protected
     * @param  array  $resultSet         数据集
     * @return ModelCollection
     */
    protected function resultSetToModelCollection(array $resultSet): ModelCollection
    {
        if (!empty($this->options['collection']) && is_string($this->options['collection'])) {
            $collection = $this->options['collection'];
        }

        if (empty($resultSet)) {
            return $this->model->toCollection([], $collection ?? null);
        }

        // 检查动态获取器
        if (!empty($this->options['with_attr'])) {
            foreach ($this->options['with_attr'] as $name => $val) {
                if (strpos($name, '.')) {
                    list($relation, $field) = explode('.', $name);

                    $withRelationAttr[$relation][$field] = $val;
                    unset($this->options['with_attr'][$name]);
                }
            }
        }

        $withRelationAttr = $withRelationAttr ?? [];

        foreach ($resultSet as $key => &$result) {
            // 数据转换为模型对象
            $this->resultToModel($result, $this->options, true, $withRelationAttr);
        }

        if (!empty($this->options['with'])) {
            // 预载入
            $result->eagerlyResultSet($resultSet, $this->options['with'], $withRelationAttr);
        }

        if (!empty($this->options['with_join'])) {
            // 预载入
            $result->eagerlyResultSet($resultSet, $this->options['with_join'], $withRelationAttr, true);
        }

        // 模型数据集转换
        return $this->model->toCollection($resultSet, $collection ?? null);
    }

    /**
     * 处理数据集
     * @access public
     * @param  array $resultSet
     * @return void
     */
    protected function resultSet(array &$resultSet): void
    {
        if (!empty($this->options['json'])) {
            foreach ($resultSet as &$result) {
                $this->jsonResult($result, $this->options['json'], true);
            }
        }

        if (!empty($this->options['with_attr'])) {
            foreach ($resultSet as &$result) {
                $this->getResultAttr($result, $this->options['with_attr']);
            }
        }

        if (!empty($this->options['visible']) || !empty($this->options['hidden'])) {
            foreach ($resultSet as &$result) {
                $this->filterResult($result);
            }
        }

        if (!empty($this->options['collection'])) {
            // 返回Collection对象
            $resultSet = new Collection($resultSet);
        }
    }

    /**
     * 查找单条记录
     * @access public
     * @param  mixed $data
     * @return array|null|Model
     * @throws DbException
     * @throws ModelNotFoundException
     * @throws DataNotFoundException
     */
    public function find($data = null)
    {
        if ($data instanceof Closure) {
            $data($this);
            $data = null;
        }

        if (!is_null($data)) {
            // AR模式分析主键条件
            $this->parsePkWhere($data);
        }

        $this->options['data'] = $data;

        $result = $this->connection->find($this);

        // 数据处理
        if (empty($result)) {
            return $this->resultToEmpty();
        }

        if (!empty($this->model)) {
            // 返回模型对象
            $this->resultToModel($result, $this->options);
        } else {
            $this->result($result);
        }

        return $result;
    }

    /**
     * 处理空数据
     * @access protected
     * @return array|Model|null
     * @throws DbException
     * @throws ModelNotFoundException
     * @throws DataNotFoundException
     */
    protected function resultToEmpty()
    {
        if (!empty($this->options['allow_empty'])) {
            return !empty($this->model) ? $this->model->newInstance([], true, $this->getModelUpdateCondition($this->options)) : [];
        } elseif (!empty($this->options['fail'])) {
            $this->throwNotFound($this->options);
        }
    }

    /**
     * 获取模型的更新条件
     * @access protected
     * @param  array $options 查询参数
     */
    protected function getModelUpdateCondition(array $options)
    {
        return $options['where']['AND'] ?? null;
    }

    /**
     * 处理数据
     * @access protected
     * @param  array $result     查询数据
     * @return void
     */
    protected function result(array &$result): void
    {
        if (!empty($this->options['json'])) {
            $this->jsonResult($result, $this->options['json'], true);
        }

        if (!empty($this->options['with_attr'])) {
            $this->getResultAttr($result, $this->options['with_attr']);
        }

        $this->filterResult($result);
    }

    /**
     * 处理数据的可见和隐藏
     * @access protected
     * @param  array $result     查询数据
     * @return void
     */
    protected function filterResult(&$result): void
    {
        if (!empty($this->options['visible'])) {
            foreach ($this->options['visible'] as $key) {
                $array[] = $key;
            }
            $result = array_intersect_key($result, array_flip($array));
        } elseif (!empty($this->options['hidden'])) {
            foreach ($this->options['hidden'] as $key) {
                $array[] = $key;
            }
            $result = array_diff_key($result, array_flip($array));
        }
    }

    /**
     * 使用获取器处理数据
     * @access protected
     * @param  array $result     查询数据
     * @param  array $withAttr   字段获取器
     * @return void
     */
    protected function getResultAttr(array &$result, array $withAttr = []): void
    {
        foreach ($withAttr as $name => $closure) {
            $name = App::parseName($name);

            if (strpos($name, '.')) {
                // 支持JSON字段 获取器定义
                list($key, $field) = explode('.', $name);

                if (isset($result[$key])) {
                    $result[$key][$field] = $closure($result[$key][$field] ?? null, $result[$key]);
                }
            } else {
                $result[$name] = $closure($result[$name] ?? null, $result);
            }
        }
    }

    /**
     * JSON字段数据转换
     * @access protected
     * @param  array $result            查询数据
     * @param  array $json              JSON字段
     * @param  bool  $assoc             是否转换为数组
     * @param  array $withRelationAttr  关联获取器
     * @return void
     */
    protected function jsonResult(array &$result, array $json = [], bool $assoc = false, array $withRelationAttr = []): void
    {
        foreach ($json as $name) {
            if (!isset($result[$name])) {
                continue;
            }

            $result[$name] = json_decode($result[$name], $assoc);

            if (!isset($withRelationAttr[$name])) {
                continue;
            }

            foreach ($withRelationAttr[$name] as $key => $closure) {
                $data = get_object_vars($result[$name]);

                $result[$name]->$key = $closure($result[$name]->$key ?? null, $data);
            }
        }
    }

    /**
     * 查询数据转换为模型对象
     * @access protected
     * @param  array $result            查询数据
     * @param  array $options           查询参数
     * @param  bool  $resultSet         是否为数据集查询
     * @param  array $withRelationAttr  关联字段获取器
     * @return void
     */
    protected function resultToModel(array &$result, array $options = [], bool $resultSet = false, array $withRelationAttr = []): void
    {
        // 动态获取器
        if (!empty($options['with_attr']) && empty($withRelationAttr)) {
            foreach ($options['with_attr'] as $name => $val) {
                if (strpos($name, '.')) {
                    list($relation, $field) = explode('.', $name);

                    $withRelationAttr[$relation][$field] = $val;
                    unset($options['with_attr'][$name]);
                }
            }
        }

        // JSON 数据处理
        if (!empty($options['json'])) {
            $this->jsonResult($result, $options['json'], $options['json_assoc'], $withRelationAttr);
        }

        $result = $this->model->newInstance($result, true, $resultSet ? null : $this->getModelUpdateCondition($options));

        // 动态获取器
        if (!empty($options['with_attr'])) {
            $result->withAttribute($options['with_attr']);
        }

        // 输出属性控制
        if (!empty($options['visible'])) {
            $result->visible($options['visible']);
        } elseif (!empty($options['hidden'])) {
            $result->hidden($options['hidden']);
        }

        if (!empty($options['append'])) {
            $result->append($options['append']);
        }

        // 关联查询
        if (!empty($options['relation'])) {
            $result->relationQuery($options['relation'], $withRelationAttr);
        }

        // 预载入查询
        if (!$resultSet && !empty($options['with'])) {
            $result->eagerlyResult($result, $options['with'], $withRelationAttr);
        }

        // JOIN预载入查询
        if (!$resultSet && !empty($options['with_join'])) {
            $result->eagerlyResult($result, $options['with_join'], $withRelationAttr, true);
        }

        // 关联统计
        if (!empty($options['with_count'])) {
            foreach ($options['with_count'] as $val) {
                $result->relationCount($result, $val[0], $val[1], $val[2]);
            }
        }
    }

    /**
     * 查询失败 抛出异常
     * @access protected
     * @param  array $options 查询参数
     * @return void
     * @throws ModelNotFoundException
     * @throws DataNotFoundException
     */
    protected function throwNotFound(array $options = []): void
    {
        if (!empty($this->model)) {
            $class = get_class($this->model);
            throw new ModelNotFoundException('model data Not Found:' . $class, $class, $options);
        }

        $table = is_array($options['table']) ? key($options['table']) : $options['table'];
        throw new DataNotFoundException('table data not Found:' . $table, $table, $options);
    }

    /**
     * 查找多条记录 如果不存在则抛出异常
     * @access public
     * @param  array|string|Query|Closure $data
     * @return array|PDOStatement|string|Model
     * @throws DbException
     * @throws ModelNotFoundException
     * @throws DataNotFoundException
     */
    public function selectOrFail($data = null)
    {
        return $this->failException(true)->select($data);
    }

    /**
     * 查找单条记录 如果不存在则抛出异常
     * @access public
     * @param  array|string|Query|Closure $data
     * @return array|PDOStatement|string|Model
     * @throws DbException
     * @throws ModelNotFoundException
     * @throws DataNotFoundException
     */
    public function findOrFail($data = null)
    {
        return $this->failException(true)->find($data);
    }

    /**
     * 查找单条记录 如果不存在则返回空
     * @access public
     * @param  array|string|Query|Closure $data
     * @return array|PDOStatement|string|Model
     * @throws DbException
     * @throws ModelNotFoundException
     * @throws DataNotFoundException
     */
    public function findOrEmpty($data = null)
    {
        return $this->allowEmpty(true)->find($data);
    }

    /**
     * 分批数据返回处理
     * @access public
     * @param  integer      $count    每次处理的数据数量
     * @param  callable     $callback 处理回调方法
     * @param  string|array $column   分批处理的字段名
     * @param  string       $order    字段排序
     * @return boolean
     * @throws DbException
     */
    public function chunk(int $count, callable $callback, $column = null, string $order = 'asc'): bool
    {
        $options = $this->getOptions();
        $column  = $column ?: $this->getPk($options);

        if (isset($options['order'])) {
            if (Container::get('app')->isDebug()) {
                throw new DbException('chunk not support call order');
            }
            unset($options['order']);
        }

        $bind = $this->bind;

        if (is_array($column)) {
            $times = 1;
            $query = $this->options($options)->page($times, $count);
        } else {
            $query = $this->options($options)->limit($count);

            if (strpos($column, '.')) {
                list($alias, $key) = explode('.', $column);
            } else {
                $key = $column;
            }
        }

        $resultSet = $query->order($column, $order)->select();

        while (count($resultSet) > 0) {
            if ($resultSet instanceof Collection) {
                $resultSet = $resultSet->all();
            }

            if (false === call_user_func($callback, $resultSet)) {
                return false;
            }

            if (isset($times)) {
                $times++;
                $query = $this->options($options)->page($times, $count);
            } else {
                $end    = end($resultSet);
                $lastId = is_array($end) ? $end[$key] : $end->getData($key);

                $query = $this->options($options)
                    ->limit($count)
                    ->where($column, 'asc' == strtolower($order) ? '>' : '<', $lastId);
            }

            $resultSet = $query->bind($bind)->order($column, $order)->select();
        }

        return true;
    }

    /**
     * 获取绑定的参数 并清空
     * @access public
     * @param  bool $clear
     * @return array
     */
    public function getBind(bool $clear = true): array
    {
        $bind = $this->bind;
        if ($clear) {
            $this->bind = [];
        }

        return $bind;
    }

    /**
     * 创建子查询SQL
     * @access public
     * @param  bool $sub
     * @return string
     * @throws DbException
     */
    public function buildSql(bool $sub = true): string
    {
        return $sub ? '( ' . $this->fetchSql()->select() . ' )' : $this->fetchSql()->select();
    }

    /**
     * 视图查询处理
     * @access protected
     * @param  array   $options    查询参数
     * @return void
     */
    protected function parseView(array &$options): void
    {
        foreach (['AND', 'OR'] as $logic) {
            if (isset($options['where'][$logic])) {
                foreach ($options['where'][$logic] as $key => $val) {
                    if (array_key_exists($key, $options['map'])) {
                        array_shift($val);
                        array_unshift($val, $options['map'][$key]);
                        $options['where'][$logic][$options['map'][$key]] = $val;
                        unset($options['where'][$logic][$key]);
                    }
                }
            }
        }

        if (isset($options['order'])) {
            // 视图查询排序处理
            foreach ($options['order'] as $key => $val) {
                if (is_numeric($key) && is_string($val)) {
                    if (strpos($val, ' ')) {
                        list($field, $sort) = explode(' ', $val);
                        if (array_key_exists($field, $options['map'])) {
                            $options['order'][$options['map'][$field]] = $sort;
                            unset($options['order'][$key]);
                        }
                    } elseif (array_key_exists($val, $options['map'])) {
                        $options['order'][$options['map'][$val]] = 'asc';
                        unset($options['order'][$key]);
                    }
                } elseif (array_key_exists($key, $options['map'])) {
                    $options['order'][$options['map'][$key]] = $val;
                    unset($options['order'][$key]);
                }
            }
        }
    }

    /**
     * 把主键值转换为查询条件 支持复合主键
     * @access public
     * @param  array|string $data    主键数据
     * @return void
     * @throws Exception
     */
    public function parsePkWhere($data): void
    {
        $pk = $this->getPk($this->options);

        if (is_string($pk)) {
            // 获取数据表
            if (empty($this->options['table'])) {
                $this->options['table'] = $this->getTable();
            }

            $table = is_array($this->options['table']) ? key($this->options['table']) : $this->options['table'];

            if (!empty($this->options['alias'][$table])) {
                $alias = $this->options['alias'][$table];
            }

            $key = isset($alias) ? $alias . '.' . $pk : $pk;
            // 根据主键查询
            if (is_array($data)) {
                $where[$pk] = isset($data[$pk]) ? [$key, '=', $data[$pk]] : [$key, 'in', $data];
            } else {
                $where[$pk] = is_string($data) && strpos($data, ',') ? [$key, 'IN', $data] : [$key, '=', $data];
            }
        }

        if (!empty($where)) {
            if (isset($this->options['where']['AND'])) {
                $this->options['where']['AND'] = array_merge($this->options['where']['AND'], $where);
            } else {
                $this->options['where']['AND'] = $where;
            }
        }
    }

    /**
     * 分析表达式（可用于查询或者写入操作）
     * @access public
     * @return array
     */
    public function parseOptions(): array
    {
        $options = $this->getOptions();

        // 获取数据表
        if (empty($options['table'])) {
            $options['table'] = $this->getTable();
        }

        if (!isset($options['where'])) {
            $options['where'] = [];
        } elseif (isset($options['view'])) {
            // 视图查询条件处理
            $this->parseView($options);
        }

        if (!isset($options['field'])) {
            $options['field'] = '*';
        }

        foreach (['data', 'order', 'join', 'union'] as $name) {
            if (!isset($options[$name])) {
                $options[$name] = [];
            }
        }

        if (!isset($options['strict'])) {
            $options['strict'] = $this->connection->getConfig('fields_strict');
        }

        foreach (['master', 'lock', 'fetch_sql', 'distinct'] as $name) {
            if (!isset($options[$name])) {
                $options[$name] = false;
            }
        }

        foreach (['group', 'having', 'limit', 'force', 'comment'] as $name) {
            if (!isset($options[$name])) {
                $options[$name] = '';
            }
        }

        if (isset($options['page'])) {
            // 根据页数计算limit
            list($page, $listRows) = $options['page'];
            $page                  = $page > 0 ? $page : 1;
            $listRows              = $listRows ? $listRows : (is_numeric($options['limit']) ? $options['limit'] : 20);
            $offset                = $listRows * ($page - 1);
            $options['limit']      = $offset . ',' . $listRows;
        }

        $this->options = $options;

        return $options;
    }

    /**
     * 注册回调方法
     * @access public
     * @param  string   $event    事件名
     * @param  callable $callback 回调方法
     * @return void
     */
    public static function event(string $event, callable $callback): void
    {
        self::$event[$event] = $callback;
    }

    /**
     * 触发事件
     * @access public
     * @param  string $event   事件名
     * @return bool
     */
    public function trigger(string $event)
    {
        $result = false;

        if (isset(self::$event[$event])) {
            $result = Container::getInstance()->invoke(self::$event[$event], [$this]);
        }

        return $result;
    }

}
