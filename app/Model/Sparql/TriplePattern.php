<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 03/03/2016
 * Time: 14:36:38
 */

namespace App\Model\Sparql;


class TriplePattern extends SparqlPattern
{
    public $subject;
    public $object;
    public $predicate;
    public function __construct(string $subject, string $predicate, string $object, bool $isOptional=false)
    {
        parent::__construct($isOptional) ;


        $this->subject = $subject;
        $this->predicate = $predicate;
        $this->object = $object;
    }
}