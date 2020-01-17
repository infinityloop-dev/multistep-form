<?php

declare(strict_types = 1);

namespace Infinityloop\MultistepForm;

interface IMultistepFormFactory
{
    public function create() : \Infinityloop\MultistepForm\MultistepForm;
}
