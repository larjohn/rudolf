<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 16/03/2016
 * Time: 13:40:16
 */

namespace App\Model;


class Dimension extends GenericProperty
{

    /**
     * @var string
     */
    public $key_attribute;

    /**
     * @var string
     */
    public $hierarchy;




    /**
     * @var string
     */
    public $label;

    /**
     * @var string
     */
    public $ref;

    /**
     * @var string
     */
    public $orig_dimension;

    /**
     * @var Attribute[]
     */
    public $attributes;

    /**
     * @var string
     */
    public $cardinality_class;

    /**
     * @var string
     */
    public $key_ref;

    /**
     * @var string
     */
    public $label_attribute;

    /**
     * @var string
     */
    public $label_ref;

    public static function getRefFromURI(string $uri){
        return preg_replace("/^.*(#|\/)/", "", $uri);
    }


    protected $dataSet;

    /**
     * @return mixed
     */
    public function getDataSet()
    {
        return $this->dataSet;
    }

    /**
     * @param mixed $dataSet
     */
    public function setDataSet($dataSet)
    {
        $this->dataSet = $dataSet;
    }

    /**
     * @var boolean
     */
    protected $generated = false;

    /**
     * @return bool
     */
    public function isGenerated(): bool
    {
        return $this->generated;
    }

    /**
     * @param bool $generated
     */
    public function setGenerated(bool $generated)
    {
        $this->generated = $generated;
    }

    public function addAttribute(Attribute $attribute){

    }

    public function getBinding()
    {
        // TODO: Implement getBinding() method.
    }
}