<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 16/03/2016
 * Time: 13:44:31
 */

namespace App\Model;


class Attribute extends GenericProperty
{

    /**
     * @var string
     */
    public $column;

    /**
     * @var string
     */
    public $label;

    /**
     * @var string
     */
    public $orig_attribute;

    /**
     * @var string
     */
    public $ref;

    /**
     * @var string
     */
    public $datatype;

    /**
     * @var boolean
     */
    private $virtual = false;

    protected $languages = [];

    /**
     * @return mixed
     */
    public function getVirtual()
    {
        return $this->virtual;
    }

    /**
     * @param mixed $virtual
     */
    public function setVirtual($virtual)
    {
        $this->virtual = $virtual;
    }

    /**
     * @return array
     */
    public function getLanguages(): array
    {
        return $this->languages;
    }

    /**
     * @param array $languages
     */
    public function setLanguages(array $languages)
    {
        $this->languages = $languages;
    }

}