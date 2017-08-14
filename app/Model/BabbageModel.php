<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 16/03/2016
 * Time: 13:31:53
 */

namespace App\Model;


class BabbageModel
{
    /**
     * @var Aggregate[]
     */
    public $aggregates = [];

    /**
     * @var Dimension[]
     */
    public $dimensions = [];

    /**
     * @var Measure[]
     */
    public $measures = [];

    /**
     * @var Hierarchy[]
     */
    public $hierarchies = [];

    /**
     * @var string
     */
    public $fact_table;
    /**
     * @var string
     */

    private $distributionURL;

    private $dataset;

    /**
     * @var string
     */
    private $dsd;

    /**
     * @param string $dataset
     */
    public function setDataset($dataset)
    {
        $this->dataset = $dataset;
    }

    /**
     * @param string $dsd
     */
    public function setDsd($dsd)
    {
        $this->dsd = $dsd;
    }

    /**
     * @return string
     */
    public function getDataset()
    {
        return $this->dataset;
    }

    /**
     * @return string
     */
    public function getDsd()
    {
        return $this->dsd;
    }
    public function __get($name)
    {
        $fn_name = 'get_' . ucfirst($name);
        if (method_exists($this, $fn_name))
        {
            return $this->$fn_name();
        }
        else
        {
            return null;
        }
    }

    public function __set($name, $value)
    {
        $fn_name = 'set_' . ucfirst($name);
        if (method_exists($this, $fn_name))
        {
            $this->$fn_name($value);
        }
    }

    private $currency;
    private $fiscalYear;

    private $title;
    private $countryCode;

    /**
     * @return mixed
     */
    public function getTitle()
    {
        return $this->title;
    }

    /**
     * @param mixed $title
     */
    public function setTitle($title)
    {
        $this->title = $title;
    }

    /**
     * @return mixed
     */
    public function getCurrency()
    {
        return $this->currency;
    }

    /**
     * @param mixed $currency
     */
    public function setCurrency($currency)
    {
        $this->currency = $currency;
    }

    /**
     * @return mixed
     */
    public function getFiscalYear()
    {
        return $this->fiscalYear;
    }

    /**
     * @param mixed $fiscalYear
     */
    public function setFiscalYear($fiscalYear)
    {
        $this->fiscalYear = $fiscalYear;
    }
    /**
     * @var string[]
     */
    private $titles;

    /**
     * @return \string[]
     */
    public function getTitles(): array
    {
        return $this->titles;
    }

    /**
     * @param \string[] $titles
     */
    public function setTitles(array $titles)
    {
        $this->titles = $titles;
    }

    /**
     * @return mixed
     */
    public function getCountryCode()
    {
        return $this->countryCode;
    }

    /**
     * @param mixed $countryCode
     */
    public function setCountryCode($countryCode)
    {
        $this->countryCode = $countryCode;
    }

    /**
     * @return string
     */
    public function getDistributionURL()
    {
        return $this->distributionURL;
    }

    /**
     * @param string $distributionURL
     */
    public function setDistributionURL($distributionURL)
    {
        $this->distributionURL = $distributionURL;
    }

}