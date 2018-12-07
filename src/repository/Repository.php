<?php
/*
 * This file is part of Laravel Credentials.
 *
 * (c) Graham Campbell <graham@alt-three.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\repository;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Request;

abstract class Repository
{

    /**
     * @var Model
     */
    private $model;

    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    protected static $expression = [
        'eq'          => '=',
        'neq'         => '!=',
        'ne'          => '!=',
        'gt'          => '>',
        'egt'         => '>=',
        'gte'         => '>=',
        'ge'          => '>=',
        'lt'          => '<',
        'le'          => '<=',
        'lte'         => '<=',
        'elt'         => '<=',
        'in'          => 'In',
        'not_in'      => 'NotIn',
        'not in'      => 'NotIn',
        'between'     => 'Between',
        'not_between' => 'NotBetween',
        'not between' => 'NotBetween',
        'like'        => 'LIKE',
        'not_like'    => 'NOT LIKE',
        'not like'    => 'NOT LIKE',
    ];

    protected static $expressionApiMap = [
        'not_in'      => 'whereNotIn',
        'in'          => 'whereIn',
        'between'     => 'whereBetween',
        'not_between' => 'whereNotBetween',
    ];


    public function get($filters = [], $fields = ['*'])
    {
        return $this->makeBuilder($filters , $fields)->first();
    }

    public function getAll($filters = [], $fields = ['*'])
    {
        return $this->makeBuilder($filters , $fields)->get();
    }

    public function page($filters = [], $fields = ['*'])
    {
        $pagesize = intval(Request::get('pagesize', 10));
        $page = intval(Request::get('p', 1));
        $offset = $pagesize * ($page - 1);

        $builder = $this->makeBuilder($filters , $fields);
        $ret['code'] = 0;
        $ret['total'] = $builder->count();
        $ret['rows'] = $builder->offset($offset)->take($pagesize)->get()->toArray();
        return $ret ;
    }

    public function pager($filters = [], $fields = ['*']){
        $builder = $this->makeBuilder($filters , $fields);
        return $builder->paginate(10)->appends(Request::all());
    }

    /**
     * @param $data
     * @return Model
     */
    public function create($data)
    {
        return $this->model->create($data);
    }

    public function update($filters,$data){
        return $this->makeBuilder($filters , [])->update($data);
    }

    public function save($data , $id = 0)
    {
        if($id){
            return $this->update($id , $data);
        }
        return $this->create($data);
    }

    public function delete($filter){
        return $this->makeBuilder($filter , [])->delete();
    }

    /**
     * @param $key
     * @return HasOneOrMany
     */
    protected function getRelation($key , $model){
        if (method_exists($model , $key)){
            return $model->{$key}();
        }
        return null;
    }

    /**
     * @param $filters
     * @param $fields
     * @return Builder
     */
    public function makeBuilder($filters, $fields)
    {
        $builder = $this->model->newQuery();
        $this->bindBuilderFilters($builder , $filters);
        $this->bindBuilderFields($builder , $fields);
        return $builder;
    }



    private function bindBuilderFilters(Builder $builder, $filters)
    {
        // 如果是标量
        if (is_scalar($filters)) {
            $filters = [$this->model->getKeyName() => $filters];
        }

        if (isset($filters['order'])) {

            foreach ($this->parseOrder($filters['order']) as $item) {
                $builder->orderBy($item[0], $item[1]);
            }
            unset($filters['order']);
        }

        if (isset($filters['limit'])) {
            $builder->limit($filters['limit']);
            unset($filters['limit']);
        }

        if (isset($filters['offset'])) {
            $builder->offset($filters['offset']);
            unset($filters['offset']);
        }

        if (isset($filters['group'])) {
            $builder->groupBy($filters['group']);
            unset($filters['group']);
        }

        // 普通字段
        foreach ($filters as $field => $val) {
            if (strpos($field, ':') === false) {
                $builder->where([$field => $val]);
                continue;
            }

            $sp = explode(':', $field);
            $dbField = $sp[0];
            $exp = trim($sp[1]);

            if (isset(self::$expressionApiMap[$exp])) {
                $method = self::$expressionApiMap[$exp];
                $builder->{$method}($dbField, $val);
            } else {
                $builder->where($dbField, self::$expression[$exp], $val);
            }
        }
    }

    private function bindBuilderFields(Builder $builder, $fields)
    {
        if (empty($fields)){
            return;
        }
        $appendFiled = [] ;
        foreach ($fields as $key => $val){
            if (is_numeric($key)){
                continue;
            }


            $relation = $this->getRelation($key , $builder->getModel());
            $localKey = $relation->getQualifiedParentKeyName();
            if($relation instanceof BelongsTo){
                $foreignKey = $relation->getForeignKey();
            }else {
                $foreignKey = $relation->getForeignKeyName();
            }
            $appendFiled[] = $localKey ;

            $builder->with([$key => function($query) use ($val , $foreignKey){
                if(!empty($val)){
                    $val[] = $foreignKey ;
                    $this->bindBuilderFields($query->getQuery() , $val);
                }
            }]);
            unset($fields[$key]);
        }
        $fields = array_unique(array_merge($fields , $appendFiled));
        $builder->select($fields);
    }

    private function parseOrder($order)
    {
        // 'id desc,type asc' => [ ['id' , 'desc'] , [..] ]
        $rs = [];
        foreach (explode(',', $order) as $item) {
            $item = trim($item);
            if (!$item) {
                continue;
            }
            $sp = explode(' ', $item);
            if (count($sp) < 2) {
                $rs[] = [$sp[0], 'asc'];
            } else {
                $rs[] = $sp;
            }
        }

        return $rs;
    }
}