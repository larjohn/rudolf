<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 07/04/2016
 * Time: 11:12:43
 */

namespace App\Model;


class PackageResult extends SparqlModel
{


    public $__origin_url;

    public $author;

    public $countryCode = "GR";

    public $model;

    public function __construct()
    {
        parent::__construct();

        $this->model = [
            /*"dimensions" => [
                "economicClassification"=>[
                    "classificationType"=>"administrative",
                    "dimensionType"=>"classification",
                    "primaryKey" => ["prefLabel"],
                    "attribute" => [
                        "prefLabel"=>[
                            "resource"=> "thessaloniki",
                            "source" => "prefLabel"

                        ]
                    ]
                ]
            ],*/
            "measures" => [
                "amount"=>[
                    "currency"=>"EUR",
                    "resource"=>"model",
                    "source"=>"amount"
                ]
            ]
        ];
        
        
        

    }


}