<?php

defined('SYSPATH') or die('No direct script access.');

use MongoDB\BSON\ObjectID;
use MongoDB\BSON\UTCDateTime;

class Kohana_ODM extends MongoDB\Collection
{
    protected $_id, $_attributes = [], $_relations = [];
    protected static $casts = [], $_db;
    public static $relations = [], $globalScopes = [];
    public static $connect = null;
    public static $main_model_instance;


    public static function factory($name = '', $attributes = [])
    {
      $model_name = 'Model_'.$name;
      static::$main_model_instance = $model_name;

      $model_instance = new $model_name(static::set_manager(), static::getDbName(), static::getSource());
      static::$connect = $model_instance;

      return static::$connect;
    }

    protected static function set_manager()
    {
        $config = Kohana::$config->load('kongodb')->as_array();

        return new \MongoDB\Driver\Manager($config['default']['server']);
    }

    public static function init($attributes = [])
    {
        if (count($attributes) > 0) {
            static::$connect->fill($attributes);
        }
        return static::$connect;
    }

    public static function create(array $attributes = [])
    {
        return static::$connect->save();
    }

    protected static function getNextSequence($name = '')
    {
      $ret = static::findAndModify(['_id' => $name], ['$inc' => ['seq' => 1]], ['new' => true, 'upsert' => true]);
      if(!isset($ret->seq))
      {
        return 1;
      }
      return ++$ret->seq;
    }

    public function save(array $attributes = null, $ai = false)
    {
        if ($attributes != null) {
            $this->fill($attributes);
        }
        $this->event('beforeSave');
        if (isset($this->_id)) {
            $this->event('beforeUpdate');
            $this->updateOne(['_id' => $this->_id], ['$set' => $this->_attributes]);
            $this->event('afterUpdate');
        } else {
            if(Kohana::$config->load('kongodb')->as_array()['default']['ai']) :
              $ai = static::getNextSequence(static::getSource());
              $attributes['meta_id'] = $ai;
              static::init($attributes);
            endif;
            $this->event('beforeCreate');
            $insertResult = $this->insertOne($this->_attributes);
            $this->_id = $insertResult->getInsertedId();
            $this->event('afterCreate');
        }
        $this->event('afterSave');

        return $this;
    }

    public static function findById($id)
    {
        $result = static::init()->findOne(['_id' => new ObjectID($id)]);
        return $result ? static::init((array) $result) : null;
    }

    public static function findByAI($id)
    {
        $result = static::init()->findOne([static::getSource().'_id' => (int)$id]);
        return $result ? static::init((array) $result) : null;
    }

    public static function findAndModify($filter = [], $update = [], $options = [])
    {
      return static::init()->findOneAndUpdate($filter, $update, $options);
    }

    public static function findFirst(array $params = [])
    {
        return static::init($params)->fill(static::init()->findOneAndUpdate($params));
    }

    public static function destroy($id)
    {
        return static::init()->deleteOne(['_id' => new ObjectID($id)]);
    }

    public static function getSource()
    {
        if(!isset(static::$main_model_instance))
        {
          throw new Exception("Main model is not instance", 1);
        }

        $main = static::$main_model_instance;
        return $main::getSource();
    }

    public static function getDbName()
    {
        if (!isset(self::$_db)) {
            self::$_db = Kohana::$config->load('kongodb')->as_array()['default']['database'];
        }

        return self::$_db;
    }

    public function find($filter = [], array $options = [], $fillModels = true)
    {
        return $this->getQueryResult(parent::find($filter, $options), $fillModels);
    }

    public function aggregate(array $pipeline, array $options = [], $fillModels = true)
    {
        return $this->getQueryResult(parent::aggregate($pipeline, $options), $fillModels);
    }

    protected function getQueryResult($result, $fillModels = true)
    {
        if ($fillModels) {
            $collections = [];
            foreach ($result as $row) {
                $collections[] = static::init($row);
            }

            return new Kohana_ODM_Collection($collections);
        } else {
            return $result->toArray();
        }
    }

    public function getId($asString = true)
    {
        return $asString ? (string) $this->_id : $this->_id;
    }

    public function update(array $attributes)
    {
        $this->event('beforeSave');
        $this->event('beforeUpdate');
        $this->fill($attributes);
        $this->updateOne(['_id' => $this->_id], ['$set' => $attributes]);
        $this->event('afterUpdate');
        $this->event('afterSave');

        return $this;
    }

    public function increment($argument, $value = 1)
    {
        $this->{$argument} += $value;
        $this->updateOne(['_id' => $this->_id], ['$set' => [$argument => $this->{$argument}]]);

        return $this;
    }
    public function decrement($argument, $value = 1)
    {
        $this->{$argument} -= $value;
        $this->updateOne(['_id' => $this->_id], ['$set' => [$argument => $this->{$argument}]]);

        return $this;
    }
    public function delete()
    {
        $this->event('beforeDelete');
        $this->deleteOne(['_id' => $this->getId(false)]);
        $this->event('afterDelete');

        return $this;
    }
    public function unsetField($field)
    {
        $path = explode('.', $field);
        $lastPart = end($path);
        if (count($path) > 1) {
            $ref = $this->getAttrRef($field, 1);
        } else {
            $ref = &$this->_attributes;
        }
        if ($ref != false) {
            $type = gettype($ref);
            if ($type == 'object' && isset($ref->{$lastPart})) {
                unset($ref->{$lastPart});
            } elseif ($type == 'array' && isset($ref[$lastPart])) {
                unset($ref[$lastPart]);
            } else {
                return false;
            }
            $this->updateOne(['_id' => $this->_id], ['$unset' => [$field => '']]);

            return true;
        }

        return false;
    }
    public function beforeCreate()
    {
        $this->created_at = self::mongoTime();
    }
    public function beforeUpdate()
    {
        $this->updated_at = self::mongoTime();
    }

    public static function mongoTime()
    {
        return new UTCDateTime(round(microtime(true) * 1000).'');
    }

    protected function event($name)
    {
        if (method_exists($this, $name)) {
            $this->{$name}();
        }
    }

    public function fill($data)
    {
        $data = (array) $data;
        if (isset($data['_id'])) {
            $this->_id = $data['_id'];
            unset($data['_id']);
        }
        foreach (static::$relations as $name => $settings) {
            if (isset($data[$name])) {
                if ($settings[1] == 'one') {
                    $value = $settings[0]::init($data[$name][0]);
                    $this->setRelation($name, $value);
                } else {
                    $value = [];
                    foreach ($data[$name] as $row) {
                        $value[] = $settings[0]::init($row);
                    }
                    $this->setRelation($name, new Collection($value));
                }
                unset($data[$name]);
            }
        }
        $this->_attributes = array_merge($this->_attributes, $this->castArrayAttributes($data));

        return $this;
    }

    protected function castArrayAttributes(array $data)
    {
        foreach ($data as $param => $value) {
            $methodName = 'set'.Inflector::camelize($param);
            $data[$param] = method_exists($this, $methodName) ? $this->{$methodName}($value) : $this->castAttribute($param, $value);
        }

        return $data;
    }

    public function castAttribute($param, $value)
    {
        if (isset(static::$casts[$param])) {
            $type = static::$casts[$param];
            if ($type == 'id') {
                if (!($value instanceof ObjectID)) {
                    try {
                        return new ObjectID((string) $value);
                    } catch (\Exception $e) {
                        return;
                    }
                }

                return $value;
            } elseif (in_array($type, ['integer', 'float', 'boolean', 'string', 'array', 'object'])) {
                settype($value, $type);
            }
        }

        return $value;
    }

    public function setRelation($name, $value)
    {
        $this->_relations[$name] = $value;
    }
    protected function hasOne($model, $field, $localKey = null, $foreignKey = '_id')
    {
        if ($localKey == null) {
            $localKey = $this->getIdFieldName($model);
        }
        $result = $model::init()->findFirst([$foreignKey => ($localKey == '_id' ? $this->getId(false) : $this->{$localKey})]);
        $this->setRelation($field, $result);

        return $result;
    }
    protected function hasMany($model, $field, $localKey = '_id', $foreignKey = null)
    {
        if ($foreignKey == null) {
            $foreignKey = $this->getIdFieldName($this);
        }
        $result = $model::init()->find([$foreignKey => ($localKey == '_id' ? $this->getId(false) : $this->{$localKey})]);
        $this->setRelation($field, $result);

        return $result;
    }
    protected function loadRelation($name)
    {
        $settings = static::$relations[$name];
        if ($settings[1] == 'one') {
            return $this->hasOne($settings[0], $name, $settings[2], $settings[3]);
        } else {
            return $this->hasMany($settings[0], $name, $settings[2], $settings[3]);
        }
    }
    protected function getIdFieldName($model)
    {
        $className = strtolower((new \ReflectionClass($model))->getShortName());
        if (Inflector::endsWith($className, 's')) {
            $className = substr($className, 0, -1);
        }

        return $className.'_id';
    }

    public function toArray($params = [])
    {
        $attributes = array_merge(['id' => (string) $this->_id], $this->_attributes);
        if (isset($params['include']) || isset($params['exclude'])) {
            $attributes = array_filter($attributes, function ($value, $key) use ($params) {
              if (isset($params['include'])) {
                  return in_array($key, $params['include']);
              }

              return !in_array($key, $params['exclude']);
          }, ARRAY_FILTER_USE_BOTH);
        }
        $attributes = array_map(function ($item) {
          if (gettype($item) == 'object') {
              if ($item instanceof ObjectID) {
                  return (string) $item;
              } elseif ($item instanceof UTCDateTime) {
                  return $item->toDateTime()->format(DATE_ISO8601);
              } else {
                  return (array) $item;
              }
          }

          return $item;
      }, $attributes);
        $relations = array_map(function ($item) {
          if (gettype($item) == 'object') {
              return $item->toArray();
          } elseif (gettype($item) == 'array') {
              return array_map(function ($item1) {
                  return $item1->toArray();
              }, $item);
          }

          return $item;
      }, $this->_relations);
        $result = array_merge($attributes, $relations);

        return $result;
    }

    protected function getAttrRef($path, $rightOffset = 0)
    {
        $path = explode('.', $path);
        $length = count($path) - $rightOffset;
        $return = &$this->_attributes;
        for ($i = 0; $i <= $length - 1; ++$i) {
            if (isset($return->{$path[$i]})) {
                if ($i == $length - 1) {
                    return $return->{$path[$i]};
                } else {
                    $return = &$return->{$path[$i]};
                }
            } elseif (isset($return[$path[$i]])) {
                if ($i == $length - 1) {
                    return $return[$path[$i]];
                } else {
                    $return = &$return[$path[$i]];
                }
            } else {
                return false;
            }
        }

        return $return;
    }
    public static function query()
    {
        return new ODM_Builder(static::class);
    }
    public static function __callStatic($name, $arguments)
    {
        if (method_exists(static::class, 'scope'.ucfirst($name))) {
            array_unshift($arguments, static::query());

            return call_user_func_array([static::init(), 'scope'.ucfirst($name)], $arguments);
        }

        return call_user_func_array([static::query(), $name], $arguments);
    }
    public function __get($name)
    {
        $methodName = 'get'.Inflector::camelize($name);

        return isset($this->_attributes[$name]) ? (method_exists($this, $methodName) ? $this->{$methodName}($this->_attributes[$name]) : $this->_attributes[$name])
            : (isset($this->_relations[$name]) ? $this->_relations[$name]
                : (isset(static::$relations[$name]) ? $this->loadRelation($name) : null));
    }
    public function __set($name, $value)
    {
        $methodName = 'set'.Inflector::camelize($name);
        $this->_attributes[$name] = method_exists($this, $methodName) ? $this->{$methodName}($value) : $this->castAttribute($name, $value);
    }
    public function __toString()
    {
        return json_encode($this->toArray());
    }
}
