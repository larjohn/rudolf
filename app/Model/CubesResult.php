<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 31/03/2016
 * Time: 11:23:06
 */

namespace App\Model;


use Asparagus\QueryBuilder;
use EasyRdf_Sparql_Result;

class CubesResult extends SparqlModel
{
    public function __construct()
    {
        parent::__construct();
        $this->load();
    }

    public $data;
    public $status;

    private function load()
    {
        $queryBuilder = new QueryBuilder( config("sparql.prefixes"));
        $queryBuilder->select('(SAMPLE(?name) AS ?name)')
            ->where('?dataset', 'a', 'qb:DataSet')
            ->bind("CONCAT(REPLACE(str(?dataset), '^.*(#|/)', \"\"), '__', SUBSTR(MD5(STR(?dataset)),1,5)) AS ?name")
            ->groupBy("?dataset")
        ;

        ;

        //   echo $queryBuilder->format();
        /** @var EasyRdf_Sparql_Result $result */
        $result = $this->sparql->query(
            $queryBuilder->getSPARQL()
        );

        $results = $this->rdfResultsToArray($result);
        $results[] = ["name"=>"global"];
        $this->data = $results;
        $this->status = "ok";
    }

}