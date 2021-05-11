<?php

namespace Rector\Tests\Php80\Rector\FunctionLike\UnionTypesRector\Fixture;

class FalsePseudoType
{
    /**
     * @return false|int
     */
    public function go($value)
    {
        return (int) $value ?? false;
    }
}

?>
