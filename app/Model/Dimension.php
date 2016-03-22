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


}