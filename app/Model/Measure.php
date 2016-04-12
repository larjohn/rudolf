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

    public $currency = 'EUR';

    public $label;

    public $ref;

    public $orig_measure;


}

