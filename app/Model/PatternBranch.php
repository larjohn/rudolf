<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 11/07/2017
 * Time: 12:53:45
 */

namespace App\Model;


class PatternBranch
{

    /**
     * @var GenericProperty
     */
    public $entity;

    /**
     * @var PatternBranch
     */
    public $previous;
    /**
     * @var PatternBranch
     */
    public $next;

    /**
     * PatternChainLink constructor.
     * @param $entity
     */
    public function __construct($entity)
    {
        $this->entity = $entity;
    }
}