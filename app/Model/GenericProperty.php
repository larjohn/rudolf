<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 21/03/2016
 * Time: 02:15:05
 */

namespace App\Model;


class GenericProperty
{
    protected $attachment;

    /**
     * @param mixed $attachment
     */
    public function setAttachment($attachment)
    {
        $this->attachment = $attachment;
    }

    /**
     * @return mixed
     */
    public function getAttachment()
    {
        return $this->attachment;
    }
    protected $uri;

    /**
     * @return mixed
     */
    public function getUri()
    {
        return $this->uri;
    }

    /**
     * @param mixed $uri
     */
    public function setUri($uri)
    {
        $this->uri = $uri;
    }

}