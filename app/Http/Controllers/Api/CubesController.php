<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 16/03/2016
 * Time: 01:37:15
 */

namespace App\Http\Controllers\Api;


use App\Http\Controllers\Controller;
use Asparagus\QueryBuilder;
use EasyRdf_Sparql_Result;

class CubesController extends Controller
{
    public function index(){
        $queryBuilder = new QueryBuilder( config("sparql.prefixes"));
        $queryBuilder->select('?name')
            ->where('?dataset', 'a', 'qb:DataSet')
            ->bind("CONCAT(REPLACE(str(?dataset), '^.*(#|/)', \"\"), '__', MD5(STR(?dataset))) AS ?name");

            ;

        //   echo $queryBuilder->format();
        /** @var EasyRdf_Sparql_Result $result */
        $result = $this->sparql->query(
            $queryBuilder->getSPARQL()
        );

        $results = $this->rdfResultsToArray($result);
        return ["data"=> $results, "status"=>"ok"];
    }

}