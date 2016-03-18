<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 16/03/2016
 * Time: 13:33:02
 */

namespace App\Model;


use Asparagus\QueryBuilder;
use Cache;
use EasyRdf_Sparql_Result;

class BabbageModelResult extends SparqlModel
{
    /**
     * @var string
     */
    public $name;
    /**
     * @var string
     */
    public $status;

    /**
     * @var BabbageModel
     */
    public $model;


    public function __construct(string $name)
    {
        parent::__construct();
        $this->load($name);

        $this->name = $name;
        $this->status = "ok";
    }


    public function load($name){
        if(Cache::has($name)){
            $this->model = Cache::get($name);
            return;
        }

        $queryBuilder = new QueryBuilder(config("sparql.prefixes"));
        $queryBuilder
            ->selectDistinct('?attribute', '?label', '(max(?attachment) as ?attachment)', "?propertyType", "?shortName", "(count(distinct ?value) AS ?cardinality)")
            ->where("?dsd", 'qb:component', '?component')
            ->where("?dataset", "a", "qb:DataSet")
            ->where("?dataset","qb:structure", "?dsd" )
            ->where('?component', 'qb:componentProperty', '?attribute')
            ->optional('?attribute', 'rdfs:label', '?label')
            ->bind("REPLACE(str(?attribute), '^.*(#|/)', \"\") AS ?shortName")
            ->optional('?component', 'qb:componentAttachment', '?attachment')
            ->bind("CONCAT(REPLACE(str(?dataset), '^.*(#|/)', \"\"), '__', MD5(STR(?dataset))) AS ?name")
            ->filter("?name = '$name'")
            ->optional($queryBuilder->newSubgraph()
                ->where("?slice", "qb:observation", "?observation")
                ->where("?slice",  "?attribute", "?value"))
            ->optional("?observation", "?attribute" ,"?value")
            ->optional($queryBuilder->newSubgraph()
                ->where("?attribute", "a", "?propertyType")->filter("?propertyType in (qb:CodedProperty, qb:MeasureProperty)"))
            ->filterNotExists('?component', 'qb:componentAttachment', 'qb:DataSet')
            ->groupBy('?attribute', '?label', "?propertyType", "?shortName");
        ;

//        dd($queryBuilder->getSPARQL());
        /** @var EasyRdf_Sparql_Result $propertiesSparqlResult */
        $propertiesSparqlResult = $this->sparql->query(
            $queryBuilder->getSPARQL()
        );
        /** @var EasyRdf_Sparql_Result $result */

        $propertiesSparqlResult = $this->rdfResultsToArray($propertiesSparqlResult);
/*        dd($propertiesSparqlResult);*/
        //dd($propertiesSparqlResult);

        foreach ($propertiesSparqlResult as $property) {
            $newMeasure = new Measure();
            $attribute = $property["attribute"];
            $queryBuilder = new QueryBuilder(config("sparql.prefixes"));
            $queryBuilder->where('?observation', 'a', 'qb:Observation');

            if(isset($property["attachment"]) &&  $property["attachment"]=="qb:Slice"){
                $queryBuilder
                    ->where("?slice", "qb:observation", "?observation")
                    ->where("?slice",  "<$attribute>", "?value");
            }
            else{
                $queryBuilder->where("?observation", "<$attribute>" ,"?value");
            }

            if($property["propertyType"]=="qb:MeasureProperty"){

                $queryBuilder->selectDistinct("?dataType")
                    ->bind("datatype(?value) AS ?dataType");

                /** @var EasyRdf_Sparql_Result $subResult */
                $subResult = $this->sparql->query(
                    $queryBuilder->getSPARQL()
                );
                /** @var EasyRdf_Sparql_Result $result */

                $subResults = $this->rdfResultsToArray($subResult);

                $newMeasure->ref = $property["shortName"];
                $newMeasure->column = $attribute;
                $newMeasure->label = $property["label"];
                $newMeasure->orig_measure = $attribute;
                $this->model->measures[$property["shortName"]] = $newMeasure;

                foreach (Aggregate::$functions as $function) {
                    $newAggregate = new Aggregate();
                    $newAggregate->label = $newMeasure->ref . ' ' . $function;
                    $newAggregate->ref = $newMeasure->ref.'.'.$function;
                    $newAggregate->measure = $newMeasure->ref;
                    $newAggregate->function = $function;
                    $this->model->aggregates[$newAggregate->ref] = $newAggregate;

                }



            }
            else{
                $queryBuilder->selectDistinct("?extensionProperty", "?shortName", "?dataType", "?label")
                    ->where('?observation', 'a', 'qb:Observation')
                    ->where("?value", "?extensionProperty", "?extension")
                    ->where("?extensionProperty", "rdfs:label", "?label")
                    ->bind("datatype(?extension) AS ?dataType")
                    ->bind("REPLACE(str(?extensionProperty), '^.*(#|/)', \"\") AS ?shortName");

                $subResult = $this->sparql->query(
                    $queryBuilder->getSPARQL()
                );

                $subResults = $this->rdfResultsToArray($subResult);

                $newDimension = new Dimension();

                $newDimension->label =  $property["label"];
                $newDimension->cardinality_class = "someClass";
                $newDimension->ref= $property["shortName"];
                $newDimension->orig_dimension= $property["shortName"];



                foreach ($subResults as $subResult) {
                    //dd($subresult);
                    if(!isset($subResult["dataType"]))continue;

                    $newAttribute = new Attribute();
                    if($subResult["extensionProperty"] == "skos:prefLabel"){
                        $newDimension->label_ref =  $property["shortName"].".".$subResult["shortName"];
                        $newDimension->label_attribute = $subResult["shortName"];

                    }
                    if($subResult["extensionProperty"] == "skos:notation"){
                        $newDimension->key_ref =  $property["shortName"].".".$subResult["shortName"];
                        $newDimension->key_attribute = $subResult["shortName"];

                    }

                    $newAttribute->ref = $property["shortName"].".".$subResult["shortName"];
                    $newAttribute->column = $subResult["extensionProperty"];
                    $newAttribute->datatype = isset($subResult["dataType"])?$this->flatten_data_type($subResult["dataType"]):"";
                    $newAttribute->label = $subResult["label"];
                    $newAttribute->orig_attribute = $property["shortName"].".".$subResult["shortName"];

                    $newDimension->attributes[$subResult["shortName"]] = $newAttribute;


                }
                if(!isset($newDimension->label_ref) || !isset($newDimension->key_ref)){

                    $selfAttribute = new Attribute();

                    $selfAttribute->ref = $property["shortName"].".".$property["shortName"];
                    $selfAttribute->column = $attribute;
                    $selfAttribute->datatype = isset($property["dataType"])? $this->flatten_data_type($property["dataType"]):"";
                    $selfAttribute->label = $property["label"];
                    $selfAttribute->orig_attribute =  $property["shortName"].".".$property["shortName"];

                    $newDimension->attributes[$property["shortName"]] = $selfAttribute;

                }
                if(!isset($newDimension->label_ref)){
                    $newDimension->label_ref = $property["shortName"].".".$property["shortName"];
                    $newDimension->label_attribute = $property["shortName"];
                }

                if(!isset($newDimension->key_ref)){
                    $newDimension->key_ref = $property["shortName"].".".$property["shortName"];
                    $newDimension->key_attribute = $property["shortName"];
                }

                $this->model->dimensions[$property["shortName"]] = $newDimension;


            }
        }

        Cache::add($name, $this->model, 10);

    }

    /**
     * @return BabbageModel
     */
    public function getModel()
    {
        
        return $this->model;
    }

}