<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 16/03/2016
 * Time: 13:40:29
 */

namespace App\Model;


class Measure extends GenericProperty
{
    public $column;

    public $currency;

    public $label;

    public $ref;

    public $orig_measure;
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


}

