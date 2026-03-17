<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Myxa\Database\QueryBuilder;

$builder = new QueryBuilder();
$builder->select('*')
    ->from('table_test')
    ->where('id', '=', 123)
    ->whereIn('id', [1,2,3])
    ->orderBy('date');
    
print_r($builder->debugSql());