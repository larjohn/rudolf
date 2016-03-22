<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 22/03/2016
 * Time: 00:43:40
 */

namespace App\Model;


class Sorter
{
    public $property;
    public $direction;
    public $binding;

    public function __construct($order)
    {
        $elements = explode(":", $order);

        if(count($elements)==2){
            $this->property = $elements[0];
            $this->direction = $elements[1];
        }
    }

}