<?php

namespace Knp\Component\Pager;

interface PaginatorInterface
{
    public function paginate($target, $page = 1, $limit = 10, array $options = []);
}
