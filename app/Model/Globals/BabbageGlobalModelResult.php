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
use Asparagus\Tests\Integration\QueryBuilderTest;
use Cache;
use EasyRdf_Sparql_Result;
use Illuminate\Support\Facades\Log;

class BabbageGlobalModelResult extends BabbageModelResult
{



    public function __construct()
    {
        SparqlModel::__construct();
        $this->model = new BabbageModel();
        $this->model->fact_table = "global";

        $this->load2();
        $this->name = "global";
        $this->status = "ok";
    }

    public function load2()
    {
        if (Cache::has("global")) {
           $this->model = Cache::get("global");
           return;
        }
        $queryBuilder = new QueryBuilder(config("sparql.prefixes"));
        $queryBuilder
            ->selectDistinct('?attribute', '(MAX(?_label) AS ?label)', '?attachment',  "?shortName", "(MAX(?_datasetName) AS ?datasetName)", "?dataset", "(SAMPLE(?_datasetLabel) AS ?datasetLabel)", "?currency", "?year"/*, "(count(distinct ?value) AS ?cardinality)"*/)
            ->where("?dsd", 'qb:component', '?component')
            ->where("?dataset", "a", "qb:DataSet")
            ->where("?dataset", "qb:structure", "?dsd")
            ->optional("?dataset", "<http://data.openbudgets.eu/ontology/dsd/attribute/currency>", "?currency")
            ->where("?dataset", "rdfs:label", "?_datasetLabel")
            ->where('?component', '?componentProperty', '?attribute')
            ->where('?componentProperty', 'rdfs:subPropertyOf', 'qb:componentProperty')
            ->where('?attribute', 'rdfs:label', '?_label')
            ->bind("REPLACE(str(?attribute), '^.*(#|/)', \"\") AS ?shortName")
            ->optional('?component', 'qb:componentAttachment', '?attachment')
            ->optional("?dataset", "<http://data.openbudgets.eu/ontology/dsd/dimension/fiscalYear>", "?year")
            ->bind("CONCAT(REPLACE(str(?dataset), '^.*(#|/)', \"\"), '__', SUBSTR(MD5(STR(?dataset)),1,5)) AS ?_datasetName")->groupBy('?attribute', "?shortName", "?attachment", "?dataset", "?currency", "?year");;

        //   echo $queryBuilder->format();die;
        /** @var EasyRdf_Sparql_Result $propertiesSparqlResult */
        $propertiesSparqlResult = $this->sparql->query(
            $queryBuilder->getSPARQL()
        );
        /** @var EasyRdf_Sparql_Result $result */
        $propertiesSparqlResult = $this->rdfResultsToArray($propertiesSparqlResult);
        //dd($propertiesSparqlResult);
        //echo(json_encode($propertiesSparqlResult));die;

        foreach ($propertiesSparqlResult as $property) {

            $propertyTypeQueryBuilder = new QueryBuilder(config("sparql.prefixes"));
            $propertyTypeQueryBuilder->select("(SAMPLE(?_propertyType) AS ?propertyType)")
                ->optional($propertyTypeQueryBuilder->newSubgraph()
                    ->where("<{$property['attribute']}>", "a", "?_propertyType")->filter("?_propertyType in ( qb:MeasureProperty, qb:DimensionProperty, qb:CodedProperty)"));

            $propertyTypeSparqlResult = $this->sparql->query(
                $propertyTypeQueryBuilder->getSPARQL()
            );
            /** @var EasyRdf_Sparql_Result $result */
            $propertyTypeSparqlResult = $this->rdfResultsToArray($propertyTypeSparqlResult);
            $property["propertyType"] = $propertyTypeSparqlResult[0]["propertyType"];

            if (!isset($property["attribute"]) || !isset($property["propertyType"])) continue;

            $attribute = $property["attribute"];
            $queryBuilder = new QueryBuilder(config("sparql.prefixes"));
            $subQuery = $queryBuilder->newSubquery();
            $subSubQuery = $subQuery->newSubquery();
            $subSubQuery->selectDistinct("?value");
            $subSubQuery->limit(1);
            if (isset($property["attachment"]) && $property["attachment"] == "qb:Slice") {
                $subSubQuery->where('?observation', 'a', 'qb:Observation');

                $subSubQuery
                    ->where("?slice", "qb:observation", "?observation")
                    ->where("?slice", "<$attribute>", "?value");
            }  elseif (isset($property["attachment"]) && $property["attachment"] == "qb:DataSet") {

                $subSubQuery
                    ->where("?dataSet", "a", "qb:DataSet")
                    ->where("?dataSet", "<$attribute>", "?value");
            } else {
                $subSubQuery->where('?observation', 'a', 'qb:Observation');
                $subSubQuery->where("?observation", "<$attribute>", "?value");
            }
            if ($property["propertyType"] == "qb:MeasureProperty") {
                if (Cache::has($property["datasetName"] . "__" . $property["shortName"])) {

                    $newMeasure = Cache::get($property["datasetName"] . "__" . $property["shortName"]);

                } else {
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
                    $newMeasure->ref = $property["datasetName"] . "__" . $property["shortName"];
                    $newMeasure->column = $property["shortName"];// $attribute;
                    $newMeasure->setDataSet($property["dataset"]);
                    $newMeasure->label = (isset($property["label"]) ? $property["label"] : $property["shortName"]) . (isset($property["datasetLabel"]) ? " (" . $property["datasetLabel"] . ")" : " (" . $property["datasetName"] . ")");
                    $newMeasure->setDataSetFiscalYear(isset($property["year"]) ? $this->convertYear($property["year"]) : date("Y"));
                    $newMeasure->currency = isset($property["currency"]) ? $this->convertCurrency($property["currency"]) : "EUR";
                    $newMeasure->orig_measure = $property["shortName"];;// $attribute;
                    Cache::forget($property["datasetName"] . "__" . $property["shortName"]);
                    Cache::forever($property["datasetName"] . "__" . $property["shortName"], $newMeasure);

                }

                $this->model->measures[$property["datasetName"] . "__" . $property["shortName"]] = $newMeasure;


            }

            else {
                if (Cache::has($property["datasetName"] . "/" . $property["shortName"])) {
                    $newDimension = Cache::get($property["datasetName"] . "/" . $property["shortName"]);

                } else {
                    $subQuery->where("?value", "?extensionProperty", "?extension");
                    $subQuery->subquery($subSubQuery);
                    $subQuery->selectDistinct("?extensionProperty", "?extension");

                    $queryBuilder->selectDistinct("?extensionProperty", "(MAX(?shortName) AS ?shortName)", "(MAX(?dataType) AS ?dataType)", "(MAX(?label) AS ?label)", "(GROUP_CONCAT(distinct ?attLang  ; separator = \"|\") AS ?attLang)")
                        ->subquery($subQuery)
                        ->where("?extensionProperty", "rdfs:label", "?label")
                        ->bind("datatype(?extension) AS ?dataType")
                        ->bind("LANG(?extension) AS ?attLang")
                        ->bind("REPLACE(str(?extensionProperty), '^.*(#|/)', \"\") AS ?shortName")
                        ->groupBy("?extensionProperty");

                    $subResult = $this->sparql->query(
                        $queryBuilder->getSPARQL()
                    );
                    $subResults = $this->rdfResultsToArray($subResult);
                  //  echo $queryBuilder->format();
                   // var_dump($property["shortName"]);
                  //  var_dump($property["attribute"]);
                    $newDimension = new Dimension();
                    $newDimension->setDataSet($property["dataset"]);
                    $newDimension->label = (isset($property["label"]) ? $property["label"] : $property["shortName"]) . " (" . $property["datasetName"] . ")";
                    //$newDimension->cardinality_class = $this->getCardinality($property["cardinality"]);
                    $newDimension->ref = $property["datasetName"] . "__" . $property["shortName"];
                    $newDimension->orig_dimension = $property["shortName"];
                    $newDimension->setUri($attribute);
                    if (isset($property["attachment"]))
                        $newDimension->setAttachment($property["attachment"]);

                    foreach ($subResults as $subResult) {
                        $newAttribute = new Attribute();
                        if ($subResult["extensionProperty"] == "skos:prefLabel" || $subResult["extensionProperty"] == "rdfs:label") {
                            $newDimension->label_ref = $property["shortName"] . "." . $subResult["shortName"];
                            $newDimension->label_attribute = $subResult["shortName"];
                        }
                        if ($subResult["extensionProperty"] == "skos:notation") {
                            $newDimension->key_ref = $property["shortName"] . "." . $subResult["shortName"];
                            $newDimension->key_attribute = $subResult["shortName"];
                        }

                        $newAttribute->ref = $property["shortName"] . "." . $subResult["shortName"];
                        $newAttribute->column = $subResult["extensionProperty"];
                        $newAttribute->datatype = isset($subResult["dataType"]) ? $this->flatten_data_type($subResult["dataType"]) : "string";
                        $newAttribute->setUri($subResult["extensionProperty"]);
                        $newAttribute->label = $subResult["label"];
                        if(isset($subResult["attLang"]))$newAttribute->setLanguages( explode("||", $subResult["attLang"]));

                        $newAttribute->orig_attribute = /*$property["shortName"].".".*/
                            $subResult["shortName"];
                        //var_dump($newAttribute);

                        $newDimension->attributes[$subResult["shortName"]] = $newAttribute;
                    }
                    if (!isset($newDimension->label_ref) || !isset($newDimension->key_ref)) {
                        $selfAttribute = new Attribute();
                        $selfAttribute->ref = $property["shortName"] . "." . $property["shortName"];
                        $selfAttribute->column = $attribute;
                        $selfAttribute->datatype = isset($property["dataType"]) ? $this->flatten_data_type($property["dataType"]) : "string";
                        $selfAttribute->label = isset($property["label"]) ? $property["label"] : $property["shortName"];
                        $selfAttribute->orig_attribute =  /*$property["shortName"].".".*/
                            $property["shortName"];
                        $selfAttribute->setUri($attribute);
                        $selfAttribute->setVirtual(true);
                        $newDimension->attributes[$property["shortName"]] = $selfAttribute;

                    }
                    if (!isset($newDimension->label_ref)) {
                        $newDimension->label_ref = $property["shortName"] . "." . $property["shortName"];
                        $newDimension->label_attribute = $property["shortName"];
                    }

                    if (!isset($newDimension->key_ref)) {
                        $newDimension->key_ref = $property["shortName"] . "." . $property["shortName"];
                        $newDimension->key_attribute = $property["shortName"];
                    }
                    Cache::forget($property["datasetName"] . "/" . $property["shortName"]);
                    Cache::forever($property["datasetName"] . "/" . $property["shortName"], $newDimension);
                }
                $this->model->dimensions[$property["datasetName"] . "__" . $property["shortName"]] = $newDimension;

            }
        }

        {
            $globalsQueryBuilder = new QueryBuilder(config("sparql.prefixes"));
            $globalsQueryBuilder->where("?dataset", "qb:structure", "?dsd");
            $globalsQueryBuilder->where("?dataset", "a", "qb:DataSet");
            $globalsQueryBuilder->where("?dsd", "a", "qb:DataStructureDefinition");
            $globalsQueryBuilder->where("?dsd", "qb:component", "?component");
            $globalsQueryBuilder->where("?component", "?componentProperty", "?attribute");
            $globalsQueryBuilder->where("?attribute", "rdfs:label", "?label");
            $globalsQueryBuilder->values(["?componentProperty"=>["qb:dimension", "qb:measure", "qb:attribute"]]);
            $globalsQueryBuilder->filter('LANG(?label) = "" || LANGMATCHES(LANG(?label), "en")');
            $globalsQueryBuilder->bind("CONCAT(REPLACE(str(?dataset), '^.*(#|/)', \"\"), '__', SUBSTR(MD5(STR(?dataset)),1,5), '__', REPLACE(str(?attribute), '^.*(#|/)', \"\")) AS ?shortName");
            $globalsQueryBuilder->bind("REPLACE(str(?dataset), '^.*(#|/)', \"\") AS ?originalName");
            //$globalsQueryBuilder->optional($globalsQueryBuilder->newSubgraph()->where("?attribute", "rdfs:subPropertyOf", "?parent")->where("?parent", "a", "rdf:Property")->where("?parent", "rdfs:label", "?parentLabel"));
            $globalsQueryBuilder->selectDistinct(["?dataset", "?attribute", "?label", "?shortName", "?originalName"]);
            //echo $globalsQueryBuilder->format();die;
            $globalsResult = $this->sparql->query(
                $globalsQueryBuilder->getSPARQL()
            );
            //dd($globalsResult);
            /** @var EasyRdf_Sparql_Result $result */
            $globalsResults = $this->rdfResultsToArray($globalsResult);
            /** @var GlobalDimension[] $globalDimensions */
            $globalDimensions = [];
            /** @var GlobalMeasure[] $globalMeasures */
            $globalMeasures = [];
            //echo $globalsQueryBuilder->format();die;
           // dd($globalsResults);
            foreach ($globalsResults as &$globalTuple) {
                $globalsSubqueryBuilder = new QueryBuilder(config("sparql.prefixes"));
                $globalsSubqueryBuilder->selectDistinct("?parent", "(SAMPLE(?_parentLabel) AS ?parentLabel)")
                    ->where("<{$globalTuple["attribute"]}>", "rdfs:subPropertyOf", "?parent")
                    ->where("?parent", "a", "rdf:Property")
                    ->where("?parent", "rdfs:label", "?_parentLabel")
                    ->filter('LANG(?_parentLabel) = "" || LANGMATCHES(LANG(?_parentLabel), "en")')
                ;
                $parentResult = $this->sparql->query(
                    $globalsSubqueryBuilder->getSPARQL()
                );
                $parentResults = $this->rdfResultsToArray($parentResult);
                $globalTuple["parent"] = isset($parentResults[0]["parent"])?$parentResults[0]["parent"]:null;
                $globalTuple["parentLabel"] = isset($parentResults[0]["parentLabel"])?$parentResults[0]["parentLabel"]:null;


            }
            //dd($globalsResults);


            foreach ($globalsResults as $globalTuple) {
                //dd($this->model->dimensions);

                if (!isset($this->model->dimensions[$globalTuple["shortName"]])) continue; //need dimensions not measures

                $newGlobalDimension = new GlobalDimension();
                if (isset($globalTuple["parent"])) {
                    $ref = "global__" . preg_replace("/^.*(#|\/)/", "", $globalTuple["parent"]) . "__" . substr(md5($globalTuple["parent"]), 0, 5);

                    if (!isset($globalDimensions[$ref])) {
                        $globalDimensions[$ref] = $newGlobalDimension;
                        $globalDimensions[$ref]->ref = $ref;
                        $globalDimensions[$ref]->orig_dimension = $ref;
                        $globalDimensions[$ref]->setUri($globalTuple["parent"]);
                        $globalDimensions[$ref]->label = "Global " . $globalTuple["parentLabel"];

                    }

                    $existingInnerDimension = $this->model->dimensions[$globalTuple["shortName"]];
                    $globalDimensions[$ref]->addInnerDimension($existingInnerDimension);
                } else {
                    $ref = "global__" . preg_replace("/^.*(#|\/)/", "", $globalTuple["attribute"]) . "__" . substr(md5($globalTuple["attribute"]), 0, 5);
                    if (!isset($globalDimensions[$ref])) {
                        $globalDimensions[$ref] = $newGlobalDimension;
                        $globalDimensions[$ref]->ref = "global__" . preg_replace("/^.*(#|\/)/", "", $globalTuple["attribute"]) . "__" . substr(md5($globalTuple["attribute"]), 0, 5);
                        $globalDimensions[$ref]->setUri($globalTuple["attribute"]);
                        $globalDimensions[$ref]->orig_dimension = $ref;

                        $globalDimensions[$ref]->label = "Global " . $globalTuple["label"];

                    }
                    $existingInnerDimension = $this->model->dimensions[$globalTuple["shortName"]];
                    $globalDimensions[$ref]->addInnerDimension($existingInnerDimension);
                    $globalDimensions[$ref]->setAttachment($existingInnerDimension->getAttachment());

                }
            }

            foreach ($globalsResults as $globalTuple) {
                if (!isset($this->model->measures[$globalTuple["shortName"]])) continue; //need measures not dimensions
                $newGlobalMeasure = new GlobalMeasure();
                //dd($this->model->measures);
                if (isset($globalTuple["parent"])) {
                    $ref = "global__" . preg_replace("/^.*(#|\/)/", "", $globalTuple["parent"]) . "__" . substr(md5($globalTuple["parent"]), 0, 5);
                   // dump($newGlobalMeasure);
                    if (!isset($globalMeasures[$ref])) {
                        $globalMeasures[$ref] = $newGlobalMeasure;
                        $globalMeasures[$ref]->ref = $ref;
                        $globalMeasures[$ref]->currency = "EUR";
                        $globalMeasures[$ref]->setOriginalMeasure($globalTuple["originalName"]);
                        $globalMeasures[$ref]->setUri($globalTuple["parent"]);
                        $globalMeasures[$ref]->setSpecialUri($globalTuple["parent"]);
                        $globalMeasures[$ref]->label = "Global " . $globalTuple["parentLabel"];
                    }
                    $existingInnerMeasure = $this->model->measures[$globalTuple["shortName"]];
                    $globalMeasures[$ref]->addInnerMeasure($existingInnerMeasure);
                } else {
                    $ref = "global__" . preg_replace("/^.*(#|\/)/", "", $globalTuple["attribute"]) . "__" . substr(md5($globalTuple["attribute"]), 0, 5);
                    if (!isset($globalMeasures[$ref])) {
                        $globalMeasures[$ref] = $newGlobalMeasure;
                        $globalMeasures[$ref]->ref = $ref;
                        $globalMeasures[$ref]->currency = "EUR";
                        $globalMeasures[$ref]->column = $ref;
                        $globalMeasures[$ref]->setUri($globalTuple["attribute"]);
                        $globalMeasures[$ref]->setSpecialUri($globalTuple["attribute"]);
                        $globalMeasures[$ref]->setOriginalMeasure($globalTuple["originalName"]);
                        $globalMeasures[$ref]->label = "Global " . $globalTuple["label"];

                    }
                    $existingInnerMeasure = $this->model->measures[$globalTuple["shortName"]];
                    $globalMeasures[$ref]->addInnerMeasure($existingInnerMeasure);

                }
            }
            $globalMeasuresCurrencyVariants = [];
            $available_currencies = array_unique(array_flatten(array_map(function ($measure) {
                /** @var GlobalMeasure $measure */
                return array_map(function ($measure) {
                    /** @var Measure $measure */
                    return $measure->currency;
                }, $measure->getInnerMeasures());
            }, $globalMeasures)));
            foreach ($globalMeasures as $globalMeasure) {
                // dd($available_currencies);
                foreach ($available_currencies as $available_currency) {
                    $currency = $this->convertCurrency($available_currency);
                    if ($currency == "EUR") continue;
                    $newGlobalMeasure = new GlobalMeasure();
                    $newGlobalMeasure->ref = $globalMeasure->ref . '__' . $currency;
                    $newGlobalMeasure->label = $globalMeasure->label . ' in ' . $currency;
                    $newGlobalMeasure->column = $newGlobalMeasure->ref;
                    $newGlobalMeasure->setUri($globalMeasure->getUri());
                    $newGlobalMeasure->setSpecialUri($globalMeasure->getUri() . "#$available_currency");


                    $newGlobalMeasure->currency = $this->convertCurrency($currency);
                    $newGlobalMeasure->setOriginalMeasure($globalMeasure->orig_measure);
                    $newGlobalMeasure->addInnerMeasures($globalMeasure->getInnerMeasures());
                    $globalMeasuresCurrencyVariants[$newGlobalMeasure->ref] = $newGlobalMeasure;

                }
            }
            $globalMeasures = array_merge($globalMeasures, $globalMeasuresCurrencyVariants);

            //dd($globalDimensions);
//dd($globalDimensions);
            foreach ($globalDimensions as $key => &$globalDimensionGroup) {

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
                        $queryBuilder->select("?extensionProperty", "(MAX(?shortName) AS ?shortName)", "(MAX(?dataType) AS ?dataType)", "(MAX(?label) AS ?label)", "(GROUP_CONCAT(distinct ?attLang  ; separator = \"|\") AS ?attLang)")
                            ->subquery($subQuery)
                            ->where("?extensionProperty", "rdfs:label", "?label")
                            ->bind("datatype(?extension) AS ?dataType")
                            ->bind("LANG(?extension) AS ?attLang")
                            ->bind("REPLACE(str(?extensionProperty), '^.*(#|/)', \"\") AS ?shortName")
                            ->groupBy("?extensionProperty")
                        ;


                        $subResult = $this->sparql->query(
                            $queryBuilder->getSPARQL()
                        );
                        $subResults = $this->rdfResultsToArray($subResult);


                        foreach ($subResults as $subResult) {

                            $newAttribute = new Attribute();
                            if ($subResult["extensionProperty"] == "skos:prefLabel" || $subResult["extensionProperty"] == "rdfs:label") {
                                $innerDimension->label_ref = $innerDimension->orig_dimension . "." . $subResult["shortName"];
                                $innerDimension->label_attribute = $subResult["shortName"];

                            }
                            if ($subResult["extensionProperty"] == "skos:notation") {
                                $innerDimension->key_ref = $innerDimension->orig_dimension . "." . $subResult["shortName"];
                                $innerDimension->key_attribute = $subResult["shortName"];
                                unset($innerDimension->attributes[$key]);
                            }

                            $newAttribute->ref = $innerDimension->orig_dimension . "." . $subResult["shortName"];
                            $newAttribute->column = $subResult["shortName"];
                            $newAttribute->datatype = isset($subResult["dataType"]) ? $this->flatten_data_type($subResult["dataType"]) : "string";
                            $newAttribute->setUri($subResult["extensionProperty"]);
                            if(isset($subResult["attLang"]))$newAttribute->setLanguages( explode("||", $subResult["attLang"]));
                            $newAttribute->label = $subResult["label"];
                            $newAttribute->orig_attribute = $subResult["shortName"];
                            $innerDimension->attributes[$subResult["shortName"]] = $newAttribute;

                        }
                    }
                }
            }
//dump($globalDimensions);
            foreach ($globalDimensions as $key => &$globalDimensionGroup) {

                if (count($globalDimensionGroup->getInnerDimensions()) < 2) {
                    unset($globalDimensions[$key]);
                } else {
                    $innerDimensions = $globalDimensionGroup->getInnerDimensions();

                    $innerDimensionAttributes = array_map(function ($value) {
                        /** @var Dimension $value */
                        return $value->attributes;
                    }, $innerDimensions);
                    //dump($innerDimensionAttributes);
                    $attributes = [];
                    $candidate_attributes = call_user_func_array("array_merge", $innerDimensionAttributes);
                   //dump($candidate_attributes);

                    foreach ($candidate_attributes as $att_key => &$attribute) {
                        /** @var Attribute $attribute */
                        $glob_att = clone $attribute;
                        $glob_att->ref = $key . '.' . $attribute->orig_attribute;
                        $glob_att->setLanguages(array_merge($glob_att->getLanguages()));
                        $attributes[$att_key] = $glob_att;
                    }
                    if (empty($attributes)) {
                        $newAttribute = new Attribute();
                        $newAttribute->ref = $key . '.' . $key;
                        $newAttribute->label = $globalDimensionGroup->label;
                        $newAttribute->setUri($globalDimensionGroup->getUri());
                        $newAttribute->setVirtual(true);
                        $newAttribute->orig_attribute = $globalDimensionGroup->ref;
                        $newAttribute->column = $key;

                        $attributes[$key] = $newAttribute;
                    }
                    $globalDimensionGroup->attributes = $attributes;

                    $allKeys = array_unique( array_map(function ($value) {
                        /** @var Dimension $value */
                        return $value->key_attribute;
                    }, $innerDimensions));
                    //dump($globalDimensionGroup->attributes);

                    if (count($allKeys) > 1) {
                        /** @var Attribute $firstAttribute */
                        $firstAttribute = in_array("notation",$allKeys)?$globalDimensionGroup->attributes["notation"]:reset($globalDimensionGroup->attributes);
                        $globalDimensionGroup->key_attribute = $firstAttribute->orig_attribute;
                        $globalDimensionGroup->key_ref = $firstAttribute->ref;
                    } else {
                        $keyAttribute = $globalDimensionGroup->attributes[reset($allKeys)];
                        $globalDimensionGroup->key_attribute = $keyAttribute->orig_attribute;
                        $globalDimensionGroup->key_ref = $keyAttribute->ref;
                    }

                    $allLabels = array_unique($innerDimensionAttributes = array_map(function ($value) {
                        /** @var Dimension $value */
                        return $value->label_attribute;
                    }, $innerDimensions));
                    if (count($allLabels) > 1) {
                        /** @var Attribute $firstAttribute */
                        $firstAttribute = in_array("prefLabel",$allLabels)?$globalDimensionGroup->attributes["prefLabel"]:reset($globalDimensionGroup->attributes);
                        $globalDimensionGroup->label_attribute = $firstAttribute->orig_attribute;
                        $globalDimensionGroup->label_ref = $firstAttribute->ref;
                    } else {
                        $keyAttribute = $globalDimensionGroup->attributes[reset($allLabels)];
                        $globalDimensionGroup->label_attribute = $keyAttribute->orig_attribute;
                        $globalDimensionGroup->label_ref = $keyAttribute->ref;
                    }
                }
            }

            $this->model->dimensions = $globalDimensions;
            //dd($this->model->measures);
            $this->model->measures = $globalMeasures;// array_merge($this->model->measures, $globalMeasures);
        }

        /** @var GlobalMeasure $measure */
        foreach ($this->model->measures as $measure) {
            //dd($this->model->aggregates);
            $datasets = array_map(function (Measure $measure) {
                return $measure->getDataSet();
            }, $measure->getInnerMeasures());
            foreach (Aggregate::$functions as $function) {
                if ($measure instanceof GlobalMeasure) {
                    $newAggregate = new GlobalAggregate();
                } else {
                    $newAggregate = new Aggregate();

                }

                $newAggregate->label = $measure->label;
                $newAggregate->ref = $measure->ref . '.' . $function;
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


    protected function convertYear($yearURI)
    {
        return str_replace("http://reference.data.gov.uk/id/year/", "", $yearURI);
    }
}

/**
 *
 * PREFIX xro: <http://purl.org/xro/ns#>
 *
 *
 * CONSTRUCT{
 * ?uri a  xro:ExchangeRateInfo.
 * ?uri xro:rate ?rate.
 * ?uri xro:yearOfConversion ?year.
 * ?uri xro:target ?target.
 * ?uri xro:source ?source.
 * }
 * WHERE
 * {
 *
 *
 * ?s a <http://purl.org/linked-data/cube#Observation>. ?s <http://purl.org/linked-data/sdmx/2009/dimension#refTime> ?date.
 * ?s <http://linked.opendata.cz/resource/ontology/currencies#hasRate> ?rate.
 * ?s <http://linked.opendata.cz/resource/ontology/currencies#currency> ?currency.
 * {SELECT (MIN(?date) as ?date) WHERE {?s a <http://purl.org/linked-data/cube#Observation>. ?s <http://purl.org/linked-data/sdmx/2009/dimension#refTime> ?date } GROUP BY year(?date)}
 * BIND(year(?date) as ?year)
 * BIND(replace(STR(?currency), "http://linked.opendata.cz/resource/currency#", "") as ?curr)
 * BIND (URI(CONCAT("http://data.openbudgets.eu/exchangerates/EUR/",?curr,"/",?year)) AS ?uri)
 * BIND(URI(CONCAT("http://data.openbudgets.eu/codelist/currency/",?curr)) AS ?target)
 * BIND(URI(CONCAT("http://data.openbudgets.eu/codelist/currency/EUR")) AS ?source)
 *
 * }
 *
 * order by ?year ?currency
 *
 *
 *
 *
 */