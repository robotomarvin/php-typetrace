<?php

declare(strict_types=1);

namespace App;

interface Pet
{
}

abstract class Animal
{
}

final class Dog extends Animal implements Pet
{
}

final class Cat extends Animal
{
}

final class Rock
{
}
