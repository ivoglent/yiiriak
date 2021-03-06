<?php
/**
 * Riak Base Model
 * Created by Long Nguyen.
 * Contact: ivoglent@gmail.com
 * @package ivoglent\phpriak
 * @category  none
 * @author  longnguyen
 * @license none
 * @link none
 * @date: 08/12/2016
 * @time: 09:47
 * @version : 
 */

namespace ivoglent\phpriak\base;


use Basho\Riak;
use ivoglent\phpriak\interfaces\RiakModelInterface;

abstract class RiakModel implements RiakModelInterface
{
    /** @var  Riak\Bucket */
    protected $bucket;

    /** @var  string */
    protected $key = '';

    /** @var  Riak $riak */
    protected $riak;

    /** @var  Riak\Location */
    protected $location;

    /**
     * @var array
     * Config array about riak server
     * Supported multi server like nodes in cluster
     */
    private $config = [

    ];

    /**
     * @var array
     * Store model attribute values
     */
    private $_data = [];

    /**
     * @var bool
     * Mark this model is new
     */
    public $isNew = TRUE;


    /**
     * RiakModel constructor.
     * @param array $config
     *
     */
    public function __construct($config = []) {
        if (!empty($config)) {
            $this->config = array_merge($this->config, $config);
        }
        $this->riak = $this->getRiakInstance();
        $this->bucket = new Riak\Bucket($this->getBucketName());
    }

    /**
     * __set
     * @param $name
     * @param $value
     * Magic function to support
     * anynomous attribute
     */
    public function __set($name, $value) {
        if (in_array($name, $this->attributes())) {
            $this->_data[$name] = $value;
        }
    }

    /**
     * __get
     * @param $name
     * @return bool|mixed
     */
    public function __get($name) {
        if (array_key_exists($name, $this->_data)) {
            return $this->_data[$name];
        }
        return FALSE;
    }

    /**
     * __toString
     * @return string
     * Convert model data to printable string
     */
    public function __toString() {
        $data = $this->getData();
        return json_encode($data);
    }

    /**
     * __call
     * @param $name
     * @param $arguments
     * @return $this|bool|mixed
     * Setter and getter supported
     */
    public function __call($name, $arguments) {
        if (method_exists($this,$name)) {
            return call_user_func([$this, $name], $arguments);
        }
        if (substr($name, 0, 3) == 'set') {
            $attr = strtolower(substr($name, 3));
            $this->$attr = $arguments;
            return $this;
        }
        if (substr($name, 0, 3) == 'get') {
            $attr = strtolower(substr($name, 3));
            return $this->$attr;
        }
    }

    /**
     * Get riak instance
     * @return \Basho\Riak|mixed
     */
    private function getRiakInstance(){
        /**
         * If it empty, just create new one
         */
        if (empty($this->riak)) {
            $nodes = [];
            $nodeConfig = RiakSetup::wakeUp()->getNodes();
            if (empty($nodeConfig)) {
                throw new RiakModelException("Please config one node as least to run riak");
            }
            foreach ($nodeConfig as $config) {
                $node = (new Riak\Node\Builder())
                    ->atHost($config->getHost())
                    ->onPort($config->getPort())
                    ->build();
                $nodes[] = $node;
            }
            $this->riak = new Riak($nodes);
        }
        return $this->riak;
    }

    /**
     * getData
     * @return array
     * Get data of this model
     */
    public function getData(){
        $attributes = $this->attributes();
        $data = [];
        foreach ($attributes as $attribute) {
            $data[$attribute] = $this->$attribute;
        }
        return $data;
    }

    /**
     * setData
     * @param array $data
     * @return $this
     * Set data to this model with attributes mapped
     */
    public function setData($data = []) {
        foreach ($data as $key => $value) {
            $this->$key = $value;
        }
        return $this;
    }

    /**
     * toString
     * @return string
     */
    public function toString(){
        return $this->__toString();
    }

    /**
     * save
     * @return bool|string
     * Save model attribute value to riak
     * with built location and bucket
     */
     public function save() {
        $data = (object) $this->getData();
        $request =  (new \Basho\Riak\Command\Builder\StoreObject($this->riak));
        if ($this->isNew) {
            $request->inBucket($this->bucket);
        } else {
            $request->atLocation($this->location);
        };

        $request = $request->buildJsonObject($data)->build();
        $response = $request->execute();
        if ($response->getCode() >= 200 && $response->getCode() < 300) {
            $this->isNew = FALSE;
            if (empty($this->location)) {
                $this->location = $response->getLocation();
            }
            return $this->key = $this->location->getKey();
        }
        return FALSE;
    }

    /**
     * fetchData
     * @param \Basho\Riak\Command\Object\Response $response
     * @param bool $one
     * @return $this
     */
    public function fetchData(Riak\Command\Object\Response $response, $one = TRUE){
        if (empty($this->location)) {
            $this->location = $response->getLocation();
        }
        $this->key = $this->location->getKey();
        $data = $response->getObject()->getData();
        $this->setData($data);
        return $this;
    }


    /**
     * findOne
     * @param $condition
     * @return RiakModelInterface
     */
    public static function findOne($condition) {
        /*$self = new static();
        $response = (new \Basho\Riak\Command\Builder\FetchObject())
            ->buildLocation('rufus', 'users')
            ->build()
            ->execute();*/
    }

    /**
     * load
     * @param $key
     * @return $this|null
     * @throws \ivoglent\phpriak\base\RiakModelException
     * Load model from riak db by given key
     */
    public function load($key) {
        if (empty($key)) {
            throw new RiakModelException("Invalid key");
        }
        $this->location = new Riak\Location($key, $this->bucket);
        $response = (new \Basho\Riak\Command\Builder\FetchObject($this->riak))
            ->atLocation($this->location)
            ->build()
            ->execute();
        if ($response->isNotFound()) {
            return NULL;
        }
        $this->isNew = FALSE;
        $this->fetchData($response);
        return $this;
    }


    /**
     * delete
     * @return bool
     * @throws \ivoglent\phpriak\base\RiakModelException
     */
    public function delete() {
        if ($this->isNew || empty($this->key)) {
            throw new RiakModelException("Can not delete empty object");
        }
        $response =(new \Basho\Riak\Command\Builder\DeleteObject($this->riak))
            ->atLocation($this->location)
            ->build()
            ->execute();
        return $response->isSuccess();
    }

    /**
     * update
     * @param bool $runValidation
     * @param null $attributeNames
     * @return false|int
     * @throws \Exception
     */
    public function update($runValidation = TRUE, $attributeNames = NULL) {

    }

    /**
     * insert
     * @param bool $runValidation
     * @param null $attributes
     * @return bool
     * @throws \Exception
     */
    public function insert($runValidation = TRUE, $attributes = NULL) {

    }

    /**
     * getKey
     * @return string
     * Get object key of this model
     */
    public function getKey(){
        return $this->key;
    }

    /**
     * getAttribute
     * @param $name
     * @return mixed
     */
    public function getAttribute($name) {
        $attributes = $this->attributes();
        if (isset($attributes[$name])) {
            return $attributes[$name];
        }
    }

    /**
     * attributes
     * @return array
     */
    public function attributes() {
        return [];
    }
}