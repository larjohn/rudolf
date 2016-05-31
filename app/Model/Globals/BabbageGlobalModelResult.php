<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 23/04/2016
 * Time: 16:59:12
 */

namespace App\Model\Globals;


use App\Model\Aggregate;
use App\Model\Attribute;
use App\Model\BabbageModel;
use App\Model\BabbageModelResult;
use App\Model\Dimension;
use App\Model\Hierarchy;
use App\Model\Measure;
use App\Model\SparqlModel;
use Asparagus\QueryBuilder;
use Cache;
use EasyRdf_Sparql_Result;

class BabbageGlobalModelResult extends BabbageModelResult
{

    public $id;

    public function __construct()
    {
        SparqlModel::__construct();
        $this->model = new BabbageModel();

        $this->load2();
        $this->name="global";
        $this->status = "ok";
    }
    public function load2()
    {

        if(Cache::has("global")){
            $this->model =  Cache::get("global");//dd($this->model);
            return;
        }
        $queryBuilder = new QueryBuilder(config("sparql.prefixes"));
        $queryBuilder
            ->selectDistinct('?attribute', '(MAX(?label) as ?label)', '?attachment', "(SAMPLE(?propertyType) AS ?propertyType)", "?shortName", "(MAX(?datasetName) AS ?datasetName)", "?dataset", "(SAMPLE(?datasetLabel) AS ?datasetLabel)"/*, "(count(distinct ?value) AS ?cardinality)"*/)
            ->where("?dsd", 'qb:component', '?component')
            ->where("?dataset", "a", "qb:DataSet")
            ->where("?dataset","qb:structure", "?dsd" )
            ->optional("?dataset", "rdfs:label" ,"?datasetLabel" )
            ->where('?component', '?componentProperty', '?attribute')
            ->where('?componentProperty', 'rdfs:subPropertyOf', 'qb:componentProperty')
            ->optional('?attribute', 'rdfs:label', '?label')
            ->bind("REPLACE(str(?attribute), '^.*(#|/)', \"\") AS ?shortName")
            ->optional('?component', 'qb:componentAttachment', '?attachment')
            ->bind("CONCAT(REPLACE(str(?dataset), '^.*(#|/)', \"\"), '__', SUBSTR(MD5(STR(?dataset)),1,5)) AS ?datasetName")
/*            ->filter("?name = '$name'")*/
            /*            ->optional($queryBuilder->newSubgraph()
                           ->where("?slice", "qb:observation", "?observation")
                          ->where("?slice",  "?attribute", "?value"))*//*
            ->optional("?observation", "?attribute" ,"?value")*/
            ->optional($queryBuilder->newSubgraph()
                ->where("?attribute", "a", "?propertyType")->filter("?propertyType in ( qb:MeasureProperty, qb:DimensionProperty, qb:CodedProperty)"))
/*            ->filterNotExists('?component', 'qb:componentAttachment', 'qb:DataSet')*/
            ->groupBy('?attribute', "?shortName", "?attachment", "?dataset");
        ;

        //echo($queryBuilder->format());die;
        /** @var EasyRdf_Sparql_Result $propertiesSparqlResult */
        $propertiesSparqlResult = $this->sparql->query(
            $queryBuilder->getSPARQL()
        );
        /** @var EasyRdf_Sparql_Result $result */

        $propertiesSparqlResult = $this->rdfResultsToArray($propertiesSparqlResult);

        foreach ($propertiesSparqlResult as $property) {
            $attribute = $property["attribute"];
            $queryBuilder = new QueryBuilder(config("sparql.prefixes"));
            $subQuery = $queryBuilder->newSubquery();
            $subSubQuery = $subQuery->newSubquery();
            $subSubQuery->where('?observation', 'a', 'qb:Observation');
            $subSubQuery->select("?value");
            $subSubQuery->limit(1);
           // dd("lol");
            if(isset($property["attachment"]) &&  $property["attachment"]=="qb:Slice"){

                $subSubQuery
                    ->where("?slice", "qb:observation", "?observation")
                    ->where("?slice",  "<$attribute>", "?value");
            }
            else{
                $subSubQuery->where("?observation", "<$attribute>" ,"?value");
            }
            if($property["propertyType"]=="qb:MeasureProperty"){
               if(Cache::has($property["datasetName"]."__".$property["shortName"])){

                    $newMeasure = Cache::get($property["datasetName"] . "__" . $property["shortName"]);

                }
                else
                {
                    $newMeasure = new Measure();

                    $queryBuilder->selectDistinct("?dataType")
                        ->bind("datatype(?value) AS ?dataType");

                    /** @var EasyRdf_Sparql_Result $subResult */
                    $subResult = $this->sparql->query(
                        $queryBuilder->getSPARQL()
                    );
                    /** @var EasyRdf_Sparql_Result $result */
                    $subResults = $this->rdfResultsToArray($subResult);
                    $newMeasure->setUri($attribute);
                    $newMeasure->ref = $property["datasetName"]."__".$property["shortName"];
                    $newMeasure->column = $property["shortName"];// $attribute;
                    $newMeasure->setDataSet($property["dataset"]);
                    $newMeasure->label = $property["label"]. (isset($property["datasetLabel"])?" (".$property["datasetLabel"].")":" (".$property["datasetName"].")");
                    $newMeasure->orig_measure = $property["shortName"];;// $attribute;
                    Cache::forget($property["datasetName"]."__".$property["shortName"]);
                    Cache::forever($property["datasetName"]."__".$property["shortName"], $newMeasure);

                }

                $this->model->measures[$property["datasetName"]."__".$property["shortName"]] = $newMeasure;





            }
            else{
               if(Cache::has($property["datasetName"]."__".$property["shortName"])){

                    $newDimension = Cache::get($property["datasetName"] . "__" . $property["shortName"]);

                }
                else
                {
                    $subQuery->where("?value", "?extensionProperty", "?extension");
                    $subQuery->subquery($subSubQuery);
                    $subQuery->selectDistinct("?extensionProperty", "?extension");
                    $queryBuilder->selectDistinct("?extensionProperty", "?shortName", "?dataType", "?label")
                        ->subquery($subQuery)
                        ->where("?extensionProperty", "rdfs:label", "?label")
                        ->bind("datatype(?extension) AS ?dataType")
                        ->bind("REPLACE(str(?extensionProperty), '^.*(#|/)', \"\") AS ?shortName");

                    $subResult = $this->sparql->query(
                        $queryBuilder->getSPARQL()
                    );
                    $subResults = $this->rdfResultsToArray($subResult);
                    // var_dump($property);
                    //echo($queryBuilder->format());
                    $newDimension = new Dimension();
                    $newDimension->setDataSet($property["dataset"]);

                    $newDimension->label =  $property["label"] . " (". $property["datasetName"].")";
                    //$newDimension->cardinality_class = $this->getCardinality($property["cardinality"]);
                    $newDimension->ref= $property["datasetName"]."__".$property["shortName"];
                    $newDimension->orig_dimension= $property["shortName"]

                    ;
                    $newDimension->setUri($attribute);
                    if(isset($property["attachment"]))
                        $newDimension->setAttachment($property["attachment"]);



                    foreach ($subResults as $subResult) {

                        // dd($subResults);
                        // if(!isset($subResult["dataType"]))continue;

                        $newAttribute = new Attribute();
                        if($subResult["extensionProperty"] == "skos:prefLabel" || $subResult["extensionProperty"] == "rdfs:label"){
                            $newDimension->label_ref =  $property["shortName"].".".$subResult["shortName"];
                            $newDimension->label_attribute = $subResult["shortName"];

                        }
                        if($subResult["extensionProperty"] == "skos:notation"){
                            $newDimension->key_ref =  $property["shortName"].".".$subResult["shortName"];
                            $newDimension->key_attribute = $subResult["shortName"];

                        }

                        $newAttribute->ref = $property["shortName"].".".$subResult["shortName"];
                        $newAttribute->column = $subResult["extensionProperty"];
                        $newAttribute->datatype = isset($subResult["dataType"])?$this->flatten_data_type($subResult["dataType"]):"string";
                        $newAttribute->setUri($subResult["extensionProperty"]);
                        $newAttribute->label = $subResult["label"];
                        $newAttribute->orig_attribute = /*$property["shortName"].".".*/$subResult["shortName"];

                        $newDimension->attributes[$subResult["shortName"]] = $newAttribute;


                    }
                    if(!isset($newDimension->label_ref) || !isset($newDimension->key_ref)){

                        $selfAttribute = new Attribute();

                        $selfAttribute->ref = $property["shortName"].".".$property["shortName"];
                        $selfAttribute->column = $attribute;
                        $selfAttribute->datatype = isset($property["dataType"])? $this->flatten_data_type($property["dataType"]):"string";
                        $selfAttribute->label = $property["label"];
                        $selfAttribute->orig_attribute =  /*$property["shortName"].".".*/$property["shortName"];
                        $selfAttribute->setUri($attribute);
                        $selfAttribute->setVirtual(true);
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
                    Cache::forget($property["datasetName"]."__".$property["shortName"]);
                    Cache::forever($property["datasetName"]."__".$property["shortName"], $newDimension);
                }



                //var_dump($property);
                $this->model->dimensions[$property["datasetName"]."__".$property["shortName"]] = $newDimension;
                //dd($newDimension);

            }

        }

        {
            $globalsQueryBuilder = new QueryBuilder(config("sparql.prefixes"));

            $globalsQueryBuilder->where("?type", "rdfs:subClassOf", "qb:ComponentProperty");
            $globalsQueryBuilder->where("?dsd", "qb:component", "?component");
            $globalsQueryBuilder->where("?attribute", "rdfs:label", "?label");
            $globalsQueryBuilder->where("?dataset", "a", "qb:DataSet");
            $globalsQueryBuilder->where("?dataset", "qb:structure", "?dsd");
            $globalsQueryBuilder->where("?component", "?componentProperty", "?attribute");
            $globalsQueryBuilder->where("?attribute", "a", "?type");
            $globalsQueryBuilder->bind("CONCAT(REPLACE(str(?dataset), '^.*(#|/)', \"\"), '__', SUBSTR(MD5(STR(?dataset)),1,5), '__', REPLACE(str(?attribute), '^.*(#|/)', \"\")) AS ?shortName");
            $globalsQueryBuilder->bind("REPLACE(str(?dataset), '^.*(#|/)', \"\") AS ?originalName");
            $globalsQueryBuilder->optional($globalsQueryBuilder->newSubgraph()->where("?attribute", "rdfs:subPropertyOf", "?parent")->where("?parent", "a", "rdf:Property")->where("?parent", "rdfs:label", "?parentLabel"));
            $globalsQueryBuilder->selectDistinct(["?dataset", "?attribute", "?label", "?parent", "?parentLabel", "?shortName", "?originalName"]);
            $globalsResult = $this->sparql->query(
                $globalsQueryBuilder->getSPARQL()
            );
            //echo $globalsQueryBuilder->format();die;
            /** @var EasyRdf_Sparql_Result $result */
            $globalsResults = $this->rdfResultsToArray($globalsResult);
            /** @var GlobalDimension[] $globalDimensions */
            $globalDimensions = [];
            /** @var GlobalMeasure[] $globalMeasures */
            $globalMeasures = [];
            $globalDimensionsAttributes = [];
            foreach ($globalsResults as $globalTuple) {
                if(!isset($this->model->dimensions[$globalTuple["shortName"]]))continue; //need dimensions not measures
                $newGlobalDimension = new GlobalDimension();

                if(isset($globalTuple["parent"]) ){
                    $ref = "global__". preg_replace("/^.*(#|\/)/", "", $globalTuple["parent"])."__" . substr(md5($globalTuple["parent"]),0,5) ;

                    if(!isset($globalDimensions[$ref])){
                        $globalDimensions[$ref] = $newGlobalDimension;
                        $globalDimensions[$ref]->ref = $ref ;
                        $globalDimensions[$ref]->orig_dimension = $ref ;
                        $globalDimensions[$ref]->setUri($globalTuple["parent"]);
                        $globalDimensions[$ref]->label = "Global ". $globalTuple["parentLabel"];

                    }

                    $existingInnerDimension = $this->model->dimensions[$globalTuple["shortName"]];
                    $globalDimensions[$ref]->addInnerDimension($existingInnerDimension);
                }
                else{
                    $ref = "global__". preg_replace("/^.*(#|\/)/", "", $globalTuple["attribute"])."__" . substr(md5($globalTuple["attribute"]),0,5) ;
                    if(!isset($globalDimensions[$ref])){
                        $globalDimensions[$ref] = $newGlobalDimension;
                        $globalDimensions[$ref]->ref = "global__". preg_replace("/^.*(#|\/)/", "", $globalTuple["attribute"])."__" . substr(md5($globalTuple["attribute"]),0,5) ;
                        $globalDimensions[$ref]->setUri($globalTuple["attribute"]);
                        $globalDimensions[$ref]->orig_dimension = $ref ;

                        $globalDimensions[$ref]->label ="Global ". $globalTuple["label"];

                    }
//dd($this->model->dimensions);
                    $existingInnerDimension = $this->model->dimensions[$globalTuple["shortName"]];
                    $globalDimensions[$ref]->addInnerDimension($existingInnerDimension);
                    $globalDimensions[$ref]->setAttachment($existingInnerDimension->getAttachment());

                }


            }
            //dd($globalDimensions);

            foreach ($globalsResults as $globalTuple) {
                //var_dump($this->model->measures);
                if(!isset($this->model->measures[$globalTuple["shortName"]]))continue; //need dimensions not measures
                $newGlobalMeasure = new GlobalMeasure();
                if(isset($globalTuple["parent"]) ){
                    $ref = "global__". preg_replace("/^.*(#|\/)/", "", $globalTuple["parent"])."__" . substr(md5($globalTuple["parent"]),0,5) ;

                    if(!isset($newGlobalMeasure[$ref])){
                        $globalMeasures[$ref] = $newGlobalMeasure;
                        $globalMeasures[$ref]->ref = $ref ;
                        $globalMeasures[$ref]->setOriginalMeasure($globalTuple["originalName"]) ;
                        $globalMeasures[$ref]->setUri($globalTuple["parent"]);
                        $globalMeasures[$ref]->label = "Global ". $globalTuple["parentLabel"];

                    }

                    $existingInnerMeasure = $this->model->measures[$globalTuple["shortName"]];
                    $globalMeasures[$ref]->addInnerMeasure($existingInnerMeasure);
                }
                else{
                    $ref =  preg_replace("/^.*(#|\/)/", "", $globalTuple["attribute"])."__" . substr(md5($globalTuple["attribute"]),0,5) ;
                    if(!isset($globalMeasures[$ref])){
                        $globalMeasures[$ref] = $newGlobalMeasure;
                        $globalMeasures[$ref]->ref = $ref ;
                        $globalMeasures[$ref]->currency = "EUR" ;
                        $globalMeasures[$ref]->column = $ref ;
                        $globalMeasures[$ref]->setUri($globalTuple["attribute"]);
                        $globalMeasures[$ref]->setOriginalMeasure($globalTuple["originalName"]) ;

                        $globalMeasures[$ref]->label ="Global ". $globalTuple["label"];

                    }
//dd($this->model->dimensions);
                    $existingInnerMeasure = $this->model->measures[$globalTuple["shortName"]];
                    $globalMeasures[$ref]->addInnerMeasure($existingInnerMeasure);




                }
            }
            foreach ($globalDimensions as $key=>&$globalDimensionGroup) {

                $attachment = $globalDimensionGroup->getAttachment();


                if (isset($attachment) && $attachment == "qb:DataSet") {


                    /** @var Dimension $innerDimension */
                    foreach ($globalDimensionGroup->getInnerDimensions() as &$innerDimension) {
                        $dataset = $innerDimension->getDataSet();
                        $queryBuilder = new QueryBuilder(config("sparql.prefixes"));
                        $attr = $globalDimensionGroup->getUri();
                        $subQuery = $queryBuilder->newSubquery();
                        $subSubQuery = $subQuery->newSubquery();
                        $subSubQuery->where("<$dataset>", 'a', 'qb:DataSet');
                        $subSubQuery->where("<$dataset>", "<$attr>", "?value");
                        $subSubQuery->select("?value");
                        $subQuery->where("?value", "?extensionProperty", "?extension");
                        $subQuery->subquery($subSubQuery);
                        $subQuery->selectDistinct("?extensionProperty", "?extension");
                        $queryBuilder->selectDistinct("?extensionProperty", "?shortName", "?dataType", "?label")
                            ->subquery($subQuery)
                            ->where("?extensionProperty", "rdfs:label", "?label")
                            ->bind("datatype(?extension) AS ?dataType")
                            ->bind("REPLACE(str(?extensionProperty), '^.*(#|/)', \"\") AS ?shortName");
                      //     echo $queryBuilder->format();
                        $subResult = $this->sparql->query(
                            $queryBuilder->getSPARQL()
                        );
                        $subResults = $this->rdfResultsToArray($subResult);
                        // var_dump($property);
                        //dd($subResults);
                        //$newDimension->cardinality_class = $this->getCardinality($property["cardinality"]);
                        foreach ($subResults as $subResult) {

                            // dd($subResults);
                            // if(!isset($subResult["dataType"]))continue;

                            $newAttribute = new Attribute();
                            if ($subResult["extensionProperty"] == "skos:prefLabel"|| $subResult["extensionProperty"] == "rdfs:label") {
                                $innerDimension->label_ref = $innerDimension->orig_dimension . "." . $subResult["shortName"];
                                $innerDimension->label_attribute = $subResult["shortName"];

                            }
                            if ($subResult["extensionProperty"] == "skos:notation") {
                                $innerDimension->key_ref = $innerDimension->orig_dimension . "." . $subResult["shortName"];
                                $innerDimension->key_attribute = $subResult["shortName"];
                                unset($innerDimension->attributes[$key]);

                            }


                            $newAttribute->ref = $innerDimension->orig_dimension . "." . $subResult["shortName"];
                            $newAttribute->column = $subResult["extensionProperty"];
                            $newAttribute->datatype = isset($subResult["dataType"]) ? $this->flatten_data_type($subResult["dataType"]) : "string";
                            $newAttribute->setUri($subResult["extensionProperty"]);
                            $newAttribute->label = $subResult["label"];
                            $newAttribute->orig_attribute =                           $subResult["shortName"];
                            $innerDimension->attributes[$subResult["shortName"]] = $newAttribute;
                            //var_dump($globalDimensionGroup);



                        }
                    }


                }


            }
//die;
    //  dd($globalDimensions);
    //  dd($globalDimensions);
            foreach ($globalDimensions as $key=>&$globalDimensionGroup) {


                if(count($globalDimensionGroup->getInnerDimensions())<2){
                    unset($globalDimensions[$key]);
                }
                else{
                    $innerDimensions = $globalDimensionGroup->getInnerDimensions();

                    $innerDimensionAttributes = array_map(function( $value){
                        /** @var Dimension $value */
                        return $value->attributes;
                    },  $innerDimensions);

                    //var_dump($innerDimensionAttributes);
                    $attributes = [];
                    $candidate_attributes = call_user_func_array("array_intersect_key",$innerDimensionAttributes);
                    foreach ($candidate_attributes as $att_key =>&$attribute) {
                        /** @var Attribute $attribute */
                        $glob_att = clone $attribute;
                        $glob_att->ref = $key.'.'. $attribute->orig_attribute;
                        $attributes[$att_key] = $glob_att;
                    }
                    if(empty($attributes)){
                        $newAttribute = new Attribute();
                        $newAttribute->ref = $key.'.'.$key;
                        $newAttribute->label = $globalDimensionGroup->label;
                        $newAttribute->setUri($globalDimensionGroup->getUri());
                        $newAttribute->setVirtual(true);
                        $newAttribute->orig_attribute = $globalDimensionGroup->ref;

                        $attributes[$key] = $newAttribute;
                    }
                    //dd($innerDimensionAttributes);
                    $globalDimensionGroup->attributes = $attributes;


                    $allKeys = array_unique($innerDimensionAttributes = array_map(function( $value){
                        /** @var Dimension $value */
                        return $value->key_attribute;
                    },  $innerDimensions));

                    if(count($allKeys)>1){
                        /** @var Attribute $firstAttribute */
                        $firstAttribute = reset($globalDimensionGroup->attributes);
                        $globalDimensionGroup->key_attribute = $firstAttribute->orig_attribute;
                        $globalDimensionGroup->key_ref = $firstAttribute->ref;
                    }
                    else{
                        $keyAttribute = $globalDimensionGroup->attributes[reset($allKeys)];
                        $globalDimensionGroup->key_attribute = $keyAttribute->orig_attribute;
                        $globalDimensionGroup->key_ref = $keyAttribute->ref;
                    }

                    $allLabels = array_unique($innerDimensionAttributes = array_map(function( $value){
                        /** @var Dimension $value */
                        return $value->label_attribute;
                    },  $innerDimensions));

                    if(count($allLabels)>1){
                        /** @var Attribute $firstAttribute */
                        $firstAttribute = reset($globalDimensionGroup->attributes);
                        $globalDimensionGroup->label_attribute = $firstAttribute->orig_attribute;
                        $globalDimensionGroup->label_ref = $firstAttribute->ref;
                    }
                    else{
                        $keyAttribute = $globalDimensionGroup->attributes[reset($allLabels)];
                        $globalDimensionGroup->label_attribute = $keyAttribute->orig_attribute;
                        $globalDimensionGroup->label_ref = $keyAttribute->ref;
                    }




                }
                //if($key=="global__currency__1a842")dd($attributes);

            }

            //dd($globalDimensions);

          //  dd($globalsResults);
            $this->model->dimensions  = $globalDimensions;
            $this->model->measures =$globalMeasures ;// array_merge($this->model->measures, $globalMeasures);




        }

        /** @var GlobalMeasure $measure */
        foreach ($this->model->measures as $measure) {
            //dd($this->model->aggregates);
            $datasets = array_map(function (Measure $measure){return $measure->getDataSet();}, $measure->getInnerMeasures());
            foreach (Aggregate::$functions as $function) {
                if($measure instanceof GlobalMeasure){
                    $newAggregate = new GlobalAggregate();
                }
                else{
                    $newAggregate = new Aggregate();

                }

                $newAggregate->label = $measure->ref . ' ' . $function;
                $newAggregate->ref = $measure->ref.'.'.$function;
                $newAggregate->measure = $measure->ref;
                $newAggregate->function = $function;
                $newAggregate->setDataSets($datasets);
                $this->model->aggregates[$newAggregate->ref] = $newAggregate;

            }
        }

        foreach ($this->model->dimensions as $dimension) {
            $newHierarchy = new Hierarchy();
            $newHierarchy->label = $dimension->label;
            $newHierarchy->ref = $dimension->ref;
            $newHierarchy->levels = [$dimension->ref];
            $dimension->hierarchy = $newHierarchy->ref;
            $this->model->hierarchies[$newHierarchy->ref] = $newHierarchy;
        }

        $countAggregate = new Aggregate();
        $countAggregate->label = "Facts";
        $countAggregate->function = "count";
        $countAggregate->ref = "_count";
        $this->model->aggregates["_count"] = $countAggregate;
        Cache::forget("global");
        Cache::forever("global", $this->model);

    }
}