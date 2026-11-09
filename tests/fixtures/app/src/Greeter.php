<?php

namespace App;

final class Greeter
{
    public function hello(): string
    {
        return 'hello from ' . (\Phar::running(false) === '' ? 'the tree' : 'the phar');
    }
}
