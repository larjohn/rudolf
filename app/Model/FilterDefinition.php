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
    public $binding;

    public function __construct($cut)
    {
        $values = explode(":", $cut);
        $this->property = $values[0];
        $this->value = $values[1];
    }

}