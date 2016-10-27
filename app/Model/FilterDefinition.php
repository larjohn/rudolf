<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 22/03/2016
 * Time: 14:04:19
 */

namespace App\Model;


class FilterDefinition
{

    public $property;
    public $value;
    public $values;
    public $binding;
    public $transitivity;
    public $isCardinal = false;

    public function __construct($cut)
    {
        $values = explode(":", $cut,2);
        $re = "/(\w*\.?\w*)(\*|\?|\^|\+|\{\d*,?\d*\})?/";
        preg_match($re, $values[0], $matches);
        $this->property = $matches[1];

        if(isset($matches[2])){
            $this->transitivity = $matches[2];
        }

        $this->value = $values[1];
        $this->values[] = $values[1];


    }

    public function addValue($cut){
        $values = explode(":", $cut,2);
        $this->values[] = $values[1];
        $this->isCardinal = true;

    }

}