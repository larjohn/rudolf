<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 16/03/2016
 * Time: 04:18:25
 */

namespace App\Http\Controllers\Api;


use App\Http\Controllers\Controller;

class FactsController extends Controller
{
    public function index($name){

        $dsd = "http://data.openbudgets.eu/ontology/dsd/budget-athens-expenditure-2013";
        $dataset = "http://data.openbudgets.eu/resource/dataset/budget-athens-expenditure-2013";

$model = $this->getDimensions2($alias);
$fields = explode(',', request('fields'));
$facts = $this->getObservations2($dataset,$dsd, $fields, $model );
return $facts;

        $selectedPatterns = $this->modelFieldsToPatterns($model,$fields);
        $datasetStructureDefinitionUri = $dsd;
        $pageSize = intval(request("pagesize", 100));
        $page = intval(request("page", 0));
        $offset = $pageSize * $page ;
        $dimensions = $this->getDimensions($datasetStructureDefinitionUri);

        $attributes = [];
        $bindings = [];
        $patterns = [];

        $selectedDimensions= [];

        foreach ($dimensions as $dimension) {
            if(!isset($selectedPatterns[$dimension["attribute"]])) continue;
            $selectedDimensions[] = $dimension;
            $bindingName = "binding_" . md5($dimension["attribute"]);
            $valueAttributeLabel = "uri";
            if($dimension["propertyType"]=="qb:MeasureProperty"){
                $valueAttributeLabel = "value";
            }
            $attributes[$dimension["attribute"]][$valueAttributeLabel] = $bindingName;
            $bindings[$dimension["attribute"]] = "?$bindingName";
        }



        $sliceSubGraph = new SubPattern([
            new TriplePattern("?slice", "a", "qb:Slice"),
            new TriplePattern("?slice", "qb:observation", "?observation"),

        ], true);



        /*        $queryBuilder->newSubgraph()
                    ->where("?slice", "a", "qb:Slice")
                    ->where("?slice", "qb:observation", "?observation");*/

        $needsSliceSubGraph = false;

        foreach ($selectedDimensions as $dimension) {
            $attribute = $dimension["attribute"];

            if(isset($dimension["attachment"]) && $dimension["attachment"]=="qb:Slice"){
                $needsSliceSubGraph = true;
                $sliceSubGraph->add(new TriplePattern("?slice", "<$attribute>", $bindings[$attribute] , true));
            }
            else{
                $patterns [] = new TriplePattern("?observation", "<$attribute>", $bindings[$attribute], true);
            }

            if($dimension["propertyType"]=="qb:CodedProperty"){
                $dimensionPatterns = &$selectedPatterns[$attribute];
                foreach ($dimensionPatterns as $patternName=>$dimensionPattern){
                    $attributes[$attribute][$patternName] = $attributes[$attribute]["uri"]."_".md5($patternName) ;
                    $bindings[] = $bindings[$attribute]."_".md5($patternName) ;
                    // dd( $attributes[$attribute]["patternName"]);
                    if(isset($dimension["attachment"]) && $dimension["attachment"]=="qb:Slice"){
                        $sliceSubGraph->add(new TriplePattern($bindings[$attribute],$patternName,$bindings[$attribute]."_".md5($patternName), true));
                    }
                    else{
                        $patterns [] = new TriplePattern($bindings[$attribute],$patternName,$bindings[$attribute]."_".md5($patternName), true);


                    }


                }


            }
        }

        // echo($queryBuilder->format());
        $bindings[] = "?observation";

        if($needsSliceSubGraph){
            $patterns[] = $sliceSubGraph;

        }

        $patterns[] = new TriplePattern('?observation', 'a', 'qb:Observation');
        $patterns[] = new TriplePattern('?observation', 'qb:dataSet', "<$dataset>");


        $queryBuilderC = $this->build(["(count(?observation) as ?observation)"], $patterns );
        /** @var EasyRdf_Sparql_Result $countResult */
        $countResult = $this->sparql->query(
            $queryBuilderC->getSPARQL()
        );
        $count = $countResult[0]->observation->getValue();
        $queryBuilder = $this->build($bindings, $patterns );



        $queryBuilder

            ->limit($pageSize )
            ->offset($offset)
            ->orderBy("?observation");


        //dd($queryBuilder->getSPARQL());
        // die;
        /** @var EasyRdf_Sparql_Result $result */
        $result = $this->sparql->query(
            $queryBuilder->getSPARQL()
        );
        //echo($result->dump());

        //dd($attributes);

        $results = $this->rdfResultsToArray3($result,$attributes, $model, $selectedPatterns);


        return [
            "data"=>$results,
            "total_fact_count" => $count,
            "page_size" => $pageSize,
            "cell" => [],
            "page" => $page,
            "fields" => $fields,
            "status" => "ok",
            "order" => []
        ];
    }
}