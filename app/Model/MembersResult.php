<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 22/03/2016
 * Time: 11:05:33
 */

namespace App\Model;


use App\Model\Sparql\SubPattern;
use App\Model\Sparql\TriplePattern;
use Asparagus\QueryBuilder;
use Cache;
use Log;

class MembersResult extends SparqlModel
{
    public $data;
    public $status;
    public $cell =[];
    public $fields;
    public $order = [];
    public $page;
    public $page_size;
    public $total_member_count;
    public function __construct($name, $dimension, $page, $page_size, $order)
    {
        parent::__construct();

        
        $this->load($name, $dimension,  $page, $page_size, $order);


        $this->page = $page;
        $this->page_size = $page_size;
        $this->order = $order;

        $this->status = "ok";
    }

    private function load($name, $attributeShortName,  $page, $page_size, $order)
    {

        if(Cache::has($name.'/'.$attributeShortName.'/'.$page.'/'.$page.'/'.implode('$',$this->order))){
            $this->data =  Cache::get($name.'/'.$attributeShortName);
            return;
        }

        $model = (new BabbageModelResult($name))->model;
        //($model->dimensions[$dimensionShortName]);
        $dimensionShortName = explode(".",$attributeShortName )[0];
        $this->fields = [$model->dimensions[$dimensionShortName]->key_ref, $model->dimensions[$dimensionShortName]->label_ref];

        // return $facts;
        $dimensions = $model->dimensions;

        $actualDimension = $model->dimensions[explode('.',$dimensionShortName)[0]];
        $selectedPatterns = $this->modelFieldsToPatterns($model,[$actualDimension->label_ref, $actualDimension->key_ref]);
        $selectedDimensions = [];
        $bindings = [];
        $attributes = [];
        foreach ($dimensions as $dimensionName=>$dimension) {
            if(!isset($selectedPatterns[$dimension->getUri()])) continue;
            $selectedDimensions[$dimension->getUri()] = $dimension;
            $bindingName = "binding_" .  substr(md5($dimensionName),0,5);
            $valueAttributeLabel = "uri";

            $attributes[$dimension->getUri()][$valueAttributeLabel] = $bindingName;
            $bindings[$dimension->getUri()] = "?$bindingName";
            break;
        }
        $dataset = $model->getDataset();



        $sliceSubGraph = new SubPattern([
            new TriplePattern("?slice", "a", "qb:Slice"),
            new TriplePattern("?slice", "qb:observation", "?observation"),

        ], true);
        $dataSetSubGraph = new SubPattern([
            new TriplePattern("<$dataset>", "a", "qb:DataSet"),

        ], true);


        $needsSliceSubGraph = false;
        $needsDataSetSubGraph = false;
        //dd($selectedDimensions);
        foreach ($selectedDimensions as $dimensionName=>$dimension) {
            $attribute = $dimensionName;
            $attachment = $dimension->getAttachment();
            if(isset($attachment) && $attachment=="qb:Slice"){
                $needsSliceSubGraph = true;
                $sliceSubGraph->add(new TriplePattern("?slice", $attribute, $bindings[$attribute] , false));
            }
            elseif(isset($attachment) && $attachment=="qb:DataSet"){
                $needsDataSetSubGraph = true;
                $dataSetSubGraph->add(new TriplePattern("<$dataset>", $attribute, $bindings[$attribute] , false));
            }
            else{
                $patterns [] = new TriplePattern("?observation", $attribute, $bindings[$attribute], false);
            }

            if($dimension instanceof Dimension){

//dd($dimension->attributes[$dimension->key_attribute]->getUri());
                if($dimension->ref!=$dimension->key_attribute){
                    $attributes[$attribute][$dimension->attributes[$dimension->key_attribute]->getUri()] = $attributes[$attribute]["uri"]."_". substr(md5($dimension->key_attribute),0,5) ;
                    $bindings[] = $bindings[$attribute]."_". substr(md5($dimension->key_attribute),0,5) ;

                }

                if($dimension->ref!=$dimension->label_attribute){
                    $attributes[$attribute][$dimension->attributes[$dimension->label_attribute]->getUri()] = $attributes[$attribute]["uri"]."_". substr(md5($dimension->label_attribute),0,5) ;

                    $bindings[] = $bindings[$attribute]."_". substr(md5($dimension->label_attribute),0,5) ;
                }


                //var_dump($dimension->attributes);
              //  var_dump($dimension->key_attribute);
                if(isset($attachment) && $attachment=="qb:Slice"){
                    if($dimension->ref!=$dimension->key_attribute)

                        $sliceSubGraph->add(new TriplePattern($bindings[$attribute],$dimension->attributes[$dimension->key_attribute]->getUri(),$bindings[$attribute]."_". substr(md5($dimension->key_attribute),0,5), true));

                    if($dimension->ref!=$dimension->label_attribute)

                        $sliceSubGraph->add(new TriplePattern($bindings[$attribute],$dimension->attributes[$dimension->label_attribute]->getUri(),$bindings[$attribute]."_". substr(md5($dimension->label_attribute),0,5), true));
                }

                elseif(isset($attachment) && $attachment=="qb:DataSet"){
                    if($dimension->ref!=$dimension->key_attribute)

                        $dataSetSubGraph->add(new TriplePattern($bindings[$attribute],$dimension->attributes[$dimension->key_attribute]->getUri(),$bindings[$attribute]."_". substr(md5($dimension->key_attribute),0,5), true));

                    if($dimension->ref!=$dimension->label_attribute)

                        $dataSetSubGraph->add(new TriplePattern($bindings[$attribute],$dimension->attributes[$dimension->label_attribute]->getUri(),$bindings[$attribute]."_". substr(md5($dimension->label_attribute),0,5), true));
                }
                else{
                    if($dimension->ref!=$dimension->key_attribute)
                        $patterns [] = new TriplePattern($bindings[$attribute],$dimension->attributes[$dimension->key_attribute]->getUri(),$bindings[$attribute]."_". substr(md5($dimension->key_attribute),0,5), true);
                    if($dimension->ref!=$dimension->label_attribute)
                        $patterns [] = new TriplePattern($bindings[$attribute],$dimension->attributes[$dimension->label_attribute]->getUri(),$bindings[$attribute]."_". substr(md5($dimension->label_attribute),0,5), true);

                }


            }
        }


        if($needsSliceSubGraph){
            $patterns[] = $sliceSubGraph;

        }

        if($needsDataSetSubGraph){
            $patterns[] = $dataSetSubGraph;

        }
        //$dsd = $model->getDsd();
        $patterns[] = new TriplePattern('?observation', 'a', 'qb:Observation');
        $patterns[] = new TriplePattern('?observation', 'qb:dataSet', "<$dataset>");

        $queryBuilder = $this->build($bindings, $patterns );
        $queryBuilderC = $this->buildC($bindings, $patterns );
        $resultC = $this->sparql->query(
            $queryBuilderC->getSPARQL()
        );
        $this->total_member_count = $resultC[0]->count->getValue();

        $queryBuilder->limit($page_size);
        $queryBuilder->offset($page* $page_size);
        Log::info($queryBuilder->format());
       // echo $queryBuilder->format();die;
        $result = $this->sparql->query(
            $queryBuilder->getSPARQL()
        );
        $results = $this->rdfResultsToArray3($result,$attributes, $model, $selectedPatterns, true);
        //dd($results);

        if($results!=null)
            $this->data = $results;
        else $this->data = [];


    }


    private function build(array $bindings, array $filters){
        $queryBuilder = new QueryBuilder(config("sparql.prefixes"));
        $innerQueryBuilder = $queryBuilder->newSubquery();
        $outsiderFilteredLabels=[];
        foreach ($filters as $filter) {
            if($filter instanceof TriplePattern ){


                if ($filter->predicate == "skos:prefLabel") {

                    $outsiderFilteredLabels[] = $filter->object;
                    $queryBuilder->optional($queryBuilder->newSubgraph()->filter("LANG({$filter->object}) = 'en' || LANG({$filter->object}) = 'el'")->where($filter->subject, self::expand($filter->predicate, $filter->transitivity), $filter->object));


                } else {
                    if (in_array($filter->object, $filters)) $innerSelectedFields[$filter->object] = $filter->object;
                    $innerQueryBuilder->where($filter->subject, self::expand($filter->predicate, $filter->transitivity), $filter->object);

                }


            }
            elseif($filter instanceof SubPattern){

                foreach($filter->patterns as $pattern){

                    if ($pattern->predicate == "skos:prefLabel") {

                        $outsiderFilteredLabels[] = $pattern->object;
                        $queryBuilder->optional($queryBuilder->newSubgraph()->filter("LANG({$pattern->object}) = 'en' || LANG({$pattern->object}) = 'el'")->where($pattern->subject, self::expand($pattern->predicate, $pattern->transitivity), $pattern->object));


                    } else {
                        if (in_array($pattern->object, $bindings)) $innerSelectedFields[$pattern->object] = $pattern->object;
                        $innerQueryBuilder->where($pattern->subject, self::expand($pattern->predicate, $pattern->transitivity), $pattern->object);

                    }

                    $innerQueryBuilder->where($pattern->subject, self::expand($pattern->predicate), $pattern->object);

                }


            }
        }

        $innerQueryBuilder
            ->groupBy(array_unique(array_diff($bindings, $outsiderFilteredLabels)))
            ->select(array_unique(array_diff($bindings, $outsiderFilteredLabels)))

        ;

        $queryBuilder->subquery($innerQueryBuilder);
        $queryBuilder->select($bindings);
        $queryBuilder->groupBy($bindings);


        return $queryBuilder;

    }

  private function buildC(array $bindings, array $filters){



      $queryBuilder = new QueryBuilder(config("sparql.prefixes"));
      $innerQueryBuilder = $queryBuilder->newSubquery();
      $outsiderFilteredLabels=[];
      foreach ($filters as $filter) {
          if($filter instanceof TriplePattern ){


              if ($filter->predicate == "skos:prefLabel") {

                  $outsiderFilteredLabels[] = $filter->object;
                  $queryBuilder->optional($queryBuilder->newSubgraph()->filter("LANG({$filter->object}) = 'en' || LANG({$filter->object}) = 'el'")->where($filter->subject, self::expand($filter->predicate, $filter->transitivity), $filter->object));


              } else {
                  if (in_array($filter->object, $filters)) $innerSelectedFields[$filter->object] = $filter->object;
                  $innerQueryBuilder->where($filter->subject, self::expand($filter->predicate, $filter->transitivity), $filter->object);

              }


          }
          elseif($filter instanceof SubPattern){

              foreach($filter->patterns as $pattern){

                  if ($pattern->predicate == "skos:prefLabel") {

                      $outsiderFilteredLabels[] = $pattern->object;
                      $queryBuilder->optional($queryBuilder->newSubgraph()->filter("LANG({$pattern->object}) = 'en' || LANG({$pattern->object}) = 'el'")->where($pattern->subject, self::expand($pattern->predicate, $pattern->transitivity), $pattern->object));


                  } else {
                      if (in_array($pattern->object, $bindings)) $innerSelectedFields[$pattern->object] = $pattern->object;
                      $innerQueryBuilder->where($pattern->subject, self::expand($pattern->predicate, $pattern->transitivity), $pattern->object);

                  }

                  $innerQueryBuilder->where($pattern->subject, self::expand($pattern->predicate), $pattern->object);

              }


          }
      }
      $basicBindings = array_diff($bindings, $outsiderFilteredLabels);

      $innerQueryBuilder
          ->groupBy($basicBindings)
          ->select($basicBindings)

      ;


      $queryBuilder->subquery($innerQueryBuilder);
      $queryBuilder->select(array_map(function ($item){ return "(COUNT (distinct $item) AS ?count)";}, $basicBindings));




        return $queryBuilder;

    }


}