<?php

namespace Rector\Tests\Php80\Rector\FunctionLike\UnionTypesRector\Fixture;

class Fixture
{
    /**
     * @param array|int $number
     * @return bool|float
     */
    public function go($number)
    {
    }
}

?>
